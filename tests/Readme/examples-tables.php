<?php

declare(strict_types=1);

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Configuration;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

/*
 * The table examples, checked against what they claim to be.
 *
 * `examples/README.md` says that `16` gets its width, its rules, its repeating header
 * and its cell typography from four different keys, and that `17` gets the same three
 * *options* with no style slot at all. Those are separate claims about separate
 * mechanisms, and each of them is checked against `word/document.xml` rather than
 * against the prose, which talks about all four in the same paragraph.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * `word/document.xml` of one of the examples, rendered from its committed source.
 */
function tableExample(string $name): string
{
    $markdown = (string) file_get_contents(dirname(__DIR__, 2) . '/examples/markdown/' . $name . '.md');

    $converter = new MarkdownToWord(
        $markdown,
        Configuration::create(),
        new CommonMarkParser([...CommonMarkParser::FLAVOURS['gfm'], FrontMatterExtension::class]),
    );

    $target = sys_get_temp_dir() . '/example-' . $name . '.docx';
    $converter->save($target);

    $xml = TemplateFactory::xmlOf($target);
    unlink($target);

    return $xml;
}

/**
 * The first `<w:tr>` of the first `<w:tbl>`, which is the header row.
 */
function firstTableHeader(string $xml): string
{
    preg_match('#<w:tbl>.*?(<w:tr>.*?</w:tr>)#s', $xml, $match);

    return $match[1] ?? '';
}

/**
 * The first `<w:tc>` of the first `<w:tbl>`'s first row.
 */
function firstTableHeaderCell(string $xml): string
{
    preg_match('#<w:tbl>.*?<w:tc>(.*?)</w:tc>#s', $xml, $match);

    return $match[1] ?? '';
}

it('takes the table width from the block', function () {
    // `tableWidth: 4000` is in fiftieths of a percent of the text column, so 4000
    // is four fifths of it and 5000 — the default — is all of it.
    expect(tableExample('16-styled-tables'))
        ->toMatch('#<w:tblW w:w="4000" w:type="pct"/>#')
        ->and(tableExample('02-tables'))->toMatch('#<w:tblW w:w="5000" w:type="pct"/>#');
});

it('takes the borders from the table slot, not from tableBorders', function () {
    // The claim worth pinning is the colour: `2E5F86` is the one in the block, and
    // `000000` is the one `options.tableBorders` would have drawn. The option is the
    // default table's, and a table with a definition of its own brings its own.
    expect(tableExample('16-styled-tables'))
        ->toContain('<w:tblBorders>')
        ->toMatch('#<w:insideH w:val="single" w:sz="8" w:color="2E5F86"/>#')
        ->and(tableExample('02-tables'))->toMatch('#<w:insideH w:val="single" w:sz="6" w:color="000000"/>#');
});

it('repeats the header row, from the row slot', function () {
    // A `w:tblHeader` on the first row is what tells Word to repeat it at the top of
    // every page the table spills onto. It is the whole of what a row can be told:
    // the slot is handed a `RowStyle`, which has no appearance of its own — an
    // earlier version of this example asked it for a shading and it was dropped.
    expect(tableExample('16-styled-tables'))->toMatch('#<w:tblHeader w:val="1"/>#');
});

it('takes the cell typography from the cell slot', function () {
    // `size: 10` is `w:sz 20` — Word measures in half-points — and `vAlign` reaches
    // the cell rather than the paragraph inside it.
    expect(firstTableHeaderCell(tableExample('16-styled-tables')))
        ->toContain('<w:vAlign w:val="center"/>')
        ->toContain('<w:sz w:val="20"/>')
        ->toContain('<w:color w:val="1F2933"/>');
});

it('takes the boldness of the header from the option, not from the slot', function () {
    // `tableHeaderBold: false` in the block against the default `true` in `02`. The
    // header row is the first row of the first table in both, so a `**bold**` further
    // down the document cannot be what is being measured here.
    $styled = firstTableHeader(tableExample('16-styled-tables'));
    $plain = firstTableHeader(tableExample('02-tables'));

    expect($styled)->not->toContain('<w:b')
        ->and($plain)->toContain('<w:b');
});

it('draws no borders at all when tableBorders is off', function () {
    // `17` has no style slot anywhere, so the option is what would have drawn the
    // rules — and `tableWidth: 0` hands the width back to Word.
    expect(tableExample('17-borderless-tables'))
        ->not->toContain('<w:tblBorders>')
        ->toMatch('#<w:tblW w:w="0" w:type="auto"/>#')
        ->and(tableExample('16-styled-tables'))->toContain('<w:tblBorders>');
});

it('still measures the columns of a borderless table', function () {
    // Borderless is not unstructured: a long cell wraps inside its column rather than
    // running across the page, and that is `w:tblLayout` with the grid beside it.
    expect(tableExample('17-borderless-tables'))
        ->toMatch('#<w:tblGrid>(<w:gridCol[^>]*/>){2,}</w:tblGrid>#')
        ->toContain('<w:tblLayout w:type="autofit"/>');
});

it('writes the block as configuration rather than as content in both', function () {
    // The claim every frontmatter example makes, and the one a block that leaked
    // would fail. Not "the keys do not appear": both files talk about their own
    // settings in prose, and `16` names the `---` delimiter in a sentence. What has
    // to be absent is the block reaching the document — the document's first heading
    // is its own, and the YAML under the delimiter never arrives as a heading.
    foreach (['16-styled-tables' => 'Styled tables', '17-borderless-tables' => 'Tables without decoration'] as $name => $heading) {
        expect(preg_match('#<w:body>.*?<w:t[^>]*>([^<]{0,40})#s', tableExample($name), $first))->toBe(1)
            ->and($first[1])->toStartWith($heading);
    }
});