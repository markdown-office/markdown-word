<?php

declare(strict_types=1);

namespace MarkdownWord\Theme;

use MarkdownWord\Configuration\Styles;
use MarkdownWord\Configuration\TextStyles;
use MarkdownWord\Configuration\Validator;
use MarkdownWord\Exception\Exception;
use MarkdownWord\Exception\MalformedTheme;
use MarkdownWord\Exception\ThemeNotFound;
use MarkdownWord\Exception\UnknownStyleSlot;
use MarkdownWord\Files\FileFinder;
use MarkdownWord\Filesystem\PathScope;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a theme out of a finder: the `theme.yml` and the `styles.yml` beside it.
 *
 * Only `theme.yml` is required. `styles.yml` is read when it is there; a theme without
 * one draws in the built-in look, which is a theme rather than a broken one.
 *
 * The finder is asked by name — `house/theme.yml`, `house/styles.yml` — because the
 * library composes names and a finder answers them. A theme that never had a
 * directory is read the same way as one that did.
 *
 * Every slot is checked against {@see Styles::defaults()} — the list
 * {@see \MarkdownWord\Configuration\Validator} checks a `styles:` block against — so a
 * typo in a theme fails here rather than reaching a document that comes out looking as
 * though the line had never been written.
 *
 * The `textStyles` key beside them is not a slot but a set of named styles, and it is
 * checked by {@see \MarkdownWord\Configuration\TextStyles}: a style that is not a set
 * of properties, one that styles nothing, one that names an attribute that does not
 * exist, one whose value is not what the attribute takes, one called `style` and two
 * names for one style are all refused here.
 *
 * Each of the theme's own files is resolved and held to the scope the loader was given
 * before it is read, and the scope is asked of the file rather than of the directory it
 * was found in: a `styles.yml` that is a symlink pointing out of the scope is read just
 * as readily as one inside it, and its values reach the document as direct formatting,
 * which is a way to put a file outside the roots into the output.
 */
final class ThemeLoader
{
    public const THEME_FILE = 'theme.yml';
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
     * @throws MalformedTheme  when a file in it does not describe a theme
     * @throws UnknownStyleSlot when `styles.yml` names a slot that does not exist
     * @throws Exception      when a file in it cannot be read or parsed, or resolves out
     *                        of the scope
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

        $read = $this->styles($files, $name, $written);

        return $this->theme($manifest, $read);
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
     * @throws UnknownStyleSlot when `styles.yml` names a slot that does not exist
     * @throws Exception      when a file in it cannot be read or parsed, or resolves out
     *                        of the scope
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

        $read = $this->styles($files, $name, $written);

        return $this->theme($manifest, $read);
    }

    /**
     * The theme a manifest and a styles file make.
     *
     * The directory is the one the manifest is in, which is the theme's own directory:
     * the styles beside it are read from there, and a manifest reached through a symlink
     * sits where its target is.
     *
     * @param array{contents: string, path: string} $manifest
     * @param array{slots: array<string, mixed>, text: array<string, array<string, mixed>>} $read
     *
     * @throws MalformedTheme
     * @throws Exception
     */
    private function theme(array $manifest, array $read): LoadedTheme
    {
        return new LoadedTheme(
            dirname($manifest['path']),
            Theme::fromArray($this->mapping($manifest, 'a theme'), $manifest['path']),
            new Styles($read['slots']),
            new TextStyles($read['text']),
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
     * The slots and the text styles a theme's `styles.yml` carries.
     *
     * Checked twice, on purpose. The slot *names* are checked here so an unknown one can
     * be reported as {@see UnknownStyleSlot} next to the file that has it — the type a
     * caller is already catching. Everything inside them — property names, value shapes,
     * a `color:` written with a leading `#`, a `size:` holding a word — goes through
     * {@see \MarkdownWord\Configuration\Validator}, which is the same check a `styles:`
     * block gets. Without it a theme is the one place a typo can reach PHPWord as if it
     * were a value, and it arrives in a document with nothing to say it was never read.
     *
     * The text styles are a key of their own rather than a slot: `textStyles` is not a
     * style of the document but the named styles a document may write a word in, and
     * the loader would otherwise refuse it as a slot that does not exist. They are
     * checked by {@see \MarkdownWord\Configuration\TextStyles}, which knows the eight
     * attributes they may carry.
     *
     * @return array{slots: array<string, mixed>, text: array<string, array<string, mixed>>}
     *
     * @throws UnknownStyleSlot
     * @throws MalformedTheme
     * @throws Exception
     */
    private function styles(?FileFinder $files, string $theme, string $written): array
    {
        $file = $this->file($files, $theme, self::STYLES_FILE, $written);

        if ($file === null) {
            return ['slots' => [], 'text' => []];
        }

        $known = array_keys(Styles::defaults());
        $slots = [];
        $text = [];

        foreach ($this->mapping($file, 'a mapping of style slots to styles') as $slot => $style) {
            $slot = (string) $slot;

            if ($slot === TextStyles::KEY) {
                $text = self::normalise($style);

                if (!is_array($text)) {
                    throw MalformedTheme::notAMapping(
                        $file['path'],
                        'a mapping of style names to styles',
                        self::describe($text),
                    );
                }

                continue;
            }

            if (!in_array($slot, $known, true)) {
                throw UnknownStyleSlot::named($file['path'], $slot, $known);
            }

            $slots[$slot] = self::normalise($style);
        }

        // After `normalise()`, so a colour read as an integer is checked as the string it
        // is about to become rather than refused for being the wrong type.
        Validator::assertValid(['styles' => $slots]);

        return ['slots' => $slots, 'text' => $text];
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
            throw MalformedTheme::notAMapping($file['path'], $wanted, self::describe($parsed));
        }

        return $parsed;
    }

    /** What a YAML document turned out to be, in the words the failure uses. */
    private static function describe(mixed $parsed): string
    {
        return is_array($parsed) ? 'a list' : 'a ' . get_debug_type($parsed);
    }
}
