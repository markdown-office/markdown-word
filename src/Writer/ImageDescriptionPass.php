<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\UnreadableDocument;
use MarkdownWord\WordPackage;
use MarkdownWord\Xml;
use ZipArchive;

/**
 * Fills in the alternative text of the images in an already written `.docx`.
 *
 * PHPWord has no API for it: a document this library writes carries its pictures
 * as VML — `w:pict/v:shape/v:imagedata` — and the writer emits the description
 * of each one as a literal `o:title=""`. A DrawingML picture, which is what a
 * template authored in Word already contains, keeps its description on
 * `wp:docPr`.
 *
 * Images are matched in document order against the order they were added in, and
 * rewritten through the DOM so content that looks like an image element cannot
 * confuse it.
 */
final class ImageDescriptionPass
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const V_NS = 'urn:schemas-microsoft-com:vml';
    private const O_NS = 'urn:schemas-microsoft-com:office:office';
    private const WP_NS = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';

    /**
     * @param list<string> $descriptions The alt text of each image, in the order
     *        they were added.
     */
    public function __construct(private readonly array $descriptions)
    {
    }

    /**
     * An archive with no body part is left as it is rather than reported, where
     * {@see HyperlinkPass} treats the same absence as a failure.
     *
     * @throws UnreadableDocument When the archive cannot be opened.
     * @throws MalformedDocument When the body part is not XML.
     */
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
                return;
            }

            $updated = $this->transform($document, $body);

            $zip->deleteName($body);
            $zip->addFromString($body, $updated);
        } finally {
            $zip->close();
        }
    }

    /**
     * @throws MalformedDocument When the document part is not XML.
     */
    private function transform(string $documentXml, string $body): string
    {
        if ($this->descriptions === []) {
            return $documentXml;
        }

        $dom = Xml::parseOrFail($documentXml, sprintf('%s is not valid XML.', $body));

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('v', self::V_NS);
        $xpath->registerNamespace('o', self::O_NS);
        $xpath->registerNamespace('wp', self::WP_NS);

        $index = 0;

        foreach ($this->imageNodes($xpath) as [$node, $attribute, $namespace]) {
            $description = $this->descriptions[$index] ?? null;

            if ($description !== null && $description !== '') {
                $namespace === ''
                    ? $node->setAttribute($attribute, $description)
                    : $node->setAttributeNS($namespace, $attribute, $description);
            }

            $index++;
        }

        return (string) $dom->saveXML();
    }

    /**
     * The elements carrying a description, in document order.
     *
     * VML wins when a document has both kinds, because that is what this library
     * writes for an inline picture.
     *
     * @return list<array{0: DOMElement, 1: string, 2: string}>
     */
    private function imageNodes(DOMXPath $xpath): array
    {
        $vml = $xpath->query('//v:imagedata');
        $nodes = [];

        if ($vml !== false && $vml->length > 0) {
            foreach ($vml as $node) {
                $nodes[] = [$node, 'o:title', self::O_NS];
            }

            return $nodes;
        }

        foreach ($xpath->query('//wp:docPr') ?: [] as $node) {
            $nodes[] = [$node, 'descr', ''];
        }

        return $nodes;
    }
}
