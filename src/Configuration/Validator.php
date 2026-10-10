<?php

declare(strict_types=1);

namespace MarkdownWord\Configuration;

use MarkdownWord\Exception\InvalidConfiguration;
use MarkdownWord\Exception\InvalidConfigurationValue;
use MarkdownWord\Exception\UnknownConfigurationKey;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Cell;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\Row;
use PhpOffice\PhpWord\Style\Table;

/**
 * Reports the configuration that cannot be used: the keys that name nothing, and the
 * values that are not what they are used as.
 *
 * Four places take a key and quietly discard one they do not recognise: an unknown
 * option, an unknown style slot, an unknown font property inside a style, and an
 * unknown paragraph property inside one. Each leaves a document that renders fine and
 * is configured as though the key had never been written, which is the worst of the
 * available outcomes — nothing looks broken and the setting is simply missing.
 *
 * A value is the same class of problem and was the quieter half. `maxHeadingLevel:
 * deep` is cast to 0, clamped to 1, and the document comes out with every heading in
 * it rendered as body text; `space: {before: "lots"}` loses the `before` and keeps the
 * `after`; `color: "#8B0000"` reaches `w:color` with a `#` in it and Word ignores it.
 * None of those looks wrong, which is the whole reason they are reported.
 *
 * So every key and every value is checked, and every problem is reported rather than
 * the first, so a file with three mistakes takes one run to fix rather than three.
 *
 * ```php
 * Validator::assertValid(['options' => ['maxHeadingLevel' => 'deep']]);
 * ```
 *
 * This is raised where it is called rather than from `fromArray()` or `withAll()`.
 * Those are the documented behaviour of a value object and there are callers relying
 * on them: a config file may carry keys for something else, and a key dropped there
 * has been dropped for a decade. Frontmatter is new, is what a language model writes,
 * and is validated on the way in.
 *
 * A property nobody could plausibly get wrong is not checked: `name` is any font on
 * the machine and `orderedListFormat` is any of the OOXML numbering formats, and an
 * allow-list for either would be a list nobody could keep up to date.
 */
final class Validator
{
    /**
     * Every problem with a configuration array, in the order the keys were written.
     *
     * @param array<string, mixed> $config
     * @param callable(string): ?int|null $locate The file line a path sits on, for
     *        `options/images` or `styles/heading.1/color`. Null for a configuration
     *        built in code, where there is no file to point into.
     *
     * @return list<string>
     */
    public static function problems(array $config, ?callable $locate = null): array
    {
        return array_column(self::collected($config, $locate), 'message');
    }

    /**
     * @param array<string, mixed> $config
     * @param callable(string): ?int|null $locate
     * @param list<string> $alsoAboutValues Problems already gathered elsewhere and known to
     *        be about values rather than keys — a `---` block that is not a mapping at
     *        all, which {@see \MarkdownWord\Document\Frontmatter} knows about and this
     *        does not. They are reported with the rest, and they decide the type of the
     *        exception along with anything found here.
     *
     * @throws InvalidConfiguration when anything in the array cannot be used.
     */
    public static function assertValid(array $config, ?callable $locate = null, array $alsoAboutValues = []): void
    {
        $problems = [
            ...array_map(
                static fn (string $message): array => ['message' => $message, 'about' => 'value'],
                $alsoAboutValues,
            ),
            ...self::collected($config, $locate),
        ];

        if ($problems === []) {
            return;
        }

        $aboutValues = [];

        foreach ($problems as $problem) {
            $aboutValues[] = $problem['about'] === 'value';
        }

        $message = implode("\n", array_column($problems, 'message'));

        // `UnknownConfigurationKey` is what a caller has been catching since keys
        // were checked, so a batch that is only about keys keeps it. Both extend
        // `InvalidConfiguration`, which is what a caller who does not care should catch.
        throw \in_array(true, $aboutValues, true)
            ? new InvalidConfigurationValue($message)
            : new UnknownConfigurationKey($message);
    }

    /**
     * One problem, written so that it can be acted on without looking anything up.
     *
     * The near-miss is the useful part. `blockquote` for `blockQuote` is a mistake
     * anyone makes once and cannot see from the error alone, so a key close enough to
     * be a plausible typo of a real one is named as a suggestion rather than left among
     * twenty alternatives.
     *
     * The key is quoted and the rest is built around it, because the three kinds read
     * differently — `Unknown option "x"`, `Unknown style "x"`, `Unknown "x" property
     * of the "y" style` — and a single interpolated noun would have to give up on
     * one of them.
     *
     * @param string       $before  what to say before the quoted key
     * @param string       $after   what to say after it
     * @param list<string> $known   every key that would have been accepted
     * @param string       $plural  how the alternatives are introduced
     */
    public static function describe(
        string $key,
        string $before,
        string $after,
        array $known,
        string $plural,
    ): string {
        sort($known);

        $suggestion = self::closest($key, $known);

        return \sprintf(
            'Unknown %s"%s"%s.%s Known %s: %s.',
            $before,
            $key,
            $after,
            $suggestion === null ? '' : \sprintf(' Did you mean "%s"?', $suggestion),
            $plural,
            implode(', ', $known),
        );
    }

    /**
     * One value problem, in the shape {@see self::describe()} gives a key one.
     *
     * The sentence is split after the value so that the second half can be whatever
     * the mistake needs — "It is one of: embed, placeholder, skip." for a mode that
     * does not exist, and "`lots` is not a number, it goes under `before:`" for a
     * space with a word in it — rather than one clause bent to cover both.
     *
     * @param string $where what to say the value is, named as it reads in the document
     * @param string $given the value as it was written
     * @param string $rest  what a value that would have worked looks like
     */
    public static function describeValue(string $where, string $given, string $rest): string
    {
        return \sprintf('%s is %s. %s', $where, $given, $rest);
    }

    /**
     * The value as the reader wrote it, for a message that has to show it back.
     *
     * {@see ValueShape::show()} owns it; this is the name a caller has always had.
     */
    public static function show(mixed $value): string
    {
        return ValueShape::show($value);
    }

    /**
     * A problem with the line it is on in front of it, when there is one.
     *
     * The line is what turns "Unknown option" into something to go and fix: a block
     * is a list of keys with nothing to say which one is wrong, and there is rarely
     * more than one document in a run.
     */
    public static function locate(string $message, ?int $line): string
    {
        return $line === null ? $message : \sprintf('Line %d: %s', $line, $message);
    }

    /**
     * Every problem, with enough to tell which exception type describes the batch.
     *
     * @param array<string, mixed> $config
     * @param callable(string): ?int|null $locate
     *
     * @return list<array{message: string, about: string}>
     */
    private static function collected(array $config, ?callable $locate): array
    {
        $problems = [];

        foreach ($config as $section => $value) {
            if (!\is_array($value)) {
                continue;
            }

            // Two sections, two vocabularies, and no loop that has to ask which of the
            // two it is in. The one loop did, and it sent every key it recognised
            // through `optionValue()` whichever section the key was written in — so
            // `thematicBreak`, which is both an option and a style slot, had a style
            // under it checked against `border` or `text`, and a valid Word style name
            // written there was refused.
            $problems = [
                ...$problems,
                ...match ($section) {
                    'options' => self::optionKeys($value, $locate),
                    'styles' => self::styleKeys($value, $locate),
                    default => [],
                },
            ];
        }

        return $problems;
    }

    /**
     * The problems with the `options` section: its keys, and the values of those that
     * are among them.
     *
     * @param array<array-key, mixed> $options
     * @param callable(string): ?int|null $locate
     *
     * @return list<array{message: string, about: string}>
     */
    private static function optionKeys(array $options, ?callable $locate): array
    {
        $known = \array_keys((new Options())->toArray());
        $problems = [];

        foreach ($options as $key => $given) {
            $key = (string) $key;

            if (\in_array($key, $known, true)) {
                $problems = [...$problems, ...self::optionValue($key, $given, $locate)];

                continue;
            }

            $problems[] = [
                'message' => self::locate(self::describe(
                    $key,
                    'option ',
                    '',
                    $known,
                    'options',
                ), self::line($locate, 'options/' . $key)),
                'about' => 'key',
            ];
        }

        return $problems;
    }

    /**
     * The problems with the `styles` section: its slots, and the values of those that
     * are among them.
     *
     * A known slot contributes nothing here — what a slot's *definition* may be is
     * {@see self::styleValues()}'s question, and it asks it of every slot rather than
     * only of the recognised ones.
     *
     * @param array<array-key, mixed> $styles
     * @param callable(string): ?int|null $locate
     *
     * @return list<array{message: string, about: string}>
     */
    private static function styleKeys(array $styles, ?callable $locate): array
    {
        $known = \array_keys(Styles::defaults());
        $problems = [];

        foreach ($styles as $slot => $definition) {
            $slot = (string) $slot;

            if (\in_array($slot, $known, true)) {
                continue;
            }

            $problems[] = [
                'message' => self::locate(self::describe(
                    $slot,
                    'style ',
                    '',
                    $known,
                    'styles',
                ), self::line($locate, 'styles/' . $slot)),
                'about' => 'key',
            ];
        }

        return [...$problems, ...self::styleValues($styles, $locate)];
    }

    /**
     * @param array<array-key, mixed> $styles
     * @param callable(string): ?int|null $locate
     *
     * @return list<array{message: string, about: string}>
     */
    private static function styleValues(array $styles, ?callable $locate): array
    {
        $problems = [];

        foreach ($styles as $slot => $definition) {
            foreach (self::styleValue((string) $slot, $definition, $locate) ?? [] as $problem) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * The problems with one slot's definition, or null when there are none.
     *
     * A slot holds three things: a Word style name, a mapping of properties, or
     * nothing. A list is the fourth, and it is the shape a hand-written block falls
     * into by accident — `heading.1:` followed by `- size` — where every entry would
     * otherwise be reported as a property named "0".
     *
     * @param callable(string): ?int|null $locate
     * @return list<array{message: string, about: string}>
     */
    private static function styleValue(string $slot, mixed $definition, ?callable $locate): array
    {
        if ($definition === null || \is_string($definition)) {
            return [];
        }

        if (!\is_array($definition) || ($definition !== [] && \array_is_list($definition))) {
            return [[
                'message' => self::locate(\sprintf(
                    'The "%s" style is %s, which is neither the name of a style nor a set of properties. '
                    . 'A slot holds either a Word style name — Quote, Heading1 — or the properties '
                    . 'themselves, one per line beneath it.',
                    $slot,
                    self::show($definition),
                ), self::line($locate, 'styles/' . $slot)),
                'about' => 'value',
            ]];
        }

        $properties = self::propertiesOf($slot);
        $problems = [];

        foreach ($definition as $property => $given) {
            $property = (string) $property;
            $path = 'styles/' . $slot . '/' . $property;

            if (!\in_array($property, $properties, true)) {
                $problems[] = [
                    'message' => self::locate(self::describe(
                        $property,
                        '',
                        \sprintf(' property of the "%s" style', $slot),
                        $properties,
                        'style properties',
                    ), self::line($locate, $path)),
                    'about' => 'key',
                ];

                continue;
            }

            $rest = self::propertyValue($slot, $property, $given);

            if ($rest === null) {
                continue;
            }

            $problems[] = [
                'message' => self::valueMessage(
                    \sprintf('The "%s" property of the "%s" style', $property, $slot),
                    $given,
                    $rest,
                    self::line($locate, $path),
                ),
                'about' => 'value',
            ];
        }

        return $problems;
    }

    /**
     * The problems with one option's value, or none.
     *
     * Both ends of both ranges are checked, because the cast and the clamp that used
     * to absorb them turn a typo into a different document rather than into a smaller
     * number: `deep` becomes 0, and 0 is clamped to 1, which renders every heading in
     * the document as body text.
     *
     * @param callable(string): ?int|null $locate
     * @return list<array{message: string, about: string}>
     */
    private static function optionValue(string $key, mixed $given, ?callable $locate): array
    {
        $rest = match ($key) {
            'softBreak' => ValueShape::oneOf($given, [Options::SOFT_BREAK_SPACE, Options::SOFT_BREAK_LINE, Options::SOFT_BREAK_PARAGRAPH]),
            'hardBreak' => ValueShape::oneOf($given, [Options::BREAK_REMOVE, Options::BREAK_LINE, Options::BREAK_PARAGRAPH]),
            'html' => ValueShape::oneOf($given, [Options::HTML_STRIP, Options::HTML_PRESERVE, Options::HTML_DROP]),
            'images' => ValueShape::oneOf($given, [Options::IMAGE_EMBED, Options::IMAGE_PLACEHOLDER, Options::IMAGE_SKIP]),
            'orderedListSuffix' => ValueShape::oneOf($given, ['tab', 'space', 'nothing']),
            'thematicBreak' => ValueShape::oneOf($given, ['border', 'text']),
            'maxHeadingLevel' => ValueShape::between($given, 1, 6),
            'tableWidth' => ValueShape::between($given, 0, 5000),
            'imageMaxWidth' => ValueShape::number($given, 0.0),
            'tableBorders', 'tableHeaderBold', 'codeBlockShading', 'deferredHyperlinks' => ValueShape::boolean($given),
            'imageBasePath' => \is_string($given) ? null : 'It is a directory, written as a string or left out.',
            default => null,
        };

        if ($rest === null) {
            return [];
        }

        return [[
            'message' => self::valueMessage(
                \sprintf('The "%s" option', $key),
                $given,
                $rest,
                self::line($locate, 'options/' . $key),
            ),
            'about' => 'value',
        ]];
    }

    /**
     * The property names one slot's definition may use.
     *
     * A style slot is not one kind of thing. A heading and a body paragraph are split
     * between a `Font` and a `Paragraph`, which is what {@see Styles::FONT_KEYS} and
     * {@see Styles::PARAGRAPH_KEYS} are the names of; `table` is a `Table`,
     * `tableCell` a `Cell` and `tableHeaderRow` a `Row`, and each has names the other
     * two do not have. `shading` under a header row is the sharpest of them: it is in
     * the list a heading uses, and the row is handed a `RowStyle` with no
     * `setShading()` on it at all — written, accepted, dropped.
     *
     * The three sets are read off the classes rather than copied out, so a setter
     * PHPWord gains is one this accepts.
     *
     * @return list<string>
     */
    private static function propertiesOf(string $slot): array
    {
        return match ($slot) {
            Styles::TABLE => self::settersOf(Table::class),
            Styles::TABLE_HEADER_ROW => self::settersOf(Row::class),
            // `alignment` is not a cell property and is not a font one either: the
            // renderer reads it off the cell style and puts it on the paragraph in
            // the cell, which is the only way a Word cell can be aligned.
            Styles::TABLE_CELL => [...Styles::FONT_KEYS, 'alignment', ...self::settersOf(Cell::class)],
            default => [...Styles::FONT_KEYS, ...Styles::PARAGRAPH_KEYS],
        };
    }

    /**
     * Every property `setStyleByArray()` on a style would dispatch to.
     *
     * Only the ones the class declares. What it inherits is `AbstractElement`'s —
     * `setPhpWord`, `setRelationId`, `setChangeInfo` — which are how the writer
     * attaches the style to the document, not anything a style definition may say.
     *
     * @param class-string $class
     * @return list<string>
     */
    private static function settersOf(string $class): array
    {
        $names = [];

        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            if (str_starts_with($method->getName(), 'set')) {
                $names[] = lcfirst(substr($method->getName(), 3));
            }
        }

        return $names;
    }

    /**
     * The rest of the sentence for one style property whose value is unusable, or null
     * when nothing is.
     *
     * `$slot` is only there because a table property is not a font or a paragraph
     * one, and the answer for one of those is a different sentence.
     */
    private static function propertyValue(string $slot, string $property, mixed $given): ?string
    {
        if ($slot === Styles::TABLE) {
            return null;
        }

        return match ($property) {
            'name' => \is_string($given) ? null : 'It is a font name, written as a string.',
            'styleName' => \is_string($given) ? null : 'It is the name of a Word style, written as a string.',
            'size' => ValueShape::number($given, 0.001),
            'lineHeight' => ValueShape::number($given, 0.001),
            'color' => ValueShape::colour($given),
            'bold', 'italic', 'strikethrough', 'keepNext' => ValueShape::boolean($given),
            'underline' => \is_bool($given) ? null : ValueShape::oneOf($given, ValueShape::wordValues(Font::class, 'UNDERLINE_')),
            'alignment' => ValueShape::oneOf($given, ValueShape::wordValues(Jc::class)),
            'space' => ValueShape::mapping($given, 'before: and after:'),
            'indentation' => ValueShape::mapping($given, 'left:, right:, firstLine: or hanging:'),
            // Colours rather than numbers: `fill` is a hex triplet, and `space` and
            // `indentation` are the two that are measured.
            'shading' => ValueShape::colourMap($given, 'fill:'),
            default => null,
        };
    }

    private static function valueMessage(string $where, mixed $given, string $rest, ?int $line): string
    {
        return self::locate(
            InvalidConfigurationValue::for($where, ValueShape::show($given), $rest)->getMessage(),
            $line,
        );
    }

    private static function line(?callable $locate, string $path): ?int
    {
        return $locate === null ? null : $locate($path);
    }

    /**
     * The known key nearest to the one given, if it is close enough to be a typo.
     *
     * The threshold is two edits over a case-insensitive comparison, so `blockquote`
     * finds `blockQuote` and `maxheadinglevel` finds `maxHeadingLevel`, while a key
     * that is merely different stays unprompted — a suggestion that is wrong is worse
     * than none, because it sends the reader to the wrong place to look.
     *
     * @param list<string> $known
     */
    private static function closest(string $key, array $known): ?string
    {
        $needle = \strtolower($key);
        $best = null;
        $bestDistance = \PHP_INT_MAX;

        foreach ($known as $candidate) {
            $distance = self::distance($needle, \strtolower($candidate));

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        return $best !== null && $bestDistance <= \max(1, \intdiv(\strlen($needle), 3)) ? $best : null;
    }

    /**
     * Levenshtein distance, computed row by row so the two words in memory stay short
     * rather than one matrix the length of both squared.
     */
    private static function distance(string $a, string $b): int
    {
        $previous = \range(0, \strlen($b));

        for ($i = 1; $i <= \strlen($a); $i++) {
            $current = [$i];

            for ($j = 1; $j <= \strlen($b); $j++) {
                $current[$j] = min(
                    $previous[$j] + 1,
                    $current[$j - 1] + 1,
                    $previous[$j - 1] + ($a[$i - 1] === $b[$j - 1] ? 0 : 1),
                );
            }

            $previous = $current;
        }

        return $previous[\strlen($b)];
    }
}
