<?php

declare(strict_types=1);

namespace MarkdownWord\Files;

use MarkdownWord\Theme\ThemeLoader;

/**
 * The default finder: the files are in directories on this machine.
 *
 * It holds the bases and nothing else — where to look, not what may be read. The
 * library asks its own scope of the path this answers, and refuses what is out of
 * reach in its own words; a finder that refused would throw this package's
 * exception, which the other half of this suite would not catch.
 *
 * The bases are given per role and are tried in the order they are given, so the
 * calling program decides what a document's file is looked for in: the themes beside
 * the document before the ones that ship with this package, a shared library of
 * themes before either.
 *
 * A name that is already a path — one that begins with `/` — is taken as it stands,
 * whatever the role: it names a file, and resolving it against a base would name a
 * file nobody meant.
 *
 * A theme is resolved by its directory first: the first base where `<base>/<theme>`
 * is a directory is the theme's directory, and the theme's files are looked for
 * inside it. So a theme directory that exists but holds no manifest still shadows a
 * later one, and is reported as a theme without a manifest rather than as a theme
 * that is not there.
 */
final class DiskFinder implements FileFinder
{
    /**
     * @param list<string> $theme The directories a theme's files are looked for in, in the
     *        order they are tried.
     * @param list<string> $picture The directories a picture is looked for in, in the order
     *        they are tried.
     * @param list<string> $document The directories a document's own file is looked for in,
     *        in the order they are tried.
     */
    public function __construct(
        private readonly array $theme = [],
        private readonly array $picture = [],
        private readonly array $document = [],
    ) {
    }

    /**
     * The contents of a file a name identifies, or null when there is none.
     */
    public function read(string $name, ?string $role = null): ?string
    {
        $path = $this->resolve($name, $role);

        if ($path === null) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * The path of a file a name identifies, when this finder holds it as a file.
     *
     * The path as it was written rather than as the file system resolved it: the
     * library does the resolving and the holding, and a path this machine chose is
     * a path this machine's own rules should be asked about.
     */
    public function path(string $name, ?string $role = null): ?string
    {
        return $this->resolve($name, $role);
    }

    /**
     * The themes that resolve, sorted.
     *
     * A directory that holds a `theme.yml` is listed as a theme and one without it
     * is not. A base that is not there holds nothing: a directory the caller pointed
     * at may not exist yet, and a base with nothing in it is not a failure here.
     *
     * @return list<string>
     */
    public function themes(): array
    {
        $names = [];

        foreach ($this->theme as $base) {
            if (!is_dir($base)) {
                continue;
            }

            foreach (scandir($base) ?: [] as $entry) {
                if (str_starts_with($entry, '.')) {
                    continue;
                }

                if (is_file($this->join($this->join($base, $entry), ThemeLoader::THEME_FILE))) {
                    $names[$entry] = true;
                }
            }
        }

        $found = array_keys($names);

        sort($found);

        return $found;
    }

    /**
     * The names of the files of a theme, sorted.
     *
     * A name that begins with a dot is not one of the theme's files: it is a thing
     * the file system put there rather than something the library reads. A
     * directory is not one either — the library reads files, and a file inside a
     * directory of the theme's is not one the theme has.
     *
     * @return list<string>
     */
    public function files(string $theme): array
    {
        $directory = $this->themeDirectory($theme);

        if ($directory === null) {
            return [];
        }

        $names = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }

            if (is_file($this->join($directory, $entry))) {
                $names[] = $entry;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * The file a name identifies, or null when there is none.
     */
    private function resolve(string $name, ?string $role): ?string
    {
        if (str_starts_with($name, '/')) {
            // A name that is already a path is taken as it stands, whatever the role:
            // resolving it against a base would name a file nobody meant.
            return is_file($name) ? $name : null;
        }

        if ($role === 'theme') {
            return $this->themeFile($name);
        }

        foreach ($this->worlds($role) as $base) {
            $path = $this->join($base, $name);

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * A file of a theme, looked for inside the theme's own directory.
     *
     * The name is `<theme>/<file>`, and the theme is resolved by its directory: the
     * first base where `<base>/<theme>` is a directory is the theme's directory, and
     * the file is inside it. A theme directory that holds no manifest therefore
     * shadows a later one that does — the theme is there, and what it is missing is
     * the manifest.
     *
     * A name that is already a path is not split here: it is taken as it stands, so
     * a path to a file of a theme is the file and not a theme whose name is the
     * empty string.
     */
    private function themeFile(string $name): ?string
    {
        $slash = strpos($name, '/');

        if ($slash === false) {
            // A name without a theme in it names no file of a theme.
            return null;
        }

        $directory = $this->themeDirectory(substr($name, 0, $slash));

        if ($directory === null) {
            return null;
        }

        $path = $this->join($directory, substr($name, $slash + 1));

        return is_file($path) ? $path : null;
    }

    /**
     * The directory a theme is in, or null when there is no such theme.
     *
     * The first base where a directory of that name is, whether it holds a manifest
     * or not: a theme that is there without a manifest is a theme the caller can
     * hear about, and the files of another theme that shares its name are not it.
     */
    private function themeDirectory(string $theme): ?string
    {
        foreach ($this->theme as $base) {
            $directory = $this->join($base, $theme);

            if (is_dir($directory)) {
                return $directory;
            }
        }

        return null;
    }

    /**
     * The directories a name is looked for in, for a role.
     *
     * A role with no bases of its own looks in none of them: the role says which
     * world the name is resolved in, and a world the finder was not given is not one
     * it can answer for. A name with no role is looked for in every world, in the
     * order the roles are declared.
     *
     * The theme role is not among them: a theme is resolved by its directory rather
     * than by a file in a base, which {@see self::themeFile()} is where happens.
     *
     * @return list<string>
     */
    private function worlds(?string $role): array
    {
        if ($role === null) {
            return [...$this->theme, ...$this->picture, ...$this->document];
        }

        return match ($role) {
            'picture' => $this->picture,
            'document' => $this->document,
            default => [],
        };
    }

    /** A path in a base, with one separator between them. */
    private function join(string $base, string $name): string
    {
        return rtrim($base, '/') . '/' . $name;
    }
}
