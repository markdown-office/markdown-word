<?php

declare(strict_types=1);

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Tests\Support\Upstream;

// Writing a document reaches the one known upstream deprecation described in
// tests/Support/Upstream, so the filter spans this file.
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/*
 * The frontmatter examples, checked against what they claim to be.
 *
 * `examples/README.md` says `11` has a 24pt heading and ruled tables, `12` has
 * Word's own heading styles, and `13` has lost both. A document that stops being that
 * makes the page wrong, and the page is where the feature is described to whoever reads
 * it — so the claims are asserted here rather than trusted.
 *
 * The example sources are rendered rather than the built documents read: the outputs in
 * `examples/out/` are generated artefacts, and a test that depends on them fails on a
 * clean checkout for the wrong reason.
 */

/**
 * The GFM flavour plus frontmatter — what `examples/build.php` builds the three with.
 *
 * Naming only `FrontMatterExtension` replaces the default dialect instead of adding to
 * it, and the tables come out as paragraphs of pipes.
 */
function exampleParser(): CommonMarkParser
{
    return new CommonMarkParser([...CommonMarkParser::FLAVOURS['gfm'], FrontMatterExtension::class]);
}

/**
 * `word/document.xml` for one of the examples, rendered from its committed source.
 *
 * The Markdown in `examples/markdown/` is committed; the `.docx` in `examples/out/` is
 * not — `.gitignore` excludes it, since it is a build artefact and depends on whether
 * LibreOffice is installed. So the sources are rendered here rather than the outputs
 * read, and the three configurations below are the ones `build.php` uses.
 */
function exampleXml(string $name, ?Configuration $config = null): string
{
    $markdown = (string) file_get_contents(__DIR__ . '/../../examples/markdown/' . $name . '.md');
    $docx = (new MarkdownToWord($markdown, $config ?? Configuration::create(), exampleParser()))->toDocx($markdown);

    $target = sys_get_temp_dir() . '/example-' . $name . '.docx';
    file_put_contents($target, $docx);

    $zip = new ZipArchive();
    $zip->open($target);
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    unlink($target);

    return $xml;
}

/** The configuration `build.php` builds the override example with. */
function exampleOverride(): Configuration
{
    return Configuration::create()
        ->withOptions(['maxHeadingLevel' => 6, 'tableBorders' => false])
        ->withStyles([Styles::HEADING_1 => [
            'name' => 'Arial',
            'size' => 12,
            'bold' => false,
            'color' => '808080',
            'space' => ['before' => 0, 'after' => 0],
        ]]);
}

/** The first run of the document that carries text, with its formatting. */
function firstRun(string $xml): array
{
    preg_match('#<w:r[ >].*?</w:r>#s', $xml, $run);
    preg_match('#<w:t[^>]*>([^<]+)#', $run[0], $text);
    preg_match('#w:ascii="([^"]+)"#', $run[0], $font);
    preg_match('#<w:sz w:val="(\d+)"#', $run[0], $size);
    preg_match('#<w:color w:val="([^"]+)"#', $run[0], $color);

    return [$text[1], $font[1] ?? 'default', $size[1] ?? 'default', $color[1] ?? 'auto'];
}

it('gives the first document the font, size and colour its block asked for', function () {
    // Word measures runs in half-points, so 30pt is `w:sz 60` and 17pt is 34. The
    // three properties together are what makes the difference obvious at a glance;
    // a size on its own reads as a slightly different document.
    [$text, $font, $size, $color] = firstRun(exampleXml('11-frontmatter'));

    expect($text)->toStartWith('Frontmatter configures')
        ->and($font)->toBe('Georgia')
        ->and($size)->toBe('60')
        ->and($color)->toBe('8B0000');
});

it('spaces the heading the way the block asked', function () {
    // `space` is the one property with two numbers in it, so it is the easiest to
    // drop on the way through: before 0 and after 480 is a hand's width of air under
    // the title and none above it.
    expect(exampleXml('11-frontmatter'))->toMatch('/<w:spacing[^>]*w:before="0"[^>]*w:after="480"/');
});

it('gives the second document the built-in look, with the style name kept', function () {
    [$text, $font, $size] = firstRun(exampleXml('12-frontmatter-none'));

    expect($text)->toStartWith('The same Markdown')
        // No block means no configuration, so the heading is drawn with the
        // library's own properties — and still says which Word style it is,
        // which is what a template of the reader's own would have to match.
        ->and($font)->toBe('default')
        ->and($size)->toBe('32')
        ->and(exampleXml('12-frontmatter-none'))->toContain('w:val="2F5496"')
        ->and(exampleXml('12-frontmatter-none'))->toContain('w:val="Heading1"');
});

it('uses the code font from the block', function () {
    // Slot names are camelCase. The first version of this example wrote `code_font`,
    // which is not a slot, so it was dropped without a word and the code came out in
    // the built-in Consolas — the block looked ignored and the document looked fine.
    expect(exampleXml('11-frontmatter'))->toContain('w:ascii="Courier New"')
        ->and(exampleXml('11-frontmatter'))->toContain('w:sz w:val="22"')
        ->and(exampleXml('12-frontmatter-none'))->toContain('w:ascii="Consolas"');
});

it('gives the table in the frontmatter example its borders', function () {
    expect(exampleXml('11-frontmatter'))->toContain('<w:tblBorders>');
});

it('writes the block as configuration rather than as content', function () {
    $xml = exampleXml('11-frontmatter');

    // The example's own prose mentions the keys, so their presence proves nothing. What
    // must be absent is the block itself: the opening delimiter, and the YAML as a
    // heading. The first paragraph is the document's first heading.
    expect($xml)->not->toContain('w:val="Heading1"')
        ->and(preg_match('/<w:body>.*?<w:t[^>]*>([^<]{0,40})/s', $xml, $first))
        ->toBe(1)
        ->and($first[1])->toStartWith('Frontmatter configures');
});


it('lets the configuration passed in code outrank the block', function () {
    $xml = exampleXml('13-frontmatter-override', exampleOverride());

    // 12pt is `w:sz 24`, and the block asked for 30pt in Georgia. No ruled table
    // either, which is the other half of the override.
    [$text, $font, $size, $color] = firstRun($xml);

    expect($text)->toStartWith('This heading is not 30pt')
        ->and($font)->toBe('Arial')
        ->and($size)->toBe('24')
        ->and($color)->toBe('808080')
        ->and($xml)->not->toContain('w:ascii="Georgia"')
        ->and($xml)->not->toContain('<w:tblBorders>');
});

it('renders a table in every one of them', function () {
    // The first version of these examples was built with a parser that named only
    // `FrontMatterExtension`, which replaced the GFM flavour instead of adding to it.
    // Every table came out as a paragraph of pipes and the documents looked fine.
    foreach (['11-frontmatter', '12-frontmatter-none', '13-frontmatter-override'] as $name) {
        expect(exampleXml($name, $name === '13-frontmatter-override' ? exampleOverride() : null))
            ->toContain('<w:tbl>');
    }
});

it('keeps the three documents distinct from one another', function () {
    $documents = ['11-frontmatter', '12-frontmatter-none', '13-frontmatter-override'];

    foreach ($documents as $name) {
        foreach ($documents as $other) {
            if ($name === $other) {
                continue;
            }

            $config = $name === '13-frontmatter-override' ? exampleOverride() : null;

            expect(exampleXml($name, $config))->not->toBe(exampleXml($other));
        }
    }
});