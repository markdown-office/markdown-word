<?php

declare(strict_types=1);

use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Template\MarkdownTemplate;

/*
 * A space that a sentence needs, through the writer that folds a fragment's indent.
 *
 * Everything a document says goes through `NumberingMerger::remap()` on its way into a
 * template, and `remap()` hands its output to `compact()` to take out the indentation a
 * fragment arrives carrying. The two once shared one pattern — fold `>`, any
 * whitespace, `<` — which is right for an indent and wrong for a space, because a soft
 * line break in a wrapped Markdown paragraph is written as a run holding one space.
 *
 * Every assertion here is about *words*, and that is the point. The tests that passed
 * throughout this defect were checking the words, and they were right: `revenue` and
 * `growth` were both present, in order, with the right styling. Only a reader could tell
 * they had arrived as `revenuegrowth`.
 */

/** Every run's text in one string, so a space lost between two runs shows as lost. */
function runText(string $docx): string
{
    $xml = TemplateFactory::xmlOf($docx, 'word/document.xml');
    $found = [];

    preg_match_all('~<w:t(?:\s[^>]*)?>([^<]*)</w:t>~', $xml, $found);

    return implode('', array_map(
        static fn (string $run): string => html_entity_decode($run, ENT_QUOTES | ENT_XML1),
        $found[1],
    ));
}

it('keeps the space a wrapped line ends on', function () {
    $path = Scratch::path('softbreak', '.docx');

    saveDocument(
        "The quarter closed above plan for the third consecutive period, with revenue\ngrowth driven almost entirely by renewals.\n",
        $path,
    );

    expect(runText($path))->toContain('with revenue growth driven');
});

it('keeps it when the line wraps inside emphasis', function () {
    $path = Scratch::path('softbreak-emphasis', '.docx');

    saveDocument("A run that wraps **across the\nbreak** and carries on.\n", $path);

    expect(runText($path))->toContain('across the break');
});

it('keeps it inside a list item', function () {
    $path = Scratch::path('softbreak-list', '.docx');

    saveDocument(
        "- **The Contoso renewal**, signed in week 3 after an evaluation that had stalled\n  the previous quarter.\n",
        $path,
    );

    expect(runText($path))->toContain('that had stalled the previous quarter');
});

it('keeps it through a template, which is where the folding happens', function () {
    $output = Scratch::path('softbreak-template', '.docx');

    withoutUpstreamDeprecations(static function () use ($output): void {
        (new MarkdownTemplate(TemplateFactory::report()))
            ->insert("A paragraph whose author wrapped the source across a line\nand a second one.\n")
            ->save($output);
    });

    expect(runText($output))->toContain('across a line and a second one');
});

it('keeps it through a template for a list item, which folded separately', function () {
    $output = Scratch::path('softbreak-template-list', '.docx');

    withoutUpstreamDeprecations(static function () use ($output): void {
        (new MarkdownTemplate(TemplateFactory::report()))
            ->insert("- An item that wraps across\na line.\n")
            ->save($output);
    });

    expect(runText($output))->toContain('wraps across a line');
});

it('still takes the indentation out of a template', function () {
    // The reason any of this folds at all: PHPWord writes a fragment indented for a
    // person to read, and whitespace between a tag and its text *is* text to Word, so an
    // unfolded fragment puts the literal characters `<w:p>` at the start of every
    // paragraph it was inserted into. Both halves have to hold at once — a space kept
    // and an indent kept is the same defect pointing the other way.
    //
    // This goes through a template because that is the only path that folds. A document
    // written straight out is never put through the merger at all.
    $output = Scratch::path('softbreak-indent', '.docx');

    withoutUpstreamDeprecations(static function () use ($output): void {
        (new MarkdownTemplate(TemplateFactory::report()))->insert("One paragraph.\n")->save($output);
    });

    $xml = TemplateFactory::xmlOf($output, 'word/document.xml');

    // The XML declaration is followed by a newline in every Word document, including
    // the ones Word writes, so the search starts past it.
    $body = substr($xml, (int) strpos($xml, '?>') + 2);

    expect($body)->not->toMatch('~>[ \t]*\r?\n\s*<~');

    // The symptom that folding exists to prevent: the indent arriving as text, so the
    // reader sees the characters `<w:p>` at the top of every paragraph.
    expect(runText($output))->toContain('One paragraph.')
        ->and(runText($output))->not->toContain('<w:p');
});

it('does not join two words across a hard break either', function () {
    // A hard break is a `w:br`, and the line after it starts a new one, so this reads
    // as two lines rather than as one sentence with a space in it.
    $path = Scratch::path('hardbreak', '.docx');

    saveDocument("First line  \nSecond line\n", $path);

    expect(runText($path))->toContain('First line')->toContain('Second line');
});