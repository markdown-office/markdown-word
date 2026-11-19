<?php

declare(strict_types=1);

use MarkdownWord\Reverse\Block;
use MarkdownWord\Reverse\Escaping;
use MarkdownWord\Reverse\Inline;
use MarkdownWord\Reverse\MarkdownWriter;
use MarkdownWord\Reverse\Options as ReverseOptions;
use MarkdownWord\WordToMarkdown;

/*
 * The writer's edge cases, kept apart from the round trip and the general
 * reverse-conversion suite.
 *
 * These are about what a document loses on the way out, or spends far too long
 * on: a blank line inside a verbatim block, the letters past the twenty-sixth
 * item of a list, and the fence around a long run of backticks. Each is written
 * as an assertion about the Markdown that comes out, because that is the only
 * place any of the three can be seen.
 */

function writeFixesRoundTrip(string $markdown): string
{
    return (new WordToMarkdown(toDocx($markdown)))->convert();
}

/**
 * @param list<Block> $blocks
 */
function writeFixes(Block ...$blocks): string
{
    return (new MarkdownWriter(new ReverseOptions()))->write($blocks);
}

/**
 * A `.docx` built by hand.
 *
 * A document written by this library cannot contain a list Word numbered in a
 * format of its own choosing, so the packages here are assembled part by part:
 * the content types, the two relationship parts, the document, and — when the
 * body references a numbering definition — the numbering part and the
 * relationship that points at it.
 *
 * @param string       $body      The children of `w:body`.
 * @param string|null  $numbering The children of the list's level 0: `w:start`,
 *        `w:numFmt` and `w:lvlText`.
 */
function writeFixesPackage(string $body, ?string $numbering = null): string
{
    $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $package = 'http://schemas.openxmlformats.org/package/2006/relationships';
    $types = 'http://schemas.openxmlformats.org/package/2006/content-types';
    $office = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="' . $w . '"><w:body>' . $body . '</w:body></w:document>';

    $contentTypes = [
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
        '<Types xmlns="' . $types . '">',
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>',
        '<Default Extension="xml" ContentType="application/xml"/>',
        '<Override PartName="/word/document.xml" ContentType="'
            . 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>',
    ];

    $documentRelationships = [
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
        '<Relationships xmlns="' . $package . '">',
    ];

    if ($numbering !== null) {
        $contentTypes[] = '<Override PartName="/word/numbering.xml" ContentType="'
            . 'application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>';
        $documentRelationships[] = '<Relationship Id="rId1" Type="' . $office
            . '/numbering" Target="numbering.xml"/>';
    }

    $contentTypes[] = '</Types>';
    $documentRelationships[] = '</Relationships>';

    $root = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="' . $package . '">'
        . '<Relationship Id="rId1" Type="' . $office . '/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';

    $parts = [
        '[Content_Types].xml' => implode('', $contentTypes),
        '_rels/.rels' => $root,
        'word/_rels/document.xml.rels' => implode('', $documentRelationships),
        'word/document.xml' => $document,
    ];

    if ($numbering !== null) {
        $parts['word/numbering.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:numbering xmlns:w="' . $w . '">'
            . '<w:abstractNum w:abstractNumId="0"><w:lvl w:ilvl="0">' . $numbering . '</w:lvl></w:abstractNum>'
            . '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'
            . '</w:numbering>';
    }

    $archive = new ZipArchive();
    $path = MarkdownWord\Tests\Support\Scratch::path('writer-fixes');

    $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($parts as $name => $contents) {
        $archive->addFromString($name, $contents);
    }

    $archive->close();

    return (string) file_get_contents($path);
}

/**
 * One line of a verbatim block, which is how a document stores one: a paragraph
 * per line, in a monospaced face, empty for a blank line.
 */
function writeFixesCodeLine(string $text): string
{
    return '<w:p><w:r><w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/></w:rPr>'
        . '<w:t xml:space="preserve">' . $text . '</w:t></w:r></w:p>';
}

/**
 * @return list<string>
 */
function writeFixesList(string $format, int $items, int $start = 1): array
{
    $body = '';

    for ($index = 0; $index < $items; $index++) {
        $body .= '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr>'
            . '<w:r><w:t>item ' . ($start + $index) . '</w:t></w:r></w:p>';
    }

    $package = writeFixesPackage(
        $body,
        '<w:start w:val="' . $start . '"/><w:numFmt w:val="' . $format . '"/><w:lvlText w:val="%1."/>',
    );

    $markdown = (new WordToMarkdown($package))->convert();

    $lines = array_values(array_filter(
        array_map(trim(...), explode("\n", $markdown)),
        static fn (string $line): bool => $line !== '',
    ));

    return array_map(
        static fn (string $line): string => strtok($line, ' ') ?: $line,
        $lines,
    );
}

// Blank lines and code

it('keeps a blank line inside a fenced code block', function () {
    // Two blank lines in a row are content where they are: the gap in a log
    // excerpt, the paragraph break in a fixture. Tidying the finished document
    // cannot tell that from a run of blank lines between two blocks, and lost
    // the first while collapsing the second.
    $markdown = "para\n\n```\na\n\n\nb\n```\n\nafter";

    expect(writeFixesRoundTrip($markdown))->toBe($markdown);
});

it('keeps a blank line inside a code block a document holds as paragraphs', function () {
    // The same content built as the document actually stores it: one monospaced
    // paragraph per line, the blank ones empty.
    $package = writeFixesPackage(
        writeFixesCodeLine('a') . writeFixesCodeLine('') . writeFixesCodeLine('') . writeFixesCodeLine('b'),
    );

    expect((new WordToMarkdown($package))->convert())->toBe("```\na\n\n\nb\n```");
});

it('writes one blank line between blocks however many the document left', function () {
    // The other half of the rule: between two blocks a run of blank lines is only
    // ever a way of writing a blank line, so however many the document left there
    // the Markdown has one.
    expect(writeFixesRoundTrip("one\n\n\n\n\ntwo"))->toBe("one\n\ntwo");
    expect(writeFixesRoundTrip("one\n\n\ntwo"))->toBe("one\n\ntwo");
});

it('writes one blank line between the blocks of a loose list', function () {
    // A loose list is separated by a blank line too, and it is the blank line
    // that makes it loose again, so the collapse belongs there as well.
    $markdown = writeFixesRoundTrip("- one\n\n- two\n\n- three");

    expect($markdown)->toBe("- one\n\n- two\n\n- three");
});

// Markers

it('continues a lettered list past the twenty-sixth item', function () {
    // Word does not run out of letters at `z`: it goes on with `aa`, `ab` and so
    // on, the way a spreadsheet names its columns. Coming back round to `a` means
    // two items in the list carry a marker that is already taken.
    $markers = writeFixesList('lowerLetter', 30);

    expect($markers)->toHaveCount(30);
    expect(array_values(array_unique($markers)))->toHaveCount(30);
    expect($markers[25])->toBe('z.');
    expect($markers[26])->toBe('aa.');
    expect($markers[27])->toBe('ab.');
    expect($markers[29])->toBe('ad.');
});

it('continues a capital lettered list past the twenty-sixth item', function () {
    $markers = writeFixesList('upperLetter', 30);

    expect($markers)->toHaveCount(30);
    expect(array_values(array_unique($markers)))->toHaveCount(30);
    expect($markers[25])->toBe('Z.');
    expect($markers[26])->toBe('AA.');
    expect($markers[29])->toBe('AD.');
});

it('numbers a lettered list from where it starts', function () {
    // The count is bijective, so a list that starts at thirty begins at `ad`
    // rather than at `d`: the letters it counts from are the ones the start
    // value names.
    $markers = writeFixesList('lowerLetter', 3, start: 30);

    expect($markers)->toBe(['ad.', 'ae.', 'af.']);
});

it('writes a lower-case Roman numeral list past fifty', function () {
    $markers = writeFixesList('lowerRoman', 52);

    expect($markers[0])->toBe('i.');
    expect($markers[50])->toBe('li.');
    expect($markers[51])->toBe('lii.');
});

it('writes a Roman numeral list past fifty', function () {
    $markers = writeFixesList('upperRoman', 52);

    expect($markers[0])->toBe('I.');
    expect($markers[50])->toBe('LI.');
    expect($markers[51])->toBe('LII.');
});

it('pads a zero-numbered list to two digits', function () {
    $markers = writeFixesList('decimalZero', 12);

    expect($markers)->toBe([
        '01.', '02.', '03.', '04.', '05.', '06.',
        '07.', '08.', '09.', '10.', '11.', '12.',
    ]);
});

it('writes an unknown numbering format as the number itself', function () {
    // Nothing better is available: the number is at least something a reader can
    // make sense of.
    expect(writeFixesList('ordinal', 3))->toBe(['1.', '2.', '3.']);
});

// Fence

it('fences a code block with more backticks than it holds', function () {
    // A run of backticks in the content closes the block if the fence is not
    // longer than it is, and a fence of three is the shortest CommonMark allows.
    expect(writeFixes(Block::code('plain')))
        ->toBe("```\nplain\n```");

    expect(writeFixes(Block::code('a ` b')))
        ->toBe("```\na ` b\n```");

    expect(writeFixes(Block::code('a ``` b')))
        ->toBe("````\na ``` b\n````");

    expect(writeFixes(Block::code('a `````` b')))
        ->toBe("```````\na `````` b\n```````");
});

it('fences a code block a document holds as paragraphs', function () {
    $package = writeFixesPackage(
        writeFixesCodeLine('before ````` after') . writeFixesCodeLine('second line'),
    );

    expect((new WordToMarkdown($package))->convert())
        ->toBe("``````\nbefore ````` after\nsecond line\n``````");
});

it('escapes the pipes of a table cell', function () {
    // A pipe ends the cell whatever surrounds it, so the pipes of a cell are
    // written escaped: in the text, in a code span, in the label of a link, in
    // its destination and in its title.
    $cell = fn (array $inlines): Block => Block::cell([Block::paragraph($inlines)]);

    $markdown = writeFixes(
        Block::table([
            Block::row([
                $cell([Inline::text('a | b')]),
                $cell([Inline::text('c | d', code: true)]),
                $cell([Inline::link('https://example.com/e|f', 'g | h', [Inline::text('i | j')])]),
            ]),
        ]),
    );

    expect($markdown)->toContain('| a \\| b | `c \\| d` | [i \\| j](https://example.com/e\\|f "g \\| h") |');
});

it('leaves a pipe outside a table cell alone', function () {
    // A pipe only ends a cell, so a pipe anywhere else is written as it is.
    expect(writeFixes(Block::paragraph([Inline::text('a | b')])))->toBe('a | b');
});

it('finds the longest run of a character in one pass', function () {
    expect(Escaping::longestRun('a ```` b ``` c', '`'))->toBe(4);
    expect(Escaping::longestRun('nothing here', '`'))->toBe(0);
    expect(Escaping::longestRun('', '`'))->toBe(0);
});

it('fences a code block holding a very long run of backticks in reasonable time', function () {
    // The fence has to be longer than the longest run in the content, and the
    // obvious way to find that is to look for three backticks, then four, then
    // five: a search of the whole content per backtick, quadratic in its size.
    // Two hundred thousand backticks took nine seconds that way against a few
    // milliseconds now, so the budget below is not a close call on any machine.
    $start = hrtime(true);

    $markdown = writeFixes(Block::code(str_repeat('`', 200000)));

    $elapsed = (hrtime(true) - $start) / 1e9;

    expect($markdown)->toStartWith(str_repeat('`', 200001));
    expect($elapsed)->toBeLessThan(
        2.0,
        sprintf('Fencing a code block took %.3f s, which is a search per backtick.', $elapsed),
    );
});
