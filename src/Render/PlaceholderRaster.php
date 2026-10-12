<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

/**
 * The image PHPWord is given when the SVG itself is the picture.
 *
 * PHPWord builds a picture out of raster bytes and writes it as VML, so a vector has to
 * start life as something with a pixel size. Under `svgFallback: none` that something is
 * a 1x1 fully transparent PNG: it gives {@see \MarkdownWord\Writer\SvgPass} a relationship
 * to remove once the picture has been rewritten to reference the vector, and it is never
 * seen, because nothing is left pointing at it.
 *
 * It is written out here rather than kept as a file so there is nothing to ship. The
 * bytes are the smallest transparent PNG that is still a valid one — a reader other than
 * PHPWord does not see them, but PHPWord measures them to decide what shape the picture
 * is, so they have to be an image it accepts.
 */
final class PlaceholderRaster
{
    private const BYTES = "\x89PNG\r\n\x1a\n"
        . "\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00"
        . "\x1f\x15\xc4\x89"
        . "\x00\x00\x00\x0aIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\r\n-\xb4"
        . "\x00\x00\x00\x00IEND\xaeB`\x82";

    /**
     * PNG bytes for an image that is one transparent pixel.
     *
     * A distinct value per call would defeat PHPWord's own deduplication of identical
     * media, and there is no reason for it: every picture in the document is about to stop
     * referencing whatever this is, and all that survives of it is a relationship that
     * {@see \MarkdownWord\Writer\SvgPass} deletes.
     */
    public static function bytes(): string
    {
        return self::BYTES;
    }
}
