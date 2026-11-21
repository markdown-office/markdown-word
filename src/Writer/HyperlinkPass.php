<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\UnreadableDocument;
use MarkdownWord\WordPackage;
use MarkdownWord\Xml;

use MarkdownWord\Render\LinkPlaceholder;
use ZipArchive;

/**
 * Rewrites the parts of a `.docx` that hold a link, replacing the placeholder runs
 * emitted for links whose label contains emphasis with genuine `w:hyperlink`
 * elements, and adding the matching relationships.
 *
 * The body is not the only part a link can be in. A note, a running head and a
 * running foot are parts of their own, and each of them has relationships of its own:
 * `rId1` in the notes' relationship part is a different relationship from `rId1` in
 * the body's, so a link in a note is resolved against the notes and not against the
 * body.
 *
 * The part is the one {@see WordPackage::bodyPart()} names rather than
 * `word/document.xml`, because that name is conventional and not guaranteed: a
 * package whose body is `word/document22.xml` would otherwise have its links
 * rewritten into a part no reader opens, and the placeholders left where the links
 * were. The other parts are found through the declarations for the same reason, and
 * a package that has none of them is not given any.
 *
 * The rewrite goes through `DOMDocument` rather than string replacement, so
 * document content that happens to look like a placeholder cannot confuse it.
 *
 * The two failures are told apart the way {@see UnreadableDocument} and
 * {@see MalformedDocument} are: a file that is not a zip is not a document at all,
 * and one that opens without the parts a document must have is a broken one.
 * {@see OdfHyperlinkPass} answers the same two questions the same way.
 */
final class HyperlinkPass
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * @param list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}> $payloads
     */
    public function __construct(private readonly array $payloads)
    {
    }

    public function applyTo(string $docxPath): void
    {
        $zip = new ZipArchive();

        if ($zip->open($docxPath) !== true) {
            throw new UnreadableDocument(sprintf('Unable to open "%s" as a zip archive.', $docxPath));
        }

        try {
            $this->rewrite($zip, WordPackage::bodyPart($zip));

            // A link is written into whichever part holds the text it is in, and a
            // relationship belongs to the part it is in — so each part is resolved
            // against its own.
            foreach (WordPackage::linkParts($zip) as $part) {
                $this->rewrite($zip, $part);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Rewrite one part of the package: the placeholder runs in it become genuine
     * `w:hyperlink` elements, and the relationships they need go into that part's own
     * relationship part.
     *
     * A part with no placeholder in it is left alone. It was not this pass's
     * business, and rewriting it would change bytes nobody asked to change.
     */
    private function rewrite(ZipArchive $zip, string $part): void
    {
        $document = $zip->getFromName($part);

        if ($document === false) {
            throw new MalformedDocument(sprintf('The document is missing %s.', $part));
        }

        if (!str_contains($document, LinkPlaceholder::MARKER)) {
            return;
        }

        $relsPath = WordPackage::relationshipsFor($part);
        $existing = $zip->getFromName($relsPath);

        $updated = $this->transform(
            $document,
            $existing === false ? self::emptyRelationships() : $existing,
            $part,
        );

        if ($updated['links'] === 0) {
            return;
        }

        $zip->deleteName($part);
        $zip->addFromString($part, $updated['document']);

        if ($existing !== false) {
            $zip->deleteName($relsPath);
        }

        $zip->addFromString($relsPath, $updated['rels']);
    }

    /**
     * @return array{document: string, rels: string, links: int}
     */
    private function transform(string $documentXml, string $relsXml, string $part): array
    {
        $index = [];
        foreach ($this->payloads as $payload) {
            $index[$payload['placeholder']] = $payload;
        }

        $dom = $this->loadDom($documentXml, $part);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);

        $counter = $this->nextRelationshipId($relsXml);
        $rels = $this->loadRels($relsXml, WordPackage::relationshipsFor($part));
        $links = 0;

        foreach ($xpath->query('//w:t') ?: [] as $textNode) {
            /** @var \DOMElement $textNode */
            $value = $textNode->textContent;

            if (!str_contains($value, LinkPlaceholder::MARKER)) {
                continue;
            }

            $run = $textNode->parentNode;
            $paragraph = $run?->parentNode;
            if (!$run instanceof \DOMElement || !$paragraph instanceof \DOMElement) {
                continue;
            }

            $payload = $this->match($value, $index);
            if ($payload === null) {
                continue;
            }

            $id = 'rId' . $counter++;
            $this->addRelationship($rels, $id, $payload['url']);

            $hyperlink = $this->buildHyperlink($dom, $id, $payload);
            $paragraph->replaceChild($hyperlink, $run);
            $links++;
        }

        return [
            'document' => $this->saveXml($dom),
            'rels' => $this->saveRels($rels),
            'links' => $links,
        ];
    }

    /**
     * A relationship part with nothing in it, in the shape the writer writes one.
     *
     * A part with no relationships of its own has no relationship part at all:
     * PHPWord writes `word/_rels/footnotes.xml.rels` only when a link or a picture
     * in the notes needs one. The part is written when the first relationship for it
     * is added.
     */
    private static function emptyRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="' . self::REL_NS . '"/>';
    }

    /**
     * @param  array<string, array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}>  $index
     * @return array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}|null
     */
    private function match(string $value, array $index): ?array
    {
        foreach ($index as $placeholder => $payload) {
            if (str_contains($value, $placeholder)) {
                return $payload;
            }
        }

        return null;
    }

    /**
     * @param  array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}  $payload
     */
    private function buildHyperlink(\DOMDocument $dom, string $relId, array $payload): \DOMElement
    {
        $hyperlink = $dom->createElementNS(self::W_NS, 'w:hyperlink');
        $hyperlink->setAttributeNS(self::R_NS, 'r:id', $relId);

        if ($payload['title'] !== null && $payload['title'] !== '') {
            $hyperlink->setAttributeNS(self::W_NS, 'w:tooltip', $payload['title']);
        }

        foreach ($payload['runs'] as $run) {
            $hyperlink->appendChild($this->buildRun($dom, $run['text'], $run['style']));
        }

        return $hyperlink;
    }

    /**
     * @param array<string, mixed> $style
     */
    private function buildRun(\DOMDocument $dom, string $text, array $style): \DOMElement
    {
        $run = $dom->createElementNS(self::W_NS, 'w:r');

        $properties = $this->buildRunProperties($dom, $style);
        if ($properties->hasChildNodes()) {
            $run->appendChild($properties);
        }

        $textNode = $dom->createElementNS(self::W_NS, 'w:t');
        $textNode->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $textNode->appendChild($dom->createTextNode($text));
        $run->appendChild($textNode);

        return $run;
    }

    /**
     * The properties of a run, in the order the schema names them.
     *
     * `w:rPr` is the sequence `EG_RPrBase` in ECMA-376's `wml.xsd` and not a set,
     * so a reader walks the children in the order they are written and stops at
     * the first one it did not expect: the typeface comes before the weight, and
     * the underline comes after the size.
     *
     * Word opens a document that gets this wrong, because Word is lenient where
     * the schema is not. A reader that walks the sequence — a validator, a
     * converter — does not.
     *
     * @param array<string, mixed> $style
     */
    private function buildRunProperties(\DOMDocument $dom, array $style): \DOMElement
    {
        $properties = $dom->createElementNS(self::W_NS, 'w:rPr');

        if (!empty($style['name'])) {
            $fonts = $dom->createElementNS(self::W_NS, 'w:rFonts');
            $fonts->setAttributeNS(self::W_NS, 'w:ascii', (string) $style['name']);
            $fonts->setAttributeNS(self::W_NS, 'w:hAnsi', (string) $style['name']);
            $properties->appendChild($fonts);
        }

        foreach (['bold' => 'b', 'italic' => 'i', 'strikethrough' => 'strike'] as $key => $element) {
            if (!empty($style[$key])) {
                $properties->appendChild($dom->createElementNS(self::W_NS, 'w:' . $element));
            }
        }

        if (!empty($style['color'])) {
            $color = $dom->createElementNS(self::W_NS, 'w:color');
            $color->setAttributeNS(self::W_NS, 'w:val', ltrim((string) $style['color'], '#'));
            $properties->appendChild($color);
        }

        if (!empty($style['size'])) {
            $size = $dom->createElementNS(self::W_NS, 'w:sz');
            $size->setAttributeNS(self::W_NS, 'w:val', (string) ((float) $style['size'] * 2));
            $properties->appendChild($size);
        }

        if (isset($style['underline']) && $style['underline'] !== 'none') {
            $underline = $dom->createElementNS(self::W_NS, 'w:u');
            $underline->setAttributeNS(self::W_NS, 'w:val', (string) $style['underline']);
            $properties->appendChild($underline);
        }

        return $properties;
    }

    private function loadDom(string $xml, string $part): \DOMDocument
    {
        return Xml::parseOrFail($xml, sprintf('%s is not valid XML.', $part));
    }

    private function loadRels(string $xml, string $part): \DOMDocument
    {
        return Xml::parseOrFail($xml, sprintf('%s is not valid XML.', $part));
    }

    private function saveXml(\DOMDocument $dom): string
    {
        return (string) $dom->saveXML();
    }

    private function saveRels(\DOMDocument $dom): string
    {
        return (string) $dom->saveXML();
    }

    private function addRelationship(\DOMDocument $rels, string $id, string $url): void
    {
        $relationship = $rels->createElementNS(self::REL_NS, 'Relationship');
        $relationship->setAttribute('Id', $id);
        $relationship->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink');
        $relationship->setAttribute('Target', $url);
        $relationship->setAttribute('TargetMode', 'External');

        $rels->documentElement?->appendChild($relationship);
    }

    private function nextRelationshipId(string $relsXml): int
    {
        preg_match_all('/Id="rId(\d+)"/', $relsXml, $matches);

        if ($matches[1] === []) {
            return 1;
        }

        return max(array_map('intval', $matches[1])) + 1;
    }
}
