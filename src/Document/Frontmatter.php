<?php

declare(strict_types=1);

namespace MarkdownWord\Document;

use League\CommonMark\Node\Block\Document;
use MarkdownWord\Configuration\Validator;
use MarkdownWord\Exception\InvalidConfiguration;
use MarkdownWord\Exception\InvalidConfigurationValue;

/**
 * The YAML block at the top of a Markdown file, read as configuration rather than
 * as content.
 *
 * The `FrontMatterExtension` already strips the block from the document so it never
 * renders; this reads the same data from where the extension leaves it. Reading the
 * parsed document rather than re-parsing the string keeps one YAML parser in the
 * process and means a file that fails to parse fails in one place rather than two.
 *
 * Two shapes live in the block and are kept apart on purpose. `options` and `styles`
 * are {@see \MarkdownWord\Configuration} and are handed to it as-is. `template_file`
 * and `theme_file` name a document or a deck and mean nothing to a Word conversion, so
 * they are read here and used by whoever renders — mixing them into `Options` would put
 * a key in the configuration that no renderer looks at.
 *
 * The block is also where the source is: {@see self::lineOf()} answers where in a
 * file a given key sits, which is what lets a mistake in it be reported as "line 7"
 * rather than as a list of keys to go looking for.
 */
final class Frontmatter
{
    /**
     * @param mixed $raw Whatever YAML gave, kept so a block that is not a mapping can
     *                   be reported as what it is rather than as nothing at all.
     * @param array<array-key, mixed>|null $entries The same thing when it is a mapping,
     *                   and null when it is not. Every reader below reads null as
     *                   "there is nothing here", which is true, and which is also why
     *                   the block itself has to be checked somewhere else.
     */
    private function __construct(
        private readonly mixed $raw,
        private readonly ?array $entries,
        private readonly BlockLines $lines,
    ) {
    }

    /**
     * The frontmatter of an already-parsed document, or an empty one when the document
     * has none.
     *
     * `null` is what the extension leaves behind when there was no block, which is a
     * different thing from a block that parsed to nothing: the first is a document
     * without frontmatter, the second is a document with an empty one, and only the
     * second can be told from the first by looking at the source.
     *
     * @param string $markdown The Markdown the document was parsed from, when the caller
     *        has it. It is the only place the block's own text survives, so without it
     *        {@see self::lineOf()} has nothing to find and every problem is reported
     *        without a line.
     */
    public static function fromDocument(Document $document, string $markdown = ''): self
    {
        // A default is required, not tidiness: the key is only written by the parser
        // that has the frontmatter extension, so every other parser leaves the document
        // without it and asking for it raises rather than answering.
        $raw = $document->data->get('front_matter', null);

        return new self($raw, self::mappingOf($raw), BlockLines::of($markdown));
    }

    public static function none(): self
    {
        return new self(null, null, BlockLines::of(''));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function of(array $data): self
    {
        return new self($data, $data, BlockLines::of(''));
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
     * The part of the block that configures the conversion.
     *
     * Only the two sub-keys {@see \MarkdownWord\Configuration} understands are passed
     * on. Everything else in the block is metadata for a renderer, and handing it here
     * would put keys into the configuration that no `with*()` reads.
     *
     * @return array{styles?: array<array-key, mixed>, options?: array<array-key, mixed>}
     */
    public function toConfigurationArray(): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        $config = [];

        // Checked for presence rather than for emptiness: `styles:` with nothing under
        // it is a document saying "no overrides", which is not the same claim as a
        // document that never mentioned styles.
        foreach (['styles', 'options'] as $key) {
            if ($this->has($key)) {
                $config[$key] = $this->getArray($key);
            }
        }

        return $config;
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
     * @see \MarkdownWord\Document\BlockLines::lineOf() for the path syntax, and for
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

    /**
     * The block as a mapping of keys, or null when it is not one.
     *
     * A block that parsed to a scalar, or to a list of lines, is not a block of
     * settings however much YAML is willing to call it an array — and treating it as
     * one is what used to happen: `array_key_exists()` on a string raised a TypeError
     * with a stack trace in it, and a list was ignored without a word.
     *
     * @return array<array-key, mixed>|null
     */
    private static function mappingOf(mixed $raw): ?array
    {
        if (!\is_array($raw)) {
            return null;
        }

        return $raw === [] || !\array_is_list($raw) ? $raw : null;
    }

    /**
     * Check the whole block, and raise for everything wrong with it at once.
     *
     * The two halves are answered by different things: a block that is not a mapping
     * at all is a question about the YAML, so it is checked here rather than in
     * {@see \MarkdownWord\Configuration\Validator}, which takes a configuration array
     * whose sections are arrays by type and cannot tell a block that never was one.
     *
     * @throws InvalidConfiguration when any part of the block is unusable.
     */
    public function assertValid(): void
    {
        Validator::assertValid($this->readable(), $this->lines->locator(), $this->shapeProblems());
    }

    /**
     * The configuration, minus any section that is not a mapping of keys.
     *
     * A section that is not one is already reported by {@see self::shapeProblems()},
     * and reading its keys as if they were options or styles produces a second,
     * worse message about the same line: `options:\n  - tableBorders` is a list, and
     * walking it says the option "0" does not exist.
     *
     * @return array{styles?: array<array-key, mixed>, options?: array<array-key, mixed>}
     */
    private function readable(): array
    {
        $config = $this->toConfigurationArray();

        foreach (['options', 'styles'] as $section) {
            if (\array_key_exists($section, $config) && self::mappingOf($this->get($section)) === null) {
                unset($config[$section]);
            }
        }

        return $config;
    }

    /**
     * Everything wrong with the block itself rather than with what is inside it.
     *
     * @return list<string>
     */
    public function shapeProblems(): array
    {
        $problems = [];

        if ($this->entries === null && $this->raw !== null) {
            $problems[] = Validator::locate(
                sprintf(
                    'The frontmatter block is %s, which is not a mapping of keys. '
                    . 'It has to be key: value pairs, as `options:` and `styles:` are.',
                    Validator::show($this->raw),
                ),
                $this->firstLine(),
            );
        }

        foreach (['options', 'styles'] as $section) {
            if (!$this->has($section)) {
                continue;
            }

            $value = $this->get($section);

            if (self::mappingOf($value) === null) {
                $problems[] = Validator::locate(
                    sprintf(
                        'The "%s" key of the frontmatter block is %s, which is not a mapping of keys. '
                        . 'Each option or style goes on its own line, indented beneath it.',
                        $section,
                        Validator::show($value),
                    ),
                    $this->lineOf($section),
                );
            }
        }

        return $problems;
    }
}
