<?php

declare(strict_types=1);

use MarkdownWord\Configuration\Options;
use MarkdownWord\Files\DiskFinder;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Render\ImageDescriptionCollector;
use MarkdownWord\Render\ImageResolver;
use MarkdownWord\Tests\Support\MemoryFinder;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;
use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\PhpWord;

/*
 * A picture a document names comes from the finder.
 *
 * A source that holds a picture as a file gives a path, and the picture is drawn
 * where it is. A source that holds the bytes gives no path, and the bytes are
 * staged into a file of the library's own — and given to PHPWord as they are, so
 * that nothing is left on disk and nothing has to survive the write.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * The media parts of a document, keyed by name.
 *
 * @return array<string, string>
 */
function pictureParts(string $docx): array
{
    $media = [];

    foreach (documentParts($docx) as $name => $contents) {
        if (str_starts_with($name, 'word/media/')) {
            $media[$name] = $contents;
        }
    }

    return $media;
}

it('draws a picture the source holds as a file', function () {
    $directory = Scratch::directory() . '/pictures';
    mkdir($directory, 0o777, true);
    copy(Scratch::image(), $directory . '/logo.png');

    $converter = new MarkdownToWord(
        null,
        files: new DiskFinder(picture: [$directory]),
    );

    $media = pictureParts($converter->toDocx("![A logo](logo.png)\n"));

    expect($media)->toHaveCount(1)
        ->and(array_key_first($media))->toEndWith('.png');
});

it('draws a picture the source holds as bytes', function () {
    // The source the finder exists for: a plugin that keeps its pictures in a
    // database and never had a directory to hand over.
    $finder = new MemoryFinder(['logo.png' => (string) file_get_contents(Scratch::image())]);

    $converter = new MarkdownToWord(null, files: $finder);

    $media = pictureParts($converter->toDocx("![A logo](logo.png)\n"));

    expect($media)->toHaveCount(1)
        // And the picture is the one that went in, rather than a placeholder.
        ->and(array_key_first($media))->toEndWith('.png')
        ->and(array_values($media)[0])->toStartWith("\x89PNG");
});

it('leaves nothing behind when it stages a picture', function () {
    $before = glob(sys_get_temp_dir() . '/mdword_pic_*') ?: [];

    $finder = new MemoryFinder(['logo.png' => (string) file_get_contents(Scratch::image())]);

    $media = pictureParts((new MarkdownToWord(null, files: $finder))->toDocx("![A logo](logo.png)\n"));

    // The picture was drawn, so it was staged — and the staged file is gone.
    expect($media)->toHaveCount(1)
        ->and(glob(sys_get_temp_dir() . '/mdword_pic_*') ?: [])->toBe($before);
});

it('still falls back to the alt text when the source has no picture', function () {
    // A file that is not there is a document that has to render, and it always has:
    // the alt text stands in rather than the picture being missing silently. The
    // source is asked all the same — a picture it does not have is not a picture the
    // document may not ask for.
    $finder = new MemoryFinder();

    $converter = new MarkdownToWord(null, files: $finder);

    $docx = $converter->toDocx("![A logo](logo.png)\n");
    $path = Scratch::path('picture-finder');
    file_put_contents($path, $docx);

    expect($finder->asked())->toContain('logo.png')
        ->and(pictureParts($docx))->toBe([])
        ->and(TemplateFactory::textOf($path))->toContain('A logo');
});

it('draws a picture whose name is not a plain name', function () {
    // A NUL in a name is not reachable from a document — CommonMark percent-encodes
    // it — but `drawFile()` is public, and the name used to reach `rename()`, which
    // refuses a path that carries one and says nothing about the picture.
    $name = "logo.png\0";
    $finder = new MemoryFinder([$name => (string) file_get_contents(Scratch::image())]);

    $resolver = new ImageResolver(new Options(), new ImageDescriptionCollector(), files: $finder);

    $phpWord = new PhpWord();
    $section = $phpWord->addSection();

    $resolver->drawFile($name, $section);

    $elements = $section->getElements();

    expect($elements)->toHaveCount(1)
        ->and($elements[0])->toBeInstanceOf(Image::class);
});
