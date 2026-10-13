<?php

declare(strict_types=1);

use MarkdownWord\Format;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

/*
 * `:footnote[…]` as a Word footnote.
 *
 * The first test is the one the feature is for. PHPWord writes
 * `Writer\Word2007\Element\Footnote` as a paragraph of its own, so a note added to a
 * section splits the sentence it belongs to in two — and a sentence split in two is
 * not a sentence with a note in it. What is asserted is therefore not that a
 * reference exists but that the mark sits in the same `w:p` as the words on either
 * side of it, and that the sentence is still one paragraph.
 *
 * The second is the one that fails silently. A `w:footnoteReference w:id` that does
 * not name the `w:footnote w:id` holding the body is a document that opens without
 * complaint and shows the wrong note, so the two ids are compared rather than
 * counted.
 *
 * The rest are the negative halves: a document with no footnote in it must not carry
 * the part, the setting or the sentence about losing one, and the two writers with no
 * footnote writer at all must be given the source form instead of a mark they cannot
 * draw. Neither writer has an `Element/Footnote.php`, which is why nothing is emitted
 * for them to fail to swap out.
 *
 * Writing a document reaches the one known upstream deprecation; see
 * tests/Support/Upstream.
 */
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * Two sentences with a note in the first, wrapped across two source lines so that a
 * renderer which drew one paragraph per line would fail the count below.
 */
const FOOTNOTE_MIDDLE = <<<'MD'
    Revenue rose by four per cent,:footnote[Figures are unaudited.] It kept rising
    through the summer,:footnote[Q1 was up two per cent.] and so did the margin.

    A sentence with no note in it at all.
    MD;

/** The document as a file, so a part can be read out of it. */
function footnoteDocument(string $markdown): string
{
    $path = Scratch::path('footnote', '.docx');

    file_put_contents($path, toDocx($markdown));

    return $path;
}

/**
 * The paragraphs of a body part, as XML.
 *
 * Read through the tree rather than matched with a pattern, because the question
 * these tests ask is which runs are in which paragraph — and a pattern that spans
 * `</w:p>` cannot tell one paragraph from two.
 *
 * @return list<string>
 */
function footnoteParagraphsOf(string $xml): array
{
    $document = new DOMDocument();
    libxml_use_internal_errors(true);
    $document->loadXML($xml, LIBXML_NOCDATA);
    libxml_clear_errors();

    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $paragraphs = [];
    foreach ($xpath->query('//w:body//w:p') ?: [] as $paragraph) {
        $paragraphs[] = (string) $document->saveXML($paragraph);
    }

    return $paragraphs;
}

/** What a paragraph says, which is not the same as a piece of text somewhere in it. */
function footnoteTextOf(string $paragraph): string
{
    preg_match_all('~<w:t[^>]*>(.*?)</w:t>~s', $paragraph, $matches);

    return implode('', $matches[1]);
}

/**
 * The `w:id` of every footnote reference in a body, in document order.
 *
 * @return list<string>
 */
function footnoteMarksOf(string $xml): array
{
    preg_match_all('~<w:footnoteReference w:id="([^"]+)"~', $xml, $matches);

    return $matches[1];
}

/**
 * The `w:id` of every real footnote in a notes part, in order.
 *
 * The two entries PHPWord always writes — the separator and the continuation
 * separator — are not notes and are left out, so the ids compared against the marks
 * are the ones a reader could be shown.
 *
 * @return list<string>
 */
function footnoteBodiesOf(string $xml): array
{
    preg_match_all('~<w:footnote w:id="(-?\d+)"(?![^>]*w:type=)~', $xml, $matches);

    return $matches[1];
}

/**
 * The text of the footnote with that id, or null when the part carries no such note.
 */
function footnoteBodyTextOf(string $part, string $id): ?string
{
    if (preg_match('~<w:footnote w:id="' . preg_quote($id, '~') . '"(?![^>]*w:type=)(.*?)</w:footnote>~s', $part, $note) !== 1) {
        return null;
    }

    preg_match_all('~<w:t[^>]*>(.*?)</w:t>~s', $note[1], $text);

    return trim(implode('', $text[1]));
}

it('puts the mark in the sentence, in the sentence\'s own paragraph', function () {
    $document = TemplateFactory::xmlOf(footnoteDocument(FOOTNOTE_MIDDLE));
    $paragraphs = footnoteParagraphsOf($document);

    // Two paragraphs for two source paragraphs, and the whole of the first sentence
    // in the first of them. A mark written as a paragraph of its own makes three,
    // which is what this fails on.
    expect($paragraphs)->toHaveCount(2)
        ->and(footnoteTextOf($paragraphs[0]))->toBe(
            'Revenue rose by four per cent, It kept rising through the summer, and so did the margin.',
        )
        ->and(footnoteTextOf($paragraphs[1]))->toBe('A sentence with no note in it at all.');

    // The mark is between the words, not merely somewhere in the same paragraph: the
    // sentence reads on either side of it.
    $paragraph = $paragraphs[0];
    $before = strpos($paragraph, 'four per cent,');
    $mark = strpos($paragraph, '<w:footnoteReference');
    $after = strpos($paragraph, 'It kept rising');

    expect($mark)->toBeInt()
        ->and($mark)->toBeGreaterThan((int) $before)
        ->and($mark)->toBeLessThan((int) $after);
});

it('writes the body into word/footnotes.xml under the id the mark names', function () {
    $path = footnoteDocument(FOOTNOTE_MIDDLE);

    expect(TemplateFactory::hasPart($path, 'word/footnotes.xml'))->toBeTrue();

    $part = TemplateFactory::xmlOf($path, 'word/footnotes.xml');
    $marks = footnoteMarksOf(TemplateFactory::xmlOf($path));

    // The mark and the note are numbered by PHPWord's own `getRelationId() + 1`, and
    // an id that names nothing is a mark the reader cannot follow — a footnote
    // pointing at the wrong text, or at none. Compared as lists, not as counts.
    expect($marks)->toHaveCount(2)
        ->and(footnoteBodiesOf($part))->toBe($marks)
        ->and(footnoteBodyTextOf($part, $marks[0]))->toBe('Figures are unaudited.')
        ->and(footnoteBodyTextOf($part, $marks[1]))->toBe('Q1 was up two per cent.');
});

it('gives each footnote in a document its own mark and its own body', function () {
    // The same text twice is the case a counter that never advances gets wrong: both
    // marks come out pointing at the first note, and the second body is written with
    // nothing referring to it.
    $path = footnoteDocument("One,:footnote[the first note] two,:footnote[the second note] three.\n");

    $part = TemplateFactory::xmlOf($path, 'word/footnotes.xml');
    $marks = footnoteMarksOf(TemplateFactory::xmlOf($path));

    expect($marks)->toHaveCount(2)
        ->and($marks[0])->not->toBe($marks[1])
        ->and(footnoteBodyTextOf($part, $marks[0]))->toBe('the first note')
        ->and(footnoteBodyTextOf($part, $marks[1]))->toBe('the second note');
});

it('keeps a footnote in a table cell a footnote', function () {
    // A cell is written by a different path from a paragraph, and a note added to the
    // wrong container would come out as a row of its own.
    $path = footnoteDocument("| A | B |\n|:--|--:|\n| one | two,:footnote[in a cell] |\n");

    $marks = footnoteMarksOf(TemplateFactory::xmlOf($path));

    expect($marks)->toHaveCount(1)
        ->and(footnoteBodyTextOf(TemplateFactory::xmlOf($path, 'word/footnotes.xml'), $marks[0]))
        ->toBe('in a cell');
});

it('leaves a document with no footnote carrying no sign of one', function () {
    // The whole of what could leak: the part, the mark, and the sentence about losing
    // one. A document that never asked for a footnote reports nothing about them.
    $converter = new MarkdownToWord("# Chapter one\n\nPlain prose, with no notes.\n");
    $path = Scratch::path('no-footnote', '.docx');

    $converter->convert($path);

    expect(TemplateFactory::hasPart($path, 'word/footnotes.xml'))->toBeFalse()
        ->and(TemplateFactory::xmlOf($path))->not->toContain('footnoteReference')
        ->and(array_column($converter->pendingLosses(), 'feature'))->not->toContain('footnotes');
});

it('leaves nothing of its own behind for a writer to find', function () {
    // The handler holds no token and swaps nothing, so there is no string in the
    // finished document that exists only to be replaced. U+2063 is what a
    // placeholder is built out of, which is what makes its absence the assertion:
    // a renderer that emitted one would be asserting on its own spelling.
    //
    // Anchored on the part being there at all, so the absence is about what was left
    // in it rather than about a document that has no footnote to leave anything in.
    $path = footnoteDocument(FOOTNOTE_MIDDLE);

    expect(TemplateFactory::hasPart($path, 'word/footnotes.xml'))->toBeTrue()
        ->and(TemplateFactory::xmlOf($path))->not->toContain("\u{2063}")
        ->and(TemplateFactory::xmlOf($path, 'word/footnotes.xml'))->not->toContain("\u{2063}");
});

it('draws a footnote with no body as the characters that were typed', function () {
    // A mark pointing at an empty note is a mark the reader cannot resolve, so a
    // directive with nothing between the brackets is left as it was written rather
    // than turned into one. Both are in the document, because a handler that drew
    // the bodiless one as a note would also be drawing a mark pointing at nothing.
    $path = footnoteDocument("A bodied note,:footnote[with a body] and a bodiless one :footnote and a sentence.\n");

    expect(footnoteMarksOf(TemplateFactory::xmlOf($path)))->toHaveCount(1)
        ->and(TemplateFactory::textOf($path))
        ->toBe('A bodied note, and a bodiless one :footnote and a sentence.');
});

it('writes the note body as text, not as Markdown', function () {
    // The directives package parses a leaf label into a `Text` child rather than as
    // inline Markdown, so there is nothing to render: `*emphasis*` is eight characters
    // of prose in a note, and a renderer that parsed it would be doing a second pass
    // over a syntax that does not have one.
    $path = footnoteDocument("A sentence,:footnote[*emphasis* and `code`.]\n");

    $marks = footnoteMarksOf(TemplateFactory::xmlOf($path));

    expect($marks)->toHaveCount(1)
        ->and(footnoteBodyTextOf(TemplateFactory::xmlOf($path, 'word/footnotes.xml'), $marks[0]))
        ->toBe('*emphasis* and `code`.');
});

/**
 * The written text of a document, from whichever part of it carries the words.
 *
 * An `.odt` is a zip and an `.rtf` is not, so reading the file as a string finds the
 * ODT's words as binary noise and never at all. The part is what the reader opens,
 * which is the only place a mark that could not be drawn would survive to be seen.
 */
function footnoteWrittenText(string $path): string
{
    if (!str_ends_with($path, '.odt')) {
        return (string) file_get_contents($path);
    }

    $zip = new ZipArchive();
    $zip->open($path);
    $content = $zip->getFromName('content.xml');
    $zip->close();

    return $content === false ? '' : $content;
}

/*
 * A footnote in a writer that cannot carry one.
 *
 * Neither `Writer/ODText` nor `Writer/RTF` has an `Element/Footnote.php`, so a mark
 * emitted for them would be a reference to a note part that was never written — and,
 * with nothing to swap it out, it would reach the reader as-is. The source form is
 * the same answer `::toc` gets in the same two writers, and the loss is reported.
 */

it('draws the source form where a footnote would have gone', function (Format $format) {
    $path = Scratch::path('footnote-source-form', $format->extension());

    (new MarkdownToWord("A sentence,:footnote[the note] and its mark.\n"))->convertTo($format, $path);

    $written = footnoteWrittenText($path);

    expect($written)->toContain(':footnote[the note]')
        ->and($written)->toContain('A sentence')
        ->and($written)->toContain('and its mark.');
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);

it('leaves no mark of the library\'s own behind in either writer', function (Format $format) {
    // Nothing is emitted that a pass would have to take back out, so this is an
    // assertion about the shape of the answer rather than about a spelling: the
    // invisible separators, the characters a placeholder is made of, and any
    // reference to a note part neither writer writes.
    $path = Scratch::path('footnote-no-placeholder', $format->extension());

    (new MarkdownToWord("A sentence,:footnote[the note] and its mark.\n"))->convertTo($format, $path);

    $written = footnoteWrittenText($path);

    expect($written)->not->toContain("\u{2063}")
        ->and($written)->not->toContain('footnoteReference')
        ->and($written)->not->toContain('word/footnotes.xml');
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);

it('draws the source form in a writer reached after one that can carry footnotes', function (Format $format) {
    // One converter writing two formats is ordinary — a caller producing a `.docx` and
    // a `.rtf` of the same Markdown — and the handler has to be built for the format
    // of each render rather than kept from the first. A stale one leaves a `Footnote`
    // element in an element tree no such writer can draw: the mark and the note are
    // gone, the source form is not put back, and the reader is left with a sentence
    // a note used to be in and nothing to say so.
    $markdown = "A sentence,:footnote[the note] and its mark.\n";
    $converter = new MarkdownToWord($markdown);

    $converter->convert(Scratch::path('footnote-then-docx', '.docx'));

    $path = Scratch::path('footnote-then-elsewhere', $format->extension());
    $converter->convertTo($format, $path);

    expect(footnoteWrittenText($path))->toContain(':footnote[the note]')
        ->and(footnoteWrittenText($path))->not->toContain('footnoteReference');
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);

it('says a document lost the footnote it asked for', function (Format $format) {
    $converter = new MarkdownToWord("A sentence,:footnote[the note] and its mark.\n");
    $converter->convertTo($format, Scratch::path('footnote-loss', $format->extension()));

    expect(array_column($converter->pendingLosses(), 'feature'))->toContain('footnotes');
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);

it('reports no such loss for a .docx, which has the footnote', function () {
    $converter = new MarkdownToWord("A sentence,:footnote[the note] and its mark.\n");
    $converter->convertTo(Format::Docx, Scratch::path('footnote-docx-loss', '.docx'));

    expect(array_column($converter->pendingLosses(), 'feature'))->not->toContain('footnotes');
});

it('reports no such loss for a document that never asked for one', function (Format $format) {
    // The other half of the same rule: a feature nobody used is not a loss, and
    // `Survey` exists to keep the sentence off every document that did not earn it.
    $converter = new MarkdownToWord("# Chapter one\n\nPlain prose.\n");
    $converter->convertTo($format, Scratch::path('no-footnote-loss', $format->extension()));

    expect(array_column($converter->pendingLosses(), 'feature'))->not->toContain('footnotes');
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);
