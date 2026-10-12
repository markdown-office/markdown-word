<?php

declare(strict_types=1);

use MarkdownWord\Reverse\Block;
use MarkdownWord\Reverse\Inline;
use MarkdownWord\Reverse\NumberingTable;
use MarkdownWord\Reverse\Options as ReverseOptions;
use MarkdownWord\Reverse\StyleTable;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\WordToMarkdown;
use MarkdownWord\Xml;

/*
 * Reading a Word document back into Markdown, at the seams.
 *
 * These are the places where the reader has to cope with a document rather than
 * produce one: a `basedOn` chain the document controls, a numbering reference
 * that points at nothing, a break written the way Word writes it, an image in
 * the shape Word draws it. The corpus suites in `reverse-conversion.php` cannot
 * reach any of them, because this library only ever writes the one shape of
 * document PHPWord produces.
 *
 * Assertions are made against the block tree and the exact Markdown rather than
 * against substrings: a `toContain` on the text of a quote passes whether the
 * quote came back as one block or as several siblings of the same text, which is
 * the defect quote nesting is checked structurally for.
 */

// Helpers

/**
 * A `.docx` written by this library with some of its parts replaced.
 *
 * The library's own output is the archive this starts from, because it is a
 * valid package by construction. A null value drops a part altogether, which is
 * how a document with no numbering part at all is made.
 *
 * @param array<string, string|null> $overrides Part name => its new contents.
 */
function reverseFixArchive(array $overrides, string $markdown = 'seed'): string
{
    $source = Scratch::path('reverse-fix-seed');
    saveDocument($markdown, $source);

    $in = new ZipArchive();
    if ($in->open($source) !== true) {
        throw new RuntimeException('Unable to read the seed archive.');
    }

    $target = Scratch::path('reverse-fix');
    $out = new ZipArchive();
    if ($out->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to write the patched archive.');
    }

    $carried = [];

    for ($index = 0; $index < $in->numFiles; $index++) {
        $name = (string) $in->getNameIndex($index);
        $carried[$name] = true;

        $contents = array_key_exists($name, $overrides) ? $overrides[$name] : $in->getFromIndex($index);

        if (is_string($contents)) {
            $out->addFromString($name, $contents);
        }
    }

    // A part that the library's output does not have is added rather than
    // replaced, so a fixture can introduce one the library never writes.
    foreach ($overrides as $name => $contents) {
        if (!isset($carried[$name]) && is_string($contents)) {
            $out->addFromString($name, $contents);
        }
    }

    $out->close();
    $in->close();

    return $target;
}

/**
 * A `word/document.xml` carrying the given body.
 *
 * Every namespace the reader looks in is declared, including the DrawingML ones
 * PHPWord's own output leaves out — it writes VML only.
 */
function reverseFixDocument(string $body): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document'
        . ' xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        . ' xmlns:o="urn:schemas-microsoft-com:office:office"'
        . ' xmlns:v="urn:schemas-microsoft-com:vml"'
        . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"'
        . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
        . '>'
        . '<w:body>' . $body . '<w:sectPr/></w:body>'
        . '</w:document>';
}

/** One paragraph, with whatever direct formatting is being tested. */
function reverseFixParagraph(string $text, string $properties = ''): string
{
    return '<w:p>'
        . ($properties === '' ? '' : '<w:pPr>' . $properties . '</w:pPr>')
        . '<w:r><w:t xml:space="preserve">' . $text . '</w:t></w:r>'
        . '</w:p>';
}

/**
 * A `w:numPr` naming a numbering definition and a level within it.
 */
function reverseFixNumbering(int $numId, int $level = 0): string
{
    return '<w:numPr><w:ilvl w:val="' . $level . '"/><w:numId w:val="' . $numId . '"/></w:numPr>';
}

/**
 * The document as a depth-first outline, so that a block that came back at the
 * wrong level or with the wrong parent cannot pass.
 *
 * @param list<Block> $blocks
 * @return list<string>
 */
function reverseFixOutline(array $blocks, int $depth = 0): array
{
    $outline = [];

    foreach ($blocks as $block) {
        $outline[] = $depth . ':' . $block->kind;
        $outline = [...$outline, ...reverseFixOutline($block->children, $depth + 1)];
    }

    return $outline;
}

/** The visible text of a block's own inlines, `[kind:alt]` for anything that is not a run. */
function reverseFixText(Block $block): string
{
    $text = '';

    foreach ($block->inlines as $inline) {
        $text .= match ($inline->kind) {
            Inline::TEXT => $inline->text,
            Inline::BREAK => "\n",
            default => '[' . $inline->kind . ':' . $inline->alt . ']',
        };
    }

    return $text;
}

/**
 * @return list<Block>
 */
function reverseFixTree(string $markdown): array
{
    $file = Scratch::path('reverse-fix');
    saveDocument($markdown, $file);

    return (new WordToMarkdown($file))->read($file);
}

function reverseFixMarkdown(string $markdown): string
{
    $file = Scratch::path('reverse-fix');
    saveDocument($markdown, $file);

    return (new WordToMarkdown($file))->convert();
}

/**
 * Run a snippet of PHP in a child process and report how it went.
 *
 * A memory limit cannot be imposed on the process running the suite, and
 * exhausting it is a fatal error that ends the run rather than failing a test. A
 * child process is the only way to assert that a document converts within a
 * budget, so a regression is a failed expectation rather than a dead suite.
 *
 * @return array{0: int, 1: string} The exit status and everything it printed.
 */
function reverseFixInChildProcess(string $code): array
{
    $script = Scratch::path('reverse-fix-child', '.php');

    file_put_contents(
        $script,
        "<?php\n\ndeclare(strict_types=1);\n\nrequire "
        . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)
        . ";\n\n"
        . $code,
    );

    // A child told to be small can die of the memory limit before a destructor runs,
    // and a PHP fatal skips destructors — so whatever it had written is left behind.
    // `sys_temp_dir` redirects both `sys_get_temp_dir()` and PHPWord's
    // `Settings::getTempDir()`, which falls back to the former, so one setting covers
    // what the child writes and what the dependency writes for it. The parent empties
    // `tmp/pest` after the test either way.
    $temporary = Scratch::path('read-fix-temp', '');

    if (!is_dir($temporary)) {
        mkdir($temporary, 0o777, true);
    }

    $output = [];
    $status = 1;

    exec(
        sprintf(
            '%s -d memory_limit=128M -d sys_temp_dir=%s -d error_reporting="E_ALL & ~E_DEPRECATED" %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($temporary),
            escapeshellarg($script),
        ),
        $output,
        $status,
    );

    return [$status, implode("\n", $output)];
}

/**
 * A `word/styles.xml` whose styles form one long `basedOn` chain.
 *
 * `S0` is based on `S1`, `S1` on `S2`, and so on; the last style is the one that
 * carries an indentation, so recovering the indentation of `S0` means walking
 * the whole chain.
 */
function reverseFixStyleChain(int $length, int $indent = 720): string
{
    $styles = '';

    for ($index = 0; $index < $length - 1; $index++) {
        $styles .= sprintf(
            '<w:style w:type="paragraph" w:styleId="S%d"><w:basedOn w:val="S%d"/></w:style>',
            $index,
            $index + 1,
        );
    }

    $styles .= sprintf(
        '<w:style w:type="paragraph" w:styleId="S%d"><w:pPr><w:ind w:left="%d"/></w:pPr></w:style>',
        $length - 1,
        $indent,
    );

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . $styles
        . '</w:styles>';
}

/**
 * The same, plus the cycle two of the styles make, for the guard that has to
 * survive the cap.
 */
function reverseFixCycledStyles(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:style w:type="paragraph" w:styleId="Quote"><w:basedOn w:val="Loop"/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Loop"><w:basedOn w:val="Quote"/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Body"><w:basedOn w:val="Base"/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Base">'
        . '<w:pPr><w:ind w:left="720"/><w:jc w:val="center"/></w:pPr></w:style>'
        . '</w:styles>';
}

// Quotes

it('reads a multi-paragraph block quote as one quote', function () {
    // The defect this pins: the second paragraph closed the quote the first one
    // had opened and opened a fresh one, so one two-paragraph quote came back as
    // two sibling quotes with a blank line between them.
    $markdown = "> First quote line\n>\n> Second quote line\n\nafter";

    expect(reverseFixOutline(reverseFixTree($markdown)))->toBe([
        '0:quote',
        '1:paragraph',
        '1:paragraph',
        '0:paragraph',
    ]);

    expect(reverseFixMarkdown($markdown))->toBe($markdown);
});

it('reads a quote nested two deep as one quote at each level', function () {
    // The inner level has to keep its two paragraphs together as well, as a
    // sibling of the outer quote's own paragraphs rather than inside one of them.
    $markdown = "> outer one\n>\n> > inner one\n> >\n> > inner two\n>\n> outer two";

    expect(reverseFixOutline(reverseFixTree($markdown)))->toBe([
        '0:quote',
        '1:paragraph',
        '1:quote',
        '2:paragraph',
        '2:paragraph',
        '1:paragraph',
    ]);

    expect(reverseFixMarkdown($markdown))->toBe($markdown);
});

it('closes only the inner quote when the outer one carries on', function () {
    // Coming back up a level ends the inner quote and nothing else: the outer
    // one is still open, so the paragraph that follows belongs in it.
    $markdown = "> outer one\n>\n> > inner\n>\n> outer two";

    expect(reverseFixOutline(reverseFixTree($markdown)))->toBe([
        '0:quote',
        '1:paragraph',
        '1:quote',
        '2:paragraph',
        '1:paragraph',
    ]);

    expect(reverseFixMarkdown($markdown))->toBe($markdown);
});

it('keeps a list and a closing paragraph in the same quote', function () {
    // A list inside a quote is the awkward case: Word records it as an indented
    // numbered paragraph, so the list is grouped first and the paragraph after it
    // still has to find the quote it belongs to.
    $markdown = "> A quote with a list:\n>\n> - one\n> - two\n>\n> And a closing line.";

    expect(reverseFixOutline(reverseFixTree($markdown)))->toBe([
        '0:quote',
        '1:paragraph',
        '1:list',
        '2:item',
        '3:paragraph',
        '2:item',
        '3:paragraph',
        '1:paragraph',
    ]);

    expect(reverseFixMarkdown($markdown))->toBe($markdown);
});

it('reads a single-paragraph quote as one quote', function () {
    expect(reverseFixOutline(reverseFixTree('> only')))->toBe([
        '0:quote',
        '1:paragraph',
    ]);
});

it('closes a quote before a paragraph that is not indented into it', function () {
    expect(reverseFixOutline(reverseFixTree("> quoted\n\nplain")))->toBe([
        '0:quote',
        '1:paragraph',
        '0:paragraph',
    ]);

    // And an unindented paragraph does not become a quote of its own, which is
    // the case the reuse must not weaken: reuse is keyed on the depth the unit
    // reports, and a paragraph that reports none opens nothing.
    expect(reverseFixOutline(reverseFixTree("> quoted\n\nplain\n\n> another")))->toBe([
        '0:quote',
        '1:paragraph',
        '0:paragraph',
        '0:quote',
        '1:paragraph',
    ]);
});

// Style chains

it('stops following a basedOn chain at the configured depth', function () {
    $xml = reverseFixStyleChain(100);

    // A hundred styles deep with a cap of eight: only the last eight of them
    // contribute, so `S92` can still reach the indentation at the bottom and
    // `S91` cannot. A table each, because a resolution is cached — the second
    // question would otherwise be answered out of the first one's cache.
    expect((new StyleTable(Xml::parse($xml), 8))->indentOf('S92'))->toBe(720);
    expect((new StyleTable(Xml::parse($xml), 8))->indentOf('S91'))->toBe(0);

    // The same boundary at the default cap of thirty-two, measured the same way.
    $shallow = reverseFixStyleChain(40);

    expect((new StyleTable(Xml::parse($shallow)))->indentOf('S8'))->toBe(720);
    expect((new StyleTable(Xml::parse($shallow)))->indentOf('S7'))->toBe(0);
});

it('resolves a chain Word could plausibly write', function () {
    // The default cap of thirty-two is far above anything Word nests, so an
    // ordinary document is unaffected by it.
    $table = new StyleTable(Xml::parse(reverseFixStyleChain(12)), 32);

    expect($table->indentOf('S0'))->toBe(720);
});

it('still terminates on a cycle in the basedOn chain', function () {
    // The cycle guard has to survive the depth cap. A style that refers back to
    // one already being resolved has nothing to contribute, rather than being a
    // reason to keep walking.
    $table = new StyleTable(Xml::parse(reverseFixCycledStyles()));

    expect($table->indentOf('Quote'))->toBe(0);
    expect($table->alignmentOf('Quote'))->toBe('');

    // And a style that inherits from a resolved one still resolves, which is what
    // would fail if the guard were left set after a style was done with.
    expect($table->indentOf('Body'))->toBe(720);
    expect($table->alignmentOf('Body'))->toBe('center');
});

it('converts a document whose basedOn chain is absurdly deep', function () {
    // A chain far deeper than Word writes is a few megabytes of XML and a few
    // kilobytes zipped, which is to say it is cheap to send. Resolving it
    // exhausted memory in a way nothing could catch; it now converts, and the
    // paragraph keeps its text.
    $file = reverseFixArchive([
        'word/styles.xml' => reverseFixStyleChain(20000),
        'word/document.xml' => reverseFixDocument(
            reverseFixParagraph('deep', '<w:pStyle w:val="S0"/>'),
        ),
    ]);

    [$status, $output] = reverseFixInChildProcess(
        'echo (new MarkdownWord\\WordToMarkdown(' . var_export($file, true) . '))->convert();',
    );

    expect($status)->toBe(0, 'The child process reported: ' . $output);
    expect($output)->toContain('deep');
});

// Numbering

it('reads the outermost level of a numbering definition', function () {
    // `root()` is what a paragraph falls back on when the level it names is not
    // one the definition has. The levels are deliberately out of order in the
    // part, because the outermost is the lowest `w:ilvl` rather than the first
    // one written.
    $numbering = new NumberingTable(Xml::parse(<<<'XML'
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
            <w:abstractNum w:abstractNumId="1">
                <w:lvl w:ilvl="2"><w:start w:val="9"/><w:numFmt w:val="upperLetter"/><w:lvlText w:val="%3."/></w:lvl>
                <w:lvl w:ilvl="0"><w:start w:val="3"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1)"/></w:lvl>
            </w:abstractNum>
            <w:num w:numId="7"><w:abstractNumId w:val="1"/></w:num>
        </w:numbering>
        XML));

    expect($numbering->root(7))->toBe(['format' => 'decimal', 'text' => '%1)', 'start' => 3]);

    // A definition the part does not have is not an error; there is simply no
    // root to give.
    expect($numbering->root(404))->toBeNull();
});

it('leaves a paragraph alone when its numbering definition is not in the document', function () {
    // A `w:numId` pointing at nothing is what a hand-edited document, or a tool
    // that wrote half of what it changed, contains. The paragraph has no marker
    // to recover, so it stays the paragraph it visibly is rather than becoming a
    // list whose numbering is a guess.
    $file = reverseFixArchive([
        'word/numbering.xml' => <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
                <w:abstractNum w:abstractNumId="1">
                    <w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1."/></w:lvl>
                </w:abstractNum>
                <w:num w:numId="7"><w:abstractNumId w:val="1"/></w:num>
                <w:num w:numId="9"><w:abstractNumId w:val="404"/></w:num>
            </w:numbering>
            XML,
        'word/document.xml' => reverseFixDocument(
            reverseFixParagraph('known', reverseFixNumbering(7))
            . reverseFixParagraph('dangling abstract', reverseFixNumbering(9))
            . reverseFixParagraph('dangling number', reverseFixNumbering(42))
            . reverseFixParagraph('plain'),
        ),
    ]);

    expect(reverseFixOutline((new WordToMarkdown($file))->read($file)))->toBe([
        '0:list',
        '1:item',
        '2:paragraph',
        '0:paragraph',
        '0:paragraph',
        '0:paragraph',
    ]);

    expect((new WordToMarkdown($file))->convert())->toBe(
        "1. known\n\ndangling abstract\n\ndangling number\n\nplain",
    );
});

it('takes the outermost level when a paragraph names a level the definition lacks', function () {
    // `w:ilvl="4"` on a definition with two levels is out of range rather than
    // broken. The list is real, so what is missing is only the shape of one of
    // its levels, and the outermost is the best answer available.
    $file = reverseFixArchive([
        'word/numbering.xml' => <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
                <w:abstractNum w:abstractNumId="1">
                    <w:lvl w:ilvl="0"><w:start w:val="3"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1)"/></w:lvl>
                    <w:lvl w:ilvl="1"><w:start w:val="1"/><w:numFmt w:val="lowerLetter"/><w:lvlText w:val="%2."/></w:lvl>
                </w:abstractNum>
                <w:num w:numId="7"><w:abstractNumId w:val="1"/></w:num>
            </w:numbering>
            XML,
        'word/document.xml' => reverseFixDocument(
            reverseFixParagraph('out of range', reverseFixNumbering(7, 4)),
        ),
    ]);

    $blocks = (new WordToMarkdown($file))->read($file);

    expect(reverseFixOutline($blocks))->toBe(['0:list', '1:item', '2:paragraph']);
    expect($blocks[0]->attrs)->toMatchArray([
        'numId' => 7,
        'ordered' => true,
        // The outermost level's shape, not the one that was asked for.
        'format' => 'decimal',
        'start' => 3,
        'delimiter' => ')',
    ]);

    expect((new WordToMarkdown($file))->convert())->toBe('3) out of range');
});

it('falls back to a numbered list when a definition has no levels at all', function () {
    // A `w:num` pointing at an abstract definition that defines nothing is legal
    // and says nothing. There is no shape to read, so the one thing every
    // numbered list has in common is used, and the document still converts.
    $file = reverseFixArchive([
        'word/numbering.xml' => <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
                <w:abstractNum w:abstractNumId="2"/>
                <w:num w:numId="8"><w:abstractNumId w:val="2"/></w:num>
            </w:numbering>
            XML,
        'word/document.xml' => reverseFixDocument(
            reverseFixParagraph('shapeless', reverseFixNumbering(8)),
        ),
    ]);

    $blocks = (new WordToMarkdown($file))->read($file);

    expect(reverseFixOutline($blocks))->toBe(['0:list', '1:item', '2:paragraph']);
    expect($blocks[0]->attrs)->toMatchArray([
        'numId' => 8,
        'ordered' => true,
        'format' => 'decimal',
        'start' => 1,
        'delimiter' => '.',
    ]);

    expect((new WordToMarkdown($file))->convert())->toBe('1. shapeless');
});

it('reads a list whose numbering part is missing altogether', function () {
    // The degenerate case of a dangling reference: there is no part at all.
    $file = reverseFixArchive(
        [
            'word/numbering.xml' => null,
            'word/document.xml' => reverseFixDocument(
                reverseFixParagraph('one', reverseFixNumbering(1))
                . reverseFixParagraph('two', reverseFixNumbering(1)),
            ),
        ],
    );

    expect(reverseFixOutline((new WordToMarkdown($file))->read($file)))->toBe([
        '0:paragraph',
        '0:paragraph',
    ]);
});

// Inlines

it('reads a break and a tab written inside a run', function () {
    // This is the form Word writes: the break and the tab are children of the
    // run. The sibling form PHPWord emits is the only one the library's own
    // output exercises, so without this every hand-authored Word document would
    // silently lose its line breaks.
    $file = reverseFixArchive([
        'word/document.xml' => reverseFixDocument(
            '<w:p><w:r>'
            . '<w:t xml:space="preserve">one</w:t>'
            . '<w:br/>'
            . '<w:t xml:space="preserve">two</w:t>'
            . '<w:tab/>'
            . '<w:t xml:space="preserve">three</w:t>'
            . '</w:r></w:p>',
        ),
    ]);

    $blocks = (new WordToMarkdown($file))->read($file);

    expect(reverseFixOutline($blocks))->toBe(['0:paragraph']);
    expect(reverseFixText($blocks[0]))->toBe("one\ntwo\tthree");

    // A break inside a paragraph is a hard break, and a tab stays a tab: both are
    // invisible characters that a soft break or a run of spaces would lose.
    expect((new WordToMarkdown($file))->convert())->toBe("one  \ntwo\tthree");
});

it('reads a break and a tab in a formatted run', function () {
    // The run's formatting rides along on the tab as well as on the text, so
    // that `w:tab` is not silently promoted out of the bold it was written in.
    $file = reverseFixArchive([
        'word/document.xml' => reverseFixDocument(
            '<w:p><w:r>'
            . '<w:rPr><w:b w:val="1"/></w:rPr>'
            . '<w:t xml:space="preserve">bold</w:t>'
            . '<w:tab/>'
            . '<w:t xml:space="preserve">tail</w:t>'
            . '</w:r></w:p>',
        ),
    ]);

    $blocks = (new WordToMarkdown($file))->read($file);

    expect(reverseFixText($blocks[0]))->toBe("bold\ttail");
    expect($blocks[0]->inlines[0]->bold)->toBeTrue();
});

it('reads a page break as nothing rather than as a line break', function () {
    // A `w:br` with a type is a page or column break: layout rather than
    // content, and turning it into a hard break would put a line break in the
    // Markdown that the author never asked for.
    $file = reverseFixArchive([
        'word/document.xml' => reverseFixDocument(
            '<w:p><w:r>'
            . '<w:t xml:space="preserve">one</w:t>'
            . '<w:br w:type="page"/>'
            . '<w:t xml:space="preserve">two</w:t>'
            . '</w:r></w:p>',
        ),
    ]);

    expect(reverseFixText((new WordToMarkdown($file))->read($file)[0]))->toBe('onetwo');
});

// Images

it('reads an image written as DrawingML, which is what Word writes', function () {
    // PHPWord only ever emits VML, so this is the shape of picture a real Word
    // document contains and the library's own output never does — and the one
    // that carries the alt text a reader with no image support falls back to.
    $image = Scratch::image('picture.png');

    $file = reverseFixArchive([
        'word/_rels/document.xml.rels' => <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                <Relationship Id="rId100" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>
            </Relationships>
            XML,
        'word/media/image1.png' => (string) file_get_contents($image),
        'word/document.xml' => reverseFixDocument(
            '<w:p><w:r><w:drawing>'
            . '<wp:inline distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="1905000" cy="1905000"/>'
            . '<wp:docPr id="1" name="Picture 1" descr="A red square"/>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic>'
            . '<pic:blipFill><a:blip r:embed="rId100"/></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="1905000" cy="1905000"/></a:xfrm></pic:spPr>'
            . '</pic:pic>'
            . '</a:graphicData></a:graphic>'
            . '</wp:inline>'
            . '</w:drawing></w:r></w:p>',
        ),
    ]);

    $blocks = (new WordToMarkdown($file))->read($file);

    expect(reverseFixOutline($blocks))->toBe(['0:paragraph']);
    expect($blocks[0]->inlines[0]->kind)->toBe(Inline::IMAGE);
    expect($blocks[0]->inlines[0]->alt)->toBe('A red square');
    expect($blocks[0]->inlines[0]->target)->toBe('media/image1.png');

    expect((new WordToMarkdown($file))->convert())->toBe('![A red square](media/image1.png)');
});

it('takes a DrawingML image out of the archive into a media directory', function () {
    $image = Scratch::image('picture.png');
    $media = Scratch::path('reverse-fix-media', '');

    $file = reverseFixArchive([
        'word/_rels/document.xml.rels' => <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                <Relationship Id="rId100" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>
            </Relationships>
            XML,
        'word/media/image1.png' => (string) file_get_contents($image),
        'word/document.xml' => reverseFixDocument(
            '<w:p><w:r><w:drawing>'
            . '<wp:inline>'
            . '<wp:docPr id="1" name="Picture 1" descr="A red square"/>'
            . '<a:graphic><a:graphicData><pic:pic>'
            . '<pic:blipFill><a:blip r:embed="rId100"/></pic:blipFill>'
            . '</pic:pic></a:graphicData></a:graphic>'
            . '</wp:inline>'
            . '</w:drawing></w:r></w:p>',
        ),
    ]);

    $options = ReverseOptions::fromArray(['mediaDirectory' => $media]);

    expect((new WordToMarkdown($file, $options))->convert())->toBe('![A red square](' . $media . '/image1.png)');
    expect(is_file($media . '/image1.png'))->toBeTrue();
});

it('reads a DrawingML image with no alt text as an image with no alt text', function () {
    // Word writes an empty `descr` for a picture nobody described. That is not
    // an error, and it is not the picture's name either: the name is a title, and
    // the description is what a reader without images sees.
    $file = reverseFixArchive([
        'word/document.xml' => reverseFixDocument(
            '<w:p><w:r><w:drawing>'
            . '<wp:inline>'
            . '<wp:docPr id="1" name="Picture 1" descr=""/>'
            . '<a:graphic><a:graphicData><pic:pic>'
            . '<pic:blipFill><a:blip r:embed="rId100"/></pic:blipFill>'
            . '</pic:pic></a:graphicData></a:graphic>'
            . '</wp:inline>'
            . '</w:drawing></w:r></w:p>',
        ),
    ]);

    $blocks = (new WordToMarkdown($file))->read($file);

    expect($blocks[0]->inlines[0]->kind)->toBe(Inline::IMAGE);
    expect($blocks[0]->inlines[0]->alt)->toBe('');

    // The relationship is missing, so there is nothing to point at; the image
    // is still an image, with a destination that names nothing.
    expect((new WordToMarkdown($file))->convert())->toBe('![]()');
});
