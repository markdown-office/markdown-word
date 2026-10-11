<?php

declare(strict_types=1);

use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\Template\TemplatePackage;
use MarkdownWord\WordPackage;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

/*
 * A `.dotx` is a `.docx` with one line of `[Content_Types].xml` different, and
 * that line decides two things: how a reader treats the file, and which part
 * holds its body. Both were wrong, and both were wrong in a way the text of the
 * finished document did not show — the words were all where they should be, in a
 * file no reader would open as a document, or in a part nothing pointed at.
 *
 * Every test here therefore asserts on the package rather than on the prose,
 * because the prose passed throughout.
 *
 * Filling in a template writes a document for every block it inserts, so the
 * filter for the one known upstream deprecation has to span the whole test
 * rather than wrap the final save — the same reason tests/Unit/template-render.php
 * carries one.
 */
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

it('writes a document rather than a template', function () {
    $output = fill(TemplateFactory::dotx(), '## Heading' . "\n\nbody\n");

    // Word opens a package typed as a template by creating a new document from it,
    // so this is what decides whether the result is a document at all.
    expect(TemplateFactory::mainContentTypeOf($output))
        ->toBe(WordPackage::DOCUMENT_MAIN);
});

it('leaves the template on disk a template', function () {
    $template = TemplateFactory::dotx();

    fill($template, '## Heading' . "\n\nbody\n");

    // The copy is the library's; the file the caller handed over is not opened for
    // writing, or a hard link to it would empty it.
    expect(TemplateFactory::mainContentTypeOf($template))
        ->toBe(WordPackage::TEMPLATE_MAIN);
});

it('fills a template whose body part is not word/document.xml', function () {
    $output = fill(TemplateFactory::dotx('document22.xml'), '## Odd' . "\n\ntext here\n");

    expect(TemplateFactory::bodyTextOf($output))->toContain('Odd');
});

it('writes content into the part the declarations name', function () {
    $output = fill(TemplateFactory::dotx('document22.xml'), '## Odd' . "\n\ntext here\n");

    // Into that part and no other: the part PHPWord falls back to when its own
    // lookup finds nothing, which on a template is always.
    expect(TemplateFactory::hasPart($output, 'word/document.xml'))->toBeFalse();
    expect(TemplateFactory::bodyTextOf($output))->not->toContain('${slot}');
});

it('carries a link whose label has emphasis into a template', function () {
    // HyperlinkPass asked for word/document.xml by name and threw on a template
    // whose body was document22.xml, rather than losing the link.
    $template = TemplateFactory::dotx('document22.xml');
    $output = Scratch::path('filled-with-link.docx');

    $renderer = new MarkdownTemplate($template);
    $renderer->insert('body', 'A [**bold** link](https://example.com).' . "\n");

    saveTemplateDocument($renderer, $output);

    expect(TemplateFactory::hyperlinkCount($output))->toBe(1);
});

it('keeps a document a document', function () {
    $output = fill(TemplateFactory::placeholder(), '## Heading' . "\n\nbody\n");

    expect(TemplateFactory::mainContentTypeOf($output))->toBe(WordPackage::DOCUMENT_MAIN);
    expect(TemplateFactory::bodyTextOf($output))->toContain('Heading');
});

it('reports a template that cannot be opened as a package', function () {
    $path = Scratch::path('not-a-package.docx');
    file_put_contents($path, "# Markdown wearing the wrong extension\n");

    expect(fn () => new MarkdownTemplate($path))
        ->toThrow(FileNotWritable::class, 'zip archive');
});

describe('the body part of a package', function () {
    it('is the part the declarations name', function () {
        expect(WordPackage::mainPartOf(TemplateFactory::dotx('document22.xml')))
            ->toBe('word/document22.xml');
    });

    it('is read whichever order the attributes are written in', function () {
        // A lookup by pattern assumes ContentType follows PartName; Word does write
        // them that way, but the other order is legal and would read as no match.
        $path = Scratch::path('reversed.docx');
        copy(TemplateFactory::placeholder(), $path);

        $zip = new ZipArchive();
        $zip->open($path);
        $declared = $zip->getFromName('[Content_Types].xml');
        $zip->deleteName('[Content_Types].xml');
        $zip->addFromString('[Content_Types].xml', (string) preg_replace(
            '~ContentType="([^"]*)"( PartName="([^"]*)")~',
            'ContentType="$1" PartName="/word/document22.xml"',
            str_replace(
                '/word/document.xml',
                '/word/document22.xml',
                str_replace(
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml',
                    $declared,
                ),
            ),
        ));
        $zip->close();

        expect(WordPackage::mainPartOf($path))->toBe('word/document22.xml');
    });

    it('falls back rather than failing on a package that declares none', function () {
        $path = Scratch::path('no-declarations.docx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'A zip, and nothing more.');
        $zip->close();

        expect(WordPackage::mainPartOf($path))->toBe(WordPackage::FALLBACK_MAIN);
    });

    it('refuses a main part that walks out of the archive', function () {
        // The declarations are a path a package writes, and the package is not
        // this library's; a name that climbs out is not one to hand back.
        $path = Scratch::path('traversal.docx');
        copy(TemplateFactory::placeholder(), $path);

        $zip = new ZipArchive();
        $zip->open($path);
        $zip->deleteName('[Content_Types].xml');
        $zip->addFromString('[Content_Types].xml', (string) preg_replace(
            '~PartName="/word/document\.xml"~',
            'PartName="/word/../../etc/passwd"',
            (string) $zip->getFromName('[Content_Types].xml'),
        ));
        $zip->close();

        expect(WordPackage::mainPartOf($path))->toBe(WordPackage::FALLBACK_MAIN);
    });
});

describe('a template package', function () {
    it('hands over the caller\'s own file when it is already a document', function () {
        $template = TemplateFactory::placeholder();

        expect(TemplatePackage::of($template)->path())->toBe($template);
    });

    it('says whether it had to make a copy', function () {
        expect(TemplatePackage::of(TemplateFactory::placeholder())->isTemplate())->toBeFalse();
        expect(TemplatePackage::of(TemplateFactory::dotx())->isTemplate())->toBeTrue();
    });

    it('removes the copy it made', function () {
        $package = TemplatePackage::of(TemplateFactory::dotx());
        $copy = $package->path();

        expect(is_file($copy))->toBeTrue();

        unset($package);

        expect(is_file($copy))->toBeFalse();
    });

    it('leaves nothing in the temporary directory when it cannot be read', function () {
        $before = glob(sys_get_temp_dir() . '/mdword_tpl_*') ?: [];
        $path = Scratch::path('not-an-archive.dotx');
        file_put_contents($path, "# Markdown wearing the wrong extension\n");

        expect(fn () => TemplatePackage::of($path))->toThrow(FileNotWritable::class);

        // The copy is ours; a run that fails before it is made leaves nothing, and
        // one that fails after it has made it takes it with it.
        expect(glob(sys_get_temp_dir() . '/mdword_tpl_*') ?: [])->toBe($before);
    });
});

/**
 * Fill a template and write it out, returning the path to the result.
 *
 * Written out rather than reached through `renderTemplate()` because that one has a
 * single target, and each of these needs a name of its own to assert on.
 */
function fill(string $template, string $markdown): string
{
    $output = Scratch::path('filled-' . basename($template));

    $renderer = new MarkdownTemplate($template);
    $renderer->insert('body', $markdown);

    saveTemplateDocument($renderer, $output);

    return $output;
}