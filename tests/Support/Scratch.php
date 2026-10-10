<?php

declare(strict_types=1);

namespace MarkdownWord\Tests\Support;

use RuntimeException;

/**
 * A place for the files a test creates, and their removal.
 *
 * Everything lands in `tmp/pest` inside the project, never in the system temp
 * directory, so a run writes nothing outside the repository; the directory is
 * ignored by git.
 *
 * The subdirectory is the whole point of it. `tmp` is shared: the checks at the
 * root of the repository write `tmp/readme`, `tmp/smoke` and `tmp/stress` into
 * it, and `composer check` runs them in the same tree as the suite. A test run
 * that emptied `tmp` would delete their output as it went — which is what used
 * to happen, and why a `composer check` in one terminal could lose the files a
 * `pest` run in another had just written. Each side now cleans up after itself
 * and nothing cleans up after anybody else.
 */
final class Scratch
{
    private const SUBDIRECTORY = 'pest';

    private static array $paths = [];

    public static function directory(): string
    {
        $directory = dirname(__DIR__, 2) . '/tmp/' . self::SUBDIRECTORY;

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create the scratch directory "%s".', $directory));
        }

        return $directory;
    }

    /**
     * A path no other test is using.
     */
    public static function path(string $prefix = 'docx', string $extension = '.docx'): string
    {
        $path = self::directory() . '/' . $prefix . '-' . bin2hex(random_bytes(6)) . $extension;

        self::$paths[$path] = true;

        return $path;
    }

    public static function remember(string $path): string
    {
        self::$paths[$path] = true;

        return $path;
    }

    /**
     * Empty the scratch directory.
     *
     * The whole of `tmp/pest` rather than only the paths that were remembered,
     * because a test cannot know every file it caused to be written: a converter
     * that names its output after its input creates a path nobody asked for, and
     * a media directory appears out of nowhere. A run that leaked those would
     * fill the disk quietly, which is worse than losing a scratch file after a
     * failure — the names are random either way, so there is little to look at.
     */
    public static function cleanUp(): void
    {
        self::$paths = [];

        $entries = scandir(self::directory());

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = self::directory() . '/' . $entry;

            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
    }

    private static function removeTree(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;

            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }

        @rmdir($directory);
    }

    public static function image(string $name = 'fixture.png'): string
    {
        $path = self::remember(self::directory() . '/' . $name);

        if (is_file($path)) {
            return $path;
        }

        $size = 24;
        $image = imagecreatetruecolor($size, $size);
        $background = imagecolorallocate($image, 0x8B, 0x1A, 0x1A);
        $ink = imagecolorallocate($image, 0xFF, 0xF3, 0xF3);

        imagefilledrectangle($image, 0, 0, $size, $size, $background);
        imagerectangle($image, 1, 1, $size - 2, $size - 2, $ink);
        imagepng($image, $path);

        return $path;
    }

    /**
     * A WebP fixture, skipped where GD was built without the format.
     *
     * There is no way to make this one on a machine that cannot: it exists to prove
     * the conversion path, and a build without WebP has no WebP to prove it with.
     */
    public static function webp(string $name = 'fixture.webp'): ?string
    {
        if (!function_exists('imagewebp') || (imagetypes() & IMG_WEBP) === 0) {
            return null;
        }

        $path = self::remember(self::directory() . '/' . $name);

        if (is_file($path)) {
            return $path;
        }

        $size = 24;
        $image = imagecreatetruecolor($size, $size);
        $background = imagecolorallocate($image, 0x1B, 0x5E, 0x20);
        $ink = imagecolorallocate($image, 0xFF, 0xFF, 0xFF);

        imagefilledrectangle($image, 0, 0, $size, $size, $background);
        imagefilledellipse($image, $size / 2, $size / 2, 14, 14, $ink);
        imagewebp($image, $path);

        return $path;
    }
}
