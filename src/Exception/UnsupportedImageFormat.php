<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * An image the document names, that is on disk, and that cannot go into a Word file.
 *
 * The other two outcomes are not this. An image whose file is not there, or whose
 * URL is remote, falls back to its alt text — the library has no HTTP client and
 * will not pretend a download happened — and that has always been documented. This
 * is the case where the file is in hand and is still unusable: a truncated JPEG, a
 * format neither Word nor the local GD build can decode.
 *
 * Falling back there too would put the two in the same class, and one of them is
 * right: an absent file is a document that has to render, an unusable one is a
 * document that would come out looking finished with the picture simply missing.
 *
 * {@see self::svg()} is kept apart from {@see self::for()} because the two have
 * nothing in common from the reader's side. A format nobody can write is a fact
 * about the format. An SVG on a build with no rasteriser is a fact about the machine
 * the document was made on, and telling somebody their vector is not a Word format
 * sends them looking in the wrong place.
 */
final class UnsupportedImageFormat extends InvalidInput
{
    /**
     * @param string $path   the file, as it resolved
     * @param string $format what was detected, or the extension when nothing could be
     */
    public static function for(string $path, string $format, string $reason = ''): self
    {
        return new self(sprintf(
            'Cannot embed "%s": %s is not a format this library can write into a Word document. '
            . 'It writes JPEG, PNG, GIF, BMP and TIFF; anything else is converted to PNG first '
            . 'if this PHP can read it.%s',
            $path,
            $format,
            $reason === '' ? '' : ' ' . $reason,
        ));
    }

    /**
     * An SVG, on a build that cannot put one into a document.
     *
     * The reason is a rasteriser, not the format. Word has held SVG since 2016 and
     * holds it the only way it ever can: a raster beside the vector, which is what
     * older readers draw, with the vector referenced from an extension on the same
     * element. A document carrying the vector and no raster renders no picture at
     * all, so there is no version of this that keeps the SVG and skips the PNG.
     *
     * @param string $detail what this build found wrong with the file itself, when the
     *                       rasteriser was present and the file was still no good
     */
    public static function svg(string $path, string $detail = ''): self
    {
        $problem = $detail === ''
            ? 'this PHP has no SVG rasteriser, and without one there is no picture to put beside the vector'
            : \sprintf('it could not be embedded because %s', $detail);

        return new self(sprintf(
            'Cannot embed "%s": Word can hold an SVG, but only beside a raster of it, so an SVG '
            . 'needs rasterising before it can go in — and %s. Install ext-imagick, or convert '
            . 'the file to PNG yourself.',
            $path,
            $problem,
        ));
    }
}