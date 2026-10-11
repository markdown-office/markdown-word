<?php

declare(strict_types=1);

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

/*
 * The image examples, checked against what they claim to be.
 *
 * `examples/README.md` says three things about `15-images-*` that are worth more as
 * a document than as a paragraph: that `imageBasePath` is what makes a relative path
 * resolve, that `imageMaxWidth` changes the size of the picture in the document, and
 * that the three `images` modes are three different documents from one source. It
 * also says a WebP is embedded rather than dropped, which was not true when this
 * file was written.
 *
 * The sources are rendered rather than the built documents read: the `.docx` in
 * `examples/out/` is a build artefact and is not in the repository.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/** The configuration `build.php` builds the image examples with. */
function imageExampleConfiguration(string $mode): Configuration
{
    return Configuration::create()->withOptions([
        'images' => $mode,
        'imageBasePath' => dirname(__DIR__, 2) . '/examples/markdown',
    ]);
}

/**
 * One of the image examples, rendered from its committed source the way
 * `examples/build.php` renders it.
 */
function imageExample(string $mode): array
{
    $markdown = (string) file_get_contents(dirname(__DIR__, 2) . '/examples/markdown/15-images.md');

    $converter = new MarkdownToWord(
        $markdown,
        imageExampleConfiguration($mode),
        new CommonMarkParser([...CommonMarkParser::FLAVOURS['gfm'], FrontMatterExtension::class]),
    );

    $target = sys_get_temp_dir() . '/example-images-' . $mode . '.docx';
    $converter->save($target);

    $zip = new ZipArchive();
    $zip->open($target);

    $media = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);

        if (str_starts_with($name, 'word/media/')) {
            $media[$name] = (string) $zip->getFromIndex($index);
        }
    }

    $xml = (string) $zip->getFromName('word/document.xml');
    $text = TemplateFactory::textOf($target);
    $zip->close();
    unlink($target);

    preg_match_all('#<v:shape[^>]*style="width:([\d.]+)pt; height:([\d.]+)pt#', $xml, $widths);

    return [
        'media' => $media,
        'xml' => $xml,
        'text' => $text,
        'widths' => $widths[1],
        'conversions' => $converter->pendingImageConversions(),
    ];
}

it('embeds both pictures, which is what imageBasePath is for', function () {
    // The Markdown says `assets/logo.png` and nothing about where that is. Without
    // the base path both resolve to nothing and the document falls back to the alt
    // text — which looks like a renderer that ignored the pictures.
    $example = imageExample(Options::IMAGE_EMBED);

    expect(array_keys($example['media']))->toHaveCount(2)
        ->and(array_key_first($example['media']))->toEndWith('.png')
        ->and($example['text'])->not->toContain('The red square generated for the examples');
});

it('embeds nothing at all without the base path, and says so with the alt text', function () {
    $markdown = (string) file_get_contents(dirname(__DIR__, 2) . '/examples/markdown/15-images.md');

    $converter = new MarkdownToWord(
        $markdown,
        Configuration::create()->withOptions(['images' => Options::IMAGE_EMBED]),
        new CommonMarkParser([...CommonMarkParser::FLAVOURS['gfm'], FrontMatterExtension::class]),
    );

    $target = sys_get_temp_dir() . '/example-images-no-base.docx';
    $converter->save($target);

    $xml = TemplateFactory::xmlOf($target);
    $text = TemplateFactory::textOf($target);
    unlink($target);

    expect($xml)->not->toContain('<w:pict>')
        ->and($text)->toContain('The red square generated for the examples');
});

it('writes the WebP into the document as a PNG, and records having done so', function () {
    // The claim the page makes and the bug behind it: PHPWord has never supported
    // WebP, the refusal used to be caught, and the document came out looking
    // finished with the alt text where the photograph should have been.
    $example = imageExample(Options::IMAGE_EMBED);

    expect($example['conversions'])->toHaveCount(1)
        ->and($example['conversions'][0]['format'])->toBe('image/webp')
        ->and($example['conversions'][0]['embeddedAs'])->toBe('PNG')
        ->and($example['conversions'][0]['source'])->toEndWith('assets/test_landscape.webp')
        ->and($example['text'])->not->toContain('A photograph, converted on the way in (');

    // The picture in the document is the re-encoded one rather than the original
    // bytes under another name, which is what makes it a PNG.
    foreach (array_keys($example['media']) as $name) {
        expect($name)->not->toEndWith('.webp');
    }

    expect($example['media'])->not->toBeEmpty();
});

it('scales the photograph to imageMaxWidth from the block', function () {
    // `imageMaxWidth: 8.0` is in the block. The photograph is 1600pt wide as it
    // comes out of `getimagesize()` and is written at 8cm = 226.8pt instead; the
    // 96pt square is narrower than the cap and keeps its own size, because the
    // option is a maximum rather than a target.
    $example = imageExample(Options::IMAGE_EMBED);

    expect($example['widths'])->toHaveCount(2)
        ->and((float) $example['widths'][0])->toEqualWithDelta(96.0, 0.1)
        ->and((float) $example['widths'][1])->toEqualWithDelta(226.77, 0.1);
});

it('writes the alt text and the path in placeholder mode', function () {
    $example = imageExample(Options::IMAGE_PLACEHOLDER);

    expect($example['media'])->toBe([])
        ->and($example['text'])->toContain('The red square generated for the examples (assets/logo.png)')
        ->and($example['text'])->toContain('A photograph, converted on the way in (assets/test_landscape.webp)')
        ->and($example['xml'])->not->toContain('<w:pict>');
});

it('writes neither the picture nor its alt text in skip mode', function () {
    // The third mode is a caption-less document, which is what it is for: a picture
    // that must not travel with the file says nothing at all. The paths themselves
    // are still in the page — the prose and the table talk about them — so it is the
    // captions that have to be gone.
    $example = imageExample(Options::IMAGE_SKIP);

    expect($example['media'])->toBe([])
        ->and($example['text'])->not->toContain('The red square generated for the examples')
        ->and($example['text'])->not->toContain('A photograph, converted on the way in')
        ->and($example['xml'])->not->toContain('<w:pict>');
});

it('puts the WebP in the links and images example as well', function () {
    // `05` used to show only the generated square, which made a document about
    // images look like it had never seen one.
    $markdown = (string) file_get_contents(dirname(__DIR__, 2) . '/examples/markdown/05-links-and-images.md');

    $converter = new MarkdownToWord($markdown, imageExampleConfiguration(Options::IMAGE_EMBED));

    $target = sys_get_temp_dir() . '/example-05.docx';
    $converter->save($target);

    $xml = TemplateFactory::xmlOf($target);

    expect($converter->pendingImageConversions())->toHaveCount(1)
        ->and($converter->pendingImageConversions()[0]['source'])->toEndWith('test_landscape.webp')
        ->and($xml)->toContain('<w:pict>')
        // The 1600pt photograph under the default 15cm cap, so it is scaled rather
        // than left wider than the page.
        ->and(preg_match('#<v:shape[^>]*style="width:(425\.\d+)pt#', $xml))->toBe(1);

    unlink($target);
});