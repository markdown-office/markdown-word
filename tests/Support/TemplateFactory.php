<?php

declare(strict_types=1);

namespace MarkdownWord\Tests\Support;

use MarkdownWord\WordPackage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;
use ZipArchive;

/**
 * Builds the Word documents the tests need as input, rather than checking binary
 * fixtures into the repository.
 */
final class TemplateFactory
{
    /**
     * A template with a single value, a Markdown region and a repeating region —
     * the constructs a typical report needs.
     */
    public static function report(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        $section->addText('Quarterly Report');
        $section->addText('Reference: ${reference}');

        $section->addText('${summary}');
        $section->addText('${slot}');
        $section->addText('${/summary}');

        $section->addText('${lines}');
        $section->addText('${line}');
        $section->addText('${/lines}');

        return self::write($phpWord, 'report');
    }

    /**
     * A template with nothing but a Markdown region, for tests that only care
     * about the inserted content.
     */
    public static function placeholder(string $name = 'body'): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        $section->addText('${' . $name . '}');
        $section->addText('${slot}');
        $section->addText('${/' . $name . '}');

        return self::write($phpWord, 'placeholder-' . $name);
    }

    /**
     * A template whose styles use non-default names, to prove the configuration
     * can target a corporate look.
     */
    public static function styled(): string
    {
        $phpWord = new PhpWord();
        $phpWord->addParagraphStyle('CorpTitle', ['bold' => true, 'size' => 20]);
        $phpWord->addParagraphStyle('CorpBody', ['size' => 11]);

        $section = $phpWord->addSection();
        $section->addText('${body}');
        $section->addText('${slot}');
        $section->addText('${/body}');

        return self::write($phpWord, 'styled');
    }

    /**
     * A template that brings a list of its own, so the numbering part it carries
     * has to be continued rather than filled in from scratch.
     */
    public static function withList(string $name = 'body'): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        $section->addText('${' . $name . '}');
        $section->addText('${slot}');
        $section->addText('${/' . $name . '}');

        // An ordered list, so the Markdown inserted into the same document is
        // the thing that changes format: numbered where the Markdown said
        // bullets is visible in a way two bullet lists are not.
        $phpWord->addNumberingStyle('CorpOrdered', [
            'type' => 'multilevel',
            'levels' => self::levels('decimal', '%1.'),
        ]);

        $section->addListItemRun(0, 'CorpOrdered')->addText('template one');
        $section->addListItemRun(0, 'CorpOrdered')->addText('template two');

        return self::write($phpWord, 'with-list');
    }

    /**
     * The nine levels a Word list definition has to carry.
     *
     * @return list<array<string, int|string>>
     */
    private static function levels(string $format, string $text): array
    {
        $levels = [];

        for ($level = 1; $level <= 9; $level++) {
            $levels[] = [
                'format' => $format,
                'text' => str_replace('%1', '%' . $level, $text),
                'left' => 720 * $level,
                'hanging' => 360,
                'start' => 1,
            ];
        }

        return $levels;
    }

    /**
     * A real `.dotx` — the same document with its body declared as a template's.
     *
     * Built rather than checked in, because a `.dotx` is a `.docx` with one line of
     * `[Content_Types].xml` changed and there is nothing in that line a binary
     * fixture would show a reader.
     *
     * @param string $mainPart The name the body part is given inside the archive.
     *        `document22.xml` is what documents from Word Online carry, and it is the
     *        case a lookup that assumes `document.xml` gets wrong.
     * @param string $source The template to convert; the default is the plain
     *        `${body}` region, and {@see self::report()} is the one carrying text of
     *        its own — which is what lets a test tell a document rendered into a
     *        template from the same Markdown rendered into nothing.
     */
    public static function dotx(
        string $mainPart = 'document.xml',
        string $name = 'dotx',
        ?string $source = null,
    ): string {
        $source ??= self::placeholder();
        $path = Scratch::path($name . '-' . $mainPart . '.dotx');

        $zip = new ZipArchive();
        $zip->open($source);

        try {
            $declared = $zip->getFromName('[Content_Types].xml');
            $document = $zip->getFromName('word/document.xml');
            $rels = $zip->getFromName('word/_rels/document.xml.rels');
            $root = $zip->getFromName('_rels/.rels');
        } finally {
            $zip->close();
        }

        copy($source, $path);

        $out = new ZipArchive();
        $out->open($path);

        // Renaming the body means renaming what points at it too, or the package is
        // one no reader can follow the body into.
        if ($mainPart !== 'document.xml') {
            $out->deleteName('word/document.xml');
            $out->addFromString('word/' . $mainPart, $document);
            $out->deleteName('word/_rels/document.xml.rels');
            $out->addFromString(
                'word/_rels/' . $mainPart . '.rels',
                str_replace('document.xml', $mainPart, $rels),
            );
            $out->deleteName('_rels/.rels');
            $out->addFromString('_rels/.rels', str_replace('word/document.xml', 'word/' . $mainPart, $root));

            $declared = str_replace('/word/document.xml', '/word/' . $mainPart, $declared);
        }

        $out->deleteName('[Content_Types].xml');
        $out->addFromString('[Content_Types].xml', str_replace(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml',
            $declared,
        ));
        $out->close();

        return $path;
    }

    /**
     * The content type a finished document declares for its body part.
     *
     * What a reader decides a file is by, and the reason a filled-in `.dotx` is
     * not a document while every word in it is exactly where it should be.
     */
    public static function mainContentTypeOf(string $docx): string
    {
        $xml = self::xmlOf($docx, '[Content_Types].xml');

        preg_match('~PartName="/word/document[^"]*" ContentType="([^"]*)"~', $xml, $matches);

        return $matches[1] ?? '';
    }

    /** Whether the archive holds a part by that name. */
    public static function hasPart(string $docx, string $part): bool
    {
        $zip = new ZipArchive();
        $opened = $zip->open($docx) === true;
        $found = $opened && $zip->locateName($part) !== false;
        $zip->close();

        return $found;
    }

    /**
     * The text of whichever part holds the body, read through the declarations.
     *
     * {@see self::textOf()} reads `word/document.xml` directly, which is right for
     * the documents this library writes and wrong for a package that calls its body
     * something else — which is the whole of what these tests are about.
     */
    public static function bodyTextOf(string $docx): string
    {
        $xpath = self::xpathOf($docx);

        $parts = [];
        foreach ($xpath->query('//w:p') ?: [] as $paragraph) {
            $parts[] = trim(self::textIn($xpath, $paragraph));
        }

        return trim(implode("\n", $parts));
    }

    private static function write(PhpWord $phpWord, string $name): string
    {
        $path = Scratch::path($name);

        Upstream::quietly(static function () use ($phpWord, $path): void {
            IOFactory::createWriter($phpWord, 'Word2007')->save($path);
        });

        return $path;
    }

    public static function directory(): string
    {
        return Scratch::directory();
    }

    public static function textOf(string $docx): string
    {
        $xpath = self::xpathOf($docx);

        $parts = [];
        foreach ($xpath->query('//w:p') ?: [] as $paragraph) {
            $parts[] = trim(self::textIn($xpath, $paragraph));
        }

        return trim(implode("\n", $parts));
    }

    /**
     * The visible text of a document, with no regard for where it is broken up.
     *
     * What a reader sees is the concatenation of every `w:t` under a node, so a
     * run boundary is a detail of the writer rather than of the text. That makes
     * this the thing to compare when the question is whether the words survived
     * and the wrong thing when it is whether a paragraph is where it should be;
     * {@see self::textOf()} is that.
     */
    public static function textIn(\DOMXPath $xpath, \DOMNode $context): string
    {
        $text = '';
        foreach ($xpath->query('.//w:t', $context) ?: [] as $node) {
            $text .= $node->textContent;
        }

        return $text;
    }

    /**
     * Whether a document's XML parses.
     *
     * A `.docx` whose `word/document.xml` does not parse is one Word refuses to
     * open, and it is the failure the escaping and raw-HTML tests are about. The
     * part comes from {@see self::read()}, so a file that is not an archive, or
     * has no such part, throws rather than reporting itself valid.
     */
    public static function xmlIsValid(string $docx, string $part = 'word/document.xml'): bool
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            return $dom->loadXML(self::read($docx, $part), LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @return list<string>
     */
    public static function targetsOf(string $docx, string $part = 'word/_rels/document.xml.rels'): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML(self::read($docx, $part));
        libxml_clear_errors();

        $targets = [];
        foreach ($dom->documentElement?->childNodes ?? [] as $node) {
            if ($node instanceof \DOMElement && $node->getAttribute('Target') !== '') {
                $targets[] = $node->getAttribute('Target');
            }
        }

        return $targets;
    }

    private static function xpathOf(string $docx): \DOMXPath
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML(self::read($docx, WordPackage::mainPartOf($docx)), LIBXML_NOCDATA);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        return $xpath;
    }

    public static function hyperlinkCount(string $docx): int
    {
        $xpath = self::xpathOf($docx);

        return $xpath->query('//w:hyperlink')?->length ?? 0;
    }

    public static function xmlOf(string $docx, string $part = 'word/document.xml'): string
    {
        return self::read($docx, $part);
    }

    private static function read(string $docx, string $part): string
    {
        $zip = new ZipArchive();

        if ($zip->open($docx) !== true) {
            throw new RuntimeException(sprintf('Unable to open "%s".', $docx));
        }

        $contents = $zip->getFromName($part);
        $zip->close();

        if ($contents === false) {
            throw new RuntimeException(sprintf('"%s" does not contain "%s".', $docx, $part));
        }

        return $contents;
    }
}
