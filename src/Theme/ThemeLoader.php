<?php

declare(strict_types=1);

namespace MarkdownWord\Theme;

use MarkdownWord\Exception\Exception;
use MarkdownWord\Exception\MalformedTheme;
use MarkdownWord\Exception\ThemeNotFound;
use MarkdownWord\Exception\UnknownStyleSlot;
use MarkdownWord\Files\FileFinder;
use MarkdownWord\Filesystem\PathScope;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a theme out of a finder: the `theme.yml` that says everything about it.
 *
 * A theme is one file. Its identity and its look are sections of the same `theme.yml`,
 * so there is nothing beside it to read — and a `styles.yml` left over from the format
 * this library used to have is refused rather than ignored, because a file that is
 * quietly not read is a theme that looks like the one its author wrote and is not.
 *
 * The finder is asked by name — `house/theme.yml` — because the library composes names
 * and a finder answers them. A theme that never had a directory is read the same way as
 * one that did.
 *
 * The theme's own file is resolved and held to the scope the loader was given before
 * it is read, and the scope is asked of the file rather than of the directory it was
 * found in: a `theme.yml` that is a symlink pointing out of the scope is read just as
 * readily as one inside it, and its values reach the document as direct formatting,
 * which is a way to put a file outside the roots into the output.
 */
final class ThemeLoader
{
    public const THEME_FILE = 'theme.yml';

    /** The file this library used to read a theme's look from, and refuses now. */
    public const STYLES_FILE = 'styles.yml';

    /**
     * @param PathScope|null $scope The directories a theme's own files may resolve into.
     *        Null takes the files the finder answers on trust, which is what a theme
     *        this library ships is.
     */
    public function __construct(private readonly ?PathScope $scope = null)
    {
    }

    /**
     * A theme read by the name it is known by.
     *
     * @param string $name The theme's name, which the finder resolves the theme's files by.
     * @param string|null $written What the document wrote, which is what a failure names.
     * @param FileFinder|null $files Where the theme's files come from.
     *
     * @throws ThemeNotFound  when the theme has no `theme.yml`, so it is not a theme
     * @throws MalformedTheme  when the file does not describe a theme
     * @throws UnknownStyleSlot when the theme names a slot that does not exist
     * @throws Exception      when the file cannot be read or parsed, or resolves out of
     *                        the scope
     */
    public function load(string $name, ?string $written = null, ?FileFinder $files = null): LoadedTheme
    {
        $written ??= $name;

        $manifest = $this->file($files, $name, self::THEME_FILE, $written);

        if ($manifest === null) {
            // What the author wrote, not where it landed — see
            // {@see \MarkdownWord\Exception\ThemeNotFound::outside()}.
            throw ThemeNotFound::noManifest($written);
        }

        return $this->theme($manifest, $files, $name);
    }

    /**
     * A theme whose manifest is a file of its own, with the rest of it read from the
     * directory that holds the file.
     *
     * The file has to be a `theme.yml`. The name is what makes it the manifest of a theme
     * rather than one of the theme's other files: a `styles.yml` named as the theme is a
     * file the theme's own directory holds, not the theme.
     *
     * @param string $name The theme's name, which the finder resolves the manifest by.
     * @param string|null $written What the document wrote, which is what a failure names.
     * @param FileFinder|null $files Where the theme's files come from.
     *
     * @throws ThemeNotFound  when the file is not there, or is not a `theme.yml` and so
     *                        is not a manifest
     * @throws MalformedTheme  when the file does not describe a theme
     * @throws UnknownStyleSlot when the theme names a slot that does not exist
     * @throws Exception      when the file cannot be read or parsed, or resolves out of
     *                        the scope
     */
    public function loadManifest(string $name, ?string $written = null, ?FileFinder $files = null): LoadedTheme
    {
        $written ??= $name;

        if (basename($written) !== self::THEME_FILE) {
            throw ThemeNotFound::noDirectory($written);
        }

        $manifest = $this->file($files, $name, self::THEME_FILE, $written);

        if ($manifest === null) {
            throw ThemeNotFound::noDirectory($written);
        }

        return $this->theme($manifest, $files, $name);
    }

    /**
     * The theme a manifest describes.
     *
     * The directory is the one the manifest is in, which is the theme's own directory.
     * A manifest reached through a symlink sits where its target is.
     *
     * @param array{contents: string, path: string} $manifest
     *
     * @throws MalformedTheme
     * @throws UnknownStyleSlot
     * @throws Exception
     */
    private function theme(array $manifest, ?FileFinder $files, string $name): LoadedTheme
    {
        $this->refuseStylesFile($files, $name);

        return new LoadedTheme(
            dirname($manifest['path']),
            Theme::fromArray($this->mapping($manifest, 'a theme'), $manifest['path']),
        );
    }

    /**
     * One of the theme's own files, resolved and held to the scope.
     *
     * A finder with the bytes and no path is asked for them: the caller vouches for
     * its own files, and there is no path to hold to the scope. A loader with no
     * finder has no source at all, and the file is not there.
     *
     * @return array{contents: string, path: string}|null
     *
     * @throws Exception when the file resolves outside the directories this render may read
     */
    private function file(?FileFinder $files, string $theme, string $file, string $written): ?array
    {
        $name = $theme . '/' . $file;
        $path = $files?->path($name, 'theme');

        if ($path === null) {
            $contents = $files?->read($name, 'theme');

            return $contents === null ? null : ['contents' => $contents, 'path' => $name];
        }

        $real = $this->contained($path, $file, $written);

        if ($real === null) {
            return null;
        }

        // `is_readable()` first, so a file that cannot be read is reported as that
        // rather than as a warning the test runner turns into a failure of its own.
        $contents = is_readable($real) ? @file_get_contents($real) : false;

        if ($contents === false) {
            throw new Exception(sprintf('Unable to read "%s".', $real));
        }

        return ['contents' => $contents, 'path' => $real];
    }

    /**
     * A resolved path, held to the scope.
     *
     * The path is resolved before it is held, because a prefix is not containment:
     * `..` and a symlink both defeat one, and a symlink pointing outside the roots is
     * the ordinary way a path that looks harmless turns into a read of the wrong file.
     *
     * @return string|null The canonical path, or null when the file is not there after all.
     *
     * @throws Exception when the file is somewhere this render may not read
     */
    private function contained(string $path, string $file, string $written): ?string
    {
        $real = PathScope::resolve($path);

        if ($real === null) {
            return null;
        }

        if ($this->scope !== null && !$this->scope->holds($real)) {
            throw new Exception(sprintf(
                'The theme "%s" has a "%s" that resolves outside the directories this render may read.',
                $written,
                $file,
            ));
        }

        return $real;
    }

    /**
     * A `styles.yml` beside the theme, refused.
     *
     * The look is in the `styles:` section of the `theme.yml` beside it, and the file
     * beside that is not read: a theme that carried one is a theme whose styles have
     * gone missing, and the sentence that says where they went is the only thing the
     * author has to act on.
     *
     * Asked of the finder rather than of the file system, because the finder is where
     * the theme's files are — a source that holds the bytes and no path has it too.
     * The scope is never asked of it: the file is not read, and the finder is asked only
     * whether it is there.
     *
     * @throws MalformedTheme
     */
    private function refuseStylesFile(?FileFinder $files, string $theme): void
    {
        $name = $theme . '/' . self::STYLES_FILE;
        $path = $files?->path($name, 'theme');

        if ($path === null) {
            if ($files?->read($name, 'theme') === null) {
                return;
            }

            $path = $name;
        }

        throw MalformedTheme::stylesFileBeside($path);
    }

    /**
     * @param array{contents: string, path: string} $file
     *
     * @return array<array-key, mixed>
     *
     * @throws MalformedTheme
     * @throws Exception
     */
    private function mapping(array $file, string $wanted): array
    {
        try {
            $parsed = Yaml::parse($file['contents']);
        } catch (ParseException $exception) {
            throw new Exception(
                sprintf('"%s" is not valid YAML: %s', $file['path'], $exception->getMessage()),
                previous: $exception,
            );
        }

        if ($parsed === null) {
            return [];
        }

        if (!is_array($parsed) || ($parsed !== [] && array_is_list($parsed))) {
            throw MalformedTheme::notAMapping($file['path'], $wanted, MalformedTheme::describe($parsed));
        }

        return $parsed;
    }
}
