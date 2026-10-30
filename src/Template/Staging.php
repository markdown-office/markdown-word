<?php

declare(strict_types=1);

namespace MarkdownWord\Template;

use MarkdownWord\Exception\FileNotWritable;

/**
 * The templates this library has staged for a source that holds the bytes.
 *
 * A source that keeps its templates in a database hands over bytes and no file, and a
 * template is a package that PHPWord opens as a file, so the bytes are written into
 * one of the library's own.
 *
 * The file belongs to the conversion that uses it rather than to the resolver that
 * staged it — {@see TemplateFile} says why — and {@see MarkdownTemplate} gives it
 * back when it has written what it was asked to write. A file nobody converted waits
 * for the end of the run.
 */
final class Staging
{
    /** @var array<string, true> The staged files, by path. */
    private static array $staged = [];

    /** Whether the end of the run has been arranged for. */
    private static bool $arranged = false;

    /**
     * The bytes of a template, in a file of the library's own.
     *
     * @throws FileNotWritable when the file cannot be made or written
     */
    public static function stage(string $bytes, string $name): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mdword_tpl_');

        if ($path === false) {
            throw new FileNotWritable(sprintf(
                'Unable to create a temporary file for the template in "%s".',
                sys_get_temp_dir(),
            ));
        }

        // Written into the file `tempnam()` made rather than over it, so that the
        // mode it was given stands: a template in flight through a shared temporary
        // directory is not readable by every account on the machine.
        if (file_put_contents($path, $bytes) === false) {
            @unlink($path);

            throw new FileNotWritable(sprintf('Unable to stage the template "%s".', $name));
        }

        @chmod($path, 0o600);

        self::$staged[$path] = true;

        self::arrangeForTheEndOfTheRun();

        return $path;
    }

    /**
     * Give back a staged template.
     *
     * A path this library did not stage is left alone: the caller may have handed
     * over a file of their own.
     */
    public static function release(string $path): void
    {
        if (!isset(self::$staged[$path])) {
            return;
        }

        unset(self::$staged[$path]);

        @unlink($path);
    }

    /**
     * The end of the run, arranged once.
     *
     * A staged template that was never converted is nobody's to release, so the run
     * takes it away rather than leaving it in the temporary directory.
     */
    private static function arrangeForTheEndOfTheRun(): void
    {
        if (self::$arranged) {
            return;
        }

        self::$arranged = true;

        register_shutdown_function(static function (): void {
            foreach (array_keys(self::$staged) as $path) {
                @unlink($path);
            }

            self::$staged = [];
        });
    }
}
