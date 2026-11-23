<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;

it('emphasis and strong become italic and bold runs', function () {
    $runs = renderRuns(renderElements("*italic*\n")[0]);

    expect(array_map(
        static fn (array $run): array => array_filter($run['font'] ?? [], static fn ($v): bool => $v === true),
        $runs,
    ))->toBe([['italic' => true]]);
});

it('strong becomes bold', function () {
    $runs = renderRuns(renderElements("**bold**\n")[0]);

    expect($runs[0]['text'])->toBe('bold');
    expect($runs[0]['font']['bold'] ?? false)->toBeTrue();
});

it('nested emphasis combines both flags', function () {
    $runs = renderRuns(renderElements("***both***\n")[0]);

    expect($runs[0]['text'])->toBe('both');
    expect($runs[0]['font']['bold'] ?? false)->toBeTrue();
    expect($runs[0]['font']['italic'] ?? false)->toBeTrue();
});

it('emphasis in the middle of a paragraph is isolated', function () {
    $runs = renderRuns(renderElements("plain *loud* plain\n")[0]);

    expect($runs)->toHaveCount(3);
    expect($runs[0])->toBe(['text' => 'plain ', 'font' => null]);
    expect($runs[1]['text'])->toBe('loud');
    expect($runs[1]['font']['italic'] ?? false)->toBeTrue();
    expect($runs[2])->toBe(['text' => ' plain', 'font' => null]);
});

it('underscore and asterisk emphasis are equivalent', function () {
    expect(renderText("_x_\n"))->toBe(renderText("*x*\n"));
    expect(renderRuns(renderElements("__x__\n")[0])[0]['font'])->toBe(renderRuns(renderElements("**x**\n")[0])[0]['font']);
});

it('code spans use the code font', function () {
    $runs = renderRuns(renderElements("`x = 1`\n")[0]);

    expect($runs[0]['text'])->toBe('x = 1');
    expect($runs[0]['font']['name'] ?? null)->toBe('Consolas');
});

it('code spans are not further parsed as emphasis', function () {
    expect(renderText("`*not italic*`\n"))->toBe('*not italic*');
});

it('code span delimiters are stripped with normalised spacing', function () {
    // Per the spec, a leading and trailing space is stripped when the content
    // is not all spaces.
    expect(renderText("` foo `\n"))->toBe('foo');
    expect(renderText("`` a`b ``\n"))->toBe('a`b');
});

it('entity references are decoded', function () {
    expect(renderText("AT&amp;T &copy;\n"))->toBe('AT&T ©');
});

it('backslash escapes are resolved', function () {
    expect(renderText("\\*not emphasis\\*\n"))->toBe('*not emphasis*');
});

it('hard break becomes a line break', function () {
    $elements = renderElements("one  \ntwo\n");
    $children = $elements[0]->getElements();

    $hasBreak = false;
    foreach ($children as $child) {
        if ($child instanceof TextBreak) {
            $hasBreak = true;
        }
    }

    expect($hasBreak)->toBeTrue();
});

it('backslash and br are also hard breaks', function () {
    foreach (["one\\\ntwo\n", "one<br>two\n", "one  \ntwo\n"] as $markdown) {
        expect(renderText($markdown))->toContain("\n");
    }
});

it('soft break becomes a space', function () {
    $runs = renderRuns(renderElements("one\ntwo\n")[0]);

    expect(implode('', array_column($runs, 'text')))->toBe('one two');
});

it('soft break can become a paragraph', function () {
    $config = \MarkdownWord\Configuration::create()
        ->withOptions(['softBreak' => \MarkdownWord\Configuration\Options::SOFT_BREAK_PARAGRAPH]);

    $elements = renderElements("one\ntwo\nthree\n", $config);

    expect($elements)->toHaveCount(3);
    expect(array_map(
        fn ($element): string => elementTextOf($element),
        $elements,
    ))->toBe(['one', 'two', 'three']);
});

it('a label across two lines breaks the paragraph it stands in', function () {
    // A label is parsed as inline Markdown, so a line ending in it is a line ending
    // in the document. The second line of the label starts a paragraph of its own,
    // and the prose after the label follows it there rather than staying behind
    // the first line.
    $config = Configuration::create()
        ->withOptions(['softBreak' => Options::SOFT_BREAK_PARAGRAPH]);

    $elements = renderElements("A :style[first\nsecond]{color=6D4BD1} B\n", $config);

    expect($elements)->toHaveCount(2)
        ->and(renderRunText($elements[0]))->toBe('A first')
        ->and(renderRunText($elements[1]))->toBe('second B')
        // The label is drawn, not its source form: the word carries the colour the
        // directive named.
        ->and(renderRuns($elements[0])[1]['font']['color'] ?? null)->toBe('6D4BD1');
});

it('inline link becomes a link element', function () {
    $children = renderElements("[text](https://example.com)\n")[0]->getElements();

    $links = array_values(array_filter($children, static fn ($e): bool => $e instanceof Link));

    expect($links)->toHaveCount(1);
    expect($links[0]->getText())->toBe('text');
});

it('resolves a reference link to its destination', function () {
    $links = array_values(array_filter(
        renderElements("[text][ref]\n\n[ref]: https://example.com\n")[0]->getElements(),
        static fn ($e): bool => $e instanceof Link,
    ));

    expect($links)->toHaveCount(1);
    expect($links[0]->getText())->toBe('text');
});

it('keeps the title of a link', function () {
    // PHPWord's own Link element has nowhere to put a title, so a link that has
    // one is written as a real `w:hyperlink` with a tooltip instead. Asserting
    // on the element tree would only see the placeholder, so the file is checked.
    $file = Scratch::path('link-title');
    saveDocument("[text](https://example.com \"The title\")\n", $file);

    $xml = TemplateFactory::xmlOf($file);

    // The title, which is the whole of what this test is named after. Without
    // this assertion a link that lost its title passed: the text and the
    // destination are the same either way, and the tooltip is the only place the
    // title appears.
    expect($xml)->toContain('w:tooltip="The title"');
    expect(TemplateFactory::textOf($file))->toContain('text');
    expect(TemplateFactory::targetsOf($file))->toContain('https://example.com');
});

it('plain link text is not split into runs', function () {
    $children = renderElements("[text](https://example.com)\n")[0]->getElements();

    expect($children)->toHaveCount(1);
    expect($children[0])->toBeInstanceOf(Link::class);
});

it('link with formatting still produces its text', function () {
    // PHPWord cannot nest runs inside a Link element, so the link is recorded
    // for the writer to rehydrate; the visible text must survive regardless.
    expect(renderText("[**bold** link](https://example.com)\n"))->toBe('bold link');
});

it('autolink becomes a link', function () {
    $links = array_values(array_filter(
        renderElements("Visit <https://example.com> now.\n")[0]->getElements(),
        static fn ($e): bool => $e instanceof Link,
    ));

    expect($links)->toHaveCount(1);
    expect($links[0]->getText())->toBe('https://example.com');
});

it('bare urls are autolinked', function () {
    $links = array_values(array_filter(
        renderElements("See https://example.com/x for more.\n")[0]->getElements(),
        static fn ($e): bool => $e instanceof Link,
    ));

    expect($links)->toHaveCount(1);
});

it('image alt text is preserved when the image cannot be embedded', function () {
    $config = \MarkdownWord\Configuration::create()
        ->withOptions(['images' => \MarkdownWord\Configuration\Options::IMAGE_PLACEHOLDER]);

    expect(renderText('![Logo](missing.png)', $config))->toContain('Logo');
});

it('images can be skipped entirely', function () {
    $config = \MarkdownWord\Configuration::create()
        ->withOptions(['images' => \MarkdownWord\Configuration\Options::IMAGE_SKIP]);

    expect(renderText('![Logo](missing.png)', $config))->toBe('');
});

it('strikethrough is rendered', function () {
    $runs = renderRuns(renderElements("~~gone~~\n")[0]);

    expect($runs[0]['text'])->toBe('gone');
    expect($runs[0]['font']['strike'] ?? false)->toBeTrue();
});

it('strikethrough combines with other formatting', function () {
    $runs = renderRuns(renderElements("**~~both~~**\n")[0]);

    expect($runs[0]['font']['bold'] ?? false)->toBeTrue();
    expect($runs[0]['font']['strike'] ?? false)->toBeTrue();
});

it('raw inline html is stripped but its text survives', function () {
    expect(renderText("Some <b>bold</b> text\n"))->toBe('Some bold text');
});

it('raw html can be preserved verbatim', function () {
    $config = \MarkdownWord\Configuration::create()
        ->withOptions(['html' => \MarkdownWord\Configuration\Options::HTML_PRESERVE]);

    expect(renderText("Some <b>bold</b> text\n", $config))->toContain('<b>bold</b>');
});

it('raw html can be dropped entirely', function () {
    $config = \MarkdownWord\Configuration::create()
        ->withOptions(['html' => \MarkdownWord\Configuration\Options::HTML_DROP]);

    // Only the tags are discarded; the words they wrapped are ordinary text
    // in the Markdown source and are kept.
    expect(renderText("Some <b>bold</b> text\n", $config))->toBe('Some bold text');
});

it('renders a task list marker as a checkbox', function () {
    // The marker is a node of its own in the syntax tree, not part of the item's
    // text. Handling the wrong class here would drop it silently.
    $runs = renderRuns(renderElements("- [ ] todo\n- [x] done\n")[0]);

    expect(renderText("- [ ] todo\n"))->toContain("\u{2610}");
    expect(renderText("- [x] done\n"))->toContain("\u{2612}");
    expect($runs[0]['text'] ?? null)->not->toBeNull();
});

it('carries the alt text of an embedded image', function () {
    Scratch::image('inline.png');

    $file = Scratch::path('image-alt');
    saveDocument('![A red square](inline.png)', $file, Configuration::create()->withOptions([
        'images' => Options::IMAGE_EMBED,
        'imageBasePath' => Scratch::directory(),
    ]));

    // An image with no alt text is in the document and its meaning is not, so the
    // text the Markdown supplied has to survive into the file.
    expect(TemplateFactory::xmlOf($file))->toContain('A red square');
});
