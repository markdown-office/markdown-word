<?php

declare(strict_types=1);

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Furniture;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Document\Frontmatter;
use MarkdownWord\Exception\InvalidConfigurationValue;
use MarkdownWord\Format;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Render\Directives;
use MarkdownWord\Render\DocumentRenderer;
use MarkdownWord\Render\FurnitureWriter;
use MarkdownWord\Render\HtmlFragmentRenderer;
use MarkdownWord\Render\ImageConversionCollector;
use MarkdownWord\Render\ImageDescriptionCollector;
use MarkdownWord\Render\ImageResolver;
use MarkdownWord\Render\InlineRenderer;
use MarkdownWord\Render\LinkPayloadCollector;
use MarkdownWord\Render\NumberingRegistry;
use MarkdownWord\Render\StyleResolver;
use MarkdownWord\Render\SvgAttachmentCollector;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/*
 * What a `header:` or a `footer:` in a document's frontmatter puts on the page.
 *
 * The first test is the one everything else is measured against, and it is the one that
 * is easiest to get wrong in a way nothing notices. A document naming no header must get
 * no `word/header1.xml` at all — not an empty one — because the part is also what
 * {@see \MarkdownWord\Writer\Survey} asks about: an empty band on every page would put a
 * sentence about a running head in front of a document that has none, and would erase a
 * template's own header to do it.
 *
 * The rest are about what a body of Markdown in a header turns into. It is drawn by the
 * document's own renderer into a different container, so a bold word in a header is the
 * same rendering as a bold word in the body — and a link in one is a real link, because
 * PHPWord writes the relationship into the header part itself.
 *
 * Writing a document reaches the one known upstream deprecation; see
 * tests/Support/Upstream.
 */
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * The text column of a default A4 section, in twips: what a tab stop has to be measured
 * against.
 *
 * Written as the arithmetic rather than the number, because the number is only correct
 * until one of PHPWord's two defaults moves — and a test that kept passing against a moved
 * default would be asserting nothing. A function rather than a constant because `round()`
 * is not a constant expression.
 */
function furnitureTextColumn(): int
{
    return (int) round(11905.511811024 - (2 * 1440));
}

/** The directory a header's picture resolves against. */
function furnitureImageDirectory(): string
{
    return dirname(Scratch::image('logo.png'));
}

/**
 * GFM plus frontmatter.
 *
 * `MarkdownToWord`'s own default has no `FrontMatterExtension` — the block is the
 * caller's to ask for — so a document naming a `header:` is parsed with this or the
 * block is rendered as the heading it looks like.
 */
function furnitureParser(): CommonMarkParser
{
    return new CommonMarkParser([...CommonMarkParser::FLAVOURS['gfm'], FrontMatterExtension::class]);
}

function furnitureDocx(string $markdown, array $overrides = []): string
{
    $path = Scratch::path('furniture');
    $converter = new MarkdownToWord($markdown, overrides: $overrides, parser: furnitureParser());

    file_put_contents($path, $converter->toDocx($markdown));

    return $path;
}

/** Every part of a written document, keyed by name. */
function furnitureParts(string $archive): array
{
    $zip = new ZipArchive();
    $zip->open($archive);
    $parts = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $parts[(string) $zip->getNameIndex($index)] = '';
    }

    $zip->close();

    return $parts;
}

/**
 * The document renderer {@see \MarkdownWord\MarkdownToWord} builds for itself, built
 * here for the one test that has to call {@see FurnitureWriter} directly — there is no
 * public way to be handed the renderer, and asking a document about a template's header
 * is not something a plain conversion can do.
 */
function furnitureRenderer(Configuration $config, PhpWord $phpWord): DocumentRenderer
{
    $styles = new StyleResolver($config);
    $html = new HtmlFragmentRenderer($config->getOptions(), $styles);
    $directives = new Directives();

    return new DocumentRenderer(
        $config,
        $styles,
        new InlineRenderer(
            $styles,
            new ImageResolver(
                $config->getOptions(),
                new ImageDescriptionCollector(),
                new ImageConversionCollector(),
                new SvgAttachmentCollector(),
            ),
            new LinkPayloadCollector($styles),
            $html,
            false,
            $directives,
        ),
        new NumberingRegistry($config, $phpWord),
        $html,
        $directives,
    );
}

// No header, no part

it('writes no header part for a document that names no header', function (string $frontmatter) {
    // The unknown-key case names a key nothing reads. A document is refused for it
    // rather than silently stripped of it, which is asserted by its own test below;
    // reaching here with one means the block was empty rather than wrong.
    // The whole claim is in the absence, so the assertion is on the list of parts rather
    // than on the contents of one: an empty `word/header1.xml` would satisfy a test that
    // opened the file, and it is exactly what must not be written.
    $parts = array_keys(furnitureParts(furnitureDocx($frontmatter . "\nBody.\n")));

    expect(array_filter($parts, static fn (string $name): bool => str_starts_with($name, 'word/header')))
        ->toBe([], 'A document that asks for no running head should get no header part.');
})->with([
    'no frontmatter at all' => '',
    'no header key' => "---\noptions:\n  codeBlockShading: false\n---\n",
    'a header key with nothing under it' => "---\nheader:\n---\n",
]);

it('writes a header part for a document that describes one', function () {
    // The other half of the same rule, so the tests above cannot pass because nothing
    // ever writes a header.
    expect(TemplateFactory::hasPart(furnitureDocx("---\nheader:\n  body: Acme Ltd\n---\n\nBody.\n"), 'word/header1.xml'))
        ->toBeTrue();
});

it('writes the furniture it was given and nothing beside it', function () {
    // The negative case and the positive one in a single document, which is what makes
    // the first of them meaningful: a part is missing here because the document named no
    // header, and not merely because nothing in this library knows how to write one.
    $parts = array_keys(furnitureParts(furnitureDocx("---\nfooter:\n  page: true\n---\n\nBody.\n")));

    expect($parts)->toContain('word/footer1.xml')
        ->and(array_filter($parts, static fn (string $name): bool => str_starts_with($name, 'word/header')))
        ->toBe([]);
});

// The body of a header

it('draws the body of a header as Markdown', function () {
    $markdown = "---\nheader:\n  body: '**Acme Ltd** — *since 1974*'\n---\n\nBody.\n";

    $header = TemplateFactory::xmlOf(furnitureDocx($markdown), 'word/header1.xml');

    // Both halves of each: a run without the weight is a header the emphasis was dropped
    // from, and the words without the run are a header the renderer never touched.
    expect($header)->toMatch('~<w:r><w:rPr><w:b w:val="1"/>.*?</w:rPr><w:t[^>]*>Acme Ltd</w:t></w:r>~s')
        ->and($header)->toMatch('~<w:r><w:rPr><w:i w:val="1"/>.*?</w:rPr><w:t[^>]*>since 1974</w:t></w:r>~s');
});

it('gives a header body one paragraph per block, as any Markdown does', function () {
    // The blank line is what stacks a logo above the wordmark beside it, and
    // `examples/markdown/24-furniture.md` depends on it. Two lines with nothing between
    // them are one paragraph, and an image inside a paragraph is inline — Word runs the
    // text beside the picture rather than under it. A renderer that gave every line a
    // paragraph of its own would look friendlier here and change the meaning of every
    // header body written this way.
    $overrides = ['options' => [
        'imageBasePath' => furnitureImageDirectory(),
        'images' => Options::IMAGE_EMBED,
    ]];

    $oneBlock = "---\nheader:\n  body: |\n    ![the logo](logo.png)\n    **Acme Ltd**\n---\n\nBody.\n";
    $twoBlocks = "---\nheader:\n  body: |\n    ![the logo](logo.png)\n\n    **Acme Ltd**\n---\n\nBody.\n";

    $paragraphs = fn (string $body): int => preg_match_all(
        '~<w:p[ >]~',
        TemplateFactory::xmlOf(furnitureDocx($body, $overrides), 'word/header1.xml'),
    );

    expect($paragraphs($oneBlock))->toBe(1)
        ->and($paragraphs($twoBlocks))->toBe(2);
});

it('embeds a raster picture in a header', function () {
    $markdown = "---\nheader:\n  body: 'Acme ![the logo](logo.png)'\n---\n\nBody.\n";

    $path = furnitureDocx($markdown, ['options' => [
        'imageBasePath' => furnitureImageDirectory(),
        'images' => Options::IMAGE_EMBED,
    ]]);
    $header = TemplateFactory::xmlOf($path, 'word/header1.xml');

    $targets = TemplateFactory::targetsOf($path, 'word/_rels/header1.xml.rels');

    // PHPWord writes a picture into a header natively, so this is the one piece of a
    // header's Markdown that needs no pass of this library's own — the `<w:pict>` and the
    // relationship that reaches the file are both written with the header, and the file
    // is named after the part holding it rather than after the body.
    expect($header)->toMatch('~<w:pict>.*?<v:imagedata r:id="rId1"~s')
        ->and($targets)->toHaveCount(1)
        ->and($targets[0])->toStartWith('media/header1_');
});

it('turns a link in a header into a real link', function () {
    $markdown = "---\nheader:\n  body: 'Acme — [the site](https://example.com)'\n---\n\nBody.\n";

    $path = furnitureDocx($markdown);

    // PHPWord writes the hyperlink and its relationship into the header part, so this is
    // the one piece of a header that needs no pass of this library's own and is not lost
    // the way alt text is. Rendered, the label comes out blue and underlined.
    expect(TemplateFactory::xmlOf($path, 'word/header1.xml'))->toContain('<w:hyperlink')
        ->and(TemplateFactory::xmlOf($path))->not->toContain('MDWL')
        ->and(TemplateFactory::targetsOf($path, 'word/_rels/header1.xml.rels'))
        ->toContain('https://example.com');
});

// Fields

it('writes a page number into a header as a field', function () {
    $markdown = "---\nheader:\n  page: true\n---\n\nBody.\n";

    $header = TemplateFactory::xmlOf(furnitureDocx($markdown), 'word/header1.xml');

    // A field and not a number: `PAGE` is what makes the figure change from page to page,
    // and a literal `1` would be right on exactly one of them.
    expect($header)->toMatch('~<w:instrText[^>]*>\s*PAGE\s*</w:instrText>~')
        ->and($header)->toContain('<w:fldChar w:fldCharType="begin"/>');
});

it('writes a token in a header body as a field too', function () {
    $markdown = "---\nheader:\n  body: 'Page {page} of {total}'\n---\n\nBody.\n";

    $header = TemplateFactory::xmlOf(furnitureDocx($markdown), 'word/header1.xml');

    // The tokens are substituted into the Markdown before it is parsed, so they work in
    // the middle of a sentence — which is the thing the `page`/`total` switches cannot
    // do, and the reason both spellings exist.
    expect($header)->toMatch('~<w:t[^>]*>Page </w:t>~')
        ->and($header)->toMatch('~<w:instrText[^>]*>\s*PAGE\s*</w:instrText>~')
        ->and($header)->toMatch('~<w:instrText[^>]*>\s*NUMPAGES\s*</w:instrText>~')
        ->and($header)->toMatch('~<w:t[^>]*> of </w:t>~')
        ->and($header)->not->toContain('{page}');
});

it('writes a date in a header body as a Word date field', function () {
    $markdown = "---\nheader:\n  dateFormat: 'd MMMM yyyy'\n  body: 'Dated {date}'\n---\n\nBody.\n";

    $header = TemplateFactory::xmlOf(furnitureDocx($markdown), 'word/header1.xml');

    // Word's own `DATE` field rather than a day written down: a document that prints the
    // date it was converted on is saying when the file was made, not when it was written,
    // and the two go out of step the moment anyone edits the document.
    expect($header)->toMatch('~<w:instrText[^>]*> DATE \\\\@ &quot;d MMMM yyyy&quot; </w:instrText>~')
        ->and($header)->toMatch('~<w:t[^>]*>Dated </w:t>~')
        ->and($header)->not->toContain('{date}');
});

it('writes a date switch as a field of its own', function () {
    $header = TemplateFactory::xmlOf(
        furnitureDocx("---\nheader:\n  date: true\n  body: Acme\n---\n\nBody.\n"),
        'word/header1.xml',
    );

    // The switches write their fields on a paragraph of their own, below the body: a
    // running head is two lines at most, and the line the words are on is the one the
    // author wrote.
    expect($header)->toMatch('~<w:instrText[^>]*> DATE </w:instrText>~')
        ->and($header)->toMatch('~>Acme</w:t></w:r></w:p><w:p>~');
});

// Where the furniture sits

it('puts a right-aligned header at the right margin', function () {
    $markdown = "---\nheader:\n  align: right\n  body: Acme Ltd\n  page: true\n  total: true\n---\n\nBody.\n";

    $header = TemplateFactory::xmlOf(furnitureDocx($markdown), 'word/header1.xml');

    // Both questions, and on the paragraphs that answer them: `w:jc` right-aligns the
    // words, the tab stop puts the numbers at the margin. Either alone is a defect — a
    // justification centres a line of text rather than pushing a page number out to the
    // edge, and a tab stop with nothing to reach it does nothing at all. Rendered, this
    // document puts `Acme Ltd` flush right and `1 2` beneath it at the right margin.
    expect($header)->toContain('<w:jc w:val="right"/>')
        ->and($header)->toMatch(
            '~<w:tabs><w:tab w:val="right" w:leader="none" w:pos="' . furnitureTextColumn() . '"/></w:tabs>~',
        )
        ->and($header)->toContain('<w:t xml:space="preserve">' . "\t" . '</w:t>')
        ->and($header)->toMatch('~<w:instrText[^>]*> NUMPAGES </w:instrText>~');
});

it('centres a header that asks to be centred', function () {
    $markdown = "---\nheader:\n  align: center\n  body: Acme Ltd\n  page: true\n---\n\nBody.\n";

    $header = TemplateFactory::xmlOf(furnitureDocx($markdown), 'word/header1.xml');

    expect($header)->toContain('<w:jc w:val="center"/>')
        ->and($header)->toMatch('~<w:tab w:val="center" w:leader="none" w:pos="' . intdiv(furnitureTextColumn(), 2) . '"/>~');
});

it('leaves a left-aligned header with nothing to tab to', function () {
    $markdown = "---\nheader:\n  align: left\n  body: Acme Ltd\n  page: true\n---\n\nBody.\n";

    $header = TemplateFactory::xmlOf(furnitureDocx($markdown), 'word/header1.xml');

    // A tab here would push the page number away from the margin `left` named, which is
    // the opposite of what was asked for.
    expect($header)->toContain('<w:jc w:val="left"/>')
        ->and($header)->not->toContain('<w:tabs>')
        ->and($header)->not->toContain('<w:t xml:space="preserve">' . "\t" . '</w:t>');
});

it('draws a rule under a header that asks for one', function () {
    $bottom = TemplateFactory::xmlOf(
        furnitureDocx("---\nheader:\n  border: bottom\n  body: Acme\n---\n\nBody.\n"),
        'word/header1.xml',
    );
    $both = TemplateFactory::xmlOf(
        furnitureDocx("---\nheader:\n  border: both\n  body: Acme\n---\n\nBody.\n"),
        'word/header1.xml',
    );

    // The same three values a thematic break draws its rule with, because it is that
    // rule — see `thematicBreak => 'border'` in the document renderer.
    expect($bottom)->toMatch('~<w:pBdr><w:bottom w:val="single" w:sz="6" w:color="auto"/></w:pBdr>~')
        ->and($bottom)->not->toContain('<w:top ')
        ->and($both)->toContain('<w:top w:val="single" w:sz="6" w:color="auto"/>')
        ->and($both)->toContain('<w:bottom w:val="single" w:sz="6" w:color="auto"/>');
});

it('gives the first page a header of its own when it is asked for', function () {
    $markdown = "---\nheader:\n  firstPage: true\n  body: Draft\n---\n\nBody.\n";

    $path = furnitureDocx($markdown);
    $document = TemplateFactory::xmlOf($path);

    // `Section::hasDifferentFirstPage()` is derived from a `Header::FIRST` existing, and
    // the writer emits `<w:titlePg/>` from it. There is no setter to check instead, and
    // one that claimed the document had a first-page header would pass on a document
    // whose first page header was never referenced.
    expect($document)->toContain('<w:headerReference w:type="first"')
        ->and($document)->toContain('<w:titlePg/>')
        ->and(TemplateFactory::xmlOf($path, 'word/header1.xml'))->toContain('Draft');

    // The odd-page header has to be there too. A `Header::FIRST` on its own replaces
    // it rather than adding to it, and Word then shows a header on page 1 and nothing
    // from page 2 on — which passes a test that only looks for the first-page
    // reference, and is not what asking for a first page of its own means.
    expect($document)->toContain('<w:headerReference w:type="default"');
});

it('asks for different even and odd headers when it is asked for', function () {
    $markdown = "---\nheader:\n  even: true\n  body: Verso\n---\n\nBody.\n";

    $path = furnitureDocx($markdown);

    // The reference and the setting are both checked: without `<w:evenAndOddHeaders/>`
    // Word reads the even header as the odd one and prints the same band on every page,
    // which looks like the switch having worked.
    expect(TemplateFactory::xmlOf($path))->toContain('<w:headerReference w:type="even"')
        ->and(TemplateFactory::xmlOf($path, 'word/settings.xml'))->toContain('<w:evenAndOddHeaders w:val="true"/>');

    // And the odd-page header, which without it leaves every odd page bare.
    expect(TemplateFactory::xmlOf($path))->toContain('<w:headerReference w:type="default"');
});

it('refuses a header naming a key nothing reads', function () {
    // The other half of the empty-form claim. A key `Furniture` does not hold is
    // dropped, so without this a mis-indented `algin: right` would leave a header
    // with no alignment and nothing anywhere in the run saying why — which is the
    // one failure this library's own error reporting exists to prevent.
    expect(fn () => furnitureDocx("---\nheader:\n  algin: right\n---\n\nBody.\n"))
        ->toThrow(InvalidConfigurationValue::class);

    // And it says which block, so a document with a head and a foot is not a guessing
    // game. A `try`/`catch` with the assertions inside would pass if nothing were
    // thrown at all, so this asks for the exception first and reads it after.
    $message = null;

    try {
        furnitureDocx("---\nfooter:\n  align: middle\n---\n\nBody.\n");
    } catch (InvalidConfigurationValue $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('"footer"')->toContain('center, left, right');
});

// What the writer cannot carry

it('reports an .odt that cannot carry a header or a footer', function () {
    $markdown = "---\nheader:\n  body: Acme\nfooter:\n  page: true\n---\n\nBody.\n";

    $converter = new MarkdownToWord($markdown, parser: furnitureParser());
    $converter->convertTo(Format::Odt, Scratch::path('furniture', '.odt'));

    expect(array_column($converter->pendingLosses(), 'feature'))
        ->toContain('header')
        ->toContain('footer');
});

it('says nothing about a header or a footer a document did not have', function () {
    // A document with no running head loses no running head, and saying otherwise would
    // be a warning about a fault that is not there.
    $converter = new MarkdownToWord("Just a paragraph of text.\n", parser: furnitureParser());
    $converter->convertTo(Format::Odt, Scratch::path('plain', '.odt'));

    expect($converter->pendingLosses())->toBe([]);
});

// What a document keeps from a template

it('writes nothing for a document that names nothing', function () {
    // The empty-form claim, at the point where it is applied rather than at the value
    // object: `Furniture::isEmpty()` is what decides, and a writer that reached past it
    // would put an empty band on every page of a document that asked for no header.
    $phpWord = new PhpWord();
    $section = $phpWord->addSection();

    (new FurnitureWriter())->draw(
        furnitureRenderer(new Configuration(), $phpWord),
        Frontmatter::of(['header' => []]),
        $section,
    );

    $path = Scratch::path('nothing-named');
    IOFactory::createWriter($phpWord, 'Word2007')->save($path);

    expect($section->getHeaders())->toBe([])
        ->and(TemplateFactory::hasPart($path, 'word/header1.xml'))->toBeFalse();
});
