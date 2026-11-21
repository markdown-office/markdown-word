<?php

declare(strict_types=1);

namespace MarkdownWord\Filesystem;

/**
 * The questions this library asks the file system about a path.
 *
 * Every one of them has the same shape: a name in, an answer out, and never an
 * exception for a name the file system cannot answer about. A name this library
 * cannot read is answered like any other name that is not there — the library
 * renders a document rather than refusing it, and a name that walks out of its
 * base, a name that is a URL and a name holding a null byte are all names, and all
 * three are answered.
 */
final class PathProbe
{
    /** Whether a path names a file. */
    public static function isFile(string $path): bool
    {
        return self::answerable($path) && is_file($path);
    }

    /** Whether a path names a directory. */
    public static function isDirectory(string $path): bool
    {
        return self::answerable($path) && is_dir($path);
    }

    /** Whether a path names anything at all. */
    public static function exists(string $path): bool
    {
        return self::answerable($path) && file_exists($path);
    }

    /** Whether a path names a link. */
    public static function isLink(string $path): bool
    {
        return self::answerable($path) && is_link($path);
    }

    /** Whether a path names something that can be read. */
    public static function isReadable(string $path): bool
    {
        return self::answerable($path) && is_readable($path);
    }

    /**
     * Whether the file system can be asked about a path at all.
     *
     * PHP 8.6 raises a `ValueError` from `is_file()` and its neighbours for a path
     * holding a null byte, where earlier versions answered false. A null byte is
     * not a name the file system can answer about — the name ends there, so there
     * is nothing behind it to answer about — and this library answers a name it
     * cannot answer rather than raising.
     */
    private static function answerable(string $path): bool
    {
        return !str_contains($path, "\0");
    }
}
