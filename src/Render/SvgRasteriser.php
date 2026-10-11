<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use Imagick;
use Throwable;

/**
 * Renders an SVG to the PNG that Word insists on having beside it.
 *
 * Word holds vector images, but never on their own: the `a:blip` an image lives in
 * has to point at something every reader can draw, so an SVG is written next to a
 * raster of itself and referenced from an extension on the same element. That raster
 * is not decoration. A document whose `svgBlip` has no raster beside it renders no
 * picture at all, so there is no version of this that skips the PNG and keeps the
 * vector.
 *
 * Imagick is the only rasteriser here and it is optional. Without it an SVG raises
 * rather than going in flattened, because a vector that has quietly become a raster is
 * a document that no longer matches its source and nothing afterwards says that it
 * did.
 *
 * The raster is produced at the SVG's own size in points, because that is the unit
 * PHPWord sizes a picture in and the one `imageMaxWidth` is compared against. A wider
 * raster would make the document lay out at the wrong scale — a picture drawn twice as
 * big as it was written.
 */
final class SvgRasteriser
{
    private const POINTS_PER_CM = 28.3465;

    /** CSS absolute units, and the bare number, which SVG reads as pixels. */
    private const UNITS = [
        'px' => 0.7513,
        'pt' => 1.0,
        'pc' => 12.0,
        'in' => 72.0,
        'cm' => self::POINTS_PER_CM,
        'mm' => self::POINTS_PER_CM / 10,
        '' => 0.7513,
    ];

    /** Where an SVG declares nothing at all: a diagram with no stated size. */
    private const ASSUMED_POINTS = 288.0;

    /**
     * Whether this PHP can rasterise an SVG.
     *
     * The extension being loaded is not enough: a build without the SVG delegate
     * registers the format and then fails to read it, which would turn a missing
     * capability into a confusing failure on the first picture instead of on the
     * first conversion.
     */
    public static function available(): bool
    {
        if (!\extension_loaded('imagick') || !\class_exists(Imagick::class)) {
            return false;
        }

        try {
            return \in_array('SVG', \Imagick::queryFormats(), true);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The size Word should lay the picture out at, in points.
     *
     * `getimagesize()` cannot be asked: it learned to read SVG in PHP 8.5, and this
     * package supports 8.2, so on every earlier build it returns false for a perfectly
     * good vector. The file says its own size, so the file is asked.
     *
     * @return array{0: float, 1: float} width and height in points
     */
    public static function intrinsicSize(string $path): array
    {
        $root = self::rootElement($path);

        if ($root === null) {
            return [self::ASSUMED_POINTS, self::ASSUMED_POINTS];
        }

        // The `width` attribute wins over the viewBox, as it does in a browser: a
        // document that states both wants the stated one.
        $width = self::toPoints($root->getAttribute('width'));

        if ($width === null) {
            $width = self::viewBoxWidth($root) ?? self::ASSUMED_POINTS;
        }

        // The height is taken from the viewBox rather than from the `height`
        // attribute, because a document that scales one and not the other means to be
        // scaled: an SVG authored at 480x180 and resized to 480x360 for a banner is
        // stretched by the viewBox ratio only if the ratio is read from the same box.
        // Reading `height` where a `width` was stated would instead letterbox it.
        $height = self::viewBoxHeight($root, $width) ?? self::ASSUMED_POINTS;

        return [$width, $height];
    }

    /**
     * The width Word should lay the picture out at, in points.
     *
     * {@see self::intrinsicSize()} is what the caller wants; this is the half of it
     * that `imageMaxWidth` compares against.
     */
    public static function intrinsicWidth(string $path): float
    {
        return self::intrinsicSize($path)[0];
    }

    /**
     * PNG bytes for an SVG, or null when this build cannot read it.
     */
    public static function toPng(string $path): ?string
    {
        if (!self::available()) {
            return null;
        }

        $image = new Imagick();

        try {
            $image->setBackgroundColor(new \ImagickPixel('transparent'));
            $image->readImage($path);
            $image->setImageFormat('png32');

            return $image->getImageBlob();
        } catch (Throwable) {
            // A file that claims to be an SVG and is not one, or a construct this
            // build's delegate cannot draw. Either way the caller has a fallback to
            // offer and the reason to say out loud.
            return null;
        } finally {
            $image->clear();
        }
    }

    private static function rootElement(string $path): ?\DOMElement
    {
        $markup = @file_get_contents($path);

        if ($markup === false || $markup === '') {
            return null;
        }

        // `LIBXML_NONET` for the reason `src/Xml.php` gives: a document should not
        // reach out to the network to be parsed, and an SVG is a document.
        $previous = \libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($markup, \LIBXML_NONET | \LIBXML_NOERROR);

        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);

        if ($loaded !== true) {
            return null;
        }

        $root = $document->documentElement;

        return $root instanceof \DOMElement ? $root : null;
    }

    private static function toPoints(string $length): ?float
    {
        $length = \trim($length);

        if ($length === '' || !\preg_match('~^(\d*\.?\d+)\s*([a-z%]*)$~i', $length, $match)) {
            return null;
        }

        $unit = \strtolower($match[2]);

        if (!isset(self::UNITS[$unit])) {
            return null;
        }

        return (float) $match[1] * self::UNITS[$unit];
    }

    /**
     * A viewBox dimension, in points.
     *
     * A viewBox is user units, not pixels. Treating them as pixels would make a
     * diagram drawn in a `0 0 120 80` box come out a quarter larger than a
     * `width="120"` one, and it is the same drawing.
     */
    private static function viewBoxWidth(\DOMElement $root): ?float
    {
        return self::viewBoxAxis($root, 2);
    }

    private static function viewBoxHeight(\DOMElement $root, float $width): ?float
    {
        $box = self::viewBox($root);

        if ($box === null) {
            return null;
        }

        // The height follows from the ratio rather than from the box's own units, so a
        // document that states one dimension and relies on the ratio for the other
        // comes out with the shape it was drawn as.
        if ($box[2] <= 0.0) {
            return null;
        }

        return $width * $box[3] / $box[2];
    }

    private static function viewBox(\DOMElement $root): ?array
    {
        $box = \preg_split('/[\s,]+/', \trim($root->getAttribute('viewBox')));

        if ($box === false || \count($box) !== 4) {
            return null;
        }

        foreach ([2, 3] as $index) {
            if (!\is_numeric($box[$index])) {
                return null;
            }
        }

        return [(float) $box[0], (float) $box[1], (float) $box[2], (float) $box[3]];
    }

    private static function viewBoxAxis(\DOMElement $root, int $index): ?float
    {
        $box = self::viewBox($root);

        return $box === null ? null : $box[$index] * self::UNITS['px'];
    }
}