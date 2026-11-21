<?php

declare(strict_types=1);

use MarkdownWord\Exception\ThemeNotFound;
use MarkdownWord\Exception\UnreadableDocument;
use MarkdownWord\Files\DiskFinder;
use MarkdownWord\Filesystem\PathProbe;
use MarkdownWord\Input;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;
use MarkdownWord\Theme\ThemeResolver;

/*
 * A name that holds a null byte.
 *
 * The name ends there: there is nothing behind it for the file system to answer
 * about. PHP 8.6 says so by raising a `ValueError` from `is_file()` and its
 * neighbours, where earlier versions answered false — so a document that wrote such
 * a name was refused on 8.6 and answered on 8.5, which is the same question with two
 * answers.
 *
 * The library's promise is that a name it cannot answer for is answered rather than
 * raised: a name that walks out of its base, a name that is a URL and a name holding
 * a null byte are all names, and all three are answered. These tests hold that
 * promise on every version the library supports.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

it('does not ask the file system about a name that holds a null byte', function () {
    $name = "logo.png\0.txt";

    expect(PathProbe::isFile($name))->toBeFalse()
        ->and(PathProbe::isDirectory($name))->toBeFalse()
        ->and(PathProbe::exists($name))->toBeFalse()
        ->and(PathProbe::isLink($name))->toBeFalse()
        ->and(PathProbe::isReadable($name))->toBeFalse();
});

it('takes a name that holds a null byte as the content itself', function () {
    // Any text is Markdown, and this text is text.
    $text = "before\0after";

    expect(Input::markdown($text))->toBe($text);
});

it('reports a name that holds a null byte as the name of no document', function () {
    expect(fn () => Input::document("report\0.docx"))
        ->toThrow(UnreadableDocument::class, 'neither a Word document nor the name of one');
});

it('answers a name that holds a null byte with no file', function () {
    $base = Scratch::directory();

    $finder = new DiskFinder(theme: [$base], picture: [$base], document: [$base]);

    expect($finder->read("logo.png\0.txt", 'picture'))->toBeNull()
        ->and($finder->path("logo.png\0.txt", 'picture'))->toBeNull()
        ->and($finder->read("logo.png\0.txt", 'document'))->toBeNull()
        ->and($finder->path("logo.png\0.txt", 'document'))->toBeNull();
});

it('draws a document whose image path holds a null byte', function () {
    // The picture is not there and cannot be, so the alt text is what is drawn —
    // the same answer a picture that is missing from the disk gets.
    $path = Scratch::path('null-byte-image');

    (new MarkdownToWord("![Logo](logo.png\0.png)\n"))->save($path);

    expect(TemplateFactory::textOf($path))->toContain('Logo');
});

it('reports a theme name that holds a null byte as the name of no theme', function () {
    $resolver = new ThemeResolver(workingDirectory: Scratch::directory());

    expect(fn () => $resolver->resolve("house\0"))
        ->toThrow(ThemeNotFound::class);
});
