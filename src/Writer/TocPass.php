<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\UnreadableDocument;
use MarkdownWord\Render\Directive\Toc;
use MarkdownWord\WordPackage;
use MarkdownWord\Xml;
use ZipArchive;

/**
 * Replaces the placeholder runs a `::toc` left in the element tree with the five
 * runs of a real TOC field.
 *
 * The part is the one {@see WordPackage::bodyPart()} names rather than
 * `word/document.xml`, for the reason {@see HyperlinkPass} gives: a package whose
 * body is `word/document22.xml` would otherwise keep a token where its table of
 * contents should be.
 *
 * The rewrite goes through `DOMDocument` rather than string replacement, so
 * document content that happens to look like a placeholder cannot confuse it, and
 * the two failures are told apart the way {@see HyperlinkPass} tells them apart:
 * a file that is not a zip is not a document at all, and one that opens without
 * the part a document must have is a broken one.
 */
final class TocPass
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const XML_NS = 'http://www.w3.org/XML/1998/namespace';

    /**
     * @param list<array{placeholder: string, instruction: string}> $payloads
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
            $body = WordPackage::bodyPart($zip);

            $document = $zip->getFromName($body);

            if ($document === false) {
                throw new MalformedDocument(sprintf('The document is missing %s.', $body));
            }

            $zip->deleteName($body);
            $zip->addFromString($body, $this->transform($document, $body));
        } finally {
            $zip->close();
        }
    }

    private function transform(string $documentXml, string $part): string
    {
        $dom = Xml::parseOrFail($documentXml, sprintf('%s is not valid XML.', $part));
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);

        foreach ($xpath->query('//w:t') ?: [] as $textNode) {
            /** @var DOMElement $textNode */
            $instruction = $this->instructionFor($textNode->textContent);

            if ($instruction === null) {
                continue;
            }

            $run = $textNode->parentNode;
            $paragraph = $run?->parentNode;

            // A paragraph, and only a paragraph: a token wrapped in anything else is
            // not one this renderer wrote, and a field built in its place would land
            // inside whatever it was wrapped in.
            if (!$run instanceof DOMElement || $paragraph?->nodeName !== 'w:p') {
                continue;
            }

            foreach ($this->field($dom, $instruction) as $replacement) {
                $paragraph->insertBefore($replacement, $run);
            }

            $paragraph->removeChild($run);
        }

        return (string) $dom->saveXML();
    }

    /**
     * The instruction a token stands for, or null when the text is not one.
     *
     * Matched whole rather than by substring: the token is a run of its own, and a
     * document is free to contain the same characters inside a sentence.
     */
    private function instructionFor(string $value): ?string
    {
        foreach ($this->payloads as $payload) {
            if ($value === $payload['placeholder']) {
                return $payload['instruction'];
            }
        }

        return null;
    }

    /**
     * The field as Word writes one, as a list of runs in the order they go in.
     *
     * `w:dirty` on the `begin` is what has Word evaluate the field when the
     * document is opened, which is the whole of what the field being empty needs;
     * a reader that ignores it shows {@see Toc::UNBUILT} instead, which is why
     * that text is there.
     *
     * @return list<DOMElement>
     */
    private function field(DOMDocument $dom, string $instruction): array
    {
        $begin = $this->run($dom);
        $marker = $dom->createElementNS(self::W_NS, 'w:fldChar');
        $marker->setAttributeNS(self::W_NS, 'w:fldCharType', 'begin');
        $marker->setAttributeNS(self::W_NS, 'w:dirty', 'true');
        $begin->appendChild($marker);

        $code = $this->run($dom);
        $text = $dom->createElementNS(self::W_NS, 'w:instrText');
        $text->setAttributeNS(self::XML_NS, 'xml:space', 'preserve');
        $text->appendChild($dom->createTextNode($instruction));
        $code->appendChild($text);

        $separate = $this->run($dom);
        $divider = $dom->createElementNS(self::W_NS, 'w:fldChar');
        $divider->setAttributeNS(self::W_NS, 'w:fldCharType', 'separate');
        $separate->appendChild($divider);

        $unbuilt = $this->run($dom);
        $label = $dom->createElementNS(self::W_NS, 'w:t');
        $label->setAttributeNS(self::XML_NS, 'xml:space', 'preserve');
        $label->appendChild($dom->createTextNode(Toc::UNBUILT));
        $unbuilt->appendChild($label);

        $end = $this->run($dom);
        $close = $dom->createElementNS(self::W_NS, 'w:fldChar');
        $close->setAttributeNS(self::W_NS, 'w:fldCharType', 'end');
        $end->appendChild($close);

        return [$begin, $code, $separate, $unbuilt, $end];
    }

    private function run(DOMDocument $dom): DOMElement
    {
        return $dom->createElementNS(self::W_NS, 'w:r');
    }
}
