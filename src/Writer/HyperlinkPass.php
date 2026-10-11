<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\UnreadableDocument;
use MarkdownWord\Xml;

use MarkdownWord\Render\LinkPlaceholder;
use ZipArchive;

/**
 * Rewrites `word/document.xml` inside a `.docx`, replacing the placeholder runs
 * emitted for links whose label contains emphasis with genuine `w:hyperlink`
 * elements, and adding the matching relationships.
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
    private const RELS_PATH = 'word/_rels/document.xml.rels';
    private const REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const DOCUMENT_PATH = 'word/document.xml';

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
            $document = $zip->getFromName(self::DOCUMENT_PATH);
            $rels = $zip->getFromName(self::RELS_PATH);

            if ($document === false || $rels === false) {
                throw new MalformedDocument('The document is missing word/document.xml or its relationship part.');
            }

            $nextId = $this->nextRelationshipId($rels);
            $updated = $this->transform($document, $rels, $nextId);

            $zip->deleteName(self::DOCUMENT_PATH);
            $zip->addFromString(self::DOCUMENT_PATH, $updated['document']);
            $zip->deleteName(self::RELS_PATH);
            $zip->addFromString(self::RELS_PATH, $updated['rels']);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{document: string, rels: string}
     */
    private function transform(string $documentXml, string $relsXml, int $nextId): array
    {
        $index = [];
        foreach ($this->payloads as $payload) {
            $index[$payload['placeholder']] = $payload;
        }

        $dom = $this->loadDom($documentXml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);

        $counter = $nextId;
        $rels = $this->loadRels($relsXml);

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
        }

        return [
            'document' => $this->saveXml($dom),
            'rels' => $this->saveRels($rels),
        ];
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
     * @param array<string, mixed> $style
     */
    private function buildRunProperties(\DOMDocument $dom, array $style): \DOMElement
    {
        $properties = $dom->createElementNS(self::W_NS, 'w:rPr');

        foreach (['bold' => 'b', 'italic' => 'i', 'strikethrough' => 'strike'] as $key => $element) {
            if (!empty($style[$key])) {
                $properties->appendChild($dom->createElementNS(self::W_NS, 'w:' . $element));
            }
        }

        if (isset($style['underline']) && $style['underline'] !== 'none') {
            $underline = $dom->createElementNS(self::W_NS, 'w:u');
            $underline->setAttributeNS(self::W_NS, 'w:val', (string) $style['underline']);
            $properties->appendChild($underline);
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

        if (!empty($style['name'])) {
            $fonts = $dom->createElementNS(self::W_NS, 'w:rFonts');
            $fonts->setAttributeNS(self::W_NS, 'w:ascii', (string) $style['name']);
            $fonts->setAttributeNS(self::W_NS, 'w:hAnsi', (string) $style['name']);
            $properties->appendChild($fonts);
        }

        return $properties;
    }

    private function loadDom(string $xml): \DOMDocument
    {
        return Xml::parseOrFail($xml, 'word/document.xml is not valid XML.');
    }

    private function loadRels(string $xml): \DOMDocument
    {
        return Xml::parseOrFail($xml, 'word/_rels/document.xml.rels is not valid XML.');
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
