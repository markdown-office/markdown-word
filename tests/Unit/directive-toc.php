<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\UnreadableDocument;
use MarkdownWord\Format;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Render\Directive\Toc;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Text\TextExtractor;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;
use MarkdownWord\Writer\TocPass;

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/*
 * `::toc` as a Word table-of-contents field.
 *
 * PHPWord's own TOC element cannot draw one: it writes a `PAGEREF` for every
 * title registered in `$phpWord->getTitles()` and this library registers none, so
 * with an empty list it emits a closing `fldChar` and no `begin` — a field that
 * starts nowhere. What is written here is five runs of the field Word itself
 * writes, with nothing between `separate` and `end` but a sentence saying what to
 * do.
 *
 * The empty result is the decision these tests pin hardest. A cached table of
 * contents from PHPWord carries a page number of `1` on every entry, and a reader
 * that does not refresh a field shows them as they are: a document whose contents
 * all claim to be on page one is worse than one that admits it has not built it.
 * So there are no cached entries, the `begin` is marked dirty, and `word/settings.xml`
 * carries `w:updateFields` so that Word builds it on open.
 *
 * That last one is asked of the document rather than of the writer, and the test
 * that says so is the negative one: a document with no `::toc` in it has no reason
 * to tell Word to re-evaluate every field it contains, so the setting must not
 * reach it.
 */

/** The document as a file, so a part can be read out of it. */
function tocDocument(string $markdown, ?Configuration $config = null): string
{
    $path = Scratch::path('toc', '.docx');

    file_put_contents($path, toDocx($markdown, $config));

    return $path;
}

function tocBody(string $markdown, ?Configuration $config = null): string
{
    return TemplateFactory::xmlOf(tocDocument($markdown, $config));
}

/**
 * The instruction of every field in a document, in the order they were written.
 *
 * Read out of the XML rather than assembled from the directive, so a test states
 * what the document says rather than what the renderer was asked for.
 *
 * @return list<string>
 */
function tocFieldsOf(string $document): array
{
    preg_match_all('~<w:instrText[^>]*>\s*(TOC[^<]*)</w:instrText>~', $document, $matches);

    return array_map('trim', $matches[1]);
}

function tocMarksOf(string $document, string $type): int
{
    return substr_count($document, 'w:fldCharType="' . $type . '"');
}

/**
 * The character properties of the run carrying a piece of text.
 *
 * Two runs compared with each other rather than against numbers, so a heading that
 * changes size does not turn these assertions into a statement about the size.
 */
function tocRunPropertiesOf(string $document, string $text): string
{
    preg_match('~<w:r>(?:(?!</w:r>).)*<w:t[^>]*>' . preg_quote($text, '~') . '</w:t>~s', $document, $run);
    preg_match('~<w:rPr>.*?</w:rPr>~s', $run[0] ?? '', $properties);

    return $properties[0] ?? '';
}

/** One paragraph of a document, so what a paragraph says is asked of the paragraph. */
function tocParagraphOf(string $document, string $text): string
{
    preg_match('~<w:p>(?:(?!</w:p>).)*' . preg_quote($text, '~') . '(?:(?!</w:p>).)*</w:p>~s', $document, $paragraph);

    return $paragraph[0] ?? '';
}

/** What a paragraph says, which is not the same as a piece of text somewhere in it. */
function tocTextOf(string $paragraph): string
{
    preg_match_all('~<w:t[^>]*>(.*?)</w:t>~s', $paragraph, $matches);

    return implode('', $matches[1]);
}

/**
 * The pass, given the tokens of a document written by hand rather than by a render.
 *
 * The documents below are the two the pass cannot rewrite into a field: one that is
 * not an archive, and one that has no body to rewrite. Everything else the pass does
 * is asserted through a conversion, which is the only way to know the two halves
 * agree.
 *
 * @param list<string> $placeholders
 */
function tocPass(array $placeholders): TocPass
{
    return new TocPass(array_map(
        static fn (string $placeholder): array => [
            'placeholder' => $placeholder,
            'instruction' => ' TOC \o "1-3" \h \z \u ',
        ],
        $placeholders,
    ));
}

it('draws a table of contents field for ::toc', function () {
    // The shape is the whole of it: a field is a `begin`, an instruction and an
    // `end`, and a document carrying only the last of those is a field Word has to
    // repair before it can show anything.
    $document = tocBody("::toc\n\n# One\n");

    expect(tocFieldsOf($document))->toBe(['TOC \o "1-6" \h \z \u'])
        ->and(tocMarksOf($document, 'begin'))->toBe(1)
        ->and(tocMarksOf($document, 'separate'))->toBe(1)
        ->and(tocMarksOf($document, 'end'))->toBe(1);
});

it('marks the field dirty so a reader builds it', function () {
    // `updateFields` is what Word honours on open; `w:dirty` is the same request on
    // the field itself, and is what a reader that only looks at the body goes by.
    expect(tocBody("::toc\n"))->toContain('w:fldCharType="begin" w:dirty="true"');
});

it('caches no entries, whose page numbers would all be one', function () {
    $path = tocDocument("::toc\n\n# One\n\n# Two\n");

    // Two headings, and between `separate` and `end` there is a sentence rather
    // than two entries claiming to be on page 1. This is the assertion that fails
    // first if anybody fills the field in from the headings at write time.
    expect(TemplateFactory::textOf($path))
        ->toBe('Press F9 to build the table of contents.' . "\n" . 'One' . "\n" . 'Two')
        ->and(TemplateFactory::xmlOf($path))->not->toContain('PAGEREF');
});

it('leaves no placeholder behind', function () {
    // The token the renderer puts in the element tree is an implementation
    // detail, and it is replaced rather than dropped: the field it stood for is in
    // the document, which is the difference between this working and this
    // silently deleting a paragraph.
    $path = tocDocument("::toc\n\n# One\n");

    expect(TemplateFactory::textOf($path))->not->toContain('MDWT')
        ->and(tocFieldsOf(TemplateFactory::xmlOf($path)))->toHaveCount(1);
});

it('takes the depth of the table from the directive', function () {
    expect(tocFieldsOf(tocBody("::toc{depth=3}\n")))->toBe(['TOC \o "1-3" \h \z \u']);
});

it('takes its depth from the heading levels the library draws', function () {
    // A heading level the renderer will not draw cannot be in the table, so the
    // default is `maxHeadingLevel` rather than the nine Word would take.
    $config = Configuration::create()->withOptions(['maxHeadingLevel' => 2]);

    expect(tocFieldsOf(tocBody("::toc\n", $config)))->toBe(['TOC \o "1-2" \h \z \u'])
        // And the directive is still the way to say something narrower than that.
        ->and(tocFieldsOf(tocBody("::toc{depth=3}\n", $config)))->toBe(['TOC \o "1-3" \h \z \u']);
});

it('writes a title above the field, in the heading look', function () {
    $document = tocBody("::toc{title=\"Contents\"}\n\n# One\n");

    // Above rather than beside it: a contents list under its own heading is what a
    // reader looks for.
    expect(strpos($document, 'Contents'))->toBeLessThan(strpos($document, 'TOC \\o'))
        // The heading look, taken from the same slot the document's own headings
        // come from — compared with one of them rather than with a size, which is
        // a number that changes.
        ->and(tocRunPropertiesOf($document, 'Contents'))
        ->toBe(tocRunPropertiesOf($document, 'One'));
});

it('keeps the title out of the table it titles', function () {
    // `\o` collects by built-in heading style, so a title carrying one lists
    // itself as the first entry. Word's own answer is a "TOC Heading" style, which
    // is a heading deliberately not one — and the document's own heading beside it
    // is what says the title's paragraph really is a different one.
    $document = tocBody("::toc{title=\"Contents\"}\n\n# One\n");
    $title = tocParagraphOf($document, 'Contents');

    expect(tocTextOf($title))->toBe('Contents')
        ->and($title)->not->toContain('w:pStyle')
        ->and(tocParagraphOf($document, 'One'))->toContain('w:pStyle w:val="Heading1"');
});

it('falls back to the paragraph look when a heading slot is nothing but a style name', function () {
    // A slot configured as a bare style id has no look to give: all it carries is
    // the name, and the name is what is being left off. The text is still written.
    $config = Configuration::create()->withStyles([Styles::HEADING_1 => 'CorpHeading']);

    $document = tocBody("::toc{title=\"Contents\"}\n\n# One\n", $config);
    $title = tocParagraphOf($document, 'Contents');

    expect(tocTextOf($title))->toBe('Contents')
        ->and($title)->not->toContain('<w:b w:val="1"/>')
        // While the document's own heading keeps the style the caller named.
        ->and(tocParagraphOf($document, 'One'))->toContain('w:pStyle w:val="CorpHeading"');
});

it('asks Word to build the table when the document opens', function () {
    // The setting is the difference between a field that is empty because nothing
    // has built it and one Word fills in on open.
    $settings = TemplateFactory::xmlOf(tocDocument("::toc\n\n# One\n"), 'word/settings.xml');

    expect($settings)->toContain('<w:updateFields w:val="true"/>');
});

it('leaves that setting out of a document with no table of contents', function () {
    // The other half of the test above. Word re-evaluates every field in a document
    // that carries this, and a document with no `::toc` has no reason to be one of
    // them — and one with nothing to build is asked to do it silently, which reads
    // as a document that changed while nobody was looking at it.
    //
    // Asserted as the value rather than as the element: PHPWord writes
    // `w:updateFields` into every document it writes, as `false`, and what a
    // document with a table of contents has that one without is not the element
    // but what it is set to.
    $settings = TemplateFactory::xmlOf(tocDocument("# One\n\nSome text.\n"), 'word/settings.xml');

    expect($settings)->toContain('<w:updateFields w:val="false"/>')
        ->and($settings)->not->toContain('<w:updateFields w:val="true"/>');
});

it('writes no page numbers when the directive says so', function () {
    expect(tocFieldsOf(tocBody("::toc{numbers=false}\n")))->toBe(['TOC \o "1-6" \n \h \z \u'])
        // Without the attribute the entries keep their numbers, so the switch is
        // the directive's and not the field's.
        ->and(tocFieldsOf(tocBody("::toc\n")))->toBe(['TOC \o "1-6" \h \z \u']);
});

it('writes a field for each ::toc in the document', function () {
    // Two directives with two different depths is the case that a pass matching
    // its placeholders carelessly gets wrong: both fields come out with the first
    // one's instruction, which is a table of contents at the wrong depth rather
    // than anything that looks broken.
    $document = tocBody("::toc\n\n# One\n\n::toc{depth=2}\n\n## Two\n");

    expect(tocFieldsOf($document))->toBe(['TOC \o "1-6" \h \z \u', 'TOC \o "1-2" \h \z \u'])
        ->and(tocMarksOf($document, 'begin'))->toBe(2)
        ->and(tocMarksOf($document, 'end'))->toBe(2);
});

it('leaves a document Word can open', function () {
    // The field is written into the element tree as a token and put back as markup
    // afterwards, which is two chances to produce XML Word refuses.
    $path = tocDocument("::toc{title=\"Contents\" depth=2}\n\n# One\n");

    expect(TemplateFactory::xmlIsValid($path))->toBeTrue();
});

it('leaves a token that is not a run of its own alone', function () {
    // The token is written as a run of its own, and the field goes where that run
    // was. Wrapped in a `w:hyperlink` it is not, and a document that says so is
    // left as it is rather than having a field written inside a link.
    $token = Toc::MARKER . '0' . Toc::MARKER;

    $path = TemplateFactory::rawPackage('toc-run', [
        'word/document.xml' => TemplateFactory::part('document', '<w:body><w:p><w:hyperlink><w:r><w:t>'
            . $token . '</w:t></w:r></w:hyperlink></w:p></w:body>'),
    ]);

    tocPass([$token])->applyTo($path);

    expect(TemplateFactory::xmlOf($path))->toContain($token)
        ->and(TemplateFactory::xmlOf($path))->not->toContain('fldChar');
});

it('says a document it cannot open is a file it could not write', function () {
    $path = Scratch::path('toc-pass', '.docx');
    file_put_contents($path, "# Markdown wearing the wrong extension\n");

    expect(fn () => tocPass([Toc::MARKER . '0' . Toc::MARKER])->applyTo($path))
        ->toThrow(UnreadableDocument::class, 'Unable to open');
});

it('says a package with no body is a broken document', function () {
    // A `.docx` with no body part is not one this library wrote, and the failure
    // that says so names the part rather than reporting an empty document.
    $path = TemplateFactory::rawPackage('toc-empty', ['word/styles.xml' => '<x/>']);

    expect(fn () => tocPass([Toc::MARKER . '0' . Toc::MARKER])->applyTo($path))
        ->toThrow(MalformedDocument::class, 'word/document.xml');
});

/**
 * The written text of a document, from whichever part of it carries the words.
 *
 * An `.odt` is a zip and an `.rtf` is not, so reading the file as a string finds
 * the ODT's words as binary noise and never at all. The part is what the reader
 * opens, which is the only place a token could have survived to be seen.
 */
function tocWrittenText(string $path): string
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
 * A table of contents in a writer that cannot carry one.
 *
 * The field is built by `TocPass`, which only `DocxWriter` runs. A token emitted
 * regardless would therefore survive into an `.odt` or an `.rtf` as a run of
 * invisible separators and the letters MDWT — a document carrying neither a table
 * of contents nor any sign that it tried to have one.
 */

it('writes no token into a format that cannot carry the field', function (Format $format) {
    $path = Scratch::path('toc-elsewhere', $format->extension());

    (new MarkdownToWord("::toc\n\n# Chapter one\n"))->convertTo($format, $path);

    $written = tocWrittenText($path);

    expect($written)->not->toContain(Toc::MARKER)
        ->and($written)->not->toContain('MDWT')
        ->and($written)->not->toContain(Toc::UNBUILT);
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);

it('leaves the source form where the field would have gone', function (Format $format) {
    // Not an empty line and not nothing. `::toc` is what the author wrote, and a
    // format that cannot do what it asked for is the one place where giving the
    // words back is more use to the reader than a silent blank.
    $path = Scratch::path('toc-source-form', $format->extension());

    (new MarkdownToWord("::toc\n\n# Chapter one\n"))->convertTo($format, $path);

    expect(tocWrittenText($path))->toContain('::toc');
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);

it('says a document lost the table of contents it asked for', function (Format $format) {
    $converter = new MarkdownToWord("::toc\n\n# Chapter one\n");
    $converter->convertTo($format, Scratch::path('toc-loss', $format->extension()));

    expect(array_column($converter->pendingLosses(), 'feature'))->toContain('table-of-contents');
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);

it('reports no such loss for a .docx, which has the field', function () {
    $converter = new MarkdownToWord("::toc\n\n# Chapter one\n");
    $converter->convertTo(Format::Docx, Scratch::path('toc-docx-loss', '.docx'));

    expect(array_column($converter->pendingLosses(), 'feature'))->not->toContain('table-of-contents');
});

it('reports no such loss for a document that never asked for one', function (Format $format) {
    // The other half of the same rule: a feature nobody used is not a loss, and
    // `Survey` exists to keep the sentence off every document that did not earn it.
    $converter = new MarkdownToWord("# Chapter one\n");
    $converter->convertTo($format, Scratch::path('no-toc-loss', $format->extension()));

    expect(array_column($converter->pendingLosses(), 'feature'))->not->toContain('table-of-contents');
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);

it('carries no token into the second format one converter writes', function () {
    // The handler is rebuilt per render rather than cached on the converter, and the
    // reason is this: a cached one keeps the format of the *first* render, so a
    // converter writing a `.docx` and then an `.odt` hands the second a field it can
    // take — emitting the token into a file no `TocPass` ever runs against. One
    // converter writing several documents is not hypothetical; it is what the
    // template renderer does.
    $docx = Scratch::path('twice', '.docx');
    $odt = Scratch::path('twice', '.odt');

    $converter = new MarkdownToWord("::toc\n\n# One\n");
    $converter->convertTo(Format::Docx, $docx);
    $converter->convertTo(Format::Odt, $odt);

    $zip = new ZipArchive();
    $zip->open($odt);
    $content = $zip->getFromName('content.xml');
    $zip->close();

    expect($content)->not->toContain(Toc::MARKER)
        ->and($content)->not->toContain('MDWT');
});

it('takes the second format\'s loss rather than the first\'s', function () {
    // The other half: the `.docx` carries the field, so the `.odt` is where the loss
    // is, and a handler frozen on the first render would report it for the `.docx`
    // instead — telling the reader of a document that has a table of contents in it
    // that the table of contents was left out.
    $converter = new MarkdownToWord("::toc\n\n# One\n");
    $converter->convertTo(Format::Docx, Scratch::path('order', '.docx'));
    $converter->convertTo(Format::Odt, Scratch::path('order', '.odt'));

    expect(array_column($converter->pendingLosses(), 'feature'))->toContain('table-of-contents');
});

it('is carried by toPhpWord(), which never writes and so never sets a format', function () {
    // `toPhpWord()` renders into a PhpWord the caller writes themselves, and writes
    // nothing — so the format property it would read is whatever it was left at. Left
    // null that reads as "not docx" and the handler draws the source form, in a
    // document the caller is about to write as a `.docx`, which is the format every
    // other part of the library defaults to.
    $converter = new MarkdownToWord("::toc\n\n# One\n");
    $phpWord = $converter->toPhpWord("::toc\n\n# One\n");

    // `TextExtractor` rather than reaching into the tree: it is what a caller reading
    // a rendered document uses, and the placeholder is a text node exactly as a
    // footnote's mark would be.
    $text = MarkdownWord\Text\TextExtractor::fromPhpWord($phpWord);

    expect($text)->not->toContain(Toc::MARKER);
});
