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
     * both has two styles a document cannot tell apart, and the one a document reaches
     * is the one that happens to be written last.
     */
    public static function duplicateStyle(string $style, string $other): self
    {
        return new self(sprintf(
            'The style "%s" is the same style as "%s": a style\'s name is a word, and a theme names one style once.',
            $style,
            $other,
        ));
    }
}