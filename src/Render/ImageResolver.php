<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Exception\UnsupportedImageFormat;
use MarkdownWord\Format;
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
 *
 * **A picture's size is in pixels.** PHPWord measures an image in pixels and its
 * RTF and ODF writers read the number back as pixels; only its Word writer labels
 * the number `pt`, which is how a picture of 104 pixels came to be laid out 104
 * points wide — a third too large. The unit is therefore stated on the style
 * rather than left to PHPWord's default, and every size here is converted into it
 * before it is handed over.
 */
final class ImageResolver
{
    /** A centimetre in pixels, which is the unit a picture's size is measured in. */
    private const PIXELS_PER_CENTIMETRE = 96 / 2.54;

    /** A point in pixels, which is the unit a vector's own size is stated in. */
    private const PIXELS_PER_POINT = 96 / 72;

    public function __construct(
        private readonly Options $options,
        private readonly ImageDescriptionCollector $descriptions,
        private readonly ImageConversionCollector $conversions = new ImageConversionCollector(),
        private readonly SvgAttachmentCollector $vectors = new SvgAttachmentCollector(),
        private readonly ?Format $format = null,
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
            $this->embed($path, $target, $this->descriptions->countPending());
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
     * Draw a picture of a file, at a size in centimetres.
     *
     * The same answer a Markdown image gives: the file is resolved against
     * `imageBasePath`, embedded when the mode says so, and the alt text stands in
     * when it cannot be. The size is in centimetres because that is the unit
     * `imageMaxWidth` is in, and a document that named a size in one unit and left
     * the other in another would be a document nobody could reason about.
     *
     * @param float|null $width the width in centimetres, or null for the picture's own
     * @param float|null $height the height in centimetres, or null for the picture's own
     *
     * @throws UnsupportedImageFormat when a file in hand has no format Word can take
     *         and none GD can decode.
     */
    public function drawFile(
        string $source,
        AbstractContainer $target,
        ?string $alt = null,
        ?float $width = null,
        ?float $height = null,
        string $alignment = 'left',
    ): void {
        if ($this->options->images === Options::IMAGE_SKIP) {
            return;
        }

        $path = $this->resolvePath($source);

        if ($this->options->images === Options::IMAGE_EMBED && $path !== null) {
            $this->embed(
                $path,
                $target,
                $this->descriptions->countPending(),
                $width === null ? null : self::PIXELS_PER_CENTIMETRE * $width,
                $height === null ? null : self::PIXELS_PER_CENTIMETRE * $height,
                $alignment,
            );
            $this->descriptions->add($alt ?? '');

            return;
        }

        // A file that is not there, or a URL that is remote. The alt text stands in,
        // which is what a Markdown image does in the same case.
        if ($alt !== null && $alt !== '') {
            $target->addText($alt);
        }

        if ($this->options->images === Options::IMAGE_PLACEHOLDER && $source !== '') {
            $target->addText($alt !== null && $alt !== '' ? sprintf(' (%s)', $source) : $source);
        }
    }

    /**
     * Add the image, decoding it first when Word will not take the format it is in.
 *
     * @param int $position where this image falls among the document's pictures, which is
     *        how {@see SvgAttachmentCollector} finds it again once the file has been written
     * @param float|null $width the width asked for, in pixels, or null for the picture's own
     * @param float|null $height the height asked for, in pixels, or null for the picture's own
     *
     * @throws UnsupportedImageFormat when the file is in hand and unusable.
     */
    private function embed(
        string $path,
        AbstractContainer $target,
        int $position,
        ?float $width = null,
        ?float $height = null,
        string $alignment = 'left',
    ): void {
        // SVG is not a format Word refuses, which is what the branches below assume.
        // Word holds one happily, but never on its own — see {@see self::embedSvg()} —
        // so it cannot be discovered by letting PHPWord fail on it.
        if (self::looksLikeSvg($path)) {
            $this->embedSvg($path, $target, $position, $width, $height, $alignment);

            return;
        }

        [$naturalWidth, $format] = self::inspect($path);

        try {
            $target->addImage($path, $this->imageStyle($naturalWidth, $width, $height, $alignment));

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
        $target->addImage($png, $this->imageStyle($naturalWidth, $width, $height, $alignment));
        $this->conversions->add($path, $format);
    }

    /**
     * An SVG goes in as a picture PHPWord can draw, with the vector kept for
     * {@see \MarkdownWord\Writer\SvgPass} to point a later Word at.
     *
     * Whether that picture is a raster of the SVG or a placeholder turns on two things.
     * `svgFallback` asks for it, and the format settles whether there is a choice at all:
     * only a writer that keeps the vector has something for a raster to sit beside, so
     * `.odt` and `.rtf`, which drop it, need the raster or they have no picture.
     *
     * @param float|null $width the width asked for, in pixels, or null for the drawing's own
     * @param float|null $height the height asked for, in pixels, or null for the drawing's own
     *
     * @throws UnsupportedImageFormat when a picture is needed and this PHP cannot produce
     *         one, when the file claims to be a vector and is not one, or when it states
     *         no size to be placed at.
     */
    private function embedSvg(
        string $path,
        AbstractContainer $target,
        int $position,
        ?float $width = null,
        ?float $height = null,
        string $alignment = 'left',
    ): void {
        // Read first, so a file that holds no SVG is reported as that rather than as one
        // that cannot be sized — the two have different fixes for whoever wrote it.
        $original = $this->readVector($path);

        if (!SvgRasteriser::declaresSize($path)) {
            throw UnsupportedImageFormat::svg($path, 'it states no width, height or viewBox, so there is nothing to place it at');
        }

        $wantsRaster = $this->needsRaster();

        if ($wantsRaster) {
            if (!SvgRasteriser::available()) {
                throw UnsupportedImageFormat::svg($path);
            }

            $raster = SvgRasteriser::toPng($path);

            if ($raster === null) {
                throw UnsupportedImageFormat::svg($path, 'this PHP could not read it as one');
            }
        } else {
            // A placeholder is only ever a stand-in for the vector a reader is about to be
            // given. Where the vector is going to be dropped the placeholder would be all
            // that is left of the picture, which {@see self::needsRaster()} rules out.
            $raster = PlaceholderRaster::bytes();
        }

        // The drawing's own size, in points, turned into the pixels a picture is measured
        // in. The raster is the SVG at 96dpi, so its pixel count is a third larger than
        // the width the SVG asked for: a 480-unit diagram is 360pt, which is 480 pixels,
        // and left to measure the file PHPWord would lay it out 480 pixels wide.
        //
        // The size is stated rather than measured, and the ratio comes with it so the
        // height follows the drawing's own.
        [$drawingWidth, $drawingHeight] = SvgRasteriser::intrinsicSize($path);

        $drawingWidth *= self::PIXELS_PER_POINT;
        $drawingHeight *= self::PIXELS_PER_POINT;

        // Stated rather than left to PHPWord's measurement, which reads the raster's
        // pixels as points and so lays the picture out 96/72 too large. `imageStyle()`
        // only sets a width for a picture *wider* than the cap, because for a raster the
        // file's own size is already right; here it is not, so both are always given.
        $widest = $this->capWidth();

        if ($widest !== null && $drawingWidth > $widest) {
            $drawingHeight = $drawingHeight > 0.0 ? $widest * $drawingHeight / $drawingWidth : 0.0;
            $drawingWidth = $widest;
        }

        $style = ['alignment' => $alignment, 'unit' => 'px'];

        $finalWidth = $width ?? $drawingWidth;
        $finalHeight = $height ?? $drawingHeight;

        if ($finalWidth > 0.0) {
            $style['width'] = $finalWidth;
        }

        if ($finalHeight > 0.0) {
            $style['height'] = $finalHeight;
        }

        $target->addImage($raster, $style);

        $this->vectors->add($position, 'image' . ($position + 1) . '.svg', $original, $wantsRaster);
    }

    /**
     * Whether this picture has to be a real raster rather than a placeholder.
     *
     * Two ways to arrive at yes. A format that drops the vector leaves the picture as the
     * only thing a reader will draw, so `svgFallback` has nothing to choose between and
     * the raster is the picture. And `svgFallback: raster`, which is what the option is
     * for — a raster for the readers that cannot take the vector.
     *
     * Asked for and not given, the vector is written on its own instead of the conversion
     * being refused: a document whose readers may miss one picture beats no document, and
     * {@see \MarkdownWord\MarkdownToWord::pendingLosses()} says what that costs.
     */
    private function needsRaster(): bool
    {
        $dropsVector = \in_array('svg-vector', $this->format?->drops() ?? [], true);

        if ($dropsVector) {
            return true;
        }

        return $this->options->svgFallback === Options::SVG_FALLBACK_RASTER
            && SvgRasteriser::available();
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

        if (\strtolower(\pathinfo($path, PATHINFO_EXTENSION)) === 'svgz') {
            $inflated = @gzdecode($bytes);

            if ($inflated === false) {
                throw UnsupportedImageFormat::svg($path, 'it is named as a compressed SVG but could not be unpacked');
            }

            $bytes = $inflated;
        }

        // Read here rather than left to the rasteriser to object. A file is routed to this
        // method on its name when its bytes do not say otherwise, and with `svgFallback: none`
        // nothing downstream would notice — the document would carry a part named `.svg`
        // that no reader can draw, which is the exact thing this library is meant not to
        // do quietly.
        if (\preg_match('~<svg[\s>]~i', $bytes) !== 1) {
            throw UnsupportedImageFormat::svg($path, 'it holds no SVG, whatever it is called');
        }

        return $bytes;
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
     * A file's own width in pixels, and its format.
     *
     * `getimagesize()` reads the header rather than the name, so it is right for a
     * file called `logo.png` that is actually something else. It returns false for
     * anything that is not a raster image at all — an SVG, a truncated download —
     * and the extension is the only thing left to say, so it is what is said.
     *
     * The width is in pixels, which is the unit a picture is measured in: PHPWord
     * reads an image's own dimensions as pixels, and so does everything here.
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
     *
     * A size the document asked for is the size the picture gets. The cap is what
     * happens to a picture that says nothing about its own size, and shrinking a
     * picture to a limit the author did not know about is the kind of quiet change
     * this library does not make.
     *
     * @param float|null $naturalWidth the picture's own width in pixels, or null when
     *        the file does not say
     * @param float|null $width the width asked for, in pixels, or null for the cap to decide
     * @param float|null $height the height asked for, in pixels, or null for the picture's own
     *
     * @return array<string, mixed>
     */
    private function imageStyle(
        ?float $naturalWidth,
        ?float $width = null,
        ?float $height = null,
        string $alignment = 'left',
    ): array {
        // The unit is stated rather than left to PHPWord's default, which is `pt` for
        // an image. The number is a pixel count — PHPWord measures an image in
        // pixels, and its RTF and ODF writers read the number back as pixels — so
        // labelling it `pt` lays every picture out a third too large.
        $style = ['alignment' => $alignment, 'unit' => 'px'];

        if ($width !== null) {
            $style['width'] = $width;
        } else {
            $widest = $this->capWidth();

            if ($widest !== null && ($naturalWidth === null || $naturalWidth > $widest)) {
                $style['width'] = $widest;
            }
        }

        if ($height !== null) {
            $style['height'] = $height;
        }

        return $style;
    }

    /**
     * The width a picture is scaled down to, in pixels, or null when there is no cap.
     *
     * Points, and a bare number. `Frame::setWidth()` puts whatever it is
     * given through `setNumericVal()`, which keeps a number and discards
     * anything else — so a width written `'8cm'` was thrown away, both
     * dimensions went unset, and the image came out at its own size however
     * wide the option said it could be.
     */
    private function capWidth(): ?float
    {
        if ($this->options->imageMaxWidth <= 0.0) {
            return null;
        }

        return self::PIXELS_PER_CENTIMETRE * $this->options->imageMaxWidth;
    }
}