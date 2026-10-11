<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Exception\UnsupportedImageFormat;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Exception\InvalidImageException;
use PhpOffice\PhpWord\Exception\UnsupportedImageTypeException;
use Throwable;

/**
 * Resolves Markdown image nodes to embedded images, alt text, or nothing at all.
 *
 * A file that is not there, or a URL that is remote, falls back to its alt text:
 * the library has no HTTP client and will not invent a download. A file that *is*
 * there and cannot be used is a different case and does not fall back — see
 * {@see UnsupportedImageFormat} and {@see self::transcode()}.
 */
final class ImageResolver
{
    /** A centimetre in points, which is what a `v:shape` width is written in. */
    private const POINTS_PER_CENTIMETRE = 72 / 2.54;

    public function __construct(
        private readonly Options $options,
        private readonly ImageDescriptionCollector $descriptions,
        private readonly ImageConversionCollector $conversions = new ImageConversionCollector(),
        private readonly SvgAttachmentCollector $vectors = new SvgAttachmentCollector(),
    ) {
    }

    /**
     * @throws UnsupportedImageFormat when a file in hand has no format Word can take
     *         and none GD can decode.
     */
    public function render(Image $node, AbstractContainer $target, string $alt, InlineStyle $style): void
    {
        if ($this->options->images === Options::IMAGE_SKIP) {
            return;
        }

        // A hyperlink around an image cannot be expressed with PHPWord's Link
        // element, which holds one string of text. The image is still rendered;
        // the link is not attached to it.
        $style = $style->isLink() ? new InlineStyle() : $style;

        $url = $node->getUrl();
        $path = $this->resolvePath($url);

        if ($this->options->images === Options::IMAGE_EMBED && $path !== null) {
            $this->embed($path, $target);
            $this->descriptions->add($alt);
            $target->addText(' ');

            return;
        }

        // Placeholder mode, or an image that could not be embedded because it is
        // remote or missing. The alt text stands in either way, so nothing is
        // dropped silently, and placeholder mode adds the source after it.
        if ($alt !== '') {
            $target->addText($alt, $style->withItalic());
        }

        if ($url !== '' && $this->options->images === Options::IMAGE_PLACEHOLDER) {
            $target->addText($alt !== '' ? sprintf(' (%s)', $url) : $url);
        }
    }

    /**
     * Add the image, decoding it first when Word will not take the format it is in.
 *
     * @throws UnsupportedImageFormat when the file is in hand and unusable.
     */
    private function embed(string $path, AbstractContainer $target): void
    {
        // SVG is not a format Word refuses, which is what the branches below assume.
        // Word holds one happily, but never on its own — see {@see self::embedSvg()} —
        // so it cannot be discovered by letting PHPWord fail on it.
        if (self::looksLikeSvg($path)) {
            $this->embedSvg($path, $target);

            return;
        }

        [$naturalWidth, $format] = self::inspect($path);

        try {
            $target->addImage($path, $this->imageStyle($naturalWidth));

            return;
        } catch (UnsupportedImageTypeException) {
            // Word knows the file and has no use for it. Whether that is a dead end
            // depends on what this PHP can decode, which is the next question.
        } catch (InvalidImageException) {
            throw UnsupportedImageFormat::for($path, $format, 'PHPWord could not read it either.');
        } catch (Throwable) {
            // Anything else PHPWord raises about this file is still about this file,
            // and is reported rather than swallowed: the alternative is the picture
            // being absent from a document that looks finished.
            throw UnsupportedImageFormat::for($path, $format);
        }

        $png = $this->transcode($path, $format);

        if ($png === null) {
            throw UnsupportedImageFormat::for($path, $format);
        }

        // The bytes go straight to `addImage()`. PHPWord reads a source that is
        // neither a path nor a URL as the image itself, so there is nothing in the
        // temporary directory to leak and nothing to clean up.
        $target->addImage($png, $this->imageStyle($naturalWidth));
        $this->conversions->add($path, $format);
    }

    /**
     * An SVG goes in as a raster, with the vector kept beside it for
     * {@see \MarkdownWord\Writer\SvgPass} to point a later Word at.
     *
     * @throws UnsupportedImageFormat when this PHP cannot rasterise the file.
     */
    private function embedSvg(string $path, AbstractContainer $target): void
    {
        if (!SvgRasteriser::available()) {
            throw UnsupportedImageFormat::svg($path);
        }

        $raster = SvgRasteriser::toPng($path);

        if ($raster === null) {
            throw UnsupportedImageFormat::svg($path, 'this PHP could not read it as one');
        }

        $original = $this->readVector($path);

        // PHPWord measures the raster in pixels and lays it out in points, one pixel
        // to a point — but the raster is the SVG at 96dpi, so its pixel count is 1.33
        // times the width the SVG asked for. Left to measure the file, a 480-unit
        // diagram comes out 480pt wide instead of 361pt, and a whole page of them
        // overflows the text column. The size is stated rather than measured, and the
        // ratio comes with it so the height follows the drawing's own.
        [$width, $height] = SvgRasteriser::intrinsicSize($path);

        // Stated rather than left to PHPWord's measurement, which reads the raster's
        // pixels as points and so lays the picture out 96/72 too large. `imageStyle()`
        // only sets a width for a picture *wider* than the cap, because for a raster the
        // file's own size is already right; here it is not, so both are always given.
        if ($this->options->imageMaxWidth > 0.0) {
            $widest = self::POINTS_PER_CENTIMETRE * $this->options->imageMaxWidth;

            if ($width > $widest) {
                $height = $height > 0.0 ? $widest * $height / $width : 0.0;
                $width = $widest;
            }
        }

        $style = ['alignment' => 'left'];

        if ($width > 0.0) {
            $style['width'] = $width;
        }

        if ($height > 0.0) {
            $style['height'] = $height;
        }

        $target->addImage($raster, $style);

        $this->vectors->add($raster, $original);
    }

    /**
     * The SVG as it goes into the archive, unpacked.
     *
     * A `.svgz` is a gzipped SVG: the same document with no `<svg` to find in its first
     * few bytes, because what those bytes are is a header. Passing the compressed file
     * on would leave a part in the archive that a reader cannot take a vector out of,
     * and it would be one that looks like an SVG by name.
     *
     * @throws UnsupportedImageFormat When the file cannot be read or unpacked.
     */
    private function readVector(string $path): string
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            throw UnsupportedImageFormat::svg($path, 'it could not be read back');
        }

        if (\strtolower(\pathinfo($path, PATHINFO_EXTENSION)) !== 'svgz') {
            return $bytes;
        }

        $inflated = @gzdecode($bytes);

        if ($inflated === false) {
            throw UnsupportedImageFormat::svg($path, 'it is named as a compressed SVG but could not be unpacked');
        }

        return $inflated;
    }

    /**
     * Whether a file is meant to be an SVG.
     *
     * The content decides, because a file called `logo.png` holding a JPEG is common
     * enough and reading the bytes answers the question actually being asked. The name
     * is consulted as well, and for a narrower reason: a file called `diagram.svg` that
     * holds nothing recognisable is still a file somebody meant to be a vector, and
     * routing it to {@see self::embedSvg()} is what makes the error say the file could
     * not be read. Left to the format path it is reported as an unsupported image
     * format, which sends the reader looking for a problem with SVG that does not exist.
     */
    private static function looksLikeSvg(string $path): bool
    {
        $head = @file_get_contents($path, false, null, 0, 512);

        if ($head !== false && \preg_match('~<svg[\s>]~i', $head) === 1) {
            return true;
        }

        return \in_array(\strtolower(\pathinfo($path, PATHINFO_EXTENSION)), ['svg', 'svgz'], true);
    }

    /**
     * A file's own width in points, and its format.
     *
     * `getimagesize()` reads the header rather than the name, so it is right for a
     * file called `logo.png` that is actually something else. It returns false for
     * anything that is not a raster image at all — an SVG, a truncated download —
     * and the extension is the only thing left to say, so it is what is said.
     *
     * PHPWord measures an image's pixels as points, so the number it hands back is
     * the one its own scaling is compared against.
     *
     * @return array{0: ?float, 1: string}
     */
    private static function inspect(string $path): array
    {
        $size = @getimagesize($path);

        if (!\is_array($size)) {
            return [null, strtoupper(pathinfo($path, PATHINFO_EXTENSION))];
        }

        return [
            isset($size[0]) && is_numeric($size[0]) ? (float) $size[0] : null,
            isset($size['mime']) && \is_string($size['mime']) ? $size['mime'] : 'unknown',
        ];
    }

    /**
     * PNG bytes for an image Word will not take, or null when this PHP cannot read it.
     *
     * WebP is the case this exists for: three quarters of the images on the web, and
     * a format PHPWord has never heard of. GD decodes it, so the picture is there
     * rather than replaced by its alt text — and the conversion is recorded rather
     * than performed quietly, because a PNG of a photograph is several times the
     * size of the WebP it came from and somebody has to know that.
     */
    private function transcode(string $path, string $format): ?string
    {
        // `image/webp` is `imagecreatefromwebp`, and the same for every other format
        // GD can read — which is the point of asking the MIME type rather than
        // carrying a table of pairs.
        $decoder = 'imagecreatefrom' . str_replace('image/', '', $format);

        // A decoder function can exist and still refuse: whether the format was
        // compiled in is a separate question, and `imagetypes()` is what answers it.
        if (!function_exists($decoder) || !self::canDecode($format)) {
            return null;
        }

        $image = @$decoder($path);

        if ($image === false) {
            return null;
        }

        $level = ob_get_level();

        try {
            // Alpha is kept: a PNG written from an image that has blending on drops
            // it, and a logo with a transparent background comes out with a black one.
            imagealphablending($image, false);
            imagesavealpha($image, true);

            ob_start();
            $written = imagepng($image, null);
            $png = (string) ob_get_clean();

            return $written ? $png : null;
        } catch (Throwable $error) {
            // `get_clean()` is not reached when the encoder raises, so the buffer it
            // opened has to be closed here or it outlives the call — and closes
            // something that is not ours.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $error;
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * Whether this GD build was compiled with the format at all.
     */
    private static function canDecode(string $mime): bool
    {
        $constant = 'IMG_' . strtoupper(str_replace('image/', '', $mime));

        return defined($constant) && (imagetypes() & (int) constant($constant)) !== 0;
    }

    /**
     * The local path a Markdown image URL refers to, or null when it is remote or
     * missing.
     */
    private function resolvePath(string $url): ?string
    {
        if ($url === '' || $url === '/') {
            return null;
        }

        // A remote URL cannot be fetched without network access, and the library
        // deliberately has no HTTP client.
        if (preg_match('#^(https?|ftp|//)#i', $url) === 1) {
            return null;
        }

        if ($this->options->imageBasePath === null) {
            return is_file($url) ? $url : null;
        }

        $candidate = rtrim($this->options->imageBasePath, '/\\') . DIRECTORY_SEPARATOR . ltrim($url, '/\\');

        return is_file($candidate) ? $candidate : null;
    }

    /**
     * The image style, with the width capped where the option says.
     *
     * The cap is a *maximum*, so an image already narrower than it keeps its own
     * size: enlarging a small picture to fill a limit it was never reaching is not
     * what `imageMaxWidth` says, and it would make every logo in a document bigger.
     */
    private function imageStyle(?float $naturalWidth = null): array
    {
        $style = ['alignment' => 'left'];

        if ($this->options->imageMaxWidth > 0.0) {
            // Points, and a bare number. `Frame::setWidth()` puts whatever it is
            // given through `setNumericVal()`, which keeps a number and discards
            // anything else — so a width written `'8cm'` was thrown away, both
            // dimensions went unset, and the image came out at its own size however
            // wide the option said it could be.
            $widest = self::POINTS_PER_CENTIMETRE * $this->options->imageMaxWidth;

            if ($naturalWidth === null || $naturalWidth > $widest) {
                $style['width'] = $widest;
            }
        }

        return $style;
    }
}