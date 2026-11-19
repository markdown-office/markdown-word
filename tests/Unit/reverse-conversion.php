<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Configuration\Options;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Reverse\Block;
use MarkdownWord\Reverse\Escaping;
use MarkdownWord\Reverse\Options as ReverseOptions;
use MarkdownWord\WordToMarkdown;
use MarkdownWord\Tests\Support\Scratch;

/*
 * Reading a Word document back into Markdown.
 *
 * The round-trip suite proves that nothing is lost between the two directions
 * across the whole specification corpus. These tests pin down the individual
 * decisions, the ones a corpus cannot single out: which construct was chosen,
 * which option changes it, and how awkward text is escaped.
 */

/**
 * Render Markdown, read the document back, and return the Markdown.
 */
function roundTrip(string $markdown, ?Configuration $config = null, ?ReverseOptions $options = null): string
{
    $file = Scratch::path('reverse');
    saveDocument($markdown, $file, $config);

    return (new WordToMarkdown($file, $options ?? new ReverseOptions()))->convert();
}

/**
 * The block tree a document converts to, for the tests that care about the
 * structure rather than the text.
 *
 * @return list<Block>
 */
function readBack(string $markdown, ?ReverseOptions $options = null): array
{
    $file = Scratch::path('reverse');
    saveDocument($markdown, $file);

    return (new WordToMarkdown(null, $options ?? new ReverseOptions()))->read($file);
}

it('writes a file as Markdown', function () {
    $file = Scratch::path('reverse');
    saveDocument('# Title', $file);
    $target = Scratch::path('read-back', '.md');

    (new WordToMarkdown($file))->save($target);

    expect(file_get_contents($target))->toContain('# Title');
});

it('converts a document held in memory', function () {
    $bytes = toDocx('# Title');

    expect((new WordToMarkdown($bytes))->convert())->toContain('# Title');
});

it('reports a file that is not there', function () {
    expect(fn () => (new WordToMarkdown('/does/not/exist.docx'))->convert())
        ->toThrow(RuntimeException::class);
});

it('reports bytes that are not a document', function () {
    expect(fn () => (new WordToMarkdown('not a zip file'))->convert())
        ->toThrow(RuntimeException::class);
});

it('reports being given nothing to convert', function () {
    // Better a sentence than a document of nothing.
    expect(fn () => (new WordToMarkdown())->convert())
        ->toThrow(RuntimeException::class, 'There is no document to convert');
});

// Blocks

it('reads headings at every level', function () {
    $markdown = roundTrip("# One\n\n## Two\n\n###### Six");

    expect($markdown)->toContain('# One');
    expect($markdown)->toContain('## Two');
    expect($markdown)->toContain('###### Six');
});

it('reads a heading written as an underlined one', function () {
    $options = ReverseOptions::fromArray(['headingSetext' => true]);

    expect(roundTrip('# One', options: $options))->toContain("One\n===");
    expect(roundTrip('## Two', options: $options))->toContain("Two\n---");
});

it('keeps the hashes of a heading too deep to underline', function () {
    // Markdown has only two underlined heading levels, so a deeper one has to
    // keep its hashes or the level would be lost.
    $options = ReverseOptions::fromArray(['headingSetext' => true]);

    expect(roundTrip('### Three', options: $options))->toContain('### Three');
});

it('keeps a trailing hash run inside a heading', function () {
    // `## foo ##` is a heading whose content is `foo`; a second pair of hashes
    // would be read as the closing sequence.
    expect(roundTrip('## Title \\###'))->toContain('## Title \\###');
});

it('reads emphasis, strong and strikethrough', function () {
    $markdown = roundTrip('*one* **two** ***three*** ~~four~~');

    expect($markdown)->toContain('*one*');
    expect($markdown)->toContain('**two**');
    expect($markdown)->toContain('***three***');
    expect($markdown)->toContain('~~four~~');
});

it('keeps whitespace outside emphasis where it has to be', function () {
    // A closing delimiter preceded by a space cannot close anything, so it has to
    // be written after the text rather than before the space.
    expect(roundTrip('*foo* [bar](https://example.test)'))->toContain('*foo* [bar]');
});

it('reads code spans and keeps their spacing', function () {
    expect(roundTrip('a `b` c'))->toContain('`b`');
});

it('fences a code block', function () {
    expect(roundTrip("```\nline one\nline two\n```"))->toContain("```\nline one\nline two\n```");
});

it('reads a link with its title', function () {
    $markdown = roundTrip('[label](https://example.test "the title")');

    expect($markdown)->toContain('[label](https://example.test "the title")');
});

it('reads a link whose label carries emphasis', function () {
    expect(roundTrip('[**bold** text](https://example.test)'))
        ->toContain('[**bold** text](https://example.test)');
});

it('reads a block quote and nests it', function () {
    $markdown = roundTrip("> outer\n>\n> > inner");

    expect($markdown)->toContain('> outer');
    expect($markdown)->toContain('> > inner');
});

it('reads a list and its nesting', function () {
    $markdown = roundTrip("- one\n  - nested\n- two");

    expect($markdown)->toContain('- one');
    expect($markdown)->toContain('  - nested');
});

it('keeps a list that starts at another number', function () {
    expect(roundTrip("5. five\n6. six"))->toContain('5. five');
});

it('keeps a list that uses a closing parenthesis', function () {
    expect(roundTrip("1) one\n2) two"))->toContain('1) one');
});

it('reads a task list', function () {
    $markdown = roundTrip("- [ ] todo\n- [x] done");

    expect($markdown)->toContain('- [ ] todo');
    expect($markdown)->toContain('- [x] done');
});

it('reads a table with its column alignment', function () {
    $markdown = roundTrip("| A | B | C |\n|:--|:-:|--:|\n| 1 | 2 | 3 |");

    expect($markdown)->toContain('| :-- | :-: | --: |');
    expect($markdown)->toContain('| 1 | 2 | 3 |');
});

it('keeps a pipe inside a table cell in that cell', function () {
    // A pipe ends a cell whatever surrounds it, so a pipe that is written as it
    // is splits the cell in two and the table with it. The pipe of a cell is
    // written escaped, and the reader of the table takes the escape for what it
    // is rather than for a character of the text.
    $markdown = roundTrip("| A | B |\n| --- | --- |\n| a \\| b | c |");

    expect($markdown)->toContain('| a \\| b | c |');
});

it('keeps a pipe inside a code span in that cell', function () {
    $markdown = roundTrip("| A | B |\n| --- | --- |\n| `a \\| b` | c |");

    expect($markdown)->toContain('| `a \\| b` | c |');
});

it('keeps a pipe inside the label of a link in that cell', function () {
    $markdown = roundTrip("| A | B |\n| --- | --- |\n| [a \\| b](https://example.com) | c |");

    expect($markdown)->toContain('| [a \\| b](https://example.com) | c |');
});

it('reads a thematic break', function () {
    expect(roundTrip('above' . "\n\n---\n\n" . 'below'))->toContain('---');
});

it('reads an image back with its alt text', function () {
    Scratch::image('picture.png');

    $file = Scratch::path('reverse');
    saveDocument(
        '![A red square](picture.png)',
        $file,
        Configuration::create()->withOptions([
            'images' => Options::IMAGE_EMBED,
            'imageBasePath' => Scratch::directory(),
        ]),
    );

    expect((new WordToMarkdown($file))->convert())->toContain('![A red square]');
});

it('takes the images out of the document into a media directory', function () {
    Scratch::image('picture.png');
    $media = Scratch::path('media', '');

    $file = Scratch::path('reverse');
    saveDocument(
        '![A red square](picture.png)',
        $file,
        Configuration::create()->withOptions([
            'images' => Options::IMAGE_EMBED,
            'imageBasePath' => Scratch::directory(),
        ]),
    );

    $options = ReverseOptions::fromArray(['mediaDirectory' => $media]);
    $markdown = (new WordToMarkdown($file, $options))->convert();

    // The reference has to resolve, so the image has to be on disk under the name
    // the document uses for it.
    preg_match('/!\[[^\]]*\]\(([^)]+)\)/', $markdown, $match);

    expect($match)->not->toBeEmpty();
    expect(is_file($match[1]))->toBeTrue();
});

// Options

it('reads the block tree a document converts to', function () {
    $blocks = readBack('# Title');

    expect($blocks)->toHaveCount(1);
    expect($blocks[0]->is(Block::PARAGRAPH))->toBeTrue();
    expect($blocks[0]->attr('level'))->toBe(1);
});

it('reads a list as a list with items', function () {
    $blocks = readBack("- one\n- two");

    expect($blocks)->toHaveCount(1);
    expect($blocks[0]->is(Block::LIST))->toBeTrue();
    expect($blocks[0]->children)->toHaveCount(2);
    expect($blocks[0]->attr('ordered'))->toBeFalse();
});

it('can leave a table without a header row', function () {
    $options = ReverseOptions::fromArray(['tableHeader' => false]);

    $markdown = roundTrip("| A | B |\n| --- | --- |\n| 1 | 2 |", options: $options);

    // A GFM table always has a header, so with the first row left as a body row
    // the table gains an empty one.
    expect($markdown)->toContain('|  |  |');
});

it('reads another set of heading styles when told to', function () {
    $options = ReverseOptions::fromArray(['headingStyles' => ['CorpTitle']]);

    $file = Scratch::path('reverse');
    saveDocument(
        '# Title',
        $file,
        Configuration::create()->withStyles([Styles::HEADING_1 => 'CorpTitle']),
    );

    // A corporate style is not a heading as far as Markdown is concerned, so the
    // configuration has to say so.
    expect((new WordToMarkdown($file, $options))->convert())->toContain('# Title');
    expect((new WordToMarkdown($file))->convert())->toContain('Title');
});

// Escaping

it('leaves an underscore inside a word alone', function () {
    // CommonMark does not let an underscore between word characters delimit
    // anything, so nothing needs escaping and nothing is added.
    expect(Escaping::text('snake_case'))->toBe('snake_case');
});

it('escapes an underscore that could delimit emphasis', function () {
    // Inside a word an underscore cannot delimit anything; at the edge of one it
    // can, and there it has to be escaped.
    expect(Escaping::text('a_b c_d'))->toBe('a_b c_d');
    expect(Escaping::text('_leading'))->toBe('\_leading');
    expect(Escaping::text('a _b_ c'))->toBe('a \_b\_ c');
});

it('leaves a pair of underscores that cannot pair up alone', function () {
    // The opening run is followed by a space, so it cannot open, and the closing
    // run has nothing to close.
    expect(Escaping::text('__ foo bar__'))->toBe('__ foo bar__');
});

it('escapes backticks, asterisks and brackets', function () {
    expect(Escaping::text('a `b` *c* [d]'))->toBe('a \\`b\\` \\*c\\* \\[d\\]');
});

it('escapes a backslash', function () {
    expect(Escaping::text('a\\b'))->toBe('a\\\\b');
});

it('leaves a less-than sign that cannot open a tag alone', function () {
    expect(Escaping::text('a < b'))->toBe('a < b');
    expect(Escaping::text('<div>'))->toBe('\\<div>');
});

it('escapes an ampersand that could start an entity', function () {
    expect(Escaping::text('AT&T'))->toBe('AT&T');
    expect(Escaping::text('&amp;'))->toBe('\\&amp;');
});

it('escapes a pipe inside a table cell', function () {
    expect(Escaping::text('a | b', inTable: true))->toBe('a \\| b');
});

it('escapes a line that would start a block', function () {
    expect(Escaping::text('# not a heading', lineStart: true))->toBe('\\# not a heading');
    expect(Escaping::text('- not a list', lineStart: true))->toBe('\\- not a list');
    expect(Escaping::text('1986. not a list', lineStart: true))->toBe('1986\\. not a list');
    expect(Escaping::text('> not a quote', lineStart: true))->toBe('\\> not a quote');
});

it('leaves a line that starts with ordinary text alone', function () {
    expect(Escaping::text('ordinary text', lineStart: true))->toBe('ordinary text');
});

it('pads a code span so its own spaces survive', function () {
    // The specification strips one space from each end of a code span when both
    // are present, so content that really has them is padded once more.
    expect(Escaping::codeSpan(' x '))->toBe('`  x  `');
});

it('grows the fence of a code span around its own backticks', function () {
    expect(Escaping::codeSpan('a `b` c'))->toBe('``a `b` c``');
});

it('pads a code span whose content starts with a backtick', function () {
    // Without the padding the fence would not be the longest run and the span
    // would end at the wrong place.
    expect(Escaping::codeSpan('`x`'))->toBe('`` `x` ``');
});

it('has no code span for content that spans a blank line', function () {
    // A code span ends at a blank line rather than at its fence, so there is no
    // valid form for this and the characters have to be written out instead.
    expect(Escaping::codeSpan("a\n\nb"))->toBeNull();
});

// Idempotence

it('reads a document back to the same Markdown twice', function () {
    $markdown = <<<'MD'
        # Report

        Some **bold** text, some *italic*, some `code`, and a [link](https://example.test).

        > A quote with a list:
        >
        > - one
        > - two

        1. first
        2. second

        | A | B |
        |---|---|
        | 1 | 2 |

        ```php
        $x = 1;
        ```
        MD;

    $once = roundTrip($markdown);

    expect(roundTrip($once))->toBe($once);
});
