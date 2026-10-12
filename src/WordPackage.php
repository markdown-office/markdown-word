<?php

declare(strict_types=1);

namespace MarkdownWord;

use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * What a Word package is made of, as far as this library is concerned.
 *
 * A `.docx` is a zip archive, and the part holding the body is whichever part
 * `[Content_Types].xml` declares as the main one. The name is nearly always
 * `word/document.xml`, and "nearly" is the whole difficulty: documents from Word
 * Online have been seen carrying `word/document22.xml`, which is why PHPWord
 * resolves the name rather than assuming it.
 *
 * That resolution matches the *document* content type, so it finds nothing in a
 * `.dotx` and falls back to `word/document.xml` — which is to say it guesses, and
 * the guess is a part nobody reads. Every pass over a finished document has to ask
 * here instead of naming the part, or it edits a part the reader never opens.
 *
 * @see \MarkdownWord\Template\TemplatePackage for the template side, which settles
 *      the type on the way in.
 */
final class WordPackage
{
    public const DOCUMENT_MAIN = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';

    public const TEMPLATE_MAIN = 'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml';

    /** The declarations every other answer here is read out of. */
    public const CONTENT_TYPES = '[Content_Types].xml';

    /**
     * Where the body is when the declarations say nothing about it: what PHPWord
     * assumes and what nearly every package uses. A fallback rather than an error,
     * because a caller that then fails to find the part reports it by name, which
     * says more than a failure here would about a part it never asked for.
     */
    public const FALLBACK_MAIN = 'word/document.xml';

    /**
     * Read through the XML rather than by pattern, because the attributes of an
     * `Override` may be written in either order and a lookup that assumes one of
     * them cannot tell a part it failed to find from a package that declares none.
     */
    public static function bodyPart(ZipArchive $zip): string
    {
        $declared = self::contentTypesOf($zip);

        // An archive with no declarations is not one this library wrote, and an
        // empty string is not XML that fails to parse — `DOMDocument::loadXML()`
        // rejects it outright — so both are answered here rather than caught.
        $dom = $declared === '' ? null : Xml::parse($declared);

        if ($dom === null) {
            return self::FALLBACK_MAIN;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ct', 'http://schemas.openxmlformats.org/package/2006/content-types');

        foreach ($xpath->query('//ct:Override') ?: [] as $override) {
            if (!$override instanceof DOMElement) {
                continue;
            }

            $type = $override->getAttribute('ContentType');

            if ($type !== self::DOCUMENT_MAIN && $type !== self::TEMPLATE_MAIN) {
                continue;
            }

            $name = ltrim($override->getAttribute('PartName'), '/');

            // The main part is a document, and this is a path a package writes: a
            // name that walks out of the archive is refused rather than handed back
            // for a caller to open, in a package that is not this library's.
            if (preg_match('~^word/document[A-Za-z0-9._-]*\.xml$~', $name) === 1) {
                return $name;
            }
        }

        return self::FALLBACK_MAIN;
    }

    /**
     * For the callers that only need to know where to read — the command line
     * checking that a region is in a template — and so must not be handed a copy
     * of a file that is not theirs to change.
     */
    public static function mainPartOf(string $path): string
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            return self::FALLBACK_MAIN;
        }

        try {
            return self::bodyPart($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * By the package's own convention rather than by substitution, so a body part
     * called something other than `document.xml` finds its relationships beside it.
     */
    public static function relationshipsFor(string $part): string
    {
        return dirname($part) . '/_rels/' . basename($part) . '.rels';
    }

    /**
     * Whether the body is declared as a template's rather than a document's, which
     * is what makes a filled-in template something other than a document: Word opens
     * a package typed this way by creating a new document from it.
     */
    public static function isTemplate(ZipArchive $zip): bool
    {
        return str_contains(self::contentTypesOf($zip), self::TEMPLATE_MAIN);
    }

    public static function contentTypesOf(ZipArchive $zip): string
    {
        $declared = $zip->getFromName(self::CONTENT_TYPES);

        return is_string($declared) ? $declared : '';
    }
}