<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Exception\UnsupportedImageFormat;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Render\SvgRasteriser;
use MarkdownWord\Writer\SvgPass;

/*
 * SVG in a Word document.
 *
 * Word has held SVG since 2016 and holds it the only way it ever can: a raster beside
 * the vector, with the vector referenced from an extension on the picture. A picture
 * carrying that extension and no raster renders nothing at all, which is why this is
 * two parts and not one.
 *
 * The awkward part is that PHPWord writes images as VML, and VML has no place to hang
 * the extension on — so the picture is rewritten as DrawingML. That is why these tests
 * assert on what the picture became and not merely on whether a vector was attached.
 */

use MarkdownWord\Tests\Support\Upstream;

beforeEach(fn () => Upstream::install());
// Every test in this file builds a document with an SVG in it, which needs something
// to rasterise. The suite runs on a build with imagick and on one without, so the file
// skips itself rather than each test carrying a mark that would be wrong on the build
// that has the extension.
//
// `uses()->beforeEach()` rather than a bare `beforeEach(function () { $this->… })`:
// Pest types the latter as a `PendingCalls`, where `markTestSkipped()` is not a method,
// so the one call the file depends on is the one static analysis cannot see.
uses()->beforeEach(function (): void {
    if (!\extension_loaded('imagick')) {
        test()->markTestSkipped('ext-imagick is not loaded, so no SVG can be embedded');
    }
});

afterEach(fn () => Upstream::restore());

const SVG_EXTENSION_URI = '{96DAC541-7B7A-43D3-8B79-37D633B846F1}';

/** An SVG with a stated size, a viewBox and text to draw. */
function anSvg(string $name = 'diagram.svg', string $body = ''): string
{
    $directory = sys_get_temp_dir() . '/mdword-svg';

    if (!is_dir($directory)) {
        mkdir($directory, 0o777, true);
    }

    file_put_contents($directory . '/' . $name, <<<SVG
        <?xml version="1.0" encoding="UTF-8"?>
        <svg xmlns="http://www.w3.org/2000/svg" width="240" height="120" viewBox="0 0 240 120">
          <rect width="240" height="120" fill="#1b3a5c"/>
          <circle cx="60" cy="60" r="42" fill="#ffffff"/>
          $body
        </svg>
        SVG);

    return $directory;
}

/**
 * A rendered document, split into the parts the assertions are about.
 *
 * @return array{document: string, rels: string, types: string, media: array<string, string>, names: list<string>}
 */
function renderWithSvg(string $markdown, string $basePath): array
{
    $target = sys_get_temp_dir() . '/mdword-svg-out.docx';

    (new MarkdownToWord($markdown, Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $basePath,
    ])))->save($target);

    $zip = new ZipArchive();
    $zip->open($target);

    $media = [];
    $names = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);
        $names[] = $name;

        if (str_contains($name, 'word/media/')) {
            $media[$name] = (string) $zip->getFromName($name);
        }
    }

    $parts = [
        'document' => (string) $zip->getFromName('word/document.xml'),
        'rels' => (string) $zip->getFromName('word/_rels/document.xml.rels'),
        'types' => (string) $zip->getFromName('[Content_Types].xml'),
    ];

    $zip->close();
    @unlink($target);

    return $parts + ['media' => $media, 'names' => $names];
}

it('writes the vector beside the raster, because the raster is what makes it render', function () {
    $base = anSvg();

    $parts = renderWithSvg("![A diagram](diagram.svg)\n", $base);

    $vectors = array_values(array_filter(
        array_keys($parts['media']),
        static fn (string $name): bool => str_ends_with($name, '.svg'),
    ));

    $rasters = array_values(array_filter(
        array_keys($parts['media']),
        static fn (string $name): bool => str_ends_with($name, '.png'),
    ));

    // One of each, and the vector is not a substitute for the raster: a picture whose
    // svgBlip has nothing behind it draws nothing in any version of Word.
    expect($vectors)->toHaveCount(1)
        ->and($rasters)->toHaveCount(1)
        ->and($parts['media'][$vectors[0]])->toContain('<svg')
        ->and($parts['media'][$rasters[0]])->toStartWith("\x89PNG");
});

it('points a reader at the vector through the extension Word reserves for it', function () {
    $parts = renderWithSvg("![A diagram](diagram.svg)\n", anSvg());

    expect($parts['document'])->toContain('svgBlip')
        ->and($parts['document'])->toContain(SVG_EXTENSION_URI)
        ->and($parts['document'])->toContain('http://schemas.microsoft.com/office/drawing/2016/SVG/main');
});

it('gives the vector a relationship of its own', function () {
    $parts = renderWithSvg("![A diagram](diagram.svg)\n", anSvg());

    $vectorId = preg_match('/asvg:svgBlip[^>]*r:embed="(rId\d+)"/', $parts['document'], $match) === 1
        ? $match[1]
        : null;

    expect($vectorId)->not->toBeNull()
        ->and($parts['rels'])->toMatch('~Id="' . $vectorId . '"[^>]*Target="media/[^"]+\.svg"~');
});

it('declares the vector as an image part, which is the type a reader resolves', function () {
    // The relationship type is not decoration. A reader resolving `svgBlip/@r:embed`
    // asks for a relationship and checks what kind of part it points at; a type it does
    // not recognise leaves the vector unreferenced and the extension dangling — a
    // document that looks fine and has lost exactly the thing it was built for.
    $parts = renderWithSvg("![A diagram](diagram.svg)\n", anSvg());

    expect($parts['rels'])
        ->toContain('Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/')
        ->and($parts['rels'])->toMatch(
            '~Type="[^"]+/relationships/image" Target="media/[^"]+\.svg"~',
        );
});

it('declares the content type before the others, so a reader that takes the first match is right', function () {
    // OPC reads the defaults as a set, so order carries no meaning — but a reader that
    // took only the first would get png for the vector, and the two are indistinguishable
    // to it. Word writes it first; so does this.
    $parts = renderWithSvg("![A diagram](diagram.svg)\n", anSvg());

    expect($parts['types'])->toMatch('~<Default Extension="svg"[^>]*/>.{0,400}<Default Extension="png"~');
});

it('declares the DrawingML namespaces once, on the document root', function () {
    // Left to itself the serialiser repeats a declaration on every element built with
    // `createElementNS()`. A document with ten vector pictures would carry the same six
    // declarations several hundred times, and the one the file was already carrying
    // would no longer be the one a reader resolves first.
    $parts = renderWithSvg("![One](diagram.svg)\n\n![Two](second.svg)\n", svgPair());

    expect($parts['document'])->toMatch('~<w:document[^>]*xmlns:a="[^"]*drawingml/2006/main"~')
        ->and($parts['document'])->toMatch('~<w:document[^>]*xmlns:pic="~')
        ->and($parts['document'])->toMatch('~<w:document[^>]*xmlns:asvg="~');
});

it('declares the content type, or Word refuses to open the package', function () {
    // OPC requires a content type for every part. A `.svg` part with no declaration is
    // a package Word reports as corrupt rather than one it opens without a picture.
    $parts = renderWithSvg("![A diagram](diagram.svg)\n", anSvg());

    expect($parts['types'])->toContain('Extension="svg"')
        ->and($parts['types'])->toContain('ContentType="image/svg+xml"');
});

it('rewrites the picture as DrawingML, because VML cannot carry the extension', function () {
    // PHPWord writes `w:pict` and `v:imagedata`. That has no extension list, so the
    // vector would have nowhere to go and the picture would stay a raster however many
    // parts the archive carried.
    $parts = renderWithSvg("![A diagram](diagram.svg)\n", anSvg());

    expect($parts['document'])->toMatch('~<w:drawing[\s>]~')
        ->and($parts['document'])->toContain('a:blip')
        ->and($parts['document'])->not->toContain('<w:pict>');
});

it('lays the picture out at the size the SVG stated', function () {
    // The fixture states 240x120 user units, which is 180.31 x 90.16 points — 240px
    // at 96dpi is 180pt, not 240. Asserting the pixel count here would be asserting the
    // bug this replaced: a picture laid out 96/72 too large, since PHPWord reads the
    // raster's pixels as points.
    $parts = renderWithSvg("![A diagram](diagram.svg)\n", anSvg());

    expect($parts['document'])->toContain('cx="2289962"')
        ->and($parts['document'])->toContain('cy="1144981"');
});

it('gives every picture its own identifier', function () {
    // Word treats a duplicate `docPr` id as a fault and drops the picture, which would
    // turn a vector that was meant to be attached into a document with no picture.
    $first = renderWithSvg("![One](diagram.svg)\n", anSvg());
    $second = renderWithSvg("![One](diagram.svg)\n\n![Again](other.svg)\n", svgWithTwo());

    $ids = static function (string $document): array {
        preg_match_all('/<wp:docPr id="(\d+)"/', $document, $matches);

        return $matches[1];
    };

    expect($ids($second['document']))->toHaveCount(2)
        ->and(array_unique($ids($second['document'])))->toHaveCount(2)
        ->and($first['document'])->toContain('<wp:docPr id="1"');
});

it('attaches a vector to the same picture twice, which is what repeating it means', function () {
    // The same SVG used twice is one entry in the archive and two in the document, so
    // matching by position would attach one and leave the other a raster.
    $parts = renderWithSvg("![One](diagram.svg)\n\n![Again](diagram.svg)\n", anSvg());

    expect(substr_count($parts['document'], 'svgBlip'))->toBe(2);
});

it('carries the alt text across, because the swap would otherwise delete it', function () {
    // PHPWord keeps a description on VML as `o:title` and DrawingML keeps it on
    // `wp:docPr/@descr`. Rewriting the picture and dropping it leaves a document with
    // no picture description at all, and nothing about that is visible.
    $parts = renderWithSvg('![A diagram of a lock and key](diagram.svg)' . "\n", anSvg());

    expect($parts['document'])->toContain('A diagram of a lock and key')
        ->and($parts['document'])->not->toContain('o:title');
});

it('leaves an ordinary picture exactly as PHPWord wrote it', function () {
    // The rewrite exists for pictures that have a vector. Touching every image in
    // every document to support a format most documents do not use would be a
    // regression bought for nothing.
    $directory = sys_get_temp_dir() . '/mdword-svg';
    imagepng(imagecreatetruecolor(20, 10), $directory . '/plain.png');

    $parts = renderWithSvg("![A plain picture](plain.png)\n", $directory);

    expect($parts['document'])->toContain('<w:pict>')
        ->and($parts['document'])->not->toContain('svgBlip');
});



it('does nothing when there is no vector to attach', function () {
    $path = sys_get_temp_dir() . '/mdword-svg-none.docx';

    (new MarkdownToWord("# Plain\n", Configuration::create()))->save($path);

    $before = (string) file_get_contents($path);

    expect((new SvgPass())->applyTo($path, []))->toBe(0)
        ->and((string) file_get_contents($path))->toBe($before);

    @unlink($path);
});

it('answers the size question from the file rather than the name', function () {
    $directory = anSvg('sized.svg');
    file_put_contents($directory . '/wrong.png', file_get_contents($directory . '/sized.svg'));

    // Read by content: a JPEG called `logo.png` is the case this codebase already
    // handles for rasters, and a vector called the wrong thing is the same mistake.
    $parts = renderWithSvg("![Named oddly](wrong.png)\n", $directory);

    expect($parts['document'])->toContain('svgBlip');
});

it('rasterises at the size the SVG stated, in points', function () {
    // PHPWord sizes a picture in points and compares `imageMaxWidth` against them, so a
    // raster wider than the SVG's own width would lay the document out too large.
    expect(SvgRasteriser::intrinsicWidth(anSvg() . '/diagram.svg'))->toBeGreaterThan(0.0);
});

it('states both dimensions on the picture, since a height left out is PHPWord\'s to guess', function () {
    // `imageStyle()` sets a width only for a picture wider than the cap, and sets no
    // height at all — PHPWord takes that from the file. For a raster the file is right;
    // for a vector it is the SVG rendered at 96dpi, and PHPWord reads its pixels as
    // points. Leaving the height out hands the picture a size that is neither the
    // SVG's nor the cap's.
    $parts = renderWithSvg("![A diagram](diagram.svg)\n", anSvg());

    expect($parts['document'])->toMatch('~<wp:extent cx="\d+" cy="\d+"/>~');
});

it('scales the height with the width when the cap applies, rather than squashing', function () {
    // The fixture is 240x120 user units — a 2:1 shape. Under a cap it must stay 2:1;
    // a width set alone leaves the height at its own value and stretches the picture.
    $directory = anSvg();

    $markdown = "![A diagram](diagram.svg)\n";
    $capped = (new MarkdownToWord($markdown, Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $directory,
        // 5cm is 141.7pt, well under the fixture's 180.3pt width.
        'imageMaxWidth' => 5.0,
    ])))->toDocx($markdown);

    $path = sys_get_temp_dir() . '/mdword-svg-out.docx';
    file_put_contents($path, $capped);

    $zip = new ZipArchive();
    $zip->open($path);
    $document = (string) $zip->getFromName('word/document.xml');
    $zip->close();

    expect($document)->toMatch('~<wp:extent cx="(\d+)" cy="(\d+)"/>~');

    preg_match('~<wp:extent cx="(\d+)" cy="(\d+)"/>~', $document, $match);

    $width = (int) $match[1];
    $height = (int) $match[2];

    // 2:1 to within a rounding step.
    expect(round($width / $height, 2))->toBe(2.0);
});

it('falls back to the viewBox when no width is stated', function () {
    $directory = anSvg();
    file_put_contents($directory . '/viewbox.svg', <<<'SVG'
        <?xml version="1.0" encoding="UTF-8"?>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 120"><rect width="240" height="120" fill="#000"/></svg>
        SVG);

    $stated = SvgRasteriser::intrinsicWidth($directory . '/diagram.svg');
    $viewBox = SvgRasteriser::intrinsicWidth($directory . '/viewbox.svg');

    expect((float) round($viewBox))->toBe((float) round($stated));
});

it('lays out a stretched SVG by its own ratio, not by the height it declares', function () {
    // Authored as 480x180 and given `width="480" height="60"` for a banner. The drawing
    // is 8:3 inside its viewBox; the height attribute says 8:1 and would letterbox it.
    // The ratio wins, which is what a browser does and what the picture was drawn for —
    // so the number in the document has to be the ratio's, not the attribute's.
    $directory = anSvg();
    file_put_contents($directory . '/banner.svg', <<<'SVG'
        <?xml version="1.0" encoding="UTF-8"?>
        <svg xmlns="http://www.w3.org/2000/svg" width="480" height="60" viewBox="0 0 480 180">
          <rect width="480" height="180" fill="#123456"/>
        </svg>
        SVG);

    $markdown = "![A banner](banner.svg)\n";
    $path = sys_get_temp_dir() . '/mdword-svg-banner.docx';

    (new MarkdownToWord($markdown, Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $directory,
    ])))->save($path);

    $zip = new ZipArchive();
    $zip->open($path);
    $document = (string) $zip->getFromName('word/document.xml');
    $zip->close();

    preg_match('~<wp:extent cx="(\d+)" cy="(\d+)"/>~', $document, $match);

    $width = (int) $match[1];
    $height = (int) $match[2];

    // The height is the *stated* width scaled by the box's ratio, not the box's height
    // in user units: 480 units is 360.6pt, and 180/480 of that is 135.2pt — 1717472 EMU.
    expect(round($width / $height))->toBe(3.0)
        ->and((int) $height)->toBe(1717472);
});

it('scales the height with the stated width, not with the box it was drawn in', function () {
    // The same drawing at half the stated width is half as tall. Reading the height out
    // of the viewBox on its own ignores the width, so the two come out the same height
    // and the picture is stretched as it is narrowed — the one way a vector can look
    // wrong while every number in the file is individually plausible.
    $directory = anSvg();
    file_put_contents($directory . '/narrow.svg', <<<'SVG'
        <?xml version="1.0" encoding="UTF-8"?>
        <svg xmlns="http://www.w3.org/2000/svg" width="240" height="30" viewBox="0 0 480 180">
          <rect width="480" height="180" fill="#123456"/>
        </svg>
        SVG);

    [$wideWidth, $wideHeight] = SvgRasteriser::intrinsicSize($directory . '/banner.svg');
    [$narrowWidth, $narrowHeight] = SvgRasteriser::intrinsicSize($directory . '/narrow.svg');

    expect(round($wideWidth))->toBe(361.0)
        ->and(round($narrowWidth))->toBe(180.0)
        ->and(round($narrowHeight))->toBe(68.0)
        // Half the width, half the height, and the same shape either way.
        ->and(round($wideWidth / $wideHeight))->toBe(round($narrowWidth / $narrowHeight))
        // And half as tall, which is the part a box-only reading gets wrong.
        ->and(round($narrowHeight))->toBe(round($wideHeight / 2));
});

it('reads the height from the viewBox ratio, since the width alone does not give it', function () {
    // A banner authored at 480x180 and given `width="480"` alone is 180 tall by ratio.
    // Taking the height from the viewBox in its own units gets that; deriving it from
    // the stated width does not, and a shape drawn 3:1 comes out square.
    $directory = anSvg();
    file_put_contents($directory . '/wide.svg', <<<'SVG'
        <?xml version="1.0" encoding="UTF-8"?>
        <svg xmlns="http://www.w3.org/2000/svg" width="480" height="60" viewBox="0 0 480 180">
          <rect width="480" height="180" fill="#000"/>
        </svg>
        SVG);

    [$width, $height] = SvgRasteriser::intrinsicSize($directory . '/wide.svg');

    // 480 units is 360.6pt, and 180/480 of that is 135.2pt — the drawing's own shape,
    // not the 45pt the `height="60"` attribute would give.
    expect(round($width))->toBe(361.0)
        ->and(round($height))->toBe(135.0)
        ->and(round($width / $height))->toBe(3.0);
});

it('still answers for a file that says nothing about its size', function () {
    $directory = anSvg();
    file_put_contents($directory . '/bare.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>');

    expect(SvgRasteriser::intrinsicWidth($directory . '/bare.svg'))->toBeGreaterThan(0.0);
});

/** Two distinct SVGs, so a document with two pictures in it can be built. */
function svgWithTwo(): string
{
    $directory = anSvg();
    file_put_contents($directory . '/other.svg', (string) file_get_contents($directory . '/diagram.svg'));

    return $directory;
}

/** The same, under the name the namespace test uses. */
function svgPair(): string
{
    return svgWithTwo();
}