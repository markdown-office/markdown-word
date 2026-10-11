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
 * @see \MarkdownWord\Template\TemplatePackage for the template side of the same
 *      question, which also settles the type on the way in.
 */
final class WordPackage
{
    /** The type a `.docx` declares for its body. */
    public const DOCUMENT_MAIN = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';

    /** The type a `.dotx` declares for the same part. */
    public const TEMPLATE_MAIN = 'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml';

    /** The declarations themselves, which every other answer here is read out of. */
    public const CONTENT_TYPES = '[Content_Types].xml';

    /**
     * Where the body is when the declarations say nothing about it.
     *
     * What PHPWord assumes and what nearly every package uses; the lookup exists
     * for the ones that do not, so it is a fallback rather than an error. A caller
     * that then fails to find the part reports it by name, which says more than a
     * failure here would about a part the caller never asked for.
     */
    public const FALLBACK_MAIN = 'word/document.xml';

    /**
     * The part holding the body of an open archive.
     *
     * Read through the XML rather than by pattern, because the attributes of an
     * `Override` may be written in either order and a lookup that assumes one of
     * them cannot tell a part it failed to find from a package that declares none.
     */
    public static function bodyPart(ZipArchive $zip): string
    {
        $declared = self::contentTypesOf($zip);

        // An archive with no declarations is not one this library wrote, and an
        // empty string is not XML that fails to parse — `DOMDocument::loadXML()`
        // rejects it outright, so it is answered here rather than caught.
        if ($declared === '') {
            return self::FALLBACK_MAIN;
        }

        $dom = Xml::parse($declared);

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
     * The relationship part belonging to a body part.
     *
     * By the package's own convention rather than by substitution, so a body part
     * called something other than `document.xml` finds its relationships beside it.
     */
    public static function relationshipsFor(string $part): string
    {
        return dirname($part) . '/_rels/' . basename($part) . '.rels';
    }

    /**
     * Whether the declarations say the body is a template's rather than a document's.
     *
     * Which is what makes a filled-in template something other than a document: Word
     * opens a package typed this way by creating a new document from it.
     */
    public static function isTemplate(ZipArchive $zip): bool
    {
        return str_contains(self::contentTypesOf($zip), self::TEMPLATE_MAIN);
    }

    /** The declarations in an open archive, or an empty string when unreadable. */
    public static function contentTypesOf(ZipArchive $zip): string
    {
        $declared = $zip->getFromName(self::CONTENT_TYPES);

        return is_string($declared) ? $declared : '';
    }
}