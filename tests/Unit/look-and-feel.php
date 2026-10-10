<?php

declare(strict_types=1);

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\LookAndFeel;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Format;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\Upstream;

/*
 * The built-in look: the formatting a slot has before anybody configures it.
 *
 * Every assertion here is about what a *reader of the finished document* sees,
 * because that is the half of this that is easy to get wrong. Markup inspection has
 * already lied once in this repository — an `.odt` heading was recorded as carried
 * because the style was in `styles.xml`, and a rendered page says otherwise — so a
 * claim about a format is asserted on the properties the writer puts on the runs
 * and the paragraph, which is what every reader has to resolve, rather than on a
 * reference that only Word understands.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/** The same document as one format, and the converter that wrote it. */
function lookConversion(Format $format, string $markdown, ?Configuration $config = null): array
{
    $converter = new MarkdownToWord(
        $markdown,
        $config ?? Configuration::create(),
        new CommonMarkParser([...CommonMarkParser::FLAVOURS['gfm'], FrontMatterExtension::class]),
    );

    $path = Scratch::path('look', $format->extension());
    $converter->convertTo($format, $path);

    return [$converter, $path];
}

/**
 * One part of a written document.
 *
 * @return array<string, string>
 */
function lookParts(string $archive): array
{
    $zip = new ZipArchive();
    $zip->open($archive);

    $parts = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $parts[(string) $zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
    }

    $zip->close();

    return $parts;
}

/** The body of a document: `content.xml`, the RTF itself, or the document part. */
function lookBody(string $archive): string
{
    if (str_ends_with($archive, '.rtf')) {
        return (string) file_get_contents($archive);
    }

    return lookParts($archive)[str_ends_with($archive, '.odt') ? 'content.xml' : 'word/document.xml'];
}

it('writes every property of the built-in look onto the runs of a heading', function () {
    $element = renderElements("# Title\n")[0];
    $font = renderRuns($element)[0]['font'];
    $look = LookAndFeel::slots()[Styles::HEADING_1];

    // The character half, on the run rather than on the paragraph: a Word
    // paragraph carries no formatting of its own, so this is the only place it can
    // go, and it is the only place the ODF and RTF writers look.
    expect($font)->toMatchArray([
        'bold' => $look['bold'],
        'size' => $look['size'],
        'color' => $look['color'],
    ])->and(array_keys($font))->toEqualCanonicalizing(array_keys(
        \MarkdownWord\Render\ParagraphStyle::fontPart($look)
    ));
});

it('keeps the Word style name beside the built-in look on a .docx heading', function () {
    [, $path] = lookConversion(Format::Docx, "# Title\n\nBody.\n");
    $parts = lookParts($path);

    // The name is the conventional hook: it is what a template's own `Heading1` has
    // to be called, what this library's own reader looks for, and what any index of
    // the document is built from.
    //
    // What it does not by itself do is set an outline level. PHPWord writes the
    // style's `w:name` from the same string as its `w:styleId`, and `Heading1` is not
    // the canonical `heading 1`, so a reader that maps built-in styles by name treats
    // it as a style of its own — LibreOffice does, and puts no `text:outline-level`
    // on the paragraph when it reads one back. PHPWord's paragraph writer only emits
    // `w:outlineLvl` for a numbered paragraph, so there is no setting here that would
    // change that.
    expect($parts['word/document.xml'])->toContain('<w:pStyle w:val="Heading1"/>')
        // And the direct formatting travels with it, so the look does not depend on
        // the definition that follows.
        ->and($parts['word/document.xml'])->toContain('<w:spacing w:before="240" w:after="120"/>')
        ->and($parts['word/styles.xml'])->toMatch('~<w:style [^>]*w:styleId="Heading1"~');
});

it('carries the same heading into an .odt and an .rtf', function (Format $format, string $needle) {
    [, $path] = lookConversion($format, "# Title\n\nBody.\n");

    expect(lookBody($path))->toContain($needle);
})->with([
    'odt' => [Format::Odt, 'fo:font-size="16pt" style:font-size-asian="16pt" style:font-size-complex="16pt" fo:color="#2F5496" fo:font-weight="bold"'],
    'rtf' => [Format::Rtf, '\fs32\b'],
]);

it('gives the quote its italics and its colour in every format', function (Format $format, string $needle) {
    [, $path] = lookConversion($format, "> Quoted text.\n\nAfter.\n");

    expect(lookBody($path))->toContain($needle);
})->with([
    // `\i` alone appears in RTF for reasons of its own, so the run's own
    // properties are matched together with the two the writer always writes.
    'docx' => [Format::Docx, '<w:i w:val="1"'],
    'odt' => [Format::Odt, 'fo:font-style="italic"'],
    'rtf' => [Format::Rtf, '\f0\i '],
]);

it('spaces a body paragraph in every format', function (Format $format, string $needle) {
    [, $path] = lookConversion($format, "First.\n\nSecond.\n");

    expect(lookBody($path))->toContain($needle);
})->with([
    'docx' => [Format::Docx, '<w:spacing w:after="120" w:line="276" w:lineRule="auto"/>'],
    'odt' => [Format::Odt, 'fo:margin-bottom="6pt"'],
    'rtf' => [Format::Rtf, '\sa120'],
]);

it('writes every key of the built-in look into a key list that accepts it', function () {
    // `Styles::FONT_KEYS` and `PARAGRAPH_KEYS` are what the registrar reads and what
    // the validator accepts. A key in the look and in neither is a property that is
    // configured and then silently dropped, which is the failure this library
    // exists not to have.
    $accepted = [...Styles::FONT_KEYS, ...Styles::PARAGRAPH_KEYS];
    $unaccepted = [];

    foreach (LookAndFeel::slots() as $slot => $definition) {
        foreach (array_keys($definition) as $key) {
            if (!in_array($key, $accepted, true)) {
                $unaccepted[] = $slot . '.' . $key;
            }
        }
    }

    expect($unaccepted)->toBe([]);
});

it('defines every built-in style id it names, and no others', function () {
    $styles = Styles::defaults();

    expect(LookAndFeel::ownedStyleIds())->toBe(['Heading1', 'Heading2', 'Heading3', 'Heading4', 'Heading5', 'Heading6', 'IntenseQuote']);

    foreach (LookAndFeel::slots() as $slot => $look) {
        if (isset($look['styleName'])) {
            expect($styles[$slot]['styleName'])->toBe($look['styleName']);
        }
    }
});

it('leaves a slot that names a style exactly as it was written', function () {
    $config = Configuration::create()->withStyles([
        Styles::HEADING_1 => 'CorpTitle',
        Styles::BLOCK_QUOTE => ['italic' => true],
    ]);

    // The template wins outright: no property of the built-in look is layered over
    // it, because a named style is a promise about what the template says that
    // style looks like.
    expect(paragraphStyleOf(renderElements("# Title\n", $config)[0]))->toBe('CorpTitle')
        ->and(renderRuns(renderElements("# Title\n", $config)[0])[0]['font'])->toBeNull();

    // A definition is the other half of the same rule: it replaces, it does not
    // merge with, so the built-in colour and spacing are not there under it. The
    // quote's own indentation is not part of the definition and stays.
    $quote = paragraphStyleOf(renderElements("> Quoted.\n", $config)[0]);

    expect($quote)->not->toHaveKey('styleName')
        ->and($quote)->not->toHaveKey('space')
        ->and($quote['indentation']['left'] ?? null)->toBe(720)
        ->and(renderRuns(renderElements("> Quoted.\n", $config)[0])[0]['font'])->toBe(['italic' => true]);

    // And the override is one slot, not the configuration: the levels nobody named
    // still come out of the built-in look, which is what a styles block means.
    expect(renderRuns(renderElements("## Second\n", $config)[0])[0]['font']['size'] ?? null)
        ->toBe(LookAndFeel::slots()[Styles::HEADING_2]['size']);
});

it('takes the built-in look back out of the way for Word\'s own styles', function () {
    $config = Configuration::create()->withBuiltInHeadingStyles();

    // A bare name and nothing else: the paragraph is written with that id, and the
    // runs carry no formatting, which is what "Word decides what this looks like"
    // means on the way into the writer.
    expect(renderElements("# Title\n", $config)[0]->getParagraphStyle())->toBe('Heading1')
        ->and(renderRuns(renderElements("# Title\n", $config)[0])[0]['font'])->toBeNull()
        ->and(renderElements("> Quoted.\n", $config)[0]->getParagraphStyle())->toBe('Quote')
        ->and($config->getStyles()->get(Styles::PARAGRAPH))->toBeNull()
        // And it is a way back rather than a no-op: the default is not a bare name.
        ->and(Configuration::create()->getStyles()->get(Styles::HEADING_1))->not->toBe('Heading1');
});

it('is overridable through the styles a document carries', function () {
    $markdown = <<<'MD'
        ---
        styles:
          heading.1:
            styleName: Heading1
            size: 24
            color: 8B0000
            bold: false
            space:
              before: 0
              after: 480
        ---

        # Title

        ## Second
        MD;

    // Read through a real conversion rather than the element tree, because the
    // frontmatter is only read by a parser that carries the extension.
    [, $path] = lookConversion(Format::Docx, $markdown);
    $document = lookParts($path)['word/document.xml'];

    preg_match('~<w:p>(?:(?!</w:p>).)*Title.*?</w:p>~s', $document, $heading);
    $paragraph = $heading[0] ?? '';

    expect($paragraph)->toContain('<w:sz w:val="48"/>')
        ->and($paragraph)->toContain('w:val="8B0000"')
        ->and($paragraph)->toMatch('~<w:spacing w:before="0" w:after="480"/>~')
        ->and($paragraph)->toContain('<w:pStyle w:val="Heading1"/>')
        // `bold: false` in the block, written as an explicit "off", and the
        // definition written for `Heading1` is this document's rather than the
        // library's: the same 24pt and the same dark red.
        ->and($paragraph)->toContain('<w:b w:val="0"/>')
        ->and(lookParts($path)['word/styles.xml'])->toContain('<w:sz w:val="48"/>')
        ->and(lookParts($path)['word/styles.xml'])->toContain('w:val="8B0000"')
        // One slot, again: the levels the block says nothing about keep the
        // built-in look rather than losing it along with the one it named.
        ->and($document)->toContain('<w:sz w:val="26"/>');
});

it('reports a loss for a named style and none for the built-in look', function () {
    [$plain, $plainPath] = lookConversion(Format::Rtf, "# Title\n\n> Quoted.\n");

    expect(array_column($plain->pendingLosses(), 'feature'))->not->toContain('named-styles')
        ->and(lookBody($plainPath))->toContain('\fs32');

    [$named, $namedPath] = lookConversion(
        Format::Rtf,
        "---\nstyles:\n  heading.1: CorpTitle\n---\n\n# Title\n",
    );

    expect(array_column($named->pendingLosses(), 'feature'))->toContain('named-styles')
        ->and(lookBody($namedPath))->not->toContain('\fs32');
});

it('takes the code block background from the option and the indent from the look', function () {
    // `codeBlockShading` decides whether there is a background; the colour and the
    // indent are the look's, and neither is a property of the option.
    $look = LookAndFeel::slots()[Styles::CODE_BLOCK];
    $on = paragraphStyleOf(renderElements("```\nx = 1\n```\n")[0]);
    $off = paragraphStyleOf(renderElements("```\nx = 1\n```\n", Configuration::create()->withOptions(['codeBlockShading' => false]))[0]);

    expect($on['shading']['fill'] ?? null)->toBe(LookAndFeel::CODE_BACKGROUND)
        ->and($off['shading'] ?? null)->toBeNull()
        ->and($on['indentation']['left'] ?? null)->toBe($look['indentation']['left'])
        ->and($off['indentation']['left'] ?? null)->toBe($look['indentation']['left']);
});

it('gives a list inside a block quote the quote\'s name as well as its italics', function () {
    // The runs carry the quote's italics, and the paragraph says where they came
    // from, so reading the document back does not find emphasis nobody typed.
    $element = renderElements("> - one\n")[0];

    expect(paragraphStyleOf($element)['styleName'] ?? null)->toBe('IntenseQuote')
        ->and(renderRuns($element)[0]['font'])->toMatchArray(['italic' => true]);
});