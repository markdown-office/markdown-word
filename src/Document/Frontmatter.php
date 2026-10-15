<?php

declare(strict_types=1);

namespace MarkdownWord\Document;

use League\CommonMark\Node\Block\Document;
use MarkdownWord\Configuration\Furniture;
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
 * and `theme_file` name a file rather than a setting, and mean nothing to a Word
 * conversion, so they are read here and resolved beside the other configuration —
 * mixing them into `Options` would put a key in the configuration that no renderer
 * looks at, and leave a path unchecked.
 *
 * `header` and `footer` are a third shape, and not one to be handed on: what a running
 * head is made of is content rather than a rendering behaviour, so it is read into
 * {@see Furniture} through {@see self::header()} and {@see self::footer()} instead,
 * which is also what lets a document override one slot of a template's header and keep
 * the rest.
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
     * The name a document gives a theme in its `---` block, if it gives one.
     *
     * Read here rather than folded into {@see self::toConfigurationArray()} because a
     * theme is a *name*, and resolving it is a filesystem operation that has to happen
     * beside the other configuration sources rather than inside a getter — see
     * {@see \MarkdownWord\Theme\ThemeResolver}. It is kept out of `styles:` and
     * `options:` for the same reason those two are the only keys passed on: a theme name
     * is not a slot value, and there is no `with*()` on {@see \MarkdownWord\Configuration}
     * that would read one.
     *
     * Null when the block names no theme, which is the ordinary case and not a failure.
     * The name is returned as written rather than validated here: the resolver says what
     * would have worked instead, and it is the thing that knows the list.
     */
    public function themeName(): ?string
    {
        $value = $this->getString('theme');

        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * The template a document names for itself in its `---` block, if it names one.
     *
     * A path rather than a name, because a template is a file and a document that
     * travels on its own has to be able to say which. It is not folded into
     * `toConfigurationArray()` because nothing in {@see \MarkdownWord\Configuration}
     * reads a path: it is asked for beside the other configuration, where it can be
     * resolved and held to a directory rather than passed along as a string nobody has
     * checked — see {@see \MarkdownWord\Template\TemplateFile}.
     *
     * Nothing in this library calls it. The command line, which moved to
     * `markdown-office/mdoffice`, is what asks — a document naming its own template
     * has to be told where the template is, and the caller holding the string is the
     * caller that has to decide. **A caller would be in another repository**, so the
     * absence of one in `src/` is not the absence of a user.
     *
     * Null when the block names no template, which is the ordinary case and not a
     * failure. Returned as written, because resolving it is a filesystem operation
     * and the resolver is the thing that knows where a document may point.
     */
    public function templateFile(): ?string
    {
        $value = $this->getString('template_file');

        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * The values a document supplies for the fields in its template.
     *
     * Read under `fields:`, one key per `MERGEFIELD` the template names. It is the
     * document that says what its own values are — a document whose client is in a
     * PHP array is a document whose client is in two places, and changing one does not
     * change the other.
     *
     * Flat on purpose. A template's fields are named by Word, one name each, and a
     * nesting here would have to be flattened before it could match any of them.
     *
     * @return array<string, string|int|float> Empty when the block has no `fields:`,
     *         which is the ordinary case: a document rendered into no template needs
     *         no values, and one that is has to say them.
     */
    public function fields(): array
    {
        $values = [];

        foreach ($this->getArray('fields') as $name => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            // A boolean a document wrote as `true` is a word to it and a letter to a
            // field, so it is spelled rather than cast: `1` in a letterhead is a
            // mystery, and `true` is what the author wrote.
            if (is_bool($value)) {
                $values[(string) $name] = $value ? 'true' : 'false';

                continue;
            }

            $values[(string) $name] = $value;
        }

        return $values;
    }

    /**
     * The header a document describes, or an empty one.
     *
     * Read into {@see Furniture} rather than passed on with {@see self::toConfigurationArray()}
     * because a running head is not a rendering behaviour switch: what goes in it is
     * content — a body, a page number, a chapter reference — and {@see \MarkdownWord\Configuration\Options}
     * has no `with*()` that would read one. A document that names no header gets an
     * empty one rather than null, so a caller can ask {@see Furniture::isEmpty()} and
     * write no header part at all.
     */
    public function header(): Furniture
    {
        return Furniture::fromArray($this->getArray('header'));
    }

    /**
     * The footer a document describes, or an empty one. {@see self::header()} for the
     * shape and the reason.
     */
    public function footer(): Furniture
    {
        return Furniture::fromArray($this->getArray('footer'));
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

        return [...$problems, ...$this->furnitureProblems()];
    }

    /**
     * Everything wrong with a `header:` or `footer:` block.
     *
     * {@see Furniture} drops a key it does not hold and a block it cannot read, so
     * without this a mis-indented `algin: right` leaves a header with no alignment and
     * nothing anywhere in the run saying why — and a `header:` written as a plain value
     * leaves a document with no header at all, reported as neither.
     *
     * @return list<string>
     */
    private function furnitureProblems(): array
    {
        $problems = [];

        foreach (['header', 'footer'] as $section) {
            if (!$this->has($section)) {
                continue;
            }

            // A key with nothing under it is a document saying "no overrides", which is
            // not the same claim as one that never mentioned a header and is the ordinary
            // way to write one — so it is left unremarked, and writes no part at all.
            $value = $this->get($section);

            if ($value === null) {
                continue;
            }

            if (self::mappingOf($value) === null) {
                $problems[] = Validator::locate(
                    sprintf(
                        'The "%s" key of the frontmatter block is %s, which is not a mapping of keys. '
                        . 'Each key goes on its own line, indented beneath it.',
                        $section,
                        Validator::show($value),
                    ),
                    $this->lineOf($section),
                );

                continue;
            }

            // The locator is what puts a problem on the line of the key it is about
            // rather than on the line of `header:`. Handed the property name as written,
            // and `BlockLines` separates a path with `/`. A key the block does not have
            // resolves to nothing and the section's own line stands in for it.
            //
            // `Furniture::validate()` returns every problem already located, so nothing
            // is wrapped again here — that would give a reader "Line 2: Line 4:".
            $locate = fn (string $property): ?int
                => $this->lineOf($section . '/' . $property) ?? $this->lineOf($section);

            $problems = [...$problems, ...Furniture::validate($value, $locate, $section)];
        }

        return $problems;
    }
}
