<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use PhpOffice\PhpWord\Element\ListItemRun;

it('bullet items become list items', function () {
    $elements = renderElements("- one\n- two\n- three\n");

    expect($elements)->toHaveCount(3);
    expect($elements)->each->toBeInstanceOf(ListItemRun::class);
    expect(array_map(
        fn ($e): string => elementTextOf($e),
        $elements,
    ))->toBe(['one', 'two', 'three']);
});

it('bullets and numbers use different numbering styles', function () {
    $bullets = renderElements("- one\n")[0];
    $numbers = renderElements("1. one\n")[0];

    expect($bullets)->toBeInstanceOf(ListItemRun::class);
    expect($numbers)->toBeInstanceOf(ListItemRun::class);
    expect(numberingStyle($numbers))->not->toBe(numberingStyle($bullets));
});

it('inline formatting survives inside list items', function () {
    $elements = renderElements("- **bold** and *italic*\n");

    $runs = renderRuns($elements[0]);

    expect($runs[0]['text'])->toBe('bold');
    expect($runs[0]['font']['bold'] ?? false)->toBeTrue();
    expect($runs[2]['text'])->toBe('italic');
    expect($runs[2]['font']['italic'] ?? false)->toBeTrue();
});

it('nested lists increase the word list depth', function () {
    $elements = renderElements("- one\n  - nested\n- two\n");

    expect($elements)->toHaveCount(3);
    expect($elements[0]->getDepth())->toBe(0);
    expect($elements[1]->getDepth())->toBe(1);
    expect($elements[2]->getDepth())->toBe(0);
    expect(elementTextOf($elements[1]))->toBe('nested');
});

it('three levels of nesting', function () {
    $elements = renderElements("- a\n  - b\n    - c\n");

    expect(array_map(
        static fn (ListItemRun $e): int => $e->getDepth(),
        $elements,
    ))->toBe([0, 1, 2]);
});

it('ordered list starting at five gets its own numbering', function () {
    $one = renderElements("1. a\n")[0];
    $five = renderElements("5. a\n")[0];

    expect(numberingStyle($five))->not->toBe(numberingStyle($one));
});

it('consecutive lists with the same start share a numbering style', function () {
    $first = renderElements("1. a\n\ntext\n\n1. b\n")[0];
    $second = renderElements("1. b\n")[0];

    expect(numberingStyle($second))->toBe(numberingStyle($first));
});

it('parenthesis delimiter is distinct from full stop', function () {
    $dot = renderElements("1. a\n")[0];
    $paren = renderElements("1) a\n")[0];

    expect(numberingStyle($paren))->not->toBe(numberingStyle($dot));
});

it('list items can contain multiple paragraphs', function () {
    $elements = renderElements("- first\n\n  second\n");

    expect(array_map(
        fn ($e): string => elementTextOf($e),
        $elements,
    ))->toBe(['first', 'second']);
});

it('tight lists have no extra spacing', function () {
    $tight = paragraphStyleOf(renderElements("- one\n- two\n")[0]);
    $loose = paragraphStyleOf(renderElements("- one\n\n- two\n")[0]);

    expect($tight['space']['before'] ?? null)->toBe(0);
    expect($tight['space']['after'] ?? null)->toBe(0);
    // A loose list keeps the list slot's own spacing rather than being flattened.
    expect($loose['space']['after'] ?? null)->toBe(60);
});

it('empty list item still renders a bullet', function () {
    $elements = renderElements("-\n- b\n");

    expect($elements)->toHaveCount(2);
    expect(elementTextOf($elements[0]))->toBe('');
});

it('list inside a block quote is still a list', function () {
    $elements = renderElements("> - a\n> - b\n");

    expect($elements)->each->toBeInstanceOf(ListItemRun::class);
    expect(array_map(fn ($e): string => elementTextOf($e), $elements))->toBe(['a', 'b']);
});

it('ordered list format is configurable', function () {
    $config = Configuration::create()->withOptions(['orderedListFormat' => 'lowerRoman']);

    convertTo("1. a\n", $config);

    // The numbering definitions live in PHPWord's static style registry.
    $levels = \PhpOffice\PhpWord\Style::getStyle('MarkdownWord-Ordered')->getLevels();

    expect($levels[0]->getFormat())->toBe('lowerRoman');
});

it('default ordered list is decimal with a full stop', function () {
    convertTo("1. a\n");

    $levels = \PhpOffice\PhpWord\Style::getStyle('MarkdownWord-Ordered')->getLevels();

    expect($levels[0]->getFormat())->toBe('decimal');
    expect($levels[0]->getText())->toBe('%1.');
});

it('parenthesis list encodes the delimiter in the numbering text', function () {
    convertTo("1) a\n");

    expect(\PhpOffice\PhpWord\Style::getStyle('MarkdownWord-Ordered-1')->getLevels()[0]->getText())->toBe('%1)');
});

it('custom start is written into the numbering level', function () {
    convertTo("7. a\n");

    expect(\PhpOffice\PhpWord\Style::getStyle('MarkdownWord-Ordered-1')->getLevels()[0]->getStart())->toBe(7);
});

function numberingStyle(ListItemRun $item) : ?string
{
        return $item->getStyle()?->getNumStyle();
    }
