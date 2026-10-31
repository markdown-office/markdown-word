<?php

declare(strict_types=1);

use MarkdownWord\Exception\UnreadableTemplate;
use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\Template\TemplateOptions;
use MarkdownWord\Template\TemplatePackage;
use MarkdownWord\Tests\Support\Scratch;

/*
 * Every test here builds an archive that is too much of something, and asserts that
 * it is refused rather than read.
 */
it('refuses a template that holds more parts than are allowed', function () {
    $path = templateWithManyParts(20);

    expect(fn () => TemplatePackage::of($path, (new TemplateOptions())->withMaxEntries(10)))
        ->toThrow(UnreadableTemplate::class, 'more entries than the 10 allowed');
});

it('refuses a template that holds a part larger than is allowed', function () {
    $path = templateWithABigPart(1024 * 1024);

    expect(fn () => TemplatePackage::of($path, (new TemplateOptions())->withMaxPartBytes(1024)))
        ->toThrow(UnreadableTemplate::class, 'larger than the 1024 allowed');
});

it('says how many parts a template holds when it refuses it', function () {
    $path = templateWithManyParts(20);

    // The number the message carries is the archive's own: a message that says zero
    // sends the reader looking for the wrong thing.
    $zip = new ZipArchive();
    $zip->open($path);
    $entries = $zip->numFiles;
    $zip->close();

    expect(fn () => TemplatePackage::of($path, new TemplateOptions(maxEntries: 10)))
        ->toThrow(UnreadableTemplate::class, sprintf('has %d parts in it', $entries));
});

it('reads a template that is within the limits', function () {
    // The other side of the guard: a limit that refuses everything refuses the
    // templates that are fine.
    $path = templateWithManyParts(20);

    expect(fn () => TemplatePackage::of($path, new TemplateOptions(maxEntries: 100, maxPartBytes: 1024 * 1024)))
        ->not->toThrow(UnreadableTemplate::class);
});

it('refuses a template that is too much through the template itself', function () {
    $path = templateWithABigPart(1024 * 1024);

    expect(fn () => new MarkdownTemplate($path, limits: new TemplateOptions(maxPartBytes: 1024)))
        ->toThrow(UnreadableTemplate::class);
});

/**
 * The declarations of a package whose body is a template.
 */
function templateDeclarations(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="'
        . 'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml"/>'
        . '</Types>';
}

/**
 * A package with the given number of parts beyond the ones it needs.
 */
function templateWithManyParts(int $parts): string
{
    $path = Scratch::path('many-parts', '.dotx');

    file_put_contents($path, '');

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', templateDeclarations());
    $zip->addFromString(
        'word/document.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>',
    );

    for ($index = 0; $index < $parts; $index++) {
        $zip->addFromString('word/media/image' . $index . '.txt', 'x');
    }

    $zip->close();

    return $path;
}

/**
 * A package with one part of the given size.
 */
function templateWithABigPart(int $bytes): string
{
    $path = Scratch::path('big-part', '.dotx');

    file_put_contents($path, '');

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', templateDeclarations());
    $zip->addFromString(
        'word/document.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>',
    );
    $zip->addFromString('word/media/big.bin', str_repeat(' ', $bytes));

    $zip->close();

    return $path;
}
