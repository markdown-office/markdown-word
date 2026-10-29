<?php

declare(strict_types=1);

use MarkdownWord\Configuration\Styles;
use MarkdownWord\Exception\Exception;
use MarkdownWord\Exception\ThemeNotFound;
use MarkdownWord\Files\DiskFinder;
use MarkdownWord\Files\FileFinder;
use MarkdownWord\Filesystem\PathScope;
use MarkdownWord\Tests\Support\Scratch;
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

/**
 * A finder with the files in memory rather than on disk.
 *
 * The source the finder exists for: a plugin that keeps its themes in a database and
 * never had a directory to hand over. It answers the contents and no path, so the
 * library reads the bytes rather than opening a file — and it remembers what it was
 * asked for, which is how a test says what the library asked for.
 */
final class MemoryFinder implements FileFinder
{
    /** @var array<string, true> */
    private array $asked = [];

    /**
     * @param array<string, string> $files The files it holds, by name.
     */
    public function __construct(private readonly array $files = [])
    {
    }

    public function read(string $name, ?string $role = null): ?string
    {
        $this->asked[$name] = true;

        return $this->files[$name] ?? null;
    }

    public function path(string $name, ?string $role = null): ?string
    {
        $this->asked[$name] = true;

        return null;
    }

    public function themes(): array
    {
        $names = [];

        foreach (array_keys($this->files) as $name) {
            $names[explode('/', $name, 2)[0]] = true;
        }

        $found = array_keys($names);

        sort($found);

        return $found;
    }

    public function files(string $theme): array
    {
        $names = [];

        foreach (array_keys($this->files) as $name) {
            if (str_starts_with($name, $theme . '/')) {
                $names[substr($name, strlen($theme) + 1)] = true;
            }
        }

        $found = array_keys($names);

        sort($found);

        return $found;
    }

    /**
     * The names it was asked for, in the order they were asked for.
     *
     * @return list<string>
     */
    public function asked(): array
    {
        return array_keys($this->asked);
    }
}

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
