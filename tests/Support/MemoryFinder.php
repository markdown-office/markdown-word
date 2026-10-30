<?php

declare(strict_types=1);

namespace MarkdownWord\Tests\Support;

use MarkdownWord\Files\FileFinder;

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

    /** @var array<string, int> */
    private array $reads = [];

    /** @var array<string, string|null> */
    private array $roles = [];

    /**
     * @param array<string, string> $files The files it holds, by name.
     */
    public function __construct(private readonly array $files = [])
    {
    }

    public function read(string $name, ?string $role = null): ?string
    {
        $this->asked[$name] = true;
        $this->roles[$name] = $role;
        $this->reads[$name] = ($this->reads[$name] ?? 0) + 1;

        return $this->files[$name] ?? null;
    }

    public function path(string $name, ?string $role = null): ?string
    {
        $this->asked[$name] = true;
        $this->roles[$name] = $role;

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

    /**
     * The role a name was asked for in.
     *
     * The role says which world the name is resolved in, so it is what tells a
     * picture asked for as a picture from a theme asked for as a theme.
     */
    public function askedFor(string $name): ?string
    {
        return $this->roles[$name] ?? null;
    }

    /**
     * How many times the contents of a name were asked for.
     *
     * The count is what says a name was resolved twice rather than once: a theme
     * named in code is resolved when the name is given, and a theme named in a
     * document's own block is resolved when the document is drawn.
     */
    public function reads(string $name): int
    {
        return $this->reads[$name] ?? 0;
    }
}
