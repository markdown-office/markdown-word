<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Configuration\TextStyles;
use MarkdownWord\Exception\InvalidConfiguration;
use MarkdownWord\Exception\MalformedTheme;
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

it('honours a label holding a construct CommonMark does not call emphasis', function () {
    // A delimiter run that opens an emphasis may not be followed by whitespace, and one
    // that closes it may not be preceded by whitespace: `__ Bakery__` and `** text**`
    // are text, and a label holding one is drawn as it was written.
    $config = styledConfig(['brand' => ['color' => '0B5CAB']]);

    expect(renderText("A :brand[__ Bakery__] here.\n", $config))->toBe('A __ Bakery__ here.')
        ->and(renderText("A :brand[** text**] here.\n", $config))->toBe('A ** text** here.')
        ->and(renderText("A :brand[**Bakery **] here.\n", $config))->toBe('A **Bakery ** here.')
        ->and(styleRuns("A :brand[** text**] here.\n", $config)[1]['font']['color'] ?? null)->toBe('0B5CAB');
});

it('holds a label that is Markdown and draws one that is not', function () {
    // The corpus is the list of labels a text style may be given and what CommonMark
    // makes of each one. A label the directive grammar cannot carry — one with a
    // bracket in it — is left out: the grammar says a label holds no brackets at all.
    $config = styledConfig(['brand' => ['color' => '0B5CAB']]);
    $labels = file(dirname(__DIR__) . '/fixtures/labels.txt', FILE_IGNORE_NEW_LINES) ?: [];
    $held = 0;
    $drawn = 0;

    foreach ($labels as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$marker, $label] = array_pad(explode(' ', $line, 2), 2, '');

        if (str_contains($label, '[') || str_contains($label, ']')) {
            continue;
        }

        $text = renderText(sprintf("A :brand[%s] here.\n", $label), $config);

        // A label the halves hold is drawn as its own source text; one they do not is
        // drawn as the word. The corpus says what CommonMark makes of the label, and
        // the two markers that differ from it say which way the halves went.
        if ($marker === 'text' || $marker === 'markdown-only') {
            expect($text)->toBe(sprintf('A %s here.', $label));
            $drawn++;

            continue;
        }

        expect($text)->toBe(sprintf('A :brand[%s] here.', $label));
        $held++;
    }

    expect($held)->toBeGreaterThan(0)
        ->and($drawn)->toBeGreaterThan(0);
});

it('finds a style under the name it is folded to', function () {
    // A style's name is a word to a reader, and `:Brand[Bakery]` and `:brand[Bakery]`
    // are the same word written twice. The folding is one answer to one question: the
    // names are folded, the lookup is folded, and the space around a name is part of
    // it rather than a second question.
    $styles = new TextStyles(['Brand' => ['color' => '0B5CAB']]);

    expect($styles->has('brand'))->toBeTrue()
        ->and($styles->has('BRAND'))->toBeTrue()
        ->and($styles->has(' Brand '))->toBeTrue()
        ->and($styles->get('brand'))->toBe(['color' => '0B5CAB'])
        ->and(TextStyles::fold(' Brand '))->toBe('brand');
});

it('merges a style of the same name over the one already there', function () {
    // The names are folded, so `brand` and `Brand` are one style and the properties
    // are merged into the one already there — under the name it was written under
    // first, because that is the spelling the author has to change.
    $styles = (new TextStyles(['Brand' => ['color' => '0B5CAB', 'bold' => true]]))
        ->withAll(['brand' => ['size' => 12]]);

    expect($styles->names())->toBe(['Brand'])
        ->and($styles->get('Brand'))->toBe(['color' => '0B5CAB', 'bold' => true, 'size' => 12]);
});

it('draws a style the theme spells with a capital letter, whichever case the document writes it in', function () {
    [$root, $themes] = styleThemes();
    writeStyleTheme($themes, 'house', "textStyles:\n  Brand:\n    color: \"0B5CAB\"\n");

    $converter = (new MarkdownToWord('# T', directory: $root))->withTheme('house');
    $markdown = "A :brand[Bakery] and a :Brand[Bakery] here.\n";

    expect(styleText($converter, $markdown))->toBe('A Bakery and a Bakery here.');
});

it('names a style the way the theme spelled it', function () {
    // The author's change is in the theme, so the spelling the theme wrote is the one
    // to name.
    [$root, $themes] = styleThemes();
    writeStyleTheme($themes, 'house', "textStyles:\n  Brand:\n    color: \"0B5CAB\"\n");

    $converter = (new MarkdownToWord('# T', directory: $root))->withTheme('house');
    $config = $converter->configurationFor($converter->parse('# T'));

    expect($config->getTextStyles()->names())->toBe(['Brand']);
});

it('publishes the names the theme spelled', function () {
    $styles = new StyleResolver(Configuration::create());
    $registry = Directives::standard(
        new Toc(new Options(), $styles),
        new Footnote($styles),
        new TextStyle(new TextStyles(['Brand' => ['color' => '0B5CAB']]), $styles),
    );

    expect($registry->draws())->toContain('Brand');
});

it('refuses a theme that names one text style twice, in two spellings', function () {
    // The names are folded, so `Brand` and `brand` are one name: a theme carrying both
    // has two styles a document cannot tell apart.
    [$root, $themes] = styleThemes();
    writeStyleTheme($themes, 'bad', "textStyles:\n  Brand:\n    color: \"0B5CAB\"\n  brand:\n    color: \"0B5CAB\"\n");

    expect(fn () => (new MarkdownToWord('# T', directory: $root))->withTheme('bad'))
        ->toThrow(MalformedTheme::class, 'is the same style as');
});

it('refuses a text style named with the reserved word in another case', function () {
    // The reserved word is folded with the rest of the names, because `Style` is the
    // same word as `style`.
    [$root, $themes] = styleThemes();
    writeStyleTheme($themes, 'bad', "textStyles:\n  Style:\n    color: \"0B5CAB\"\n");

    expect(fn () => (new MarkdownToWord('# T', directory: $root))->withTheme('bad'))
        ->toThrow(InvalidConfiguration::class, '"style" is the name the raw form takes.');
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
