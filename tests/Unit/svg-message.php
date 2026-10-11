<?php

declare(strict_types=1);

use MarkdownWord\Exception\UnsupportedImageFormat;

/*
 * What the SVG refusal says.
 *
 * Checked here rather than beside the tests that embed a vector, because these are
 * claims about words and not about a document: they hold on a build that cannot
 * rasterise anything, which is exactly the build that produces the message.
 *
 * The claim being defended is a correction. An earlier version of the message said an
 * SVG "is not a format this library can put into a Word document", and listed the
 * formats Word takes as though omitting SVG settled the matter. It does not: Word has
 * held SVG since 2016. What was missing was a rasteriser, and a reader sent to check
 * the format would have found the format was never the problem.
 */

it('names the rasteriser, which is what is actually missing', function () {
    $message = UnsupportedImageFormat::svg('/tmp/diagram.svg')->getMessage();

    expect($message)->toContain('Word can hold an SVG')
        ->and($message)->toContain('rasteriser')
        ->and($message)->toContain('ext-imagick');
});

it('does not blame the format, because the format is not the problem', function () {
    expect(UnsupportedImageFormat::svg('/tmp/diagram.svg')->getMessage())
        ->not->toContain('is not a format');
});

it('says the two words that make it clear Word does support SVG', function () {
    // A reader who skims the first clause should already know the format is supported
    // and the machine is the problem, without reaching the second sentence.
    $message = UnsupportedImageFormat::svg('/tmp/diagram.svg')->getMessage();

    expect($message)->toStartWith('Cannot embed "/tmp/diagram.svg": Word can hold an SVG');
});

it('offers the two ways out, because refusing without a next step is a dead end', function () {
    $message = UnsupportedImageFormat::svg('/tmp/diagram.svg')->getMessage();

    expect($message)->toContain('Install ext-imagick')
        ->and($message)->toContain('convert the file to PNG yourself');
});

it('distinguishes a missing rasteriser from a file the rasteriser could not draw', function () {
    // Two different problems with two different fixes: install the extension, or fix
    // the file. Collapsing them into one message sends half the readers to do the
    // wrong thing.
    $noRasteriser = UnsupportedImageFormat::svg('/tmp/diagram.svg')->getMessage();
    $unreadable = UnsupportedImageFormat::svg('/tmp/diagram.svg', 'this PHP could not read it as one')->getMessage();

    expect($noRasteriser)->toContain('no SVG rasteriser')
        ->and($unreadable)->toContain('could not read it as one')
        ->and($unreadable)->not->toContain('no SVG rasteriser');
});

it('still says what is wrong about a format that really is one', function () {
    // The correction is about SVG alone. A format Word cannot take and nothing here
    // can decode is still reported as a format, because that is what it is.
    $message = UnsupportedImageFormat::for('/tmp/photo.heic', 'HEIC')->getMessage();

    expect($message)->toContain('is not a format this library can write')
        ->and($message)->toContain('JPEG, PNG, GIF, BMP and TIFF');
});

it('keeps the two messages distinguishable by type as well as by words', function () {
    // One catch covers both, so a caller can handle an unusable image without caring
    // which of the two reasons it was unusable.
    expect(UnsupportedImageFormat::svg('/tmp/a.svg'))->toBeInstanceOf(UnsupportedImageFormat::class)
        ->and(UnsupportedImageFormat::for('/tmp/a.heic', 'HEIC'))->toBeInstanceOf(UnsupportedImageFormat::class)
        ->and(UnsupportedImageFormat::svg('/tmp/a.svg'))->toBeInstanceOf(MarkdownWord\Exception\InvalidInput::class);
});