<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * A theme's own file does not describe a theme.
 *
 * The writer of a `theme.yml` is usually a person or a model, and both can act on what
 * is said here, so every failure names what would have worked instead, and the file it
 * came from where there is one — a document's own configuration brings named styles too,
 * and there is no file to name then. It is an {@see InvalidConfiguration} rather than a
 * parse error because the YAML is usually *valid* — it is the description of the theme
 * that is wrong, and the file that says so is the configuration.
 *
 * A file left beside a theme from an older format belongs here too: it is refused
 * rather than ignored, because a file that is quietly not read is a theme that looks
 * like the one its author wrote and is not.
 */
final class MalformedTheme extends InvalidConfiguration
{
    public static function notAMapping(string $file, string $wanted, string $given): self
    {
        return new self(sprintf('"%s" is %s. It has to be %s.', $file, $given, $wanted));
    }

    public static function missingKey(string $file, string $key): self
    {
        return new self(sprintf('"%s" has no "%s". Every theme has to name itself.', $file, $key));
    }

    public static function wrongType(string $file, string $key, string $wanted): self
    {
        return new self(sprintf('The "%s" in "%s" has to be %s.', $key, $file, $wanted));
    }

    /**
     * @param list<string> $known in the order a file should list them, which is why these
     *   are not sorted: {@see \MarkdownWord\Theme\Theme::KEYS} is the order of the keys a
     *   theme reads naturally, and the alternative names appear alphabetically elsewhere.
     */
    public static function unknownKey(string $file, string $key, array $known): self
    {
        return new self(sprintf(
            'Unknown key "%s" in "%s". A theme may have: %s.',
            $key,
            $file,
            implode(', ', $known),
        ));
    }

    /**
     * Two text styles whose names are the same word.
     *
     * The names are folded, so `Brand` and `brand` are one name: a theme that carries
     * both has two styles a document cannot tell apart.
     */
    public static function duplicateStyle(string $style, string $other): self
    {
        return new self(sprintf(
            'The style "%s" is the same style as "%s": a style\'s name is a word, and a theme names one style once.',
            $style,
            $other,
        ));
    }

    /**
     * A `styles.yml` beside a theme, from the format this library used to have.
     *
     * The file is not read, and a file that is quietly not read is a theme that looks
     * like the one its author wrote and is not — so it is refused, and the sentence
     * says where the content goes rather than only that the file is wrong.
     *
     * It says that the file has to be deleted as well, because the file is refused for
     * being there and not for what it holds: an author who moved the styles into the
     * `theme.yml` and left the file beside it is refused again, with the same sentence.
     * The author of a theme has two things to do about the file, and the message is the
     * only place that says both.
     */
    public static function stylesFileBeside(string $file): self
    {
        return new self(sprintf(
            '"%s" is not read any more. A theme\'s styles are in the "styles:" section of its "theme.yml", and its named text styles in the "textStyles:" section. Delete the file, and the theme is read again.',
            $file,
        ));
    }

    /** What a YAML document turned out to be, in the words the failure uses. */
    public static function describe(mixed $parsed): string
    {
        return is_array($parsed) ? 'a list' : 'a ' . get_debug_type($parsed);
    }
}
