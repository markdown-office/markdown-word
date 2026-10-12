<?php

declare(strict_types=1);

namespace MarkdownWord\Tests\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use MarkdownWord\Template\ContentControl;
use MarkdownWord\WordPackage;
use MarkdownWord\Xml;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;
use ZipArchive;

/**
 * Builds the Word documents the tests need as input, rather than checking binary
 * fixtures into the repository.
 *
 * Input, which is the whole of the distinction: a fixture exists so a test has
 * something to open, and nobody reads one. `examples/templates/meridian-qbr.dotx` is
 * committed for the opposite reason — it is there to be opened in Word, and a clone
 * that could only get it by running Composer and PHP had no reason to carry it.
 */
final class TemplateFactory
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * A template with a field, a title and a place for the content — what a report
     * letterhead actually needs.
     */
    public static function report(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        $section->addText('Quarterly Report');
        // The marker is the whole of the value, so the field's name is whatever is
        // inside it. A marker in the middle of a sentence would need the text either
        // side kept, which is what {@see self::withControl()} does when it splits.
        $section->addText('@@reference@@');

        // `body`, the name {@see ContentControl::BODY} gives and the renderer looks for by
        // default. A template may name it anything — `insert()` takes the name — and
        // a letterhead whose control is called `terms` is as ordinary as one called
        // `body`; this fixture uses the default so the CLI tests exercise it.
        return self::withControl(self::write($phpWord, 'report'), ContentControl::BODY);
    }

    /**
     * A template with nothing but a place for the content, for tests that only care
     * about what is inserted.
     */
    public static function placeholder(string $name = 'body'): string
    {
        $phpWord = new PhpWord();
        $phpWord->addSection();

        return self::withControl(self::write($phpWord, 'placeholder-' . $name), $name);
    }

    /**
     * A template shaped the way a real one is: a masthead, a salutation, fields for
     * the values, and a closing block a person wrote by hand. **No content controls at
     * all**, because nobody making a company letterhead inserts one.
     *
     * This is the case the renderer has to survive. A template authored for a
     * particular tool carries that tool's placeholder; one authored in Word to be
     * filled in by a person carries none, and refusing those would leave the library
     * able to use only the templates it wrote itself.
     */
    public static function letterhead(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        $section->addText('Northwind Ltd');
        $section->addText('@@client@@');
        $section->addText('Dear colleague,');
        $section->addText('@@reference@@');
        // The closing matter a letterhead ends with, which is the whole reason an
        // append-at-the-end fallback has to be a decision rather than an accident.
        $section->addText('Yours sincerely,');
        $section->addText('The Management');

        return self::withFields(self::write($phpWord, 'letterhead'));
    }

    /**
     * A template that names no place for the content under the name the renderer uses.
     *
     * It does carry a control, called something else, so the failure names what is
     * there — which is the whole of the fix for whoever has to write the template.
     */
    public static function noControl(): string
    {
        $phpWord = new PhpWord();
        $phpWord->addSection();

        return self::withControl(self::write($phpWord, 'no-control'), 'terms');
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

        $phpWord->addSection();

        return self::withControl(self::write($phpWord, 'styled'), 'body');
    }

    /**
     * A template that brings a list of its own, so the numbering part it carries
     * has to be continued rather than filled in from scratch.
     */
    public static function withList(string $name = 'body'): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        // An ordered list, so the Markdown inserted into the same document is
        // the thing that changes format: numbered where the Markdown said
        // bullets is visible in a way two bullet lists are not.
        $phpWord->addNumberingStyle('CorpOrdered', [
            'type' => 'multilevel',
            'levels' => self::levels('decimal', '%1.'),
        ]);

        $section->addListItemRun(0, 'CorpOrdered')->addText('template one');
        $section->addListItemRun(0, 'CorpOrdered')->addText('template two');

        $path = self::write($phpWord, 'with-list');

        return self::withControl($path, $name);
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

    /**
 * Put a content control into a written template, and turn `@@name@@` into a field.
 *
 * Both are things PHPWord has no API for, which is the point: a template comes from
 * Word, where a person inserts a content control from the Developer tab and a field
 * with Ctrl+F9. Writing them here rather than with `${…}` markers is what keeps the
 * tests honest about what the library actually reads.
 *
 * @param string $body Name of the content control the Markdown goes into.
 */
private static function withControl(string $path, string $body): string
{
    return self::withFields($path, $body);
}

/**
 * Turn every `@@name@@` marker into a real `MERGEFIELD`, and put a content control of
 * that name into the body.
 *
 * Two jobs rather than one because a real template can have either: {@see self::letterhead()}
 * has fields and no control, which is what a letterhead a person made in Word looks
 * like.
 *
 * @param string|null $body The control to add, or null for fields alone.
 */
private static function withFields(string $path, ?string $body = null): string
{
    $zip = new ZipArchive();
    $zip->open($path);
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();

    $dom = Xml::parse($xml) ?? throw new RuntimeException(
        sprintf('"%s" has a document part that is not XML.', $path),
    );

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    foreach ($xpath->query('//w:r[w:t]') ?: [] as $run) {
        $text = $run->getElementsByTagNameNS(self::W_NS, 't')->item(0);

        if (!$text instanceof DOMElement || !str_contains((string) $text->textContent, '@@')) {
            continue;
        }

        // A marker is the whole of the value here, so the field takes the name
        // inside it. Word does the same thing when a field is typed into a sentence:
        // the run is split and the text either side of the field stays.
        //
        // `@@reference@@` splits into three parts — empty, the name, empty — so the
        // name is the middle one and what is either side is what a sentence carries.
        [, $name, $after] = explode('@@', (string) $text->textContent, 3);

        $parent = $run->parentNode;
        $field = self::mergeField($dom, $name);

        $parent?->insertBefore($field, $run);
        $parent?->removeChild($run);

        if ($after !== '') {
            $tail = $dom->createElementNS(self::W_NS, 'w:r');
            $tailText = $dom->createElementNS(self::W_NS, 'w:t');
            $tailText->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            $tailText->appendChild($dom->createTextNode($after));
            $tail->appendChild($tailText);
            $parent?->insertBefore($tail, $field->nextSibling);
        }
    }

    if ($body !== null) {
        $document = $dom->documentElement;
        $control = Xml::parse(
            sprintf(
                '<root xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
                . 'xmlns:xml="http://www.w3.org/XML/1998/namespace">%s</root>',
                ContentControl::markup($body),
            ),
        )?->documentElement?->firstChild;

        if (!$document instanceof DOMElement || !$control instanceof DOMNode) {
            throw new RuntimeException('The content control could not be built.');
        }

        // Before the `w:sectPr`, which has to stay last in a body or Word rejects it.
        $section = $xpath->query('//w:body/w:sectPr')?->item(0);
        $bodyElement = $xpath->query('//w:body')?->item(0);

        if ($section instanceof DOMNode) {
            $bodyElement?->insertBefore($dom->importNode($control, true), $section);
        } else {
            $bodyElement?->appendChild($dom->importNode($control, true));
        }
    }

    $zip = new ZipArchive();
    $zip->open($path);
    $zip->deleteName('word/document.xml');
    $zip->addFromString('word/document.xml', (string) $dom->saveXML());
    $zip->close();

    return $path;
}

/** A `w:fldSimple` naming a `MERGEFIELD`, in the shape Word writes. */
private static function mergeField(DOMDocument $dom, string $name): DOMElement
{
    $field = $dom->createElementNS(self::W_NS, 'w:fldSimple');
    $field->setAttributeNS(self::W_NS, 'w:instr', ' MERGEFIELD ' . trim($name, ' ') . ' \\* MERGEFORMAT ');

    $run = $dom->createElementNS(self::W_NS, 'w:r');
    $text = $dom->createElementNS(self::W_NS, 'w:t');
    $text->appendChild($dom->createTextNode('«' . trim($name, ' ') . '»'));
    $run->appendChild($text);
    $field->appendChild($run);

    return $field;
}

/**
 * A package holding exactly the parts given, and nothing else.
 *
 * For a test about one part's contents rather than about a document PHPWord wrote:
 * a `.docx` from {@see self::write()} has relationships, numbering and a stylesheet
 * in it, and a test that only cares whether a field was filled should not have to
 * reason about the rest.
 *
 * @param array<string, string> $parts Part name to its XML.
 */
public static function rawPackage(string $name, array $parts): string
{
    $path = Scratch::path($name . '-' . bin2hex(random_bytes(4)), '.docx');

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);

    $zip->addFromString(
        '[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'
        . '</Types>',
    );

    foreach ($parts as $part => $xml) {
        $zip->addFromString($part, $xml);
    }

    $zip->close();

    return $path;
}

/** Put a part into an existing package, replacing what is there. */
public static function addRawPart(string $path, string $part, string $xml): void
{
    $zip = new ZipArchive();
    $zip->open($path);
    $zip->deleteName($part);
    $zip->addFromString($part, $xml);
    $zip->close();
}

/** A Word part wrapped in its element, with the namespaces a `w:` fragment needs. */
public static function part(string $root, string $inner): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:' . $root . ' xmlns:w="' . self::W_NS . '">' . $inner . '</w:' . $root . '>';
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
