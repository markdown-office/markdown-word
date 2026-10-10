<?php

declare(strict_types=1);

namespace MarkdownWord\Configuration;

/**
 * What a value is allowed to be, and what to say when it is not.
 *
 * {@see Validator} decides which keys exist and which of them a given key is; this
 * decides what the value under one has to look like, and writes the sentence that
 * says so. The two are separate because the answer is the same whichever key asked
 * for it — `space` and `indentation` are both mappings of numbers, and `size` and
 * `imageMaxWidth` are both numbers above a floor — so the shapes are stated once
 * here rather than copied into every place a key is checked.
 *
 * Every method returns null when the value is usable, and the half of a sentence that
 * describes one that is not when it is not. The caller puts it after whatever it was
 * checking: {@see Validator::describeValue()} has the same shape for a reason.
 *
 * What a value may be is decided by what it is *used as*, never by what it looks
 * like. `123456` and `'123456'` are the same colour, `9` and `'9'` the same size, and
 * a caller who wrote a number without quotes wrote it the way YAML says they should.
 * Refusing one of the two is refusing the shorthand the format itself allows.
 */
final class ValueShape
{
    /**
     * The value as the reader wrote it, for a message that has to show it back.
     *
     * A scalar is quoted so a value of `no` cannot be read as the sentence, and a
     * block is described by its keys rather than reproduced: the point is to let
     * someone recognise what they wrote, not to echo it.
     */
    public static function show(mixed $value): string
    {
        if ($value === null) {
            return 'nothing';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_array($value)) {
            return self::showBlock($value);
        }

        return \is_scalar($value) ? '"' . (string) $value . '"' : get_debug_type($value);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function showBlock(array $value): string
    {
        if ($value === []) {
            return 'empty';
        }

        return \array_is_list($value)
            ? 'a list: [' . implode(', ', array_map(self::show(...), $value)) . ']'
            : 'a mapping of ' . implode(', ', array_map(strval(...), array_keys($value)));
    }

    /**
     * @param list<string> $allowed
     */
    public static function oneOf(mixed $given, array $allowed): ?string
    {
        if (\is_string($given) && \in_array($given, $allowed, true)) {
            return null;
        }

        sort($allowed);

        return 'It is one of: ' . implode(', ', $allowed) . '.';
    }

    public static function between(mixed $given, int $lowest, int $highest): ?string
    {
        if (\is_int($given) && $given >= $lowest && $given <= $highest) {
            return null;
        }

        return \sprintf('It is a whole number between %d and %d.', $lowest, $highest);
    }

    /**
     * A number, at or above `$lowest`.
     *
     * A numeric string is accepted because that is what an unquoted scalar is: a
     * colour written `123456` and a size written `9` are both numbers to whoever wrote
     * them, and refusing them would be refusing the shorthand YAML already allows.
     */
    public static function number(mixed $given, float $lowest): ?string
    {
        if ($lowest > 0.0 && $given === 0) {
            return 'It is a size in points, and 0 makes the text invisible in Word.';
        }

        if (\is_int($given) || \is_float($given) || (\is_string($given) && \is_numeric($given))) {
            return (float) $given >= $lowest ? null : \sprintf('It is a number of at least %s.', $lowest);
        }

        return 'It is a number.';
    }

    /**
     * A colour is six hexadecimal digits and nothing else.
     *
     * `#8B0000` is the CSS spelling and reaches `w:color` with the `#` still in it,
     * which Word ignores; `darkred` is not a colour there either. A YAML integer is
     * tested as its digits, so `123456` passes and `0xFF0000` — which YAML has
     * already turned into `16711680` — does not.
     */
    public static function colour(mixed $given): ?string
    {
        if ((\is_string($given) || \is_int($given)) && preg_match('/^[0-9A-Fa-f]{6}$/', (string) $given) === 1) {
            return null;
        }

        return 'It is six hexadecimal digits, as in 8B0000, with no leading # and no colour name.';
    }

    /**
     * A boolean, or one of the spellings YAML and people write for one.
     *
     * `(bool) "no"` is `true`, so a quoted `false` in a block is not a small mistake:
     * it turns an option off and writes the opposite into the document.
     */
    public static function boolean(mixed $given): ?string
    {
        if (\is_bool($given)) {
            return null;
        }

        if (\is_string($given) && \in_array(\strtolower($given), ['true', 'false', 'yes', 'no', 'on', 'off'], true)) {
            return null;
        }

        return 'It is true or false.';
    }

    /**
     * A mapping of numbers, as `space` and `indentation` are read.
     *
     * A scalar under one of them is dropped whole, so `space: 480` leaves the paragraph
     * with no space at all and the document looks as though nothing had been asked for.
     * A word inside one loses its own key only, which is a quieter failure still.
     */
    public static function mapping(mixed $given, string $written): ?string
    {
        $expected = \sprintf('It is a mapping of numbers, with %s beneath it.', $written);

        if (!\is_array($given) || \array_is_list($given)) {
            return $expected;
        }

        foreach ($given as $value) {
            if (!\is_int($value) && !\is_float($value) && !(\is_string($value) && \is_numeric($value))) {
                return \sprintf('%s is not a number, and it goes under %s.', self::show($value), $written);
            }
        }

        return null;
    }

    /**
     * A mapping whose values are colours, as `shading` is read.
     *
     * A scalar under it is dropped whole, so `shading: F2F2F2` leaves the paragraph with
     * no background and the document looks as though nothing had been asked for.
     */
    public static function colourMap(mixed $given, string $written): ?string
    {
        return \is_array($given) && !\array_is_list($given)
            ? null
            : \sprintf('It is a mapping, with %s beneath it.', $written);
    }

    /**
     * Every string constant PHPWord holds for a property, sorted.
     *
     * Read off the class rather than copied out, so the list in the message cannot
     * fall behind the one in the writer: `w:jc` and `w:u` are closed sets, and
     * anything outside one is written into the document and ignored by Word.
     *
     * Asked for as the public constants, because that is what the set is made of. A
     * private constant in an upstream class is not something this library may offer a
     * caller as an accepted value, and `getConstants()` with no filter hands those
     * over along with the rest.
     *
     * @param class-string $class
     * @return list<string>
     */
    public static function wordValues(string $class, string $prefix = ''): array
    {
        $values = [];

        foreach ((new \ReflectionClass($class))->getConstants(\ReflectionClassConstant::IS_PUBLIC) as $name => $value) {
            if (\is_string($value) && ($prefix === '' || str_starts_with((string) $name, $prefix))) {
                $values[] = $value;
            }
        }

        sort($values);

        return $values;
    }
}
