<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Render\SvgAttachmentCollector;
use ZipArchive;

/**
 * Puts the vector back behind the raster Word is able to draw.
 *
 * An SVG reaches a document as a PNG of itself, because {@see SvgRasteriser} has to
 * produce one and PHPWord has no way to say otherwise. Word is not fooled by that: a
 * picture that carries only a raster *is* a raster, and it will keep being one at every
 * zoom level. So the original SVG is added as a part of its own, given a
 * relationship, and referenced from an extension on the picture element.
 *
 * That extension only exists in one place, which is why this pass is larger than it
 * looks. PHPWord writes images as VML — `w:pict`, `v:shape`, `v:imagedata` — and VML
 * has no list of extensions to hang a vector on. The picture is therefore rewritten
 * into DrawingML, which is the form Word itself writes today and the only one that can
 * carry an `asvg:svgBlip`. It happens only for pictures that have a vector: everything
 * else keeps the markup PHPWord wrote, byte for byte.
 *
 * A 2016-or-later reader picks up the vector and scales it like a vector. An older one
 * ignores the extension and draws the PNG, which is the whole reason the PNG is there —
 * a picture carrying an `svgBlip` with no raster beside it renders nothing at all.
 *
 * The element is matched by the content of the raster rather than by the order the
 * pictures were added, because PHPWord names its media parts as it writes them and two
 * pictures of the same bytes are one entry in the archive and two in the document.
 */
final class SvgPass
{
    /**
     * The URI Word identifies an SVG extension by. It is a fixed GUID and not a
     * namespace of its own: a reader matches on this string and nothing else.
     */
    private const SVG_EXTENSION_URI = '{96DAC541-7B7A-43D3-8B79-37D633B846F1}';

    private const EMU_PER_POINT = 12700;

    private const WP_NS = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
    private const A_NS = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const PIC_NS = 'http://schemas.openxmlformats.org/drawingml/2006/picture';
    private const R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const O_NS = 'urn:schemas-microsoft-com:office:office';
    private const ASVG_NS = 'http://schemas.microsoft.com/office/drawing/2016/SVG/main';
    private const REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const CT_NS = 'http://schemas.openxmlformats.org/package/2006/content-types';
    private const IMAGE_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';

    private const RELS_PATH = 'word/_rels/document.xml.rels';
    private const DOCUMENT_PATH = 'word/document.xml';
    private const CONTENT_TYPES_PATH = '[Content_Types].xml';
    private const MEDIA_PREFIX = 'word/media/';

    /** Used when neither the markup nor the raster says how big the picture is. */
    private const FALLBACK_POINTS = 288.0;

    private int $nextDrawingId = 1;

    /**
     * @param list<array{fallback: string, vector: string}> $attachments the hash of each
     *        PNG PHPWord was given, and the SVG to attach to it
     *
     * @return int how many pictures carry a vector afterwards
     *
     * @throws MalformedDocument When a part that has to be rewritten is not XML.
     */
    public function applyTo(string $docxPath, array $attachments): int
    {
        if ($attachments === []) {
            return 0;
        }

        $zip = new ZipArchive();

        if ($zip->open($docxPath) !== true) {
            throw new MalformedDocument(sprintf('Unable to open "%s" as a zip archive.', $docxPath));
        }

        try {
            $rels = $this->load((string) $zip->getFromName(self::RELS_PATH), self::RELS_PATH);
            $document = $this->load((string) $zip->getFromName(self::DOCUMENT_PATH), self::DOCUMENT_PATH);

            $byHash = $this->rastersByHash($zip);
            $this->nextDrawingId = $this->highestDrawingId($document) + 1;
            $this->declareDrawingNamespaces($document);

            $attached = 0;
            $next = $this->highestRelationshipId($rels);
            $rewritten = false;

            foreach ($attachments as $attachment) {
                $target = $byHash[$attachment['fallback']] ?? null;

                if ($target === null) {
                    continue;
                }

                $rasterId = $this->relationshipId($rels, $target);
                $picture = $rasterId === null ? null : $this->pictureUsing($document, $rasterId);

                if ($picture === null) {
                    continue;
                }

                $vectorId = 'rId' . $next++;
                $drawing = $this->buildDrawing($document, $rasterId, $vectorId, $picture);

                $vml = $picture['picture'];
                $vml->parentNode?->replaceChild($drawing, $vml);

                $this->addRelationship(
                    $rels,
                    $vectorId,
                    'media/' . \basename($target, '.png') . '.svg',
                );

                $zip->addFromString(
                    self::MEDIA_PREFIX . \basename($target, '.png') . '.svg',
                    $attachment['vector'],
                );

                $attached++;
                $rewritten = true;
            }

            if ($rewritten === false) {
                return 0;
            }

            // Read before deleting: `ZipArchive::deleteName()` is what it says, and an
            // argument read afterwards is an empty string rather than the part.
            $contentTypes = $this->declareSvgContentType((string) $zip->getFromName(self::CONTENT_TYPES_PATH));

            $zip->deleteName(self::DOCUMENT_PATH);
            $zip->addFromString(self::DOCUMENT_PATH, (string) $document->saveXML());
            $zip->deleteName(self::RELS_PATH);
            $zip->addFromString(self::RELS_PATH, (string) $rels->saveXML());
            $zip->deleteName(self::CONTENT_TYPES_PATH);
            $zip->addFromString(self::CONTENT_TYPES_PATH, $contentTypes);

            return $attached;
        } finally {
            $zip->close();
        }
    }

    /**
     * Media part path to relationship target, for every part in the archive.
     *
     * @return array<string, string> hash to a target such as `media/image3.png`
     */
    private function rastersByHash(ZipArchive $zip): array
    {
        $found = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (!\str_starts_with($name, self::MEDIA_PREFIX)) {
                continue;
            }

            $bytes = $zip->getFromName($name);

            if ($bytes === false) {
                continue;
            }

            $found[SvgAttachmentCollector::fingerprint($bytes)] = \substr($name, \strlen('word/'));
        }

        return $found;
    }

    /**
     * The VML picture a raster relationship points at, and how big it is drawn.
     *
     * @return array{picture: \DOMElement, width: float, height: float, description: string}|null
     */
    private function pictureUsing(DOMDocument $document, string $rasterId): ?array
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('v', 'urn:schemas-microsoft-com:vml');
        $xpath->registerNamespace('r', self::R_NS);

        $data = $xpath->query(sprintf('//v:imagedata[@r:id="%s"]', $rasterId))?->item(0);

        if (!$data instanceof DOMElement) {
            return null;
        }

        $shape = $data->parentNode;
        $picture = $shape?->parentNode;

        if (!$shape instanceof DOMElement || !$picture instanceof DOMElement) {
            return null;
        }

        [$width, $height] = $this->sizeOf($shape->getAttribute('style'));

        return [
            'picture' => $picture,
            'width' => $width,
            'height' => $height,
            // PHPWord keeps a description on the VML as `o:title`, and DrawingML keeps
            // it on `wp:docPr/@descr`. Moving between the two without carrying it
            // across silently deletes the alt text, which is the one part of a picture
            // a screen reader has.
            'description' => $data->getAttributeNS(self::O_NS, 'title'),
        ];
    }

    /**
     * Declare the DrawingML namespaces once, on the document root.
     *
     * PHPWord declares `wp` and `r` there and not the other three. Left to itself the
     * serialiser repeats a declaration on every element that needs one, which is valid
     * and unreadable — a document with ten vector pictures would carry the same four
     * declarations several hundred times. Word declares them at the root, and so does
     * this.
     */
    private function declareDrawingNamespaces(DOMDocument $document): void
    {
        $root = $document->documentElement;

        if (!$root instanceof DOMElement) {
            return;
        }

        foreach (['a' => self::A_NS, 'pic' => self::PIC_NS, 'asvg' => self::ASVG_NS] as $prefix => $uri) {
            if ($root->lookupNamespaceUri($prefix) === null) {
                $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $prefix, $uri);
            }
        }
    }

    /**
     * The DrawingML picture that replaces it.
     */
    private function buildDrawing(
        DOMDocument $document,
        string $rasterId,
        string $vectorId,
        array $picture,
    ): DOMElement {
        $id = $this->nextDrawingId++;

        $width = (int) \round($picture['width'] * self::EMU_PER_POINT);
        $height = (int) \round($picture['height'] * self::EMU_PER_POINT);

        $description = \htmlspecialchars($picture['description'], \ENT_XML1 | \ENT_QUOTES);
        $attribute = $description === '' ? '' : \sprintf(' descr="%s"', $description);
        $name = \htmlspecialchars('Picture ' . $id, \ENT_XML1 | \ENT_QUOTES);

        // Written as markup rather than assembled node by node: the serialiser repeats
        // a namespace declaration on every element built with `createElementNS()`, so a
        // picture assembled that way carries the same six declarations a dozen times.
        // Parsed once, they are written once, on the root of the fragment.
        $xml = \sprintf(
            '<w:drawing xmlns:w="%1$s" xmlns:wp="%2$s" xmlns:a="%3$s" xmlns:pic="%4$s" xmlns:r="%5$s" xmlns:asvg="%6$s">'
            . '<wp:inline distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="%7$d" cy="%8$d"/>'
            . '<wp:docPr id="%9$d" name="%10$s"%11$s/>'
            . '<a:graphic><a:graphicData uri="%4$s"><pic:pic>'
            . '<pic:nvPicPr><pic:cNvPr id="%9$d" name="%10$s"%11$s/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="%12$s"><a:extLst>'
            . '<a:ext uri="%13$s"><asvg:svgBlip r:embed="%14$s"/></a:ext>'
            . '</a:extLst></a:blip><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="%7$d" cy="%8$d"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing>',
            'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
            self::WP_NS,
            self::A_NS,
            self::PIC_NS,
            self::R_NS,
            self::ASVG_NS,
            $width,
            $height,
            $id,
            $name,
            $attribute,
            $this->escape($rasterId),
            self::SVG_EXTENSION_URI,
            $this->escape($vectorId),
        );

        $fragment = $document->createDocumentFragment();

        try {
            $fragment->appendXML($xml);
        } catch (\DOMException) {
            throw new MalformedDocument('The picture markup for a vector image could not be built.');
        }

        $drawing = $fragment->firstChild;

        if (!$drawing instanceof DOMElement) {
            throw new MalformedDocument('The picture markup for a vector image produced no element.');
        }

        return $drawing;
    }

    private function escape(string $value): string
    {
        return \htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES);
    }

    /**
     * The drawn size out of a VML style attribute, in points.
     *
     * The raster is the fallback, so PHPWord wrote a size for it already and this only
     * has to read it back. Both dimensions fall back to the same figure rather than
     * being taken from the other: a style with one and not the other says nothing
     * about the missing one, and inventing an aspect ratio produces a picture
     * stretched to a shape nobody asked for.
     *
     * @return array{0: float, 1: float}
     */
    private function sizeOf(string $style): array
    {
        $width = self::pointsIn($style, 'width');
        $height = self::pointsIn($style, 'height');

        return [$width ?? self::FALLBACK_POINTS, $height ?? self::FALLBACK_POINTS];
    }

    private static function pointsIn(string $style, string $property): ?float
    {
        $pattern = \sprintf('~(?:^|;)\s*%s\s*:\s*([0-9]*\.?[0-9]+)\s*(pt|px|in|cm|mm|pc)?\s*(?:;|$)~i', $property);

        if (\preg_match($pattern, $style, $match) !== 1) {
            return null;
        }

        $perUnit = match (\strtolower($match[2] ?? '')) {
            'in' => 72.0,
            'cm' => 72 / 2.54,
            'mm' => 7.2 / 2.54,
            'pc' => 12.0,
            // `pt` is what PHPWord writes, and CSS treats a bare number the same way.
            default => 1.0,
        };

        return (float) $match[1] * $perUnit;
    }

    private function relationshipId(DOMDocument $rels, string $target): ?string
    {
        foreach ($rels->getElementsByTagNameNS(self::REL_NS, 'Relationship') as $relationship) {
            if ($relationship instanceof DOMElement && $relationship->getAttribute('Target') === $target) {
                return $relationship->getAttribute('Id');
            }
        }

        return null;
    }

    private function addRelationship(DOMDocument $rels, string $id, string $target): void
    {
        $relationship = $rels->createElementNS(self::REL_NS, 'Relationship');
        $relationship->setAttribute('Id', $id);

        // The image relationship of the base standard, not a newer type: the vector is
        // an image part, and the extension on the picture is what tells a reader to
        // prefer it. A type a reader does not recognise would leave the part
        // unreferenced and the extension pointing at nothing.
        $relationship->setAttribute('Type', self::IMAGE_REL);
        $relationship->setAttribute('Target', $target);

        $rels->documentElement?->appendChild($relationship);
    }

    private function highestRelationshipId(DOMDocument $rels): int
    {
        return $this->highestNumber($rels, self::REL_NS, 'Relationship', '/^rId(\d+)$/', 'Id') + 1;
    }

    /**
     * The highest `wp:docPr` id in use, so the ids written here cannot collide with one.
     *
     * Word treats a duplicate as a repairable fault and drops the picture, which would
     * turn a vector that was meant to be attached into a document with no picture at
     * all — the exact failure this exists to avoid.
     */
    private function highestDrawingId(DOMDocument $document): int
    {
        return $this->highestNumber($document, self::WP_NS, 'docPr', '/^\d+$/', 'id');
    }

    private function highestNumber(DOMDocument $document, string $ns, string $tag, string $shape, string $attribute): int
    {
        $highest = 0;

        foreach ($document->getElementsByTagNameNS($ns, $tag) as $element) {
            if ($element instanceof DOMElement
                && \preg_match($shape, $element->getAttribute($attribute), $match) === 1
            ) {
                $highest = \max($highest, (int) $match[1]);
            }
        }

        return $highest;
    }

    /**
     * Word will not open a package carrying a part it has no content type for, so the
     * SVG is declared before the document is returned.
     */
    private function declareSvgContentType(string $types): string
    {
        if (\str_contains($types, 'Extension="svg"')) {
            return $types;
        }

        $document = $this->load($types, self::CONTENT_TYPES_PATH);

        $default = $document->createElementNS(self::CT_NS, 'Default');
        $default->setAttribute('Extension', 'svg');
        $default->setAttribute('ContentType', 'image/svg+xml');

        // Before the existing defaults: OPC reads them as a set and order carries no
        // meaning, but a reader that takes the first match wins if two ever did.
        $document->documentElement?->insertBefore($default, $document->documentElement->firstChild);

        return (string) $document->saveXML();
    }

    private function load(string $xml, string $what): DOMDocument
    {
        $previous = \libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, \LIBXML_NONET | \LIBXML_NOERROR);

        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);

        if ($loaded !== true) {
            throw new MalformedDocument(sprintf('"%s" in the generated document is not XML.', $what));
        }

        return $document;
    }
}