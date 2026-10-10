<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\UnreadableDocument;
use MarkdownWord\Xml;
use ZipArchive;

/**
 * Fills in the alternative text of the images in an already written `.odt`.
 *
 * The ODF counterpart of {@see ImageDescriptionPass}: `Writer\ODText\Element\Image`
 * writes a `draw:frame` with no `svg:title` and no `svg:desc`, and ODF keeps a
 * frame's title and description there.
 *
 * Pictures are matched in document order against the order the renderer added
 * them, and rewritten through the DOM so content that happens to look like a frame
 * cannot confuse it.
 */
final class OdfImageDescriptionPass
{
    private const CONTENT_PATH = 'content.xml';

    private const SVG_NS = 'urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0';

    /**
     * @param list<string> $descriptions The alt text of each image, in the order they
     *        were added.
     */
    public function __construct(private readonly array $descriptions)
    {
    }

    /**
     * @throws UnreadableDocument When the archive cannot be opened.
     * @throws MalformedDocument When the content part is not XML.
     */
    public function applyTo(string $odtPath): void
    {
        $zip = new ZipArchive();

        if ($zip->open($odtPath) !== true) {
            throw new UnreadableDocument(sprintf('Unable to open "%s" as a zip archive.', $odtPath));
        }

        try {
            $content = $zip->getFromName(self::CONTENT_PATH);

            if ($content === false) {
                return;
            }

            $zip->deleteName(self::CONTENT_PATH);
            $zip->addFromString(self::CONTENT_PATH, $this->transform($content));
        } finally {
            $zip->close();
        }
    }

    /**
     * @throws MalformedDocument When the content part is not XML.
     */
    private function transform(string $contentXml): string
    {
        $dom = Xml::parseOrFail($contentXml, 'content.xml is not valid XML.');

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('draw', 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0');

        $frames = $xpath->query('//draw:frame');
        $index = 0;

        foreach ($frames === false ? [] : $frames as $frame) {
            $description = $this->descriptions[$index] ?? null;

            if ($description !== null && $description !== '') {
                $frame->setAttributeNS(self::SVG_NS, 'svg:desc', $description);
            }

            $index++;
        }

        return (string) $dom->saveXML();
    }
}