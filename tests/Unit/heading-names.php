<?php

declare(strict_types=1);

use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Writer\HeadingNamePass;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

/*
 * The `w:name` of this library's heading styles.
 *
 * A `.docx` identifies a built-in style by the `w:name` in its definition, and Word
 * spells those `heading 1` … `heading 9`. A definition of `w:styleId="Heading1"`
 * carrying `w:name="Heading1"` is a style *called* Heading1 — it resembles the built-in
 * heading and is not it.
 *
 * Nothing else is wrong with such a document, which is why it survived: it looks
 * right, every property in it is plausible, and a table of contents is the one thing
 * that notices. Word resolves a `w:pStyle` to a styleId and a `TOC \o` to a set of
 * *names*, so it finds no headings to collect and says:
 *
 * > Ein Inhaltsverzeichnis erstellen? Beginnen Sie damit, dass Sie ein
 * > Überschriftenformat aus dem Formatvorlagenkatalog auf den ausgewählten Text
 * > anwenden.
 *
 * Every assertion below is on the name in the definition rather than on whether a
 * heading looks like one, because that is the only part that was wrong.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * The `w:name` given to each heading style, keyed by its `w:styleId`.
 *
 * Headings only: `word/styles.xml` also carries `Normal`, `FootnoteReference` and
 * whichever of the built-in styles a template brought, none of which is one of these.
 */
function headingStyleNames(string $docx): array
{
    $found = [];

    foreach (TemplateFactory::styleDefinitions($docx) as $id => $definition) {
        if (str_starts_with($id, 'Heading')) {
            $found[$id] = TemplateFactory::styleNameOf($definition);
        }
    }

    return $found;
}

it('names a heading style the way Word spells the built-in one', function () {
    $path = Scratch::path('heading-names');
    (new MarkdownToWord("# One\n\n## Two\n"))->save($path);

    // The name is the whole of it. The styleId does not change, because that is what
    // a `w:pStyle` in the body points at, and changing it would break every heading
    // the document already has.
    expect(headingStyleNames($path))
        ->toHaveKey('Heading1', 'heading 1')
        ->toHaveKey('Heading2', 'heading 2');
});

it('names all six of them, not only the levels a document used', function () {
    // The definitions are written for every level whether or not a heading used it,
    // so a document with one `#` still carries a `Heading6` — and a TOC at depth 6
    // needs that one named as well.
    $path = Scratch::path('heading-names-all');
    (new MarkdownToWord("# One\n"))->save($path);

    $names = headingStyleNames($path);

    expect(array_keys($names))->toBe(['Heading1', 'Heading2', 'Heading3', 'Heading4', 'Heading5', 'Heading6'])
        ->and(array_values(array_unique($names)))->toBe(['heading 1', 'heading 2', 'heading 3', 'heading 4', 'heading 5', 'heading 6']);
});

it('leaves the rest of a heading definition alone', function () {
    // A document that set a size and a colour means them. Only the name was wrong, so
    // only the name changes: rewriting the definition would discard what the author
    // asked for to fix something nobody else could see.
    $path = Scratch::path('heading-names-kept');
    (new MarkdownToWord(
        '# One',
        overrides: ['styles' => ['heading.1' => ['size' => 20, 'color' => 'AA0000']]],
    ))->save($path);

    $definition = TemplateFactory::styleDefinitions($path)['Heading1'];

    // Twenty points is forty half-points, which is what a `w:sz` holds.
    expect(TemplateFactory::styleNameOf($definition))->toBe('heading 1')
        ->and($definition)->toContain('<w:color w:val="AA0000"/>')
        ->and($definition)->toContain('<w:sz w:val="40"/>');
});

it('leaves a style of the document\'s own naming alone', function () {
    // A slot pointed at a name outside the library's is not one of the built-in
    // headings, whatever it is called. Giving it a built-in name would change how
    // every reader draws it.
    //
    // `StyleRegistrar` skips writing a definition for such a slot — the name belongs
    // to whatever carries it — so what is asserted here is that nothing was renamed:
    // there is no `Heading1` to rename, and the heading kept the name it asked for.
    $path = Scratch::path('heading-names-own');
    (new MarkdownToWord(
        '# One',
        overrides: ['styles' => ['heading.1' => ['styleName' => 'MrdTitle']]],
    ))->save($path);

    $definitions = TemplateFactory::styleDefinitions($path);

    expect($definitions)->not->toHaveKey('Heading1')
        ->and(TemplateFactory::xmlOf($path))->toContain('<w:pStyle w:val="MrdTitle"/>');
});

it('renames a heading that a document gave a name of its own to', function () {
    // The case the guard is for, asked from the other side: a `w:styleId` on this
    // library's list is one of the built-in headings whatever the document calls it,
    // so the name is corrected and the size and colour beside it are not.
    $path = Scratch::path('heading-names-renamed');
    (new MarkdownToWord(
        '# One',
        overrides: ['styles' => ['heading.1' => ['styleName' => 'Heading1', 'size' => 18]]],
    ))->save($path);

    $definition = TemplateFactory::styleDefinitions($path)['Heading1'];

    expect(TemplateFactory::styleNameOf($definition))->toBe('heading 1')
        // Eighteen points is thirty-six half-points.
        ->and($definition)->toContain('<w:sz w:val="36"/>');
});

it('is written for a document rendered through the command line too', function () {
    // The pass is in the writer rather than in the renderer, so it cannot be reached
    // by a document that does not go through `DocxWriter` — which is worth asserting,
    // since a `::toc` in a document converted some other way would silently collect
    // nothing again.
    $path = Scratch::path('heading-names-cli');
    runCli(['to-docx', Scratch::remember(inputFile('heading-names-cli.md', "# One\n\n## Two\n")), '-o', $path]);

    expect(headingStyleNames($path))->toHaveKey('Heading1', 'heading 1');
});

it('renames nothing in a package that has no such style', function () {
    // The pass rewrites a part it was not asked about otherwise, so the case of a
    // document with no headings of its own is worth having: a `.dotx` rendered into
    // carries the template's stylesheet, and this must not rename a single one of them.
    $path = Scratch::path('heading-names-none', '.dotx');
    copy(TemplateFactory::dotx(), $path);

    $before = TemplateFactory::xmlOf($path, 'word/styles.xml');
    (new HeadingNamePass())->applyTo($path);

    expect(TemplateFactory::xmlOf($path, 'word/styles.xml'))->toBe($before);
});

it('says a stylesheet that is not XML is a broken document', function () {
    $path = Scratch::path('heading-names-malformed', '.docx');
    (new MarkdownToWord("# One\n"))->save($path);

    $zip = new ZipArchive();
    $zip->open($path);
    $zip->deleteName('word/styles.xml');
    $zip->addFromString('word/styles.xml', '<w:styles');
    $zip->close();

    expect(fn () => (new HeadingNamePass())->applyTo($path))
        ->toThrow(MalformedDocument::class);
});

it('says a file that is not a zip is one it could not write', function () {
    expect(fn () => (new HeadingNamePass())->applyTo(Scratch::path('heading-names-nofile', '.docx')))
        ->toThrow(MarkdownWord\Exception\FileNotWritable::class);
});
