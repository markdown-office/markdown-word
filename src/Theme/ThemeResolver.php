<?php

declare(strict_types=1);

namespace MarkdownWord\Theme;

use MarkdownWord\Exception\ThemeNotFound;
use MarkdownWord\Files\DiskFinder;
use MarkdownWord\Files\FileFinder;
use MarkdownWord\Filesystem\PathScope;

/**
 * Turns the `theme:` a document names into a theme, in the order one is looked for:
 *
 * 1. an explicit path — anything with a `/` in it, written from the document's own
 *    directory — or the working directory, where the document has none — unless it
 *    names an absolute one;
 * 2. `./themes/<name>` beside the document;
 * 3. `./themes/<name>` beside the working directory;
 * 4. a theme in a root the calling program allowed;
 * 5. a theme that ships with this package;
 * 6. nothing, which is a failure naming every theme that did resolve.
 *
 * Step 6 is the point. Falling back to the built-in default would produce a document in
 * the wrong colours with nothing in the output to say so; the alternative is an error that
 * names the themes that exist, which is a sentence the author of the document can act on.
 *
 * The document's own directory comes before the working directory because a document
 * travels: the theme beside it travels with it, and the working directory is where the
 * program happens to be. A run that resolved against the working directory found the
 * theme of the machine — and found nothing at all unless it happened to start in the
 * document's own directory.
 *
 * A path is looked for on its own: one that does not resolve is a failure rather than a
 * name that is looked for elsewhere, so a document writing `theme: ./nowhere` is told
 * about the path and not about the themes that do exist. What the path names decides
 * only what is read from it — the `theme.yml` of a directory, or the file itself as the
 * manifest of the theme around it — and not where it is looked for.
 *
 * Every step resolves the path with `realpath()` and holds it to {@see PathScope}. A
 * `theme:` is a path a document writes, and a document is not trusted to mean the path
 * it names: `../` walks out of the directory the path was written from and an absolute
 * path is read from wherever it points.
 *
 * The name is worth saying once more, because the resolution is not obvious from it: a
 * bare name is looked for under `./themes/`, so `theme: ..` lands on the directory above
 * it rather than on anything inside `themes/`. It is contained, because that directory is
 * a root — but it is not what the name suggests.
 *
 * The theme's files are read through a finder, which is given the directory that holds
 * the theme: the name the library asks for then resolves to the theme this resolver
 * found and not to another theme that shares its name. The listing of the themes that
 * resolve comes from a finder given the directories of themes above.
 *
 * A finder the calling program brought is the source of themes in its own right: a
 * theme that never had a directory is resolved by asking for its manifest by name, and
 * the directories of themes on disk are the fallback rather than the only place a
 * theme is looked for. The themes that ship with this package are among them, so a
 * program that brings a source of its own does not lose the built-in ones.
 */
final class ThemeResolver
{
    /**
     * The directory a theme is looked for in, under the one it was named beside.
     *
     * Steps 2, 3 and 5 of the order above each look in a directory of themes:
     * the document's own, the working directory's, and the one that ships with
     * this package.
     */
    private const THEMES_DIRECTORY = '/themes';

    private readonly string $builtinDirectory;

    private readonly PathScope $scope;

    private readonly ThemeLoader $loader;

    /** @var list<string> */
    private readonly array $roots;

    /** The source of themes the calling program brought, if it brought one. */
    private readonly ?FileFinder $files;

    /**
     * @param list<string> $roots Further directories a document may name a theme in, for
     *   a shared library of themes beside the project. Each is a directory of themes: a
     *   name is looked for in one of them directly, the way it is in the themes that ship
     *   with this package. As with a theme's this is the calling program's decision,
     *   made before a document is read: a document that could widen it would be the
     *   arbitrary path read this argument exists to stop.
     * @param string|null $documentDirectory The directory the document was read from,
     *   which is where its own `themes/` sits and where a relative path is written from.
     *   Null for a document handed in as a string: there is no file beside it, so a theme
     *   beside it is not there to be found.
     * @param ThemeLoader|null $loader The loader the themes are read through. A loader
     *   given here brings its own scope; the scope of this resolver still holds the
     *   theme's own directory.
     * @param FileFinder|null $files The source of themes, for a program whose themes are
     *   not in a directory. The directories of themes on disk remain the fallback.
     */
    public function __construct(
        private readonly string $workingDirectory = '.',
        ?string $builtinDirectory = null,
        ?ThemeLoader $loader = null,
        array $roots = [],
        private readonly ?string $documentDirectory = null,
        ?FileFinder $files = null,
    ) {
        $this->roots = array_values($roots);

        // The themes beside `src/`, so a package installed anywhere resolves its own
        // themes without being told where they are.
        $this->builtinDirectory = $builtinDirectory ?? dirname(__DIR__, 2) . self::THEMES_DIRECTORY;
        $this->scope = new PathScope([
            ...($this->documentDirectory === null ? [] : [$this->documentDirectory]),
            $this->workingDirectory,
            $this->builtinDirectory,
            ...$this->roots,
        ]);
        $this->loader = $loader ?? new ThemeLoader($this->scope);
        $this->files = $files;
    }

    /**
     * @throws ThemeNotFound when no step of the order above resolves
     */
    public function resolve(string $name): LoadedTheme
    {
        if (PathScope::isUrl($name)) {
            throw ThemeNotFound::aUrl($name);
        }

        if (str_contains($name, '/')) {
            // A `theme:` is a path a document writes, so where it is written from says
            // nothing about it; what is allowed is where it lands.
            $path = str_starts_with($name, '/') ? $name : $this->base() . '/' . $name;

            // Whether the path names a directory or a file decides what is read from it,
            // not where it is looked for: a path is found the same way either way.
            return is_file($path)
                ? $this->themeOfManifest($this->file($name, $path), $name)
                : $this->themeIn($this->directory($name, $path), $name);
        }

        // The source the calling program brought, asked for by name.
        $theme = $this->throughFinder($name);

        if ($theme !== null) {
            return $theme;
        }

        foreach ($this->themeDirectories() as $themes) {
            if (is_dir($directory = $themes . '/' . $name)) {
                return $this->themeIn($this->directory($name, $directory), $name);
            }
        }

        throw ThemeNotFound::named($name, $this->available());
    }

    /**
     * A theme in the source the calling program brought, or null when it has none.
     *
     * The manifest is asked for by name — `house/theme.yml` — because the library
     * composes names and a finder answers them. A finder that answers a path has it
     * held to the scope, exactly as a theme found on disk has; one that answers the
     * bytes has the caller's word for them.
     *
     * Whether the theme is there at all is answered by the listing of its files, and
     * not by asking for the manifest: a theme whose files are there but whose
     * manifest is not is a different failure from a theme that is not there, and the
     * listing tells the two apart without reading anything.
     */
    private function throughFinder(string $name): ?LoadedTheme
    {
        $finder = $this->finder();

        $manifest = $finder->path($name . '/' . ThemeLoader::THEME_FILE, 'theme');

        if ($manifest !== null) {
            $real = PathScope::resolve($manifest);

            if ($real === null) {
                throw ThemeNotFound::noManifest($name);
            }

            // The theme's own directory is held to the scope, as it is when the theme
            // was found on disk: a `theme:` is a path a document writes, and what is
            // allowed is where it lands.
            if (!$this->scope->holds(dirname($real))) {
                throw ThemeNotFound::outside($name, $this->scope->roots());
            }

            return $this->loader->load($name, $name, $finder);
        }

        if ($finder->files($name) === []) {
            return null;
        }

        return $this->loader->load($name, $name, $finder);
    }

    /**
     * The directory a relative path is written from.
     *
     * The document's own directory, because that is where a document's paths are written
     * from — the rule a `template_file:` and a relative image path follow. The working
     * directory stands in for a document handed in as a string, which has no directory
     * of its own.
     */
    private function base(): string
    {
        return $this->documentDirectory ?? $this->workingDirectory;
    }

    /**
     * The directories a bare name is looked for in, in the order they are tried.
     *
     * One list because a name has to resolve the same way whether it is being looked up
     * or enumerated: a name the resolver would accept and not list is a name a failure
     * message leaves the reader to guess at.
     *
     * @return list<string>
     */
    private function themeDirectories(): array
    {
        return [
            ...($this->documentDirectory === null ? [] : [$this->documentDirectory . self::THEMES_DIRECTORY]),
            $this->workingDirectory . self::THEMES_DIRECTORY,
            ...$this->roots,
            $this->builtinDirectory,
        ];
    }

    /**
     * The finder the themes are read through: the source the calling program brought,
     * or the disk default.
     *
     * The default holds the directories of themes the six steps name, in the order
     * they are tried, so a theme is resolved by its directory and a theme directory
     * that holds no manifest still shadows a later one.
     */
    private function finder(): FileFinder
    {
        return $this->files ?? new DiskFinder(theme: $this->themeDirectories());
    }

    /**
     * The theme in a directory, read through a finder that resolves it there.
     *
     * The finder is given the directory that holds the theme, so the name it resolves
     * is the theme this resolver found and not another theme that shares its name.
     */
    private function themeIn(string $directory, string $written): LoadedTheme
    {
        return $this->loader->load(
            basename($directory),
            $written,
            new DiskFinder(theme: [dirname($directory)]),
        );
    }

    /**
     * The theme a manifest is, read through a finder that resolves it there.
     *
     * The manifest's own directory, so the styles beside it are the ones the manifest
     * was written next to.
     */
    private function themeOfManifest(string $file, string $written): LoadedTheme
    {
        $directory = dirname($file);

        return $this->loader->loadManifest(
            basename($directory),
            $written,
            new DiskFinder(theme: [dirname($directory)]),
        );
    }

    /**
     * The directory a step of the order above found, resolved and held to the roots.
     *
     * Two messages rather than one, because "there is nothing there" and "it is there and
     * this render may not read it" are different problems with different fixes: the first
     * is a typo in a document, the second is a document reaching for something.
     *
     * Both name what the document wrote. Neither names where it resolved to — see
     * {@see \MarkdownWord\Exception\ThemeNotFound::outside()} for why, and for what that
     * makes the pair of them safe to hand to a document's author.
     *
     * @param string $name  what the document wrote
     * @param string $found the path it resolved to before `realpath()`
     *
     * @throws ThemeNotFound
     */
    private function directory(string $name, string $found): string
    {
        if (!is_dir($found)) {
            throw ThemeNotFound::noDirectory($name);
        }

        $real = PathScope::resolve($found);

        if ($real === null || !$this->scope->holds($real)) {
            throw ThemeNotFound::outside($name, $this->scope->roots());
        }

        return $real;
    }

    /**
     * The manifest a path named, resolved and held to the roots.
     *
     * The same question as {@see self::directory()} asks of a directory, asked of a file:
     * a `theme:` is a path a document writes, and the file it lands on is read as
     * readily as the directory beside it.
     *
     * @param string $name  what the document wrote
     * @param string $found the path it resolved to before `realpath()`
     *
     * @throws ThemeNotFound
     */
    private function file(string $name, string $found): string
    {
        $real = PathScope::resolve($found);

        if ($real === null || !$this->scope->holds($real)) {
            throw ThemeNotFound::outside($name, $this->scope->roots());
        }

        return $real;
    }

    /**
     * Every theme name that resolves — the document's own, the working directory's, the
     * roots' and the library's — sorted: a name resolves to one of them and the reader of
     * the list cannot tell which, so listing one kind ahead of the other would say
     * something untrue.
     *
     * The source the calling program brought and the directories on disk are listed
     * together, because a name that resolves is a name that is offered: a program with
     * themes of its own does not lose the ones that ship with this package.
     *
     * @return list<string>
     */
    public function available(): array
    {
        $names = [...$this->finder()->themes(), ...$this->diskThemes()];

        $unique = array_values(array_unique($names));

        sort($unique);

        return $unique;
    }

    /**
     * The themes in the directories on disk, which are the fallback for a name the
     * source the calling program brought does not have.
     *
     * @return list<string>
     */
    private function diskThemes(): array
    {
        return (new DiskFinder(theme: $this->themeDirectories()))->themes();
    }
}
