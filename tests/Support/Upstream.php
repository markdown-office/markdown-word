<?php

declare(strict_types=1);

namespace MarkdownWord\Tests\Support;

/**
 * Hides one known defect in a dependency, from a test run.
 *
 * PHPWord 1.4.0 reads its style registry with a null array offset while writing
 * `word/styles.xml` — once per paragraph style it emits, whatever the document
 * holds:
 *
 * ```php
 * if (isset(self::$styles[$styleName])) {   // $styleName is null
 * ```
 *
 * `Style\Paragraph::writeNumbering()` makes that call one line before it tests
 * `$numStyle !== null`, so no document this library writes can avoid it. Left
 * reported it marks every document-writing test as deprecated, which buries
 * anything real; exactly that message from exactly that file is swallowed rather
 * than deprecation reporting being switched off.
 *
 * The rule lives here rather than in the library because the library does not
 * deserve it: a caller embedding this package in an application is better served
 * by seeing every diagnostic, and this is a property of a program that chose to
 * print a document rather than of a converter it did not. The command line
 * filters the same message for the same reason and keeps its own copy, in
 * `mdoffice`; a filter per program is the shape, and there is nothing here for a
 * second copy of the rule in this repository to drift away from.
 *
 * `install()` runs for the duration of a test because a write need not go
 * through a wrapping helper, and the writer raises the diagnostic either way.
 */
final class Upstream
{
    private const NULL_OFFSET = 'Using null as an array offset';

    private const PHPWORD_STYLE = '/phpoffice/phpword/src/PhpWord/Style.php';

    private static bool $installed = false;

    /**
     * Put the filter in place. A second call is a no-op, so a test file can
     * install it and a helper can install it again without either noticing.
     */
    public static function install(): void
    {
        if (self::$installed) {
            return;
        }

        self::$installed = true;

        set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if ($severity === E_DEPRECATED && self::isKnown($message, $file)) {
                return true;
            }

            // False hands the diagnostic to PHP, which prints it and carries on —
            // exactly what would have happened without this filter.
            return false;
        });
    }

    /**
     * Take the filter off again, leaving the handler it replaced where it was.
     */
    public static function restore(): void
    {
        if (!self::$installed) {
            return;
        }

        self::$installed = false;
        restore_error_handler();
    }

    public static function quietly(callable $work): mixed
    {
        $installed = !self::$installed;
        self::install();

        try {
            return $work();
        } finally {
            if ($installed) {
                self::restore();
            }
        }
    }

    private static function isKnown(string $message, string $file): bool
    {
        return str_contains($message, self::NULL_OFFSET)
            && str_ends_with(str_replace('\\', '/', $file), self::PHPWORD_STYLE);
    }
}
