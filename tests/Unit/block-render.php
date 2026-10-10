<?php

declare(strict_types=1);

use MarkdownWord\Configuration\Styles;
use PhpOffice\PhpWord\Element\TextRun;

it('atx heading uses the matching heading style', function () {
    $elements = renderElements("# Title\n");

    expect($elements)->toHaveCount(1);
    expect($elements[0])->toBeInstanceOf(TextRun::class);
    expect(paragraphStyleOf($elements[0])['styleName'])->toBe('Heading1');
    expect(elementTextOf($elements[0]))->toBe('Title');
});

it('each heading level has its own style', function () {
    foreach ([1, 2, 3, 4, 5, 6] as $level) {
        $elements = renderElements(str_repeat('#', $level) . " Title\n");

        expect($elements)->toHaveCount(1);
        expect(paragraphStyleOf($elements[0])['styleName'])->toBe('Heading' . $level);
    }
});

it('setext headings become headings', function () {
    expect(paragraphStyleOf(renderElements("Title\n=====\n")[0])['styleName'])->toBe('Heading1');
    expect(paragraphStyleOf(renderElements("Title\n-----\n")[0])['styleName'])->toBe('Heading2');
});

it('closing sequences are not part of the heading', function () {
    expect(elementTextOf(renderElements("## Title ##\n")[0]))->toBe('Title');
});

it('heading style is configurable', function () {
    $config = \MarkdownWord\Configuration::create()->withStyles([Styles::HEADING_1 => 'Report Title']);

    expect(renderElements("# Title\n", $config)[0]->getParagraphStyle())->toBe('Report Title');
});

it('headings deeper than the configured maximum become paragraphs', function () {
    $config = \MarkdownWord\Configuration::create()->withOptions(['maxHeadingLevel' => 2]);

    expect(paragraphStyleOf(renderElements("## Two\n", $config)[0])['styleName'])->toBe('Heading2');
    expect(paragraphStyleOf(renderElements("### Three\n", $config)[0])['styleName'] ?? null)->toBeNull();
});

it('a plain paragraph carries the body spacing and no style name', function () {
    $elements = renderElements("Just a paragraph.\n");

    // Direct formatting, so the name is absent where a heading has one — which is
    // what makes this a body paragraph rather than a heading with no style.
    expect($elements)->toHaveCount(1);
    expect(paragraphStyleOf($elements[0]))->toBe(['space' => ['after' => 120]]);
    expect(elementTextOf($elements[0]))->toBe('Just a paragraph.');
});

it('blank lines separate paragraphs', function () {
    $elements = renderElements("First.\n\nSecond.\n");

    expect($elements)->toHaveCount(2);
    expect(elementTextOf($elements[0]))->toBe('First.');
    expect(elementTextOf($elements[1]))->toBe('Second.');
});

it('thematic break becomes a bordered paragraph', function () {
    $elements = renderElements("Above\n\n---\n\nBelow\n");

    expect($elements)->toHaveCount(3);

    $rule = paragraphStyleOf($elements[1]);
    expect($rule)->toBeArray();
    expect($rule['borderBottomStyle'])->toBe('single');
});

it('thematic break can render as text', function () {
    $config = \MarkdownWord\Configuration::create()->withOptions(['thematicBreak' => 'text']);

    $elements = renderElements("Above\n\n***\n", $config);

    expect(elementTextOf($elements[1]))->toBe(str_repeat('-', 40));
});
