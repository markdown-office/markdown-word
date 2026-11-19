<?php

declare(strict_types=1);

namespace MarkdownWord\Document;

/**
 * The values in a frontmatter block, and the file lines they were written on.
 *
 * The block itself is {@see Frontmatter}; this is what it is made of, and the
 * questions a caller asks about the values in it.
 *
 * The block is also where the source is: {@see self::lineOf()} answers where in a
 * file a given key sits, which is what lets a mistake in it be reported as "line 7"
 * rather than as a list of keys to go looking for.
 */
abstract class FrontmatterValues
{
    /**
     * @param mixed $raw Whatever YAML gave, kept so a block that is not a mapping can
     *                   be reported as what it is rather than as nothing at all.
     * @param array<array-key, mixed>|null $entries The same thing when it is a mapping,
     *                   and null when it is not. Every reader reads null as
     *                   "there is nothing here", which is true, and which is also why
     *                   the block itself has to be checked somewhere else.
     */
    protected function __construct(
        protected readonly mixed $raw,
        protected readonly ?array $entries,
        protected readonly BlockLines $lines,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->entries === null || $this->entries === [];
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->entries ?? []);
    }

    /**
     * @return mixed the raw value, for a key nothing else reads
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->entries[$key] ?? $default;
    }

    public function getString(string $key, ?string $default = null): ?string
    {
        $value = $this->get($key);

        return \is_string($value) ? $value : $default;
    }

    public function getInt(string $key, ?int $default = null): ?int
    {
        $value = $this->get($key);

        return \is_int($value) || (\is_string($value) && \preg_match('/^[+-]?\d+$/', $value) === 1)
            ? (int) $value
            : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return \is_bool($value) ? $value : $default;
    }

    /**
     * A nested block, such as `options:` or `styles:`.
     *
     * A key that is present but is not a block is dropped rather than coerced. YAML
     * makes `options: 3` and `options:\n  a: 1` the same syntax, and taking the first
     * as the second would mean a document's contents deciding what they configure.
     *
     * @return array<array-key, mixed>
     */
    public function getArray(string $key): array
    {
        $value = $this->get($key);

        return \is_array($value) ? $value : [];
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->entries ?? [];
    }

    /**
     * The file line a configuration key sits on, or null when the block's text was
     * not kept or the key is written in a shape this cannot follow.
     *
     * @see MarkdownWord\Document\BlockLines::lineOf() for the path syntax, and for
     *      what it gives up on.
     */
    public function lineOf(string $path): ?int
    {
        return $this->lines->lineOf($path);
    }

    /**
     * The line the block's first entry is on, for a problem with the block itself.
     */
    public function firstLine(): ?int
    {
        return $this->lines->firstLine();
    }
}
