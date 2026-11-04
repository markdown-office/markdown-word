<?php

declare(strict_types=1);

namespace MarkdownWord\Theme;

use MarkdownWord\Configuration\Styles;
use MarkdownWord\Configuration\TextStyles;
use MarkdownWord\Configuration\Validator;
use MarkdownWord\Exception\InvalidConfiguration;
use MarkdownWord\Exception\MalformedTheme;
use MarkdownWord\Exception\UnknownStyleSlot;

/**
 * What a `theme.yml` says about itself: what it calls itself, and how it looks.
 *
 * The look is two sections of the same file — `styles:` for the
 * {@see \MarkdownWord\Configuration\Styles} slots a `.docx` is drawn from, and
 * `textStyles:` for the named styles a document may write a word in.
 *
 * This half deliberately holds no palette, no type scale and no rules for turning
 * either into slot values, and the reason is worth stating. PHPWord writes
 * `word/theme/theme1.xml` with a fixed scheme and no way to change it
 * (`Writer\Word2007\Part\Theme` writes its own twelve colours), and the renderer
 * writes colours straight onto the runs rather than referring to theme slots. A
 * palette therefore has nowhere to land except as direct formatting, and a set of
 * rules deciding which slot takes which palette entry would be a vocabulary nobody
 * wrote down, applied in a layer the reader of the file never sees. A theme that
 * says `heading.1: {color: "…"}` says what it means.
 *
 * The two halves of this suite part at the look, and this one is narrow on purpose:
 * a `theme.yml` that carries the slides half's `colors:` or `fonts:` is refused, and
 * so is one that carries a key nobody wrote down. This half's own look is `styles:`,
 * and the slides half's is `dark:`, `colors:`, `fonts:`, `style:` or `layouts:`;
 * what the two share is a theme's name, its description, its author and its named
 * text styles, so a theme file carrying only those is read by both, and each draws
 * it its own way. A key this half does not know is a mistake worth saying so about.
 */
final class Theme
{
    /** The keys a `theme.yml` may have. */
    public const KEYS = ['name', 'description', 'author', 'styles', 'textStyles'];

    private function __construct(
        private readonly string $name,
        private readonly ?string $description,
        private readonly ?string $author,
        private readonly Styles $styles,
        private readonly TextStyles $textStyles,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws MalformedTheme
     * @throws UnknownStyleSlot
     * @throws InvalidConfiguration
     */
    public static function fromArray(array $data, string $file = 'theme.yml'): self
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw MalformedTheme::unknownKey($file, (string) $key, self::KEYS);
            }
        }

        $name = $data['name'] ?? null;

        if (!is_string($name) || trim($name) === '') {
            throw MalformedTheme::missingKey($file, 'name');
        }

        return new self(
            trim($name),
            self::text($data, 'description', $file),
            self::text($data, 'author', $file),
            self::slots($data, $file),
            self::named($data, $file),
        );
    }

    /** The name a human reads, which is not the directory it was found in. */
    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function author(): ?string
    {
        return $this->author;
    }

    /** The slots this theme draws from, already merged over the built-in look. */
    public function styles(): Styles
    {
        return $this->styles;
    }

    /** The named text styles this theme brings, for a document to write a word in. */
    public function textStyles(): TextStyles
    {
        return $this->textStyles;
    }

    /**
     * The theme as it was written, sections and all.
     *
     * The slots are the ones the theme named rather than the whole merged look: what
     * a theme says about itself is what it wrote, and the built-in look underneath it
     * is this library's rather than the theme's.
     *
     * It is public API — the shape {@see self::fromArray()} reads, for a caller who
     * wants a theme as an array: to write a theme file, or to compare two themes.
     *
     * @return array{name: string, description: ?string, author: ?string, styles: array<string, mixed>, textStyles: array<string, array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'author' => $this->author,
            'styles' => $this->styles->written(),
            'textStyles' => $this->textStyles->toArray(),
        ];
    }

    /**
     * The slots the theme's `styles:` section names.
     *
     * Checked twice, on purpose. The slot *names* are checked here so an unknown one
     * can be reported as {@see UnknownStyleSlot} next to the file that has it — the
     * type a caller is already catching. Everything inside them — property names,
     * value shapes, a `color:` written with a leading `#`, a `size:` holding a word —
     * goes through {@see \MarkdownWord\Configuration\Validator}, which is the same
     * check a `styles:` block gets. Without it a theme is the one place a typo can
     * reach PHPWord as if it were a value, and it arrives in a document with nothing
     * to say it was never read.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws MalformedTheme
     * @throws UnknownStyleSlot
     * @throws InvalidConfiguration
     */
    private static function slots(array $data, string $file): Styles
    {
        $styles = $data['styles'] ?? null;

        if ($styles === null) {
            return new Styles();
        }

        if (!is_array($styles) || ($styles !== [] && array_is_list($styles))) {
            throw MalformedTheme::notAMapping(
                $file,
                'a mapping of style slots to styles',
                MalformedTheme::describe($styles),
            );
        }

        $known = array_keys(Styles::defaults());
        $slots = [];

        foreach ($styles as $slot => $style) {
            $slot = (string) $slot;

            if (!in_array($slot, $known, true)) {
                throw UnknownStyleSlot::named($file, $slot, $known);
            }

            $slots[$slot] = self::normalise($style);
        }

        // After `normalise()`, so a colour read as an integer is checked as the string
        // it is about to become rather than refused for being the wrong type.
        Validator::assertValid(['styles' => $slots]);

        return new Styles($slots);
    }

    /**
     * The named text styles the theme's `textStyles:` section names.
     *
     * They are a section of their own rather than a slot: `textStyles` is not a style
     * of the document but the named styles a document may write a word in, and it
     * would otherwise be refused as a slot that does not exist. They are checked by
     * {@see \MarkdownWord\Configuration\TextStyles}, which knows the eight attributes
     * they may carry.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws MalformedTheme
     * @throws InvalidConfiguration
     */
    private static function named(array $data, string $file): TextStyles
    {
        $named = $data[TextStyles::KEY] ?? null;

        if ($named === null) {
            return new TextStyles();
        }

        if (!is_array($named) || ($named !== [] && array_is_list($named))) {
            throw MalformedTheme::notAMapping(
                $file,
                'a mapping of style names to styles',
                MalformedTheme::describe($named),
            );
        }

        $styles = [];

        foreach ($named as $name => $properties) {
            $styles[(string) $name] = self::normalise($properties);
        }

        return new TextStyles($styles);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws MalformedTheme
     */
    private static function text(array $data, string $key, string $file): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw MalformedTheme::wrongType($file, $key, 'a string');
        }

        return $value;
    }

    /**
     * A colour written as six digits and left unquoted, turned back into the string it was.
     *
     * YAML reads `404040` as the integer it is, and a colour is the one value in this
     * vocabulary that is legitimately written as nothing but digits — so `color: 404040`
     * arrives as an integer and reaches PHPWord where a string was meant.
     * {@see \MarkdownWord\Configuration\ValueShape::colour()} accepts the integer,
     * deliberately, because the digits are the colour; this is the other half of that
     * decision, which is where the string comes back.
     *
     * Only an integer, and only one whose digits are all digits. The second half matters:
     * YAML also reads a scalar as a *float* when it looks like scientific notation, and a
     * hex colour is exactly that shape — `2E5395` is `2e5395` in a mantissa, and parses as
     * infinity. Casting that would write the literal text `"INF"` into the document where
     * the colour was meant, which is why a theme file that names such a colour has to
     * quote it.
     *
     * {@see \MarkdownWord\Configuration\Validator} then refuses whatever does not survive
     * this, so a colour that only *looks* like one is a load-time error rather than a
     * document drawn in the wrong ink.
     */
    private static function normalise(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::normalise(...), $value);
        }

        return is_int($value) && preg_match('/\A\d{6}\z/', (string) $value) === 1
            ? (string) $value
            : $value;
    }
}
