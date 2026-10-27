<?php

declare(strict_types=1);

namespace MarkdownWord\Configuration;

use MarkdownWord\Exception\InvalidConfiguration;
use MarkdownWord\Exception\InvalidConfigurationValue;
use MarkdownWord\Exception\MalformedTheme;
use MarkdownWord\Exception\UnknownConfigurationKey;

/**
 * The named text styles a theme brings, and the attributes they may carry.
 *
 * A text style is a name and a set of properties, and the properties are the eight
 * attributes the inline style vocabulary has: `color`, `size`, `font`, `bold`,
 * `italic`, `underline`, `strike` and `highlight`. There is no second vocabulary —
 * a style's `color` is the `color` attribute's value, and a style's `size` is in
 * points as every other size in this library is.
 *
 * The names are folded, because a directive name is matched without regard to case
 * and `:Brand[…]` is the same word to a reader as `:brand[…]`. The theme's own
 * spelling is kept beside the folded one, because that is the spelling the author
 * has to change.
 *
 * A style that is not a set of properties, a style that styles nothing, a style
 * whose properties name an attribute that does not exist, a value that is not one,
 * a style called `style` and two names for one style are refused here rather than
 * in the renderer: a theme is read once, and a mistake in it is worth saying so
 * about before a byte of the document is built.
 */
final class TextStyles
{
    /**
     * The attributes a style may carry, and the PHPWord property each one reaches.
     *
     * Three of the properties are not spelled the way the attribute is: a `font` is a
     * `name` there, a `strike` is a `strikethrough`, and a `highlight` is a
     * background — `w:shd`, which takes a colour, rather than `w:highlight`, whose
     * value is one of Word's sixteen names and not a colour at all.
     */
    public const ATTRIBUTES = [
        'color' => 'color',
        'size' => 'size',
        'font' => 'name',
        'bold' => 'bold',
        'italic' => 'italic',
        'underline' => 'underline',
        'strike' => 'strikethrough',
        'highlight' => 'bgColor',
    ];

    /**
     * The name the raw form takes: `:style[text]{color=…}`, with no theme style
     * behind it.
     */
    public const RESERVED = 'style';

    /** The key a theme's `styles.yml` carries them under. */
    public const KEY = 'textStyles';

    /**
     * The styles, keyed by the name they are found under.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $styles = [];

    /**
     * The name the theme wrote each style under, by the name it is found under.
     *
     * @var array<string, string>
     */
    private array $written = [];

    /**
     * @param array<string, array<string, mixed>> $styles The styles, keyed by the name
     *        they are written under.
     *
     * @throws InvalidConfiguration
     */
    public function __construct(array $styles = [])
    {
        [$this->styles, $this->written] = self::validated($styles);
    }

    /**
     * A style's name as the suite folds it.
     *
     * Without regard to case and without the space around it: a style's name is a word
     * to a reader, and `:Brand[Bakery]` and `:brand[Bakery]` are the same word written
     * twice. The slides half folds its names the same way, and the directives package
     * folds the names of the directives themselves.
     */
    public static function fold(string $name): string
    {
        return strtolower(trim($name));
    }

    /**
     * Whether a style of that name exists.
     */
    public function has(string $name): bool
    {
        return isset($this->styles[self::fold($name)]);
    }

    /**
     * The properties a style sets, or null when there is no style of that name.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        return $this->styles[self::fold($name)] ?? null;
    }

    /**
     * The names this theme has, in the order its file listed them and as it spelled
     * them.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_values($this->written);
    }

    /**
     * The styles, keyed by the name they are written under.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        $styles = [];

        foreach ($this->styles as $folded => $properties) {
            $styles[$this->written[$folded]] = $properties;
        }

        return $styles;
    }

    /**
     * Styles merged over the ones already here, a style's properties among them.
     *
     * The names are folded, so a style of the same name in another case is the same
     * style and its properties are merged into the one already here — under the name
     * it was written under first, because that is the spelling the author has to
     * change.
     *
     * @param array<string, array<string, mixed>> $styles
     */
    public function withAll(array $styles): self
    {
        $incoming = new self($styles);

        $merged = $this->toArray();
        $written = $this->written;

        foreach ($incoming->styles as $folded => $properties) {
            if (isset($written[$folded])) {
                $merged[$written[$folded]] = array_replace_recursive($merged[$written[$folded]], $properties);

                continue;
            }

            $merged[$incoming->written[$folded]] = $properties;
            $written[$folded] = $incoming->written[$folded];
        }

        return new self($merged);
    }

    /**
     * The font properties a set of attributes amounts to.
     *
     * Null when one of them names an attribute that does not exist or holds a value
     * that is not what the attribute takes — the two things this half cannot draw.
     *
     * A property whose value is `false` is not set on the run: the run inherits
     * whatever the block around it gave it, which is what an attribute set to
     * `false` means.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>|null
     */
    public static function fontFor(array $attributes): ?array
    {
        $font = [];

        foreach ($attributes as $attribute => $value) {
            $attribute = (string) $attribute;

            if (self::restFor($attribute, $value) !== null) {
                return null;
            }

            $property = self::propertyFor($attribute, $value);

            if ($property === null) {
                return null;
            }

            $font += $property;
        }

        return array_filter($font, static fn (mixed $value): bool => $value !== false);
    }

    /**
     * The styles that can be drawn, and the name each of them was written under.
     *
     * @param array<string, array<string, mixed>> $styles
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, string>}
     *
     * @throws InvalidConfiguration
     */
    private static function validated(array $styles): array
    {
        $validated = [];
        $written = [];

        foreach ($styles as $name => $properties) {
            $name = (string) $name;
            $folded = self::fold($name);

            if (isset($written[$folded])) {
                throw MalformedTheme::duplicateStyle($name, $written[$folded]);
            }

            if ($folded === self::RESERVED) {
                throw new InvalidConfiguration(sprintf(
                    'The style "%s" cannot be defined: "%s" is the name the raw form takes.',
                    $name,
                    self::RESERVED,
                ));
            }

            if (!is_array($properties) || ($properties !== [] && array_is_list($properties))) {
                throw new InvalidConfiguration(sprintf(
                    'The "%s" style is %s. A text style is a set of properties, one per line beneath it.',
                    $name,
                    ValueShape::show($properties),
                ));
            }

            if ($properties === []) {
                throw new InvalidConfiguration(sprintf(
                    'The "%s" style has no properties. A style that styles nothing is a mistake.',
                    $name,
                ));
            }

            foreach ($properties as $attribute => $value) {
                self::assertUsable((string) $attribute, $value, $name);
            }

            $validated[$folded] = $properties;
            $written[$folded] = $name;
        }

        return [$validated, $written];
    }

    /**
     * @throws InvalidConfiguration
     */
    private static function assertUsable(string $attribute, mixed $value, string $style): void
    {
        if (!isset(self::ATTRIBUTES[$attribute])) {
            throw UnknownConfigurationKey::for(
                $attribute,
                '',
                sprintf(' property of the "%s" style', $style),
                array_keys(self::ATTRIBUTES),
                'style properties',
            );
        }

        $rest = self::restFor($attribute, $value);

        if ($rest !== null) {
            throw InvalidConfigurationValue::for(
                sprintf('The "%s" property of the "%s" style', $attribute, $style),
                Validator::show($value),
                $rest,
            );
        }
    }

    /**
     * The rest of the sentence that says what an attribute's value may be, or null
     * when the value is one the attribute takes.
     */
    private static function restFor(string $attribute, mixed $value): ?string
    {
        $property = self::ATTRIBUTES[$attribute] ?? null;

        if ($property === null) {
            return 'It is not one of the attributes a style may carry.';
        }

        return match ($property) {
            'color', 'bgColor' => ValueShape::colour($value),
            'size' => ValueShape::number($value, 0.001),
            'name' => is_string($value) && trim($value) !== ''
                ? null
                : 'It is a font name, written as a string.',
            default => self::boolean($value) === null ? 'It is true or false.' : null,
        };
    }

    /**
     * The font property an attribute and its value amount to.
     *
     * @return array<string, mixed>|null
     */
    private static function propertyFor(string $attribute, mixed $value): ?array
    {
        $property = self::ATTRIBUTES[$attribute] ?? null;

        if ($property === null) {
            return null;
        }

        return match ($property) {
            'color', 'bgColor' => [$property => (string) $value],
            'size' => [$property => (float) $value],
            'name' => [$property => $value],
            default => self::flagFor($property, $value),
        };
    }

    /**
     * The font property a boolean attribute and its value amount to.
     *
     * @return array<string, mixed>|null Null when the value is not a boolean.
     */
    private static function flagFor(string $property, mixed $value): ?array
    {
        $flag = self::boolean($value);

        if ($flag === null) {
            return null;
        }

        // `w:u` takes one of Word's underline names, and `single` is the one a
        // boolean means.
        $underline = $flag ? 'single' : false;

        return [$property => $property === 'underline' ? $underline : $flag];
    }

    /**
     * A boolean, or null when the value is not one.
     *
     * `true` and `false`, and nothing else: the vocabulary is the one the inline
     * style attributes use, and a half is better than a spelling nobody agreed on.
     */
    private static function boolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return match (strtolower($value)) {
                'true' => true,
                'false' => false,
                default => null,
            };
        }

        return null;
    }
}
