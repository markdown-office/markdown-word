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

it('a code font named as a style reaches a code block', function () {
    // The way a template supplies its own typeface: a name rather than properties.
    // PHPWord puts a string style on a run as an `rStyle` reference, which is the
    // only way a name reaches a run at all — so a name that goes through a property
    // array is lost. It reached an inline span and not the block, because the block
    // merged the two and the inline one did not, and the block came out in the
    // body's typeface with nothing to say so.
    $config = Configuration::create()->withStyles([Styles::CODE_FONT => 'CorpCode']);

    $runs = renderRuns(renderElements("```\nx\n```\n", $config)[0]);

    expect($runs[0]['font'] ?? null)->toBe('CorpCode');
});

it('a code block takes the font half of its own style over the named code font', function () {
    // The block's own font slot wins over the code span's, as it does for properties.
    // A name and a property cannot both be applied to one run, so the block's half is
    // what the run gets.
    $config = Configuration::create()->withStyles([
        Styles::CODE_FONT => 'CorpCode',
        Styles::CODE_BLOCK => ['name' => 'Fira Code'],
    ]);

    $runs = renderRuns(renderElements("```\nx\n```\n", $config)[0]);

    expect($runs[0]['font']['name'] ?? null)->toBe('Fira Code');
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

/*
 * A code span's spaces are code.
 *
 * CommonMark normalises exactly two things inside one — line endings become spaces, and
 * one space comes off each end when both ends have one and the span is not all spaces —
 * and the parser has done both by the time the node reaches the renderer. Anything after
 * that is not normalisation, it is alteration: `foo(  bar)` and `foo( bar)` are different
 * lines of code, and the two-space version is usually the bug the reader is looking for.
 *
 * A code *block* never passes through the same path and always kept its whitespace; these
 * are the inline cases, which did not.
 */

it('keeps the spaces inside a code span', function () {
    expect(renderText("Run `a   b` here.\n"))->toContain('a   b');
});

it('keeps an alignment a code span is there for', function () {
    // The reason anyone writes a run of spaces in one, and the reason collapsing it is
    // a defect rather than a tidy-up.
    expect(renderText("`name  value\nid    7`\n"))->toContain('name  value');
});

it('keeps a code span that is one space', function () {
    // The one case where the two rules disagree, and the only one that could go either
    // way: `` ` ` `` holds a single space and is not an empty span.
    expect(renderText("a` `b\n"))->toContain(' ');
});

it('still takes one space off each end, as the spec says', function () {
    expect(renderText("`  lead and trail  `\n"))->toContain(' lead and trail ');
});

it('still collapses the whitespace of ordinary text', function () {
    // The other half of the same decision. A browser collapses it, so the document must,
    // and `&#10;` inside ordinary text is a space on screen whatever the source says.
    expect(renderText("one   two\n"))->toBe('one two');
});

it('leaves the whitespace in a code block alone', function () {
    expect(renderText("```\na    b\n```\n"))->toContain('a    b');
});
