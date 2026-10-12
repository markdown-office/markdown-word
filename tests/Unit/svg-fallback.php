<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Exception\UnsupportedImageFormat;
use MarkdownWord\Format;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Render\SvgRasteriser;
use MarkdownWord\Tests\Support\Upstream;

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/*
 * `svgFallback`, and the pairing that has to work whether or not a raster is written.
 *
 * Deliberately not in `svg.php`, which skips itself without ext-imagick: the whole point
 * of `svgFallback: none` is that a build without a rasteriser can still write the vector,
 * and a test that skips itself on such a build cannot say so.
 */

/** Write an SVG into a directory of its own and return the directory. */
function svgBase(string $name, string $fill = '#1b3a5c'): string
{
    $directory = sys_get_temp_dir() . '/mdword-fallback-' . bin2hex(random_bytes(4));

    if (!is_dir($directory)) {
        mkdir($directory, 0o777, true);
    }

    writeSvg($directory . '/' . $name, $fill);

    return $directory;
}

function writeSvg(string $path, string $fill, string $note = ''): void
{
    file_put_contents($path, sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="120" viewBox="0 0 240 120">'
        . '<!-- %s -->'
        . '<rect width="240" height="120" fill="%s"/></svg>',
        $note,
        $fill,
    ));
}

/**
 * @return array{document: string, rels: string, media: array<string, string>, names: list<string>}
 */
function renderFallback(string $markdown, string $basePath, string $mode): array
{
    $target = sys_get_temp_dir() . '/mdword-fallback-out.docx';

    (new MarkdownToWord($markdown, Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $basePath,
        'svgFallback' => $mode,
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
    ];

    $zip->close();
    @unlink($target);

    return $parts + ['media' => $media, 'names' => $names];
}

/** The body part of a document held in memory. */
function bodyOf(string $bytes): string
{
    $path = sys_get_temp_dir() . '/mdword-fallback-body.docx';
    file_put_contents($path, $bytes);

    $zip = new ZipArchive();
    $zip->open($path);
    $body = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    @unlink($path);

    return $body;
}

/** @return list<string> */
function mediaNamed(array $media, string $extension): array
{
    return array_values(array_filter(
        array_keys($media),
        static fn (string $name): bool => str_ends_with($name, $extension),
    ));
}

it('writes a raster beside the vector by default, when this build can draw one', function () {
    if (!SvgRasteriser::available()) {
        test()->markTestSkipped('this build cannot rasterise an SVG, so there is no raster to write');
    }

    $base = svgBase('diagram.svg');

    $parts = renderFallback("![A](diagram.svg)\n", $base, 'raster');

    expect(mediaNamed($parts['media'], '.png'))->toHaveCount(1)
        ->and(mediaNamed($parts['media'], '.svg'))->toHaveCount(1)
        ->and($parts['document'])->toContain('<a:blip r:embed=');

    @unlink($base . '/diagram.svg');
    @rmdir($base);
});

it('falls back to the vector alone, and says so, on a build that cannot rasterise', function () {
    // The alternative was refusing the conversion, which loses the whole document over a
    // picture. A document whose readers may miss one image beats no document, and
    // `pendingLosses()` is where the cost is stated rather than left to be discovered.
    if (SvgRasteriser::available()) {
        test()->markTestSkipped('this build can rasterise an SVG, so nothing is lost');
    }

    $base = svgBase('diagram.svg');

    $converter = new MarkdownToWord("![A](diagram.svg)\n", Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $base,
        // Asked for explicitly, so that a degrade here is the answer to a real request
        // rather than to the default.
        'svgFallback' => 'raster',
    ]));
    $converter->toDocx("![A](diagram.svg)\n");

    $losses = implode("\n", array_map(strval(...), $converter->pendingLosses()));

    expect($losses)->toContain('the vector was written with no raster beside it');

    @unlink($base . '/diagram.svg');
    @rmdir($base);
});

it('writes the vector on its own when asked, and leaves no raster behind', function () {
    $base = svgBase('diagram.svg');

    $parts = renderFallback("![A](diagram.svg)\n", $base, 'none');

    // The `a:blip` carries the extension and nothing else. `r:embed` is optional in
    // `CT_Blip`, and this is the shape Word draws a vector as when nobody asked for a
    // fallback: Word 2016 and later render it, and every other reader renders nothing.
    expect($parts['document'])->toContain('<a:blip><a:extLst>')
        ->and($parts['document'])->not->toContain('<a:blip r:embed')
        ->and(mediaNamed($parts['media'], '.svg'))->toHaveCount(1)
        ->and(mediaNamed($parts['media'], '.png'))->toHaveCount(0);

    @unlink($base . '/diagram.svg');
    @rmdir($base);
});

it('gives each vector its own part, referenced by the picture it belongs to', function () {
    $base = svgBase('one.svg');
    writeSvg($base . '/two.svg', '#7c2d12');

    $parts = renderFallback("![One](one.svg)\n\n![Two](two.svg)\n", $base, 'raster');

    $written = mediaNamed($parts['media'], '.svg');
    preg_match_all('~<asvg:svgBlip r:embed="(rId\d+)"~', $parts['document'], $references);

    expect($written)->toHaveCount(2)
        ->and($written[0])->not->toBe($written[1])
        // Two pictures, two relationships, two parts: neither can be showing the other.
        ->and(array_unique($references[1]))->toHaveCount(2)
        ->and($parts['media'][$written[0]])->not->toBe($parts['media'][$written[1]]);

    @unlink($base . '/one.svg');
    @unlink($base . '/two.svg');
    @rmdir($base);
});

it('attaches only this document\'s vectors to it', function () {
    // The collector outlives a single document, as the description collector does, and a
    // vector carries the position of its picture. A second document handed the first
    // one's would be given a vector it never mentioned, on whatever picture happened to
    // be at that index — which for a document of plain images is the first of them.
    $base = svgBase('diagram.svg');
    imagepng(imagecreatetruecolor(40, 30), $base . '/photo.png');

    $converter = new MarkdownToWord(null, Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $base,
        'svgFallback' => 'none',
    ]));

    $converter->toDocx("![Vector](diagram.svg)\n");
    $afterPlain = bodyOf($converter->toDocx("Just a photo.\n\n![Photo](photo.png)\n"));
    $afterMixed = bodyOf($converter->toDocx("![Vector](diagram.svg)\n\n![Photo](photo.png)\n"));

    // The document with no SVG in it is left as PHPWord wrote it, and the one after it
    // keeps its vector on the vector rather than losing it to the picture beside it.
    expect(substr_count($afterPlain, 'svgBlip'))->toBe(0)
        ->and(substr_count($afterPlain, '<w:drawing>'))->toBe(0)
        ->and(substr_count($afterMixed, 'svgBlip'))->toBe(1)
        ->and(substr_count($afterMixed, '<w:drawing>'))->toBe(1)
        ->and(substr_count($afterMixed, '<w:pict>'))->toBe(1);

    @unlink($base . '/diagram.svg');
    @unlink($base . '/photo.png');
    @rmdir($base);
});

it('gives a format that drops the vector a raster, or refuses rather than a blank', function () {
    // `.odt` and `.rtf` never run the pass that attaches a vector, so whatever PHPWord
    // was handed is all a reader will ever draw. A placeholder there is not a fallback
    // for the vector — it *is* the picture, and it is one transparent pixel.
    $base = svgBase('diagram.svg');

    $converter = new MarkdownToWord(null, Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $base,
        'svgFallback' => 'none',
    ]));

    foreach ([Format::Odt, Format::Rtf] as $format) {
        if (SvgRasteriser::available()) {
            $bytes = $converter->to($format, "![A](diagram.svg)\n");

            expect(strlen($bytes))->toBeGreaterThan(200);

            continue;
        }

        // Without a rasteriser the only honest answer is to refuse, which is what the
        // library did before there was a placeholder to write by mistake.
        expect(fn () => $converter->to($format, "![A](diagram.svg)\n"))
            ->toThrow(UnsupportedImageFormat::class);
    }

    @unlink($base . '/diagram.svg');
    @rmdir($base);
});

it('finds its picture among the ordinary images rather than only among the vectors', function () {
    // The pairing is the picture's position in the document, not a digest of its bytes,
    // so a document whose first picture is a PNG has to put the vector on the second.
    $base = svgBase('diagram.svg');
    imagepng(imagecreatetruecolor(40, 30), $base . '/photo.png');

    $parts = renderFallback("![Photo](photo.png)\n\n![Vector](diagram.svg)\n", $base, 'none');

    expect(mediaNamed($parts['media'], '.svg'))->toHaveCount(1)
        ->and(mediaNamed($parts['media'], '.png'))->toHaveCount(1)
        ->and($parts['document'])->toContain('<asvg:svgBlip');

    @unlink($base . '/diagram.svg');
    @unlink($base . '/photo.png');
    @rmdir($base);
});
