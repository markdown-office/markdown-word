<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Configuration\TextStyles;
use MarkdownWord\Exception\InvalidConfiguration;
use MarkdownWord\Exception\UnknownConfigurationKey;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Render\Directive\Footnote;
use MarkdownWord\Render\Directive\TextStyle;
use MarkdownWord\Render\Directive\Toc;
use MarkdownWord\Render\Directives;
use MarkdownWord\Render\StyleResolver;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Text\TextExtractor;
use MarkdownWord\WordToMarkdown;

/*
 * Inline styles: a word in a style, in the middle of a sentence.
 *
 * Three forms, one grammar: a name a theme defines, that name with attributes over
 * it, and the reserved name `style` with attributes and no theme style behind it.
 *
 * The policy this half keeps is the one it has always kept: **what it cannot draw
 * is drawn as its own source text.** A name no theme defines, an attribute that
 * does not exist, a value that is not what the attribute takes and a label holding
 * a complete Markdown construct all come out as the characters the author typed —
 * the same answer an unknown directive has always had, and the reason a document
 * nobody changed the syntax of does not change either.
 */

/** A configuration carrying the named styles a theme would bring. */
function styledConfig(array $styles): Configuration
{
    return Configuration::create()->withTextStyles($styles);
}

/**
 * A themes directory in the scratch, with a theme in it.
 *
 * @return array{0: string, 1: string} The directory a document is read from, and its `themes/`.
 */
function styleThemes(): array
{
    $root = Scratch::directory() . '/themes-' . bin2hex(random_bytes(4));
    mkdir($root . '/themes', 0o777, true);

    return [$root, $root . '/themes'];
}

/** A theme with a `styles.yml` of the given contents. */
function writeStyleTheme(string $themes, string $name, string $styles): void
{
    $directory = $themes . '/' . $name;
    mkdir($directory, 0o777, true);
    file_put_contents($directory . '/theme.yml', "name: Test\n");
    file_put_contents($directory . '/styles.yml', $styles);
}

/** The runs of the first paragraph of a document. */
function styleRuns(string $markdown, ?Configuration $config = null): array
{
    return renderRuns(renderElements($markdown, $config)[0]);
}

/**
 * The visible text of a document, rendered by a converter of its own.
 *
 * A converter rather than a configuration, because a theme is named on the
 * converter and not in the configuration.
 */
function styleText(MarkdownToWord $converter, string $markdown): string
{
    return TextExtractor::fromPhpWord(
        $converter->toPhpWord($markdown),
        TextExtractor::LINE_BREAK,
        $converter->pendingHyperlinks(),
    );
}

it('colours a word in a named style, in the middle of a sentence', function () {
    $runs = styleRuns("Resilience :brand[Bakery] is open.\n", styledConfig([
        'brand' => ['color' => '0B5CAB'],
    ]));

    expect($runs)->toHaveCount(3)
        ->and($runs[0]['text'])->toBe('Resilience ')
        ->and($runs[1]['text'])->toBe('Bakery')
        ->and($runs[1]['font']['color'] ?? null)->toBe('0B5CAB')
        // The rest of the sentence is untouched.
        ->and($runs[2]['text'])->toBe(' is open.')
        ->and($runs[2]['font'])->toBeNull();
});

it('draws the raw form with no theme style behind it', function () {
    $runs = styleRuns("The word :style[teal]{color=00B4D8} here.\n");

    expect($runs[1]['text'])->toBe('teal')
        ->and($runs[1]['font']['color'] ?? null)->toBe('00B4D8')
        // A raw form with no attributes says nothing — there is no named style and
        // no property — so it is its own source text.
        ->and(renderText("The word :style[teal] here.\n"))->toBe('The word :style[teal] here.');
});

it('lets an attribute override the named style for that property alone', function () {
    $runs = styleRuns(":brand[Bakery]{color=FF0000}\n", styledConfig([
        'brand' => ['color' => '0B5CAB', 'bold' => true],
    ]));

    expect($runs[0]['font']['color'] ?? null)->toBe('FF0000')
        // The style's other property is still there.
        ->and($runs[0]['font']['bold'] ?? false)->toBeTrue();
});

it('draws a name it does not know as its own source text', function () {
    // The behaviour this half has always had, and the one the whole feature rests
    // on: a word nobody claims comes back out as the characters the author typed.
    expect(renderText("Status :green[go] today.\n"))->toBe('Status :green[go] today.');
});

it('draws the names a theme defines and leaves the rest alone', function () {
    [$root, $themes] = styleThemes();
    writeStyleTheme($themes, 'house', "textStyles:\n  brand:\n    color: \"0B5CAB\"\n");

    $markdown = "A :brand[Bakery] and a :green[go] here.\n";
    $converter = (new MarkdownToWord($markdown, directory: $root))->withTheme('house');

    expect(styleText($converter, $markdown))->toBe('A Bakery and a :green[go] here.');
});

it('leaves a label holding a complete Markdown construct as its source text', function () {
    // The label is plain text, so `**Bakery**` would reach the document with its
    // asterisks — and a word that comes out as something the author did not write
    // is the one failure this suite refuses to commit.
    $config = styledConfig(['brand' => ['color' => '0B5CAB']]);

    expect(renderText("A :brand[**Bakery**] here.\n", $config))->toBe('A :brand[**Bakery**] here.')
        ->and(renderText("A :brand[*Bakery*] here.\n", $config))->toBe('A :brand[*Bakery*] here.')
        ->and(renderText("A :brand[_Bakery_] here.\n", $config))->toBe('A :brand[_Bakery_] here.')
        ->and(renderText("A :brand[__Bakery__] here.\n", $config))->toBe('A :brand[__Bakery__] here.')
        ->and(renderText("A :brand[`Bakery`] here.\n", $config))->toBe('A :brand[`Bakery`] here.');
});

it('honours a label holding a bare asterisk or an underscore', function () {
    // A bare marker is a character and not an intent: `2 * 3` and `snake_case` are
    // ordinary text, and a rule that refused every label holding one of them would
    // refuse correct documents all day.
    $config = styledConfig(['brand' => ['color' => '0B5CAB']]);

    expect(renderText("A :brand[2 * 3] here.\n", $config))->toBe('A 2 * 3 here.')
        ->and(renderText("A :brand[snake_case] here.\n", $config))->toBe('A snake_case here.')
        ->and(styleRuns("A :brand[2 * 3] here.\n", $config)[1]['font']['color'] ?? null)->toBe('0B5CAB');
});

it('renders a theme with no text styles, and draws a named style against it as source text', function () {
    [$root, $themes] = styleThemes();
    writeStyleTheme($themes, 'plain', "heading.1:\n  color: 112233\n");

    $markdown = "A :brand[Bakery] here.\n";
    $converter = (new MarkdownToWord($markdown, directory: $root))->withTheme('plain');

    expect(styleText($converter, $markdown))->toBe('A :brand[Bakery] here.')
        // The raw form needs no theme, so it still works against one that has no
        // named styles.
        ->and(styleText($converter, "A :style[Bakery]{color=0B5CAB} here.\n"))->toBe('A Bakery here.');
});

it('draws a style whose attribute it does not know as its source text', function () {
    $config = styledConfig(['brand' => ['color' => '0B5CAB']]);

    expect(renderText("A :brand[Bakery]{weight=900} here.\n", $config))
        ->toBe('A :brand[Bakery]{weight="900"} here.');
});

it('draws a style whose attribute holds a value it cannot use as its source text', function () {
    $config = styledConfig(['brand' => ['color' => '0B5CAB']]);

    expect(renderText("A :style[Bakery]{color=red} here.\n", $config))
        ->toBe('A :style[Bakery]{color="red"} here.');
});

it('draws a style with no text at all as its source text', function () {
    $config = styledConfig(['brand' => ['color' => '0B5CAB']]);

    expect(renderText("A :brand[] here.\n", $config))->toBe('A :brand here.');
});

it('refuses a styles file whose text styles name an attribute that does not exist', function () {
    [$root, $themes] = styleThemes();
    writeStyleTheme($themes, 'bad', "textStyles:\n  brand:\n    colour: \"0B5CAB\"\n");

    expect(fn () => (new MarkdownToWord('# T', directory: $root))->withTheme('bad'))
        ->toThrow(UnknownConfigurationKey::class, 'Unknown "colour" property of the "brand" style');
});

it('refuses a styles file whose text style has no properties', function () {
    [$root, $themes] = styleThemes();
    writeStyleTheme($themes, 'bad', "textStyles:\n  brand: {}\n");

    expect(fn () => (new MarkdownToWord('# T', directory: $root))->withTheme('bad'))
        ->toThrow(InvalidConfiguration::class, 'A style that styles nothing is a mistake.');
});

it('refuses a styles file that defines a style called "style"', function () {
    // The one reserved word: `style` is the name the raw form takes, and a theme
    // that took it would leave the raw form with nothing to mean.
    [$root, $themes] = styleThemes();
    writeStyleTheme($themes, 'bad', "textStyles:\n  style:\n    color: \"0B5CAB\"\n");

    expect(fn () => (new MarkdownToWord('# T', directory: $root))->withTheme('bad'))
        ->toThrow(InvalidConfiguration::class, '"style" is the name the raw form takes.');
});

it('refuses a styles file whose text style holds a value it cannot use', function () {
    [$root, $themes] = styleThemes();
    writeStyleTheme($themes, 'bad', "textStyles:\n  brand:\n    color: \"#0B5CAB\"\n");

    expect(fn () => (new MarkdownToWord('# T', directory: $root))->withTheme('bad'))
        ->toThrow(InvalidConfiguration::class, 'six hexadecimal digits');
});

it('leaves the slot styles alone when a theme brings text styles', function () {
    [$root, $themes] = styleThemes();
    writeStyleTheme($themes, 'house', "heading.1:\n  color: 112233\n\ntextStyles:\n  brand:\n    color: \"0B5CAB\"\n");

    $markdown = "---\ntheme: house\n---\n\n# T\n";
    $converter = new MarkdownToWord($markdown, directory: $root, parser: CommonMarkParser::withFrontMatter());
    $config = $converter->configurationFor($converter->parse($markdown), $markdown);

    expect($config->getStyles()->get(Styles::HEADING_1)['color'])->toBe('112233')
        // And the text styles are not slots: the slot list is unchanged.
        ->and(array_keys($config->getStyles()->toArray()))->not->toContain('textStyles')
        ->and($config->getTextStyles()->names())->toBe(['brand']);
});

it('brings the named styles of a theme that ships with the library', function () {
    $converter = (new MarkdownToWord('# T'))->withTheme('corporate');
    $config = $converter->configurationFor($converter->parse('# T'));

    expect($config->getTextStyles()->names())->toBe(['brand', 'muted']);
});

it('names the directives it draws', function () {
    expect(Directives::names())->toBe(['toc', 'footnote', 'style']);
});

it('draws the names it says it draws', function () {
    $styles = new StyleResolver(Configuration::create());
    $registry = Directives::standard(
        new Toc(new Options(), $styles),
        new Footnote($styles),
        new TextStyle(new TextStyles(), $styles),
    );

    expect($registry->draws())->toBe(Directives::names());
});

it('writes a size in points as the half-points Word keeps', function () {
    $markdown = "A :brand[Bakery] here.\n";
    $document = documentParts(toDocx($markdown, styledConfig([
        'brand' => ['size' => 14],
    ])))['word/document.xml'];

    expect($document)->toContain('<w:sz w:val="28"/>');
});

it('writes the colour behind a word as a shading rather than a highlight', function () {
    // `w:highlight` takes one of Word's sixteen names and not a colour, so a hex
    // written there is a value the format cannot hold. `w:shd` takes the colour.
    $markdown = "A :brand[Bakery] here.\n";
    $document = documentParts(toDocx($markdown, styledConfig([
        'brand' => ['highlight' => 'FFFF00'],
    ])))['word/document.xml'];

    expect($document)->toContain('<w:shd w:val="clear" w:fill="FFFF00"/>')
        ->and($document)->not->toContain('w:highlight');
});

it('does not keep a text style in the other direction', function () {
    // The round trip reads bold, italic and strike and no colour, so a word in a
    // style comes back as the word.
    $markdown = "A :brand[Bakery] here.\n";
    $converter = new MarkdownToWord(null, styledConfig([
        'brand' => ['color' => '0B5CAB'],
    ]));

    $back = withoutUpstreamDeprecations(
        static fn (): string => (new WordToMarkdown($converter->toDocx($markdown)))->convert(),
    );

    expect($back)->toContain('Bakery')
        ->and($back)->not->toContain('0B5CAB');
});

it('names the eight attributes a style may carry', function () {
    expect(array_keys(TextStyles::ATTRIBUTES))
        ->toBe(['color', 'size', 'font', 'bold', 'italic', 'underline', 'strike', 'highlight']);
});
