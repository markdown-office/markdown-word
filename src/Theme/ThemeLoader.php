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
use MarkdownWord\Filesystem\PathScope;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a theme out of a directory, or out of the `theme.yml` inside one.
 *
 * Only `theme.yml` is required. `styles.yml` is read when it is there; a theme without
 * one draws in the built-in look, which is a theme rather than a broken one.
 *
 * A theme is a `theme.yml` and the `styles.yml` beside it. The two are usually held in a
 * directory of their own, and a document may name either the directory or the manifest
 * inside it: the manifest is the theme, and the directory around it is where the rest of
 * it is looked for.
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
 * Each of the two files is resolved and held to the caller's
 * {@see \MarkdownWord\Filesystem\PathScope} before it is read. Checking the directory is
 * not enough: a `styles.yml` that is a symlink pointing out of the scope is read just as
 * readily as one inside it, and its values reach the document as direct formatting, which
 * is a way to put a file outside the roots into the output.
 */
final class ThemeLoader
{
    public const THEME_FILE = 'theme.yml';
    public const STYLES_FILE = 'styles.yml';

    /**
     * @param PathScope|null $files The directories a theme's own files may resolve into.
     *        Null takes the files inside a resolved directory on trust, which is what a
     *        theme this library ships is.
     *
     * @throws ThemeNotFound  when the directory has no `theme.yml`, so it is not a theme
     * @throws MalformedTheme  when a file in it does not describe a theme
     * @throws UnknownStyleSlot when `styles.yml` names a slot that does not exist
     * @throws Exception      when a file in it cannot be read or parsed, or resolves out
     *                        of the scope
     */
    public function load(string $directory, ?string $name = null, ?PathScope $files = null): LoadedTheme
    {
        $written = $name ?? $directory;
        $manifest = $this->contained($files, $directory . '/' . self::THEME_FILE, $written);

        if (!is_file($manifest)) {
            // What the author wrote, not where it landed — see
            // {@see \MarkdownWord\Exception\ThemeNotFound::outside()}.
            throw ThemeNotFound::noManifest($written);
        }

        $read = $this->styles($directory, $files, $written);

        return new LoadedTheme(
            $directory,
            Theme::fromArray($this->mapping($manifest, 'a theme'), $manifest),
            new Styles($read['slots']),
            new TextStyles($read['text']),
        );
    }

    /**
     * A theme whose manifest is this file, with the rest of it read from the directory
     * that holds the file.
     *
     * The file has to be a `theme.yml`. The name is what makes it the manifest of a theme
     * rather than one of the theme's other files: a `styles.yml` named as the theme is a
     * file the theme's own directory holds, not the theme.
     *
     * @param string      $file  the manifest
     * @param string|null $name  what the document wrote, which is what a failure names
     * @param PathScope|null $files The directories the theme's own files may resolve into.
     *
     * @throws ThemeNotFound  when the file is not there, or is not a `theme.yml` and so
     *                        is not a manifest
     * @throws MalformedTheme  when the file does not describe a theme
     * @throws UnknownStyleSlot when `styles.yml` names a slot that does not exist
     * @throws Exception      when a file in it cannot be read or parsed, or resolves out
     *                        of the scope
     */
    public function loadManifest(string $file, ?string $name = null, ?PathScope $files = null): LoadedTheme
    {
        $written = $name ?? $file;

        if (basename($written) !== self::THEME_FILE) {
            throw ThemeNotFound::noDirectory($written);
        }

        $manifest = $this->contained($files, $file, $written);

        if (!is_file($manifest)) {
            throw ThemeNotFound::noDirectory($written);
        }

        // The directory the manifest is in, rather than the one the document wrote: a
        // manifest reached through a symlink sits where its target is, and that is where
        // the `styles.yml` that belongs to it is.
        $directory = dirname($manifest);

        $read = $this->styles($directory, $files, $written);

        return new LoadedTheme(
            $directory,
            Theme::fromArray($this->mapping($manifest, 'a theme'), $manifest),
            new Styles($read['slots']),
            new TextStyles($read['text']),
        );
    }

    /**
     * A file of the theme, refused when it resolves outside the scope.
     *
     * The path is returned as it stands when there is nothing to check, so a theme with
     * no `styles.yml` costs no more than the one `file_exists()` call.
     *
     * @throws Exception when the file is there but is somewhere this render may not read
     */
    private function contained(?PathScope $files, string $path, string $name): string
    {
        if ($files === null || !file_exists($path)) {
            return $path;
        }

        $real = PathScope::resolve($path);

        if ($real === null || !$files->holds($real)) {
            throw new Exception(sprintf(
                'The theme "%s" has a "%s" that resolves outside the directories this render may read.',
                $name,
                basename($path),
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
    private function styles(string $directory, ?PathScope $files, string $name): array
    {
        $file = $this->contained($files, $directory . '/' . self::STYLES_FILE, $name);

        if (!is_file($file)) {
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
                        $file,
                        'a mapping of style names to styles',
                        self::describe($text),
                    );
                }

                continue;
            }

            if (!in_array($slot, $known, true)) {
                throw UnknownStyleSlot::named($file, $slot, $known);
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
     * @return array<array-key, mixed>
     *
     * @throws MalformedTheme
     * @throws Exception
     */
    private function mapping(string $file, string $wanted): array
    {
        if (!is_readable($file)) {
            throw new Exception(sprintf('Unable to read "%s".', $file));
        }

        try {
            $parsed = Yaml::parseFile($file);
        } catch (ParseException $exception) {
            throw new Exception(
                sprintf('"%s" is not valid YAML: %s', $file, $exception->getMessage()),
                previous: $exception,
            );
        }

        if ($parsed === null) {
            return [];
        }

        if (!is_array($parsed) || ($parsed !== [] && array_is_list($parsed))) {
            throw MalformedTheme::notAMapping($file, $wanted, self::describe($parsed));
        }

        return $parsed;
    }

    /** What a YAML document turned out to be, in the words the failure uses. */
    private static function describe(mixed $parsed): string
    {
        return is_array($parsed) ? 'a list' : 'a ' . get_debug_type($parsed);
    }
}