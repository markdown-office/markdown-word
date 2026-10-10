<?php

declare(strict_types=1);

use MarkdownWord\Format;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\Upstream;

/*
 * What an SVG becomes in each of the three formats.
 *
 * A vector is the one construct where the two newer formats lose something the
 * reader cannot see without opening the file at a large zoom, so the claim is
 * checked against the archive rather than against how the document looks.
 *
 * Every test here needs something to rasterise, and the suite runs on a build with
 * imagick and on one without, so the file skips itself rather than each test
 * carrying a mark that would be wrong on the build that has the extension.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

uses()->beforeEach(function (): void {
    if (!\extension_loaded('imagick')) {
        test()->markTestSkipped('ext-imagick is not loaded, so no SVG can be embedded');
    }
});

const FORMAT_VECTOR_DIAGRAM = __DIR__ . '/../../examples/markdown/assets/19-vector.svg';

/**
 * One SVG converted to one format, and the media parts of the result.
 *
 * @return list<string>
 */
function formatVectorParts(Format $format): array
{
    $markdown = "![A diagram](19-vector.svg)\n";

    $converter = new MarkdownToWord($markdown, overrides: ['options' => [
        'imageBasePath' => dirname(FORMAT_VECTOR_DIAGRAM),
    ]]);

    $path = Scratch::path('vector', $format->extension());
    $converter->convertTo($format, $path);

    if ($format === Format::Rtf) {
        // RTF is not a zip: the picture is hex in the body, and the only question
        // worth asking of it is whether a vector went in beside the raster.
        return str_contains((string) file_get_contents($path), '\\svgblip') ? ['svg'] : ['png'];
    }

    $zip = new ZipArchive();
    $zip->open($path);
    $names = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $names[] = (string) $zip->getNameIndex($index);
    }

    $zip->close();

    return array_values(array_filter(
        $names,
        static fn (string $name): bool => (bool) preg_match('#(media|Pictures)/#', $name),
    ));
}

it('puts the vector beside the raster in a .docx', function () {
    // The claim the other two are measured against: both parts, because a picture
    // carrying a vector with no raster beside it renders nothing at all.
    expect(formatVectorParts(Format::Docx))->toHaveCount(2);
});

it('flattens the vector into the raster in an .odt, and says so', function () {
    expect(formatVectorParts(Format::Odt))->toHaveCount(1);

    $markdown = "![A diagram](19-vector.svg)\n";
    $converter = new MarkdownToWord($markdown, overrides: ['options' => [
        'imageBasePath' => dirname(FORMAT_VECTOR_DIAGRAM),
    ]]);
    $converter->convertTo(Format::Odt, Scratch::path('survey', '.odt'));

    expect(array_column($converter->pendingLosses(), 'feature'))->toContain('svg-vector');
});

it('flattens the vector into the raster in an .rtf, and says so', function () {
    expect(formatVectorParts(Format::Rtf))->toBe(['png']);

    $markdown = "![A diagram](19-vector.svg)\n";
    $converter = new MarkdownToWord($markdown, overrides: ['options' => [
        'imageBasePath' => dirname(FORMAT_VECTOR_DIAGRAM),
    ]]);
    $converter->convertTo(Format::Rtf, Scratch::path('survey', '.rtf'));

    expect(array_column($converter->pendingLosses(), 'feature'))->toContain('svg-vector');
});