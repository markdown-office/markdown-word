<?php

declare(strict_types=1);

use MarkdownWord\Exception\ThemeNotFound;
use MarkdownWord\Files\DiskFinder;
use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\Tests\Support\MemoryFinder;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

/*
 * The template's document gets its files from the caller's source.
 *
 * The template renders a document of its own, so it builds a converter of its own — and
 * a converter with no finder reads the disk. A caller whose themes and pictures live
 * in a database therefore got the disk's rather than its own, which is the problem the
 * finder exists to solve, left open on one path.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/** A theme the caller holds rather than the disk. */
function templateFinderTheme(): MemoryFinder
{
    return new MemoryFinder([
        'house/theme.yml' => "name: House\n",
        'house/styles.yml' => "heading.1:\n  color: 112233\n",
    ]);
}

it('reads the theme the inserted document names from the caller\'s source', function () {
    // The theme is named in the document the template is filled with, and it comes
    // from the source the caller brought rather than from the disk.
    $finder = templateFinderTheme();

    $template = new MarkdownTemplate(
        Scratch::remember(TemplateFactory::placeholder()),
        files: $finder,
    );

    $template->insert("---\ntheme: house\n---\n\n# Title\n");

    $output = Scratch::path('template-finder-theme');
    saveTemplateDocument($template, $output);

    // The theme's colour, written onto the text as direct formatting over the
    // template's own styles.
    expect(TemplateFactory::xmlOf($output))->toContain('w:color w:val="112233"');
});

it('reads the theme named in code from the caller\'s source', function () {
    // The other way a theme is named: in the caller's own code rather than in the
    // document. It reaches the document through the template's converter, so it has
    // to reach the converter's finder.
    $finder = templateFinderTheme();

    $template = new MarkdownTemplate(
        Scratch::remember(TemplateFactory::placeholder()),
        theme: 'house',
        files: $finder,
    );

    $template->insert("# Title\n");

    $output = Scratch::path('template-finder-theme-in-code');
    saveTemplateDocument($template, $output);

    expect(TemplateFactory::xmlOf($output))->toContain('w:color w:val="112233"');
});

it('asks the caller\'s source for the picture the document shows', function () {
    // The source that keeps its pictures in a database and never had a directory to
    // hand over. The picture is asked for by name and in the picture role, and it is
    // drawn rather than left to the alt text.
    $finder = new MemoryFinder(['logo.png' => (string) file_get_contents(Scratch::image())]);

    $template = new MarkdownTemplate(
        Scratch::remember(TemplateFactory::placeholder()),
        files: $finder,
    );

    $template->insert("![A logo](logo.png)\n");

    $output = Scratch::path('template-finder-picture');
    saveTemplateDocument($template, $output);

    expect($finder->asked())->toContain('logo.png')
        ->and($finder->askedFor('logo.png'))->toBe('picture')
        ->and(TemplateFactory::xmlOf($output))->toContain('w:pict');
});

it('still finds a theme on disk when the caller brought a source of its own', function () {
    // The directories of themes on disk are the fallback rather than the only place a
    // theme is looked for: a program with a source of its own does not lose the
    // themes that ship with this package.
    $directory = Scratch::directory() . '/beside';
    mkdir($directory . '/themes/house', 0o777, true);
    file_put_contents($directory . '/themes/house/theme.yml', "name: House\n");
    file_put_contents($directory . '/themes/house/styles.yml', "heading.1:\n  color: 445566\n");

    $finder = new MemoryFinder(['other/theme.yml' => "name: Other\n"]);

    $template = new MarkdownTemplate(
        Scratch::remember(TemplateFactory::placeholder()),
        directory: $directory,
        files: $finder,
    );

    $template->insert("---\ntheme: house\n---\n\n# Title\n");

    $output = Scratch::path('template-finder-disk');
    saveTemplateDocument($template, $output);

    expect($finder->asked())->toContain('house/theme.yml')
        ->and(TemplateFactory::xmlOf($output))->toContain('w:color w:val="445566"');
});

it('refuses a theme the caller\'s source answers for outside the roots, in the library\'s words', function () {
    // The finder answers the path; the library holds it to its own scope and refuses
    // it. A finder that refused would throw this package's exception, which the other
    // half of this suite would not catch.
    //
    // Outside the project, because the working directory is a root and everything
    // under the project is inside it.
    $outside = sys_get_temp_dir() . '/mdword-template-finder-' . bin2hex(random_bytes(4));
    mkdir($outside . '/secrets', 0o777, true);
    file_put_contents($outside . '/secrets/theme.yml', "name: Secrets\n");

    try {
        $template = new MarkdownTemplate(
            Scratch::remember(TemplateFactory::placeholder()),
            files: new DiskFinder(theme: [$outside]),
        );

        expect(fn () => $template->insert("---\ntheme: secrets\n---\n\n# Title\n"))
            ->toThrow(ThemeNotFound::class, 'outside the directories a document may name');
    } finally {
        @unlink($outside . '/secrets/theme.yml');
        @rmdir($outside . '/secrets');
        @rmdir($outside);
    }
});
