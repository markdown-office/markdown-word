<?php

declare(strict_types=1);

namespace MarkdownWord\Theme;

use MarkdownWord\Exception\ThemeNotFound;
use MarkdownWord\Filesystem\PathScope;

/**
 * Turns the `theme:` a document names into a theme, in the order one is looked for:
 *
 * 1. an explicit path — anything with a `/` in it;
 * 2. `./themes/<name>` under the working directory;
 * 3. a theme that ships with this package;
 * 4. nothing, which is a failure naming every theme that did resolve.
 *
 * Step 4 is the point. Falling back to the built-in default would produce a document in
 * the wrong colours with nothing in the output to say so; the alternative is an error that
 * names the themes that exist, which is a sentence the author of the document can act on.
 *
 * Every step resolves the path with `realpath()` and holds it to {@see PathScope}. A
 * `theme:` is a path a document writes, and a document is not trusted to mean the
 * directory it names: `../` walks out of the working directory and an absolute path is
 * read from wherever it points.
 *
 * The name is worth saying once more, because the resolution is not obvious from it: a
 * bare name is looked for under `./themes/`, so `theme: ..` lands on the working
 * directory itself rather than on anything inside `themes/`. It is contained, because the
 * working directory is a root — but it is not what the name suggests, which is why a
 * theme whose own `theme.yml` sits beside the document resolves this way too.
 */
final class ThemeResolver
{
    private readonly string $builtinDirectory;

    private readonly PathScope $files;

    /**
     * @param list<string> $roots Further directories a document may name a theme in, for a
     *   shared library of themes beside the project. As with a theme's this is the calling
     *   program's decision, made before a document is read: a document that could widen it
     *   would be the arbitrary path read this argument exists to stop.
     */
    public function __construct(
        private readonly string $workingDirectory = '.',
        ?string $builtinDirectory = null,
        private readonly ThemeLoader $loader = new ThemeLoader(),
        array $roots = [],
    ) {
        // The themes beside `src/`, so a package installed anywhere resolves its own
        // themes without being told where they are.
        $this->builtinDirectory = $builtinDirectory ?? dirname(__DIR__, 2) . '/themes';
        $this->files = new PathScope([$this->workingDirectory, $this->builtinDirectory, ...$roots]);
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
            return $this->loader->load($this->directory(
                $name,
                str_starts_with($name, '/') ? $name : $this->workingDirectory . '/' . $name,
            ), $name, $this->files);
        }

        foreach ($this->roots() as $root) {
            if (is_dir($directory = $root . '/' . $name)) {
                return $this->loader->load($this->directory($name, $directory), $name, $this->files);
            }
        }

        throw ThemeNotFound::named($name, $this->available());
    }

    /**
     * The directories a bare name is looked for in, in the order they are tried.
     *
     * @return list<string>
     */
    private function roots(): array
    {
        return [$this->workingDirectory . '/themes', $this->builtinDirectory];
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

        if ($real === null || !$this->files->holds($real)) {
            throw ThemeNotFound::outside($name, $this->files->roots());
        }

        return $real;
    }

    /**
     * Every theme name that resolves, local and built-in together, sorted: a name resolves
     * to one of them and the reader of the list cannot tell which, so listing one kind
     * ahead of the other would say something untrue.
     *
     * @return list<string>
     */
    public function available(): array
    {
        $names = [];

        foreach ($this->roots() as $root) {
            if (!is_dir($root)) {
                continue;
            }

            foreach (scandir($root) ?: [] as $entry) {
                if (str_starts_with($entry, '.')) {
                    continue;
                }

                if (is_file($root . '/' . $entry . '/' . ThemeLoader::THEME_FILE)) {
                    $names[$entry] = true;
                }
            }
        }

        $found = array_keys($names);

        sort($found);

        return $found;
    }
}