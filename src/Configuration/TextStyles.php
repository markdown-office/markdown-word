<?php

declare(strict_types=1);

namespace MarkdownWord\Configuration;

use MarkdownWord\Exception\InvalidConfiguration;
use MarkdownWord\Exception\InvalidConfigurationValue;
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
 * and `:Brand[…]` is the same word to a reader as `:brand[…]`.
 *
 * A style that styles nothing, a style whose properties name an attribute that does
 * not exist and a style called `style` are refused here rather than in the renderer:
 * a theme is read once, and a mistake in it is worth saying so about before a byte
 * of the document is built.
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

    /** @var array<string, array<string, mixed>> */
    private array $styles;

    /**
     * @param array<string, array<string, mixed>> $styles
     *
     * @throws InvalidConfiguration when a style is not one that can be drawn
     */
    public function __construct(array $styles = [])
    {
        $this->styles = self::validated($styles);
    }

    /**
     * Whether a style of that name exists.
     */
    public function has(string $name): bool
    {
        return isset($this->styles[strtolower($name)]);
    }

    /**
     * The properties a style sets, or null when there is no style of that name.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        return $this->styles[strtolower($name)] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->styles);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        return $this->styles;
    }

    /**
     * Styles merged over the ones already here, a style's properties among them.
     *
     * @param array<string, array<string, mixed>> $styles
     */
    public function withAll(array $styles): self
    {
        return new self(array_replace_recursive($this->styles, $styles));
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
     * @param array<string, mixed> $styles
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws InvalidConfiguration
     */
    private static function validated(array $styles): array
    {
        $validated = [];

        foreach ($styles as $name => $properties) {
            $name = (string) $name;

            if (strtolower($name) === self::RESERVED) {
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

            $validated[strtolower($name)] = $properties;
        }

        return $validated;
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

        if ($property === 'color' || $property === 'bgColor') {
            return [$property => (string) $value];
        }

        if ($property === 'size') {
            return [$property => (float) $value];
        }

        if ($property === 'name') {
            return [$property => $value];
        }

        $flag = self::boolean($value);

        if ($flag === null) {
            return null;
        }

        // `w:u` takes one of Word's underline names, and `single` is the one a
        // boolean means.
        return [$property => $property === 'underline' ? ($flag ? 'single' : false) : $flag];
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
