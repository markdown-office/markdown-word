<?php

declare(strict_types=1);

use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;
use MarkdownWord\Writer\BuiltInHeadingPass;

/*
 * Whether a heading style is the built-in one.
 *
 * Two things are missing from what PHPWord writes, and each one breaks a table of
 * contents on its own. Correcting the first is what produces the second message, so a
 * document that was fixed once still did not work:
 *
 *   Ein Inhaltsverzeichnis erstellen? Beginnen Sie damit, dass Sie ein
 *   Überschriftenformat aus dem Formatvorlagenkatalog auf den ausgewählten Text
 *   anwenden.
 *
 * … and, once the name was right:
 *
 *   Es wurden keine Einträge für das Inhaltsverzeichnis gefunden.
 *
 * The name is what Word identifies a built-in style by, and it spells those
 * `heading 1` … `heading 9`. The level is what a heading is *collected* by, and it
 * belongs to the style: a paragraph at no outline level is a paragraph, however it is
 * styled. LibreOffice reads it that way, and says so in its own markup — the same
 * document is `<text:p>` without the level and `<text:h>` with it.
 *
 * Nothing visible is wrong with a document missing either of them. The headings look
 * right, every property in them is plausible, and a table of contents is the only
 * thing that notices.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * The `w:name` and `w:outlineLvl` each heading style carries, keyed by its `w:styleId`.
 *
 * Headings only: `word/styles.xml` also carries `Normal`, `FootnoteReference` and
 * whichever of the built-in styles a template brought, none of which is one of these.
 *
 * @return array<string, array{name: ?string, level: ?string}>
 */
function headingStyles(string $docx): array
{
    $found = [];

    foreach (TemplateFactory::styleDefinitions($docx) as $id => $definition) {
        if (str_starts_with($id, 'Heading')) {
            $found[$id] = [
                'name' => TemplateFactory::styleNameOf($definition),
                'level' => TemplateFactory::outlineLevelOf($definition),
            ];
        }
    }

    return $found;
}

/**
 * A package with one hand-written heading definition, for the shapes PHPWord never writes.
 *
 * @param string $definition the whole `<w:style>` element
 */
function packageWithHeading(string $definition, string $name): string
{
    $path = Scratch::path($name, '.docx');
    $styles = sprintf(
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">%s</w:styles>',
        $definition,
    );

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('word/styles.xml', $styles);
    $zip->close();

    return $path;
}

it('names a heading style the way Word spells the built-in one', function () {
    $path = Scratch::path('heading-styles');
    (new MarkdownToWord("# One\n\n## Two\n"))->save($path);

    // The styleId does not change, because that is what a `w:pStyle` in the body points
    // at, and changing it would break every heading the document already has.
    expect(headingStyles($path))
        ->toHaveKey('Heading1')
        ->and(headingStyles($path)['Heading1']['name'])->toBe('heading 1')
        ->and(headingStyles($path)['Heading2']['name'])->toBe('heading 2');
});

it('names all six of them, not only the levels a document used', function () {
    // The definitions are written for every level whether or not a heading used it, so
    // a document with one `#` still carries a `Heading6` — and a TOC at depth 6 needs
    // that one to be a heading as well.
    $path = Scratch::path('heading-styles-all');
    (new MarkdownToWord("# One\n"))->save($path);

    $styles = headingStyles($path);

    expect(array_keys($styles))->toBe(['Heading1', 'Heading2', 'Heading3', 'Heading4', 'Heading5', 'Heading6'])
        ->and(array_values(array_unique(array_column($styles, 'name'))))
        ->toBe(['heading 1', 'heading 2', 'heading 3', 'heading 4', 'heading 5', 'heading 6']);
});

it('gives every heading the outline level a table of contents collects it by', function () {
    // The missing half, and the one that has to be asked about separately: naming a
    // style `heading 1` makes it the built-in heading, and the level is what says
    // *which* one. Zero-based — heading 1 is `0`, not `1`.
    $path = Scratch::path('heading-styles-levels');
    (new MarkdownToWord("# One\n\n## Two\n\n### Three\n"))->save($path);

    $levels = array_column(headingStyles($path), 'level');

    expect(array_values($levels))->toBe(['0', '1', '2', '3', '4', '5']);
});

it('writes the level inside the paragraph properties, where the schema puts it', function () {
    // `CT_PPrBase` is a sequence, not a choice, and `outlineLvl` comes late in it:
    // after `spacing`, before the two elements that follow it and that nothing here
    // writes. A definition whose children are all correct in the wrong order is still
    // a broken one, and the order is invisible in a `toContain`.
    $path = Scratch::path('heading-styles-order');
    (new MarkdownToWord('# One'))->save($path);

    $definition = TemplateFactory::styleDefinitions($path)['Heading1'];

    expect(TemplateFactory::paragraphPropertiesOf($definition))->toBe(['keepNext', 'spacing', 'outlineLvl']);
});

it('leaves the rest of a heading definition alone', function () {
    // A document that set a size and a colour means them. Only what made the style not
    // a heading was wrong, so only that changes: rewriting the definition would discard
    // what the author asked for in order to fix something nobody else could see.
    $path = Scratch::path('heading-styles-kept');
    (new MarkdownToWord(
        '# One',
        overrides: ['styles' => ['heading.1' => ['size' => 20, 'color' => 'AA0000']]],
    ))->save($path);

    $definition = TemplateFactory::styleDefinitions($path)['Heading1'];

    // Twenty points is forty half-points, which is what a `w:sz` holds.
    expect(TemplateFactory::styleNameOf($definition))->toBe('heading 1')
        ->and(TemplateFactory::outlineLevelOf($definition))->toBe('0')
        ->and($definition)->toContain('<w:color w:val="AA0000"/>')
        ->and($definition)->toContain('<w:sz w:val="40"/>');
});

it('leaves a style of the document\'s own naming alone', function () {
    // A slot pointed at a name outside the library's is not one of the built-in
    // headings, whatever it is called. Giving it a built-in name would change how every
    // reader draws it.
    //
    // `StyleRegistrar` skips writing a definition for such a slot — the name belongs to
    // whatever carries it — so what is asserted here is that nothing was corrected:
    // there is no `Heading1` to correct, and the heading kept the name it asked for.
    $path = Scratch::path('heading-styles-own');
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
    // library's list is the built-in heading whatever the document called it, so both
    // halves are corrected and the size beside them is not.
    $path = Scratch::path('heading-styles-renamed');
    (new MarkdownToWord(
        '# One',
        overrides: ['styles' => ['heading.1' => ['styleName' => 'Heading1', 'size' => 18]]],
    ))->save($path);

    $definition = TemplateFactory::styleDefinitions($path)['Heading1'];

    expect(TemplateFactory::styleNameOf($definition))->toBe('heading 1')
        ->and(TemplateFactory::outlineLevelOf($definition))->toBe('0')
        // Eighteen points is thirty-six half-points.
        ->and($definition)->toContain('<w:sz w:val="36"/>');
});

it('gives a heading that arrived without paragraph properties a set of them', function () {
    // Nothing this library writes produces a definition with no `w:pPr`, but a
    // template's stylesheet can carry one — and without a `w:pPr` there is nowhere to
    // put the level, so a pass that only looked inside an existing one would leave such
    // a heading as collectable as it was before.
    $path = packageWithHeading(
        '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="Heading2"/>'
        . '<w:rPr><w:b w:val="1"/></w:rPr></w:style>',
        'heading-styles-no-ppr',
    );

    (new BuiltInHeadingPass())->applyTo($path);

    $definition = TemplateFactory::styleDefinitions($path)['Heading2'];

    // Before `w:rPr`, because `CT_Style` puts `w:pPr` there and a reader that finds
    // them the other way round does not find the definition at all.
    expect(strpos($definition, '<w:pPr>'))->toBeLessThan(strpos($definition, '<w:rPr>'))
        ->and(TemplateFactory::outlineLevelOf($definition))->toBe('1')
        ->and(TemplateFactory::styleNameOf($definition))->toBe('heading 2');
});

it('rewrites nothing in a stylesheet that is already right', function () {
    // A template's own headings come through here carrying the built-in name and the
    // level that goes with it, which is the case the other tests cannot reach because
    // nothing this library writes is ever already right. Reserialising it anyway would
    // churn a part Word wrote for no gain, so the package has to come back byte for
    // byte.
    $definition = '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/>'
        . '<w:pPr><w:outlineLvl w:val="0"/></w:pPr></w:style>';
    $path = packageWithHeading($definition, 'heading-styles-untouched');
    $before = TemplateFactory::xmlOf($path, 'word/styles.xml');

    (new BuiltInHeadingPass())->applyTo($path);

    expect(TemplateFactory::xmlOf($path, 'word/styles.xml'))->toBe($before);
});

it('corrects a level that disagrees with the name rather than leaving both', function () {
    // A heading whose name says level one and whose level says level two collects at
    // neither depth a reader expects. There is no way to ask for that today, which is
    // exactly why it is settled here rather than deferred to whoever gets there first.
    $path = packageWithHeading(
        '<w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/>'
        . '<w:pPr><w:outlineLvl w:val="1"/></w:pPr></w:style>',
        'heading-styles-level-clash',
    );

    (new BuiltInHeadingPass())->applyTo($path);

    expect(TemplateFactory::outlineLevelOf(TemplateFactory::styleDefinitions($path)['Heading3']))->toBe('2');
});

it('is written for a document rendered through the command line too', function () {
    // The pass is in the writer rather than in the renderer, so it cannot be reached by
    // a document that does not go through `DocxWriter` — which is worth asserting,
    // since a `::toc` in a document converted some other way would silently collect
    // nothing again.
    $path = Scratch::path('heading-styles-cli');
    runCli(['to-docx', Scratch::remember(inputFile('heading-styles-cli.md', "# One\n\n## Two\n")), '-o', $path]);

    expect(headingStyles($path))
        ->toHaveKey('Heading1')
        ->and(headingStyles($path)['Heading1'])->toBe(['name' => 'heading 1', 'level' => '0']);
});

it('renames nothing in a package that has no heading style at all', function () {
    // The pass rewrites a part it was not asked about otherwise, so a package without
    // one is worth having: a `.dotx` rendered into carries the template's stylesheet,
    // and this must not touch a single definition of it.
    $path = Scratch::path('heading-styles-none', '.dotx');
    copy(TemplateFactory::dotx(), $path);

    $before = TemplateFactory::xmlOf($path, 'word/styles.xml');
    (new BuiltInHeadingPass())->applyTo($path);

    expect(TemplateFactory::xmlOf($path, 'word/styles.xml'))->toBe($before);
});

it('says a stylesheet that is not XML is a broken document', function () {
    $path = Scratch::path('heading-styles-malformed', '.docx');
    (new MarkdownToWord("# One\n"))->save($path);

    $zip = new ZipArchive();
    $zip->open($path);
    $zip->deleteName('word/styles.xml');
    $zip->addFromString('word/styles.xml', '<w:styles');
    $zip->close();

    expect(fn () => (new BuiltInHeadingPass())->applyTo($path))
        ->toThrow(MalformedDocument::class);
});

it('says a file that is not a zip is one it could not write', function () {
    expect(fn () => (new BuiltInHeadingPass())->applyTo(Scratch::path('heading-styles-nofile', '.docx')))
        ->toThrow(FileNotWritable::class);
});
