<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;

it('fenced code becomes one paragraph per line', function () {
    $elements = renderElements("```\nline one\nline two\n```\n");

    expect($elements)->toHaveCount(2);
    expect(elementTextOf($elements[0]))->toBe('line one');
    expect(elementTextOf($elements[1]))->toBe('line two');
});

it('indented code becomes a code block', function () {
    $elements = renderElements("    indented\n");

    expect($elements)->toHaveCount(1);
    expect(elementTextOf($elements[0]))->toBe('indented');
});

it('code uses the code font', function () {
    $runs = renderRuns(renderElements("```\nx = 1\n```\n")[0]);

    expect($runs[0]['font']['name'] ?? null)->toBe('Consolas');
});

it('markdown inside code is not interpreted', function () {
    expect(renderText("```\n# not a heading\n**not bold**\n```\n"))->toBe("# not a heading\n**not bold**");
});

it('leading whitespace inside code is preserved', function () {
    $elements = renderElements("```\n    four spaces\n```\n");

    expect(elementTextOf($elements[0]))->toBe('    four spaces');
});

it('blank lines inside code are kept', function () {
    $elements = renderElements("```\na\n\nb\n```\n");

    expect($elements)->toHaveCount(3);
    expect(elementTextOf($elements[1]))->toBe('');
});

it('code block has light shading by default', function () {
    $style = paragraphStyleOf(renderElements("```\nx\n```\n")[0]);

    expect($style['shading']['fill'] ?? null)->toBe('F2F2F2');
});

it('code block shading can be disabled', function () {
    $config = Configuration::create()->withOptions(['codeBlockShading' => false]);

    expect(paragraphStyleOf(renderElements("```\nx\n```\n", $config)[0]) ?? [])->not->toHaveKey('shading');
});

it('code block style is configurable', function () {
    $config = Configuration::create()->withStyles([Styles::CODE_BLOCK => 'Source Code']);

    expect(paragraphStyleOf(renderElements("```\nx\n```\n", $config)[0]))->toBe('Source Code');
});

it('code font is configurable', function () {
    $config = Configuration::create()->withStyles([
        Styles::CODE_FONT => ['name' => 'Fira Code', 'size' => 11],
    ]);

    $runs = renderRuns(renderElements("```\nx\n```\n", $config)[0]);

    expect($runs[0]['font']['name'] ?? null)->toBe('Fira Code');
    expect($runs[0]['font']['size'] ?? null)->toBe(11);
});

it('block quote is indented', function () {
    // The default quote style is a named Word style, so the indentation comes
    // from an explicit inline style instead.
    $config = Configuration::create()->withStyles([Styles::BLOCK_QUOTE => null]);
    $style = paragraphStyleOf(renderElements("> quoted\n", $config)[0]);

    expect($style['indentation']['left'] ?? null)->toBe(720);
});

it('nested block quotes indent further', function () {
    $config = Configuration::create()->withStyles([Styles::BLOCK_QUOTE => null]);
    $style = paragraphStyleOf(renderElements(">> deeper\n", $config)[0]);

    expect($style['indentation']['left'] ?? null)->toBe(1440);
});

it('block quote keeps its text', function () {
    expect(renderText("> quoted\n"))->toBe('quoted');
});

it('block quote keeps inline formatting', function () {
    $runs = renderRuns(renderElements("> **bold**\n")[0]);

    expect($runs[0]['font']['bold'] ?? false)->toBeTrue();
});

it('block quote can contain several paragraphs', function () {
    expect(renderText("> one\n>\n> two\n"))->toBe("one\ntwo");
});

it('a list inside a quote is indented into it', function () {
    $elements = renderElements("> intro\n>\n> - one\n> - two\n");

    expect($elements)->toHaveCount(3);
    expect(paragraphStyleOf($elements[1])['indentation']['left'] ?? null)->toBe(720);
    expect(paragraphStyleOf($elements[2])['indentation']['left'] ?? null)->toBe(720);
});

it('a list outside a quote keeps no extra indentation', function () {
    $elements = renderElements("- one\n- two\n");

    expect(paragraphStyleOf($elements[0]) ?? [])->not->toHaveKey('indentation');
});

it('a nested quote steps in further', function () {
    $elements = renderElements("> outer\n>\n> > inner\n");

    // The outer level uses the configured style, which carries its own indent.
    expect(paragraphStyleOf($elements[0])['styleName'])->toBe('IntenseQuote');

    expect(paragraphStyleOf($elements[1])['indentation']['left'] ?? null)->toBe(1440);
});

it('a nested quote keeps the quote character style', function () {
    $config = Configuration::create()->withStyles([Styles::BLOCK_QUOTE => ['italic' => true]]);
    $elements = renderElements("> outer\n>\n> > inner\n", $config);

    // A Word paragraph carries no character formatting of its own, so the
    // quote's italic has to reach the runs to be visible.
    expect(renderRuns($elements[0])[0]['font']['italic'] ?? false)->toBeTrue();
    expect(renderRuns($elements[1])[0]['font']['italic'] ?? false)->toBeTrue();
});

it('a list inside a quote inherits the quote character style', function () {
    $config = Configuration::create()->withStyles([Styles::BLOCK_QUOTE => ['italic' => true]]);
    $elements = renderElements("> - one\n> - two\n", $config);

    expect(renderRuns($elements[0])[0]['font']['italic'] ?? false)->toBeTrue();
});

it('block quote style is configurable', function () {
    $config = Configuration::create()->withStyles([Styles::BLOCK_QUOTE => 'Quote']);

    expect(paragraphStyleOf(renderElements("> quoted\n", $config)[0]))->toBe('Quote');
});
