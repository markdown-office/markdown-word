<?php

declare(strict_types=1);

use MarkdownWord\Configuration\Styles;
use MarkdownWord\Exception\Exception;
use MarkdownWord\Exception\ThemeNotFound;
use MarkdownWord\Files\DiskFinder;
use MarkdownWord\Files\FileFinder;
use MarkdownWord\Filesystem\PathScope;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Tests\Support\MemoryFinder;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\Upstream;
use MarkdownWord\Theme\ThemeLoader;
use MarkdownWord\Theme\ThemeResolver;

/*
 * The theme, read through a finder.
 *
 * The library asks for names and a finder answers; the scope — the question of what
 * this render may read — stays with the library, which holds the path a finder
 * answered to its own scope and refuses what is out of reach in its own words. These
 * are about that division: the finder answers for a file that is out of reach, and
 * the library is the one that says no.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/** A scratch directory no other test is using. */
function finderScratch(string $name = 'theme-finder'): string
{
    $directory = Scratch::path($name, '');

    if (!mkdir($directory, 0o777, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create "%s".', $directory));
    }

    return $directory;
}

/**
 * A scratch working directory, with the `themes/` in it a bare theme name is looked for in.
 *
 * @return array{0: string, 1: string} The working directory, and its `themes/` directory.
 */
function finderWork(string $name = 'theme-finder-work'): array
{
    $root = finderScratch($name);
    $themes = $root . '/themes';

    if (!mkdir($themes, 0o777, true) && !is_dir($themes)) {
        throw new RuntimeException(sprintf('Unable to create "%s".', $themes));
    }

    return [$root, $themes];
}

/**
 * A theme directory, with a manifest and a styles file.
 *
 * A null styles file is a theme with none, which is what the tests that put a
 * symlink in its place need.
 */
function finderTheme(string $themes, string $name, ?string $styles = ''): string
{
    $directory = $themes . '/' . $name;

    if (!mkdir($directory, 0o777, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create "%s".', $directory));
    }

    file_put_contents($directory . '/theme.yml', "name: House\n");

    if ($styles !== null) {
        file_put_contents($directory . '/styles.yml', $styles);
    }

    return $directory;
}

it('reads a theme through the finder', function () {
    $themes = finderScratch('theme-finder-themes');
    finderTheme($themes, 'house', "heading.1:\n  color: 112233\n");

    $loader = new ThemeLoader(new PathScope([$themes]));
    $theme = $loader->load('house', 'house', new DiskFinder(theme: [$themes]));

    expect($theme->theme()->name())->toBe('House')
        ->and($theme->styles()->get(Styles::HEADING_1)['color'])->toBe('112233')
        ->and($theme->directory())->toBe(realpath($themes . '/house'));
});

it('asks the finder for the theme\'s files by name', function () {
    // The names are the library's own: a theme's manifest is `<theme>/theme.yml` and
    // the slots beside it are `<theme>/styles.yml`.
    $finder = new MemoryFinder([
        'house/theme.yml' => "name: House\n",
        'house/styles.yml' => "heading.1:\n  color: 112233\n",
    ]);

    $loader = new ThemeLoader();
    $loader->load('house', 'house', $finder);

    expect($finder->asked())->toBe(['house/theme.yml', 'house/styles.yml']);
});

it('reads a theme through a finder that has the bytes and no path', function () {
    // The source the finder exists for: a plugin that keeps its themes in a database
    // and never had a directory to hand over. The library reads the bytes rather
    // than opening a file.
    $finder = new MemoryFinder([
        'house/theme.yml' => "name: House\n",
        'house/styles.yml' => "heading.1:\n  color: 112233\n",
    ]);

    $loader = new ThemeLoader();
    $theme = $loader->load('house', 'house', $finder);

    expect($theme->theme()->name())->toBe('House')
        ->and($theme->styles()->get(Styles::HEADING_1)['color'])->toBe('112233')
        // A finder with no path has no directory, and the theme's name stands in.
        ->and($theme->directory())->toBe('house');
});

it('refuses a theme file that resolves outside the scope, in the library\'s words', function () {
    // The finder answers the path; the library holds it to its scope and refuses it.
    // The message is the library's, because the library is what knows what this
    // render may read.
    $themes = finderScratch('theme-finder-outside');
    finderTheme($themes, 'evil', null);

    $outside = finderScratch('theme-finder-secret');
    file_put_contents($outside . '/styles.yml', "heading.1:\n  color: DEADBEEF\n");

    if (!@symlink($outside . '/styles.yml', $themes . '/evil/styles.yml')) {
        test()->markTestSkipped('symlinks are not available here');
    }

    $loader = new ThemeLoader(new PathScope([$themes]));

    expect(fn () => $loader->load('evil', 'evil', new DiskFinder(theme: [$themes])))
        ->toThrow(Exception::class, 'resolves outside the directories this render may read');
});

it('a loader with a scope of its own refuses a theme file out of it', function () {
    // The scope belongs to the loader: it is what holds the theme's own files, and a
    // loader the caller brings brings its own. The resolver's scope holds the theme's
    // directory, which is a different question.
    [$work, $themes] = finderWork('theme-finder-scope');
    finderTheme($themes, 'user', null);

    // A styles file that resolves inside the resolver's scope and outside the
    // loader's: the working directory is a root, the `themes/` directory is not.
    file_put_contents($work . '/shared.yml', "heading.1:\n  color: 112233\n");

    if (!@symlink($work . '/shared.yml', $themes . '/user/styles.yml')) {
        test()->markTestSkipped('symlinks are not available here');
    }

    $resolver = new ThemeResolver(
        workingDirectory: $work,
        loader: new ThemeLoader(new PathScope([$themes])),
    );

    expect(fn () => $resolver->resolve('user'))
        ->toThrow(Exception::class, 'resolves outside the directories this render may read');
});

it('reports a theme whose file the finder answers for is not there', function () {
    // A finder that answers a path for a file that has since gone: the theme is not
    // there, and the library says so rather than reading nothing.
    $finder = new class implements FileFinder {
        public function read(string $name, ?string $role = null): ?string
        {
            return null;
        }

        public function path(string $name, ?string $role = null): ?string
        {
            return '/mdword-not-here/theme.yml';
        }

        public function themes(): array
        {
            return [];
        }

        public function files(string $theme): array
        {
            return [];
        }
    };

    $loader = new ThemeLoader(new PathScope([finderScratch('theme-finder-gone')]));

    expect(fn () => $loader->load('house', 'house', $finder))
        ->toThrow(ThemeNotFound::class, 'is not a theme: it has no theme.yml');
});

it('reports a theme file it cannot read', function () {
    skipWithoutPermissions('the file cannot be made unreadable');

    $themes = finderScratch('theme-finder-unreadable');
    finderTheme($themes, 'house', "heading.1:\n  color: 112233\n");

    chmod($themes . '/house/styles.yml', 0o000);

    $loader = new ThemeLoader(new PathScope([$themes]));

    expect(fn () => $loader->load('house', 'house', new DiskFinder(theme: [$themes])))
        ->toThrow(Exception::class, 'Unable to read');
});

it('refuses a text styles block that is not a mapping', function () {
    // The named text styles are a mapping of names to styles; one that is not is a
    // theme that does not describe a theme.
    $themes = finderScratch('theme-finder-textstyles');
    finderTheme($themes, 'house', "textStyles: not a mapping\n");

    $loader = new ThemeLoader(new PathScope([$themes]));

    expect(fn () => $loader->load('house', 'house', new DiskFinder(theme: [$themes])))
        ->toThrow(Exception::class, 'It has to be a mapping of style names to styles.');
});

it('tells a document that the path it wrote names nothing', function () {
    // A path is looked for on its own: one that does not resolve is a failure rather
    // than a name that is looked for elsewhere.
    [$work] = finderWork('theme-finder-nowhere');

    $resolver = new ThemeResolver(workingDirectory: $work);

    expect(fn () => $resolver->resolve('./nowhere'))
        ->toThrow(ThemeNotFound::class, 'There is no theme directory named "./nowhere"');
});

it('tells a document that the manifest it named is not there', function () {
    $loader = new ThemeLoader();

    expect(fn () => $loader->loadManifest('house', 'house/theme.yml', new MemoryFinder()))
        ->toThrow(ThemeNotFound::class, 'There is no theme directory named "house/theme.yml"');
});

it('resolves a theme the source holds and no directory has', function () {
    // The source the finder exists for: a plugin that keeps its themes in a database
    // and never had a directory to hand over. The name is asked for by name, and the
    // theme comes back the same way it would from a directory.
    $finder = new MemoryFinder([
        'house/theme.yml' => "name: House\n",
        'house/styles.yml' => "heading.1:\n  color: 112233\n",
    ]);

    $resolver = new ThemeResolver(workingDirectory: finderScratch('theme-finder-nowhere'), files: $finder);
    $theme = $resolver->resolve('house');

    expect($theme->theme()->name())->toBe('House')
        ->and($theme->styles()->get(Styles::HEADING_1)['color'])->toBe('112233')
        // And the name is offered in the list of the themes that resolve.
        ->and($resolver->available())->toContain('house');
});

it('refuses a theme the source answers for outside the roots, in the library\'s words', function () {
    // The finder answers the path; the library holds it to its own scope and refuses
    // it. A finder that refused would throw this package's exception, which the other
    // half of this suite would not catch.
    $outside = finderScratch('theme-finder-outside-roots');
    finderTheme($outside, 'secrets');

    $resolver = new ThemeResolver(
        workingDirectory: finderScratch('theme-finder-inside-roots'),
        files: new DiskFinder(theme: [$outside]),
    );

    expect(fn () => $resolver->resolve('secrets'))
        ->toThrow(ThemeNotFound::class, 'outside the directories a document may name');
});

it('resolves a theme named in code once', function () {
    // The name is resolved when it is given, and the document is drawn in the theme
    // that came back. A source that counts its reads says so: the theme is read once,
    // not once for the name and once for the document.
    $finder = new MemoryFinder([
        'house/theme.yml' => "name: House\n",
        'house/styles.yml' => "heading.1:\n  color: 112233\n",
    ]);

    $converter = (new MarkdownToWord('# T', files: $finder))->withTheme('house');
    $converter->toDocx('# T');

    expect($finder->reads('house/theme.yml'))->toBe(1)
        ->and($finder->reads('house/styles.yml'))->toBe(1);
});

it('resolves a theme in a root the calling program allowed', function () {
    // A shared library of themes beside the project: the roots widen what a document
    // may read, and a name is looked for in one of them the way it is in the themes
    // that ship with this package.
    $library = finderScratch('theme-finder-library');
    finderTheme($library, 'shared', "heading.1:\n  color: 112233\n");

    $markdown = "---\ntheme: shared\n---\n\n# T\n";
    $converter = new MarkdownToWord(
        $markdown,
        parser: CommonMarkParser::withAllExtensions(),
        roots: [$library],
    );

    $config = $converter->configurationFor($converter->parse($markdown), $markdown);

    expect($config->getStyles()->get(Styles::HEADING_1)['color'])->toBe('112233');
});
