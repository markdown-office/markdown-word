<?php

declare(strict_types=1);

namespace MarkdownWord\Files;

use MarkdownWord\Exception\InvalidInput;
use MarkdownWord\Filesystem\PathScope;
use MarkdownWord\Theme\ThemeLoader;

/**
 * The default finder: the files are in directories on this machine.
 *
 * It holds two things, and they are not the same thing. The bases say where to look
 * for a file; the scope says what may be read. A file looked for in a directory of
 * themes can be a symlink pointing out of every one of them, and a document that
 * names it must not get to read it — so the scope is asked of the file as it is
 * read, and never of the directory it was found in.
 *
 * The bases are given per role and are tried in the order they are given, so the
 * calling program decides what a document's file is looked for in: the themes beside
 * the document before the ones that ship with this package, a shared library of
 * themes before either.
 *
 * Every file goes through the scope, a theme's `styles.yml` as surely as the theme's
 * manifest. That is what stops a theme from putting a file outside the roots into
 * the document as direct formatting.
 */
final class DiskFinder implements FileFinder
{
    private readonly PathScope $scope;

    /**
     * @param list<string> $theme The directories a theme's files are looked for in, in the
     *        order they are tried.
     * @param list<string> $picture The directories a picture is looked for in, in the order
     *        they are tried.
     * @param list<string> $document The directories a document's own file is looked for in,
     *        in the order they are tried.
     * @param PathScope|null $scope The directories a file may resolve into. Null holds the
     *        finder to its own bases, which is the safe reading of a scope that was not
     *        given: a file in a base is a file this finder may read, and nothing else is.
     */
    public function __construct(
        private readonly array $theme = [],
        private readonly array $picture = [],
        private readonly array $document = [],
        ?PathScope $scope = null,
    ) {
        $this->scope = $scope ?? new PathScope([...$theme, ...$picture, ...$document]);
    }

    /**
     * The contents of a file a name identifies, or null when there is none.
     *
     * @throws InvalidInput when the file is there and this render may not read it.
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
     * The resolved path rather than the name as it was written, because the caller
     * opens this file — as a package, or as a template — and a name that resolves
     * elsewhere is a different file.
     *
     * @throws InvalidInput when the file is there and this render may not read it.
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

                if (is_file(rtrim($base, '/') . '/' . $entry . '/' . ThemeLoader::THEME_FILE)) {
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
     * the file system put there rather than something the library reads.
     *
     * What is on disk rather than what this render may read: the listing is how the
     * library tells a file the theme does not have from a source that cannot serve
     * the one it does, and a file this render may not read is still a file the theme
     * has.
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

            if (is_file($directory . '/' . $entry)) {
                $names[] = $entry;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * The file a name identifies, resolved and held to the scope, or null when there
     * is none.
     *
     * The first base that has the file decides, and the scope is asked of that file.
     * A file that is there and may not be read is refused rather than passed over in
     * favour of another base's file of the same name: a document naming it is
     * reaching for something, and a different file with the same name is not what it
     * asked for.
     *
     * @throws InvalidInput when the file is outside the directories this render may read.
     */
    private function resolve(string $name, ?string $role): ?string
    {
        if (str_starts_with($name, '/')) {
            // A name that is already a path is taken as it stands, because resolving
            // it against a base would name a file nobody meant.
            return $this->held(PathScope::resolve($name), $name);
        }

        foreach ($this->bases($role) as $base) {
            $real = PathScope::resolve(rtrim($base, '/') . '/' . $name);

            if ($real === null || !is_file($real)) {
                continue;
            }

            return $this->held($real, $name);
        }

        return null;
    }

    /**
     * A resolved path, held to the scope.
     *
     * The name is echoed and the path it resolved to is not, for the reason
     * {@see \MarkdownWord\Exception\ThemeNotFound::outside()} gives: the resolved form
     * is a path this machine chose, and the roots are the program's own.
     *
     * @throws InvalidInput when the file is outside the directories this render may read.
     */
    private function held(?string $resolved, string $name): ?string
    {
        if ($resolved === null) {
            return null;
        }

        if (!$this->scope->holds($resolved)) {
            throw new InvalidInput(sprintf(
                'The file "%s" is outside the directories this render may read: %s.',
                $name,
                $this->scope->roots() === [] ? 'none' : implode(', ', $this->scope->roots()),
            ));
        }

        return $resolved;
    }

    /**
     * The directories a name is looked for in, for a role.
     *
     * A role with no bases of its own looks in none of them: the role says which
     * world the name is resolved in, and a world the finder was not given is not one
     * it can answer for. A name with no role is looked for in every world, in the
     * order the roles are declared.
     *
     * @return list<string>
     */
    private function bases(?string $role): array
    {
        if ($role === null) {
            return [...$this->theme, ...$this->picture, ...$this->document];
        }

        return match ($role) {
            'theme' => $this->theme,
            'picture' => $this->picture,
            'document' => $this->document,
            default => [],
        };
    }

    /**
     * The directory a theme is in, or null when there is no such theme.
     *
     * The first directory of that name holding a manifest, so a theme that is not
     * there has no files rather than the files of something that shares its name.
     */
    private function themeDirectory(string $theme): ?string
    {
        foreach ($this->theme as $base) {
            $directory = rtrim($base, '/') . '/' . $theme;

            if (is_dir($directory) && is_file($directory . '/' . ThemeLoader::THEME_FILE)) {
                return $directory;
            }
        }

        return null;
    }
}
