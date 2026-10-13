<?php

declare(strict_types=1);

namespace MarkdownWord\Configuration;

/**
 * One header or one footer: the text written in it, and the switches for the parts of
 * it that change from page to page.
 *
 * A value object of its own rather than a corner of {@see Options}, because a running
 * head is not a rendering behaviour: it is content a document describes about itself,
 * and a template brings one of its own that a document overrides a slot at a time. The
 * page number and the soft-break rule answer different questions and do not belong in
 * one list of switches.
 *
 * Every property defaults to `null`, so nothing is configured until a document or a
 * template says so, and that is what {@see self::isEmpty()} asks. It is also what makes
 * a `false` a value rather than an absence: `page: false` turns a page number off, while
 * a `page:` with nothing under it says nothing at all.
 *
 * The two ways in from an array read a `null` differently, as they do in {@see Options}:
 * {@see self::fromArray()} has nothing to lose by ignoring one, and {@see self::withAll()}
 * has a receiver whose value would go missing, so it reads the same null as "not
 * mentioned" and keeps what is there.
 */
final class Furniture
{
    /** @var list<string> */
    private const ALIGNS = ['left', 'center', 'right'];

    /** @var list<string> */
    private const BORDERS = ['top', 'bottom', 'both'];

    /**
     * The properties whose value is a switch rather than text.
     *
     * {@see self::cast()} reads them and {@see self::validate()} accepts a spelling for
     * each, and the two agree on which spellings there are. A value accepted by one and
     * not read the same way by the other is a document that validates and then gets the
     * opposite of what it wrote, which is worse than a document refused.
     *
     * @var list<string>
     */
    private const SWITCHES = ['chapter', 'page', 'total', 'filename', 'date', 'firstPage', 'even'];

    /**
     * The properties where `null` is a value in its own right rather than the absence of
     * one, and so a merge applies it rather than keeping what the receiver holds.
     *
     * Empty, and deliberately so: each of these can be turned off by writing its own
     * value — `page: false` — or by leaving the key out, so a `null` carries nothing to
     * apply and both ways in drop it. A property that means something when it is null
     * belongs in this list; without a line here it would be merged as an absence, and
     * could never be cleared once it had a value.
     *
     * {@see \MarkdownWord\Configuration\Options} keeps the same list for the same reason.
     *
     * @var list<string>
     */
    private const NULLABLE = [];

    /**
     * @param string|null $dateFormat The picture a date is written in, such as
     *        `d MMMM yyyy`. A value in its own right rather than part of a switch, so a
     *        document can change the format of a date a template already asked for.
     * @param bool|null   $date Whether to draw a date. Word's own `DATE` field rather
     *        than a date written down, so a document that says so does not print the
     *        day it was generated and call it the day it was written. A document that
     *        wants a fixed date states it in `fields:` and puts it in the template,
     *        which is where a value belongs and a switch does not.
     *
     * What either of them is used for is the writer's question; this class holds what a
     * block said, and knows only that the two are separate keys a merge keeps apart.
     */
    public function __construct(
        public readonly ?string $body = null,
        public readonly ?string $align = null,
        public readonly ?string $border = null,
        public readonly ?string $dateFormat = null,
        public readonly ?bool $chapter = null,
        public readonly ?bool $page = null,
        public readonly ?bool $total = null,
        public readonly ?bool $filename = null,
        public readonly ?bool $date = null,
        public readonly ?bool $firstPage = null,
        public readonly ?bool $even = null,
    ) {
    }

    /**
     * Build one from a plain array, for a `config.php` or a JSON document.
     *
     * A `null` means "not configured" here, and the default below — which is `null` for
     * every property — fills it back in. {@see self::withAll()} reads the same null the
     * other way round, because it has a receiver that would lose a value.
     *
     * @param array<string, mixed> $given
     */
    public static function fromArray(array $given): self
    {
        // Unknown keys are dropped rather than passed on, so a template or a config file
        // may carry entries for something else without breaking the conversion.
        $known = (new self())->toArray();
        $kept = array_intersect_key($given, $known);

        return new self(...self::cast(array_merge($known, self::withoutUnconfigured($kept))));
    }

    /**
     * Merge a block over this one.
     *
     * This is the whole of what a frontmatter's override of a template's header is. A
     * `null` means "not mentioned" rather than "reset": a document naming a `body` and
     * saying nothing about the alignment its template set is changing the body, and
     * reading the absent alignment as a reset would change a slot the author never
     * mentioned. That is silent data loss rather than a visible mistake, and it is the
     * one thing a partial override must never do.
     *
     * The two ways in agree on which properties can hold a null at all — that is a
     * property of the class rather than of the caller — so they drop the same keys
     * through {@see self::withoutUnconfigured()}.
     *
     * @param array<string, mixed> $given
     */
    public function withAll(array $given): self
    {
        return self::fromArray(array_merge($this->toArray(), self::withoutUnconfigured($given)));
    }

    /**
     * Whether anything at all was configured, which is how an empty header is told from
     * a filled one.
     *
     * Nothing configured has to produce no Word header part at all: a document naming a
     * `header:` and giving it no keys is asking for none, and writing the part would put
     * an empty band on every page of a template that has a header of its own.
     *
     * Compared against `null` rather than through `empty()`, because `false` and `''`
     * are values: a page number switched off, and a body with nothing in it under a
     * border, are both headers somebody asked for.
     */
    public function isEmpty(): bool
    {
        // Read through `toArray()` so a property added later cannot be missing here.
        foreach ($this->toArray() as $value) {
            if ($value !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'body' => $this->body,
            'align' => $this->align,
            'border' => $this->border,
            'dateFormat' => $this->dateFormat,
            'chapter' => $this->chapter,
            'page' => $this->page,
            'total' => $this->total,
            'filename' => $this->filename,
            'date' => $this->date,
            'firstPage' => $this->firstPage,
            'even' => $this->even,
        ];
    }

    /**
     * Everything wrong with a `header:` or `footer:` block, in the order the keys were
     * written.
     *
     * The error surface for the block rather than part of {@see self::fromArray()},
     * which stays the documented behaviour of a value object and drops what it cannot
     * use. The case this exists for is a known key with a value it cannot take — an
     * `align` that is not one of the three words — which nothing raises over, and the
     * alignment the document asked for is then simply not there.
     *
     * A `null` is not a problem either: it is not configured, which is what every
     * property's default already is, so a block that says it says nothing.
     *
     * Nothing is checked across properties either. `dateFormat` without `date` is a
     * partial override of a date a template already asked for, and reporting it would
     * refuse the merge this class exists to perform.
     *
     * @param array<string, mixed> $given
     * @param callable(string): ?int|null $locate The file line a property sits on. It is
     *        handed the property name as written — `align`, `dateFormat` — because this
     *        class does not know which of `header:` and `footer:` it is reading.
     * @param string $where The key the block was read from, named in each message.
     *        Without it a `align: 3` is reported as a problem with a `align` and the
     *        reader is left to work out which of the two blocks it is in.
     *
     * @return list<string>
     */
    public static function validate(
        array $given,
        ?callable $locate = null,
        string $where = 'header or footer',
    ): array {
        $known = array_keys((new self())->toArray());
        $problems = [];

        foreach ($given as $property => $value) {
            $property = (string) $property;

            if (!\in_array($property, $known, true)) {
                $problems[] = Validator::locate(
                    \sprintf('In "%s": %s', $where, Validator::describe($property, '', '', $known, 'keys')),
                    self::line($locate, $property),
                );

                continue;
            }

            $rest = $value === null ? null : self::shapeOf($property, $value);

            if ($rest !== null) {
                $problems[] = Validator::locate(
                    \sprintf(
                        'In "%s": %s',
                        $where,
                        Validator::describeValue(
                            \sprintf('the "%s" key', $property),
                            ValueShape::show($value),
                            $rest,
                        ),
                    ),
                    self::line($locate, $property),
                );
            }
        }

        return $problems;
    }

    /**
     * What one property's value has to look like, or null when it does.
     *
     * A string property is a string and is not cast: `align: 3` is a mistake in the file
     * rather than an alignment of some number, and the sentence below says the word that
     * would have worked instead.
     *
     * The switches are asked about as a group rather than one arm each, so the list they
     * are cast from is the list they are checked against. A property with no arm accepts
     * anything, which is deliberate: adding one to {@see self::toArray()} then cannot
     * raise here before anybody has decided what it may be.
     */
    private static function shapeOf(string $property, mixed $value): ?string
    {
        if (\in_array($property, self::SWITCHES, true)) {
            return ValueShape::boolean($value);
        }

        return match ($property) {
            'align' => ValueShape::oneOf($value, self::ALIGNS),
            'border' => ValueShape::oneOf($value, self::BORDERS),
            'body' => \is_string($value)
                ? null
                : 'It is Markdown text, written as a value or as a block beneath the colon.',
            'dateFormat' => \is_string($value) ? null : 'It is text, written as a string.',
            default => null,
        };
    }

    /**
     * The keys of `$given` that say nothing, so both ways in can drop them and read a
     * `null` the same way.
     *
     * {@see self::NULLABLE} is empty, which is why this removes nothing at all today:
     * every property's default is `null` already, so dropping the key and keeping it
     * reach the same object, and the list has one place to be wrong when a property
     * comes to mean something when it is null.
     *
     * @param  array<string, mixed> $given
     * @return array<string, mixed>
     */
    private static function withoutUnconfigured(array $given): array
    {
        foreach (array_keys($given) as $property) {
            if ($given[$property] === null && !\in_array($property, self::NULLABLE, true)) {
                unset($given[$property]);
            }
        }

        return $given;
    }

    /**
     * Coerce the loosely typed values a YAML or JSON block produces into the types the
     * constructor promises.
     *
     * {@see self::SWITCHES} is listed here rather than cast where each is read, so a
     * switch added later is cast on arrival instead of reaching the renderer as the
     * string a config file happened to write. The strings are deliberately not cast: a
     * mode that is not one of the allowed words is a mistake in the file, and inventing
     * a fallback for it is a decision this class does not get to make.
     *
     * The array handed in has been merged over the defaults already, so a `null` here is
     * a property that takes one, and is left alone.
     *
     * @param  array<string, mixed> $given
     * @return array<string, mixed>
     */
    private static function cast(array $given): array
    {
        foreach (self::SWITCHES as $switch) {
            if (isset($given[$switch])) {
                $given[$switch] = self::asSwitch($given[$switch]);
            }
        }

        return $given;
    }

    /**
     * A switch as a YAML block or a hand-written config file spells it.
     *
     * `(bool) "no"` is `true`, so a plain cast would write a page number into a header
     * that asked for none. The words read are the ones {@see ValueShape::boolean()}
     * accepts, and the two have to agree: it has to say a value is usable and this has
     * to read it the way it was written.
     */
    private static function asSwitch(mixed $value): bool
    {
        if (\is_string($value)) {
            return !\in_array(\strtolower($value), ['false', 'no', 'off'], true);
        }

        return (bool) $value;
    }

    /**
     * @param callable(string): ?int|null $locate
     */
    private static function line(?callable $locate, string $property): ?int
    {
        return $locate === null ? null : $locate($property);
    }
}