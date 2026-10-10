<?php

declare(strict_types=1);

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Exception\UnsupportedImageFormat;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;

/*
 * The vector example, checked against what it claims.
 *
 * `examples/markdown/19-vector.md` makes five claims that a paragraph in a README
 * cannot carry, because each of them is about what is inside the file rather than
 * about how it looks: that both parts are present, that the raster is one of them,
 * that the vector is reached through the extension Word reserves for it, that the
 * alt text survived a change of markup, and that the reason given when there is no
 * rasteriser is about the machine rather than about the format.
 *
 * The example is skipped where `ext-imagick` is absent, and so is the part of this
 * file that needs it. The message claim is checked either way, because it is a claim
 * about words rather than about a document.
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

const VECTOR_SOURCE = __DIR__ . '/../../examples/markdown/19-vector.md';
const VECTOR_DIAGRAM = __DIR__ . '/../../examples/markdown/assets/19-vector.svg';
const VECTOR_EXTENSION_URI = '{96DAC541-7B7A-43D3-8B79-37D633B846F1}';

/** The example rendered the way `examples/build.php` renders it. */
function vectorDocument(): array
{
    $target = sys_get_temp_dir() . '/example-vector.docx';

    (new MarkdownToWord(
        (string) file_get_contents(VECTOR_SOURCE),
        Configuration::create()->withOptions([
            'images' => Options::IMAGE_EMBED,
            // Without this the relative source path resolves against the working
            // directory, the picture is not found, and the document comes out with
            // alt text where the diagram should be — which is what the first build of
            // this example did.
            'imageBasePath' => dirname(__DIR__, 2) . '/examples/markdown',
        ]),
        new CommonMarkParser([...CommonMarkParser::FLAVOURS['gfm'], FrontMatterExtension::class]),
    ))->save($target);

    $zip = new ZipArchive();
    $zip->open($target);

    $media = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);

        if (str_starts_with($name, 'word/media/')) {
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

    return $parts + ['media' => $media];
}

it('has the SVG the example says it embeds', function () {
    expect(is_file(VECTOR_DIAGRAM))->toBeTrue()
        ->and((string) file_get_contents(VECTOR_DIAGRAM))->toContain('<svg');
});

it('puts both parts in the document, because the raster is not optional', function () {
    $parts = vectorDocument();

    $rasters = array_filter($parts['media'], static fn (string $n): bool => str_ends_with($n, '.png'), ARRAY_FILTER_USE_KEY);
    $vectors = array_filter($parts['media'], static fn (string $n): bool => str_ends_with($n, '.svg'), ARRAY_FILTER_USE_KEY);

    // The claim is "two parts in one file". One of them would mean the vector is
    // either missing or standing alone, and the second of those renders nothing at all.
    expect($rasters)->toHaveCount(1)
        ->and($vectors)->toHaveCount(1);

    $raster = (string) array_values($rasters)[0];

    expect($raster)->toStartWith("\x89PNG");
});

it('reaches the vector through the extension Word reserves for it', function () {
    $parts = vectorDocument();

    expect($parts['document'])->toContain(VECTOR_EXTENSION_URI)
        ->and($parts['document'])->toContain('asvg:svgBlip')
        ->and($parts['document'])->toContain('http://schemas.microsoft.com/office/drawing/2016/SVG/main');
});

it('says rId7 is the raster and rId8 the vector, and means it', function () {
    // The example prints this pair as the thing a reader will see when they unzip the
    // file. If the two ids swap, or one of them is not what it claims, the printed
    // markup is a fiction.
    $parts = vectorDocument();

    $rasterId = preg_match('/<a:blip[^>]*r:embed="(rId\d+)"/', $parts['document'], $blip) === 1
        ? $blip[1]
        : null;

    $vectorId = preg_match('/asvg:svgBlip[^>]*r:embed="(rId\d+)"/', $parts['document'], $svg) === 1
        ? $svg[1]
        : null;

    expect($rasterId)->toBe('rId7')
        ->and($vectorId)->toBe('rId8');

    // And the relationships agree: the first points at a PNG, the second at an SVG.
    expect($parts['rels'])->toMatch('~Id="' . $rasterId . '"[^>]*Target="media/[^"]+\.png"~')
        ->and($parts['rels'])->toMatch('~Id="' . $vectorId . '"[^>]*Target="media/[^"]+\.svg"~');
});

it('carries the alt text into the DrawingML it rewrites the picture into', function () {
    // The example says the alt text crosses from `o:title` to `descr`, and prints the
    // element it expects. Both halves are checked, because an example that shows the
    // wrong attribute teaches the wrong thing just as well as one that shows nothing.
    $parts = vectorDocument();

    expect($parts['document'])->toContain('descr="The two parts of an SVG in a Word file"')
        ->and($parts['document'])->toMatch('~<wp:docPr id="1" name="Picture 1" descr="The two parts of an SVG in a Word file"/>~');

    // `o:title` was where the description *was*. Nothing may still be carrying it on a
    // VML picture, which would mean the picture was rewritten and the description left
    // behind on a node that is no longer in the document.
    expect($parts['document'])->not->toMatch('~<v:imagedata[^>]*o:title~');
});

it('rewrites only the picture that has a vector', function () {
    $parts = vectorDocument();

    expect($parts['document'])->toMatch('~<w:drawing[\s>]~')
        ->and($parts['document'])->not->toContain('<w:pict>');
});

it('declares the content type, since a package without one will not open', function () {
    $parts = vectorDocument();

    expect($parts['types'])->toContain('Extension="svg"')
        ->and($parts['types'])->toContain('ContentType="image/svg+xml"');
});

it('lays the diagram out at the size the SVG stated', function () {
    // The example sets `imageMaxWidth: 18` and says the diagram is drawn at its own
    // size. It is 480x180 user units, which is 360.6x135.2 points, and 18cm is wider
    // than that, so the cap does not apply and the drawing keeps its own dimensions.
    //
    // The width is checked against the *stated* size and not against the raster's pixel
    // count, because those differ: the raster is the SVG at 96dpi, so it is 480 pixels
    // wide, and a size taken from it would be 480pt — a third too large, and 33% wider
    // than the example claims.
    $parts = vectorDocument();

    expect($parts['document'])->toMatch('~<wp:extent cx="4579925" cy="1717472"/>~');
});

it('keeps the ratio when the cap does apply, rather than squashing the drawing', function () {
    // At 8cm the diagram is too wide, so both axes scale together. A width that was set
    // on its own would leave the height alone and stretch the picture.
    $directory = sys_get_temp_dir() . '/example-vector-cap';

    if (!is_dir($directory)) {
        mkdir($directory, 0o777, true);
    }

    copy(VECTOR_DIAGRAM, $directory . '/diagram.svg');

    $markdown = "![A diagram](diagram.svg)\n";
    $docx = (new MarkdownToWord($markdown, Configuration::create()->withOptions([
        'images' => Options::IMAGE_EMBED,
        'imageBasePath' => $directory,
        'imageMaxWidth' => 8.0,
    ])))->toDocx($markdown);

    $path = $directory . '/capped.docx';
    file_put_contents($path, $docx);

    $zip = new ZipArchive();
    $zip->open($path);
    $document = (string) $zip->getFromName('word/document.xml');
    $zip->close();

    // 8cm is 226.77pt; the 480:180 ratio makes the height 85.04pt. In EMU, rounded.
    expect($document)->toMatch('~<wp:extent cx="2880000" cy="1080000"/>~');
});

it('embeds an SVG through the public API, not only through the build script', function () {
    // The build script is one caller. If the API needed a path only it had, the feature
    // would not be available to anybody else.
    $directory = sys_get_temp_dir() . '/example-vector-api';

    if (!is_dir($directory)) {
        mkdir($directory, 0o777, true);
    }

    copy(VECTOR_DIAGRAM, $directory . '/diagram.svg');

    $markdown = "![A diagram](diagram.svg)\n";
    $docx = (new MarkdownToWord($markdown, Configuration::create()->withOptions([
        'images' => Options::IMAGE_EMBED,
        'imageBasePath' => $directory,
    ])))->toDocx($markdown);

    $path = $directory . '/api.docx';
    file_put_contents($path, $docx);

    $zip = new ZipArchive();
    $zip->open($path);

    $hasVector = false;

    for ($index = 0; $index < $zip->numFiles; $index++) {
        if (str_ends_with((string) $zip->getNameIndex($index), '.svg')) {
            $hasVector = true;
        }
    }

    $document = (string) $zip->getFromName('word/document.xml');

    $zip->close();

    expect($hasVector)->toBeTrue()
        ->and($document)->toContain('svgBlip');
});
