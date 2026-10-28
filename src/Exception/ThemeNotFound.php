<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * A document or a caller named a theme that is not there.
 *
 * Failing rather than falling back to the built-in `default` is the whole of the design:
 * a theme that silently did not apply is a document in the wrong colours with nothing in
 * the output to say so, which reads as a finished document. The message names every theme
 * that does resolve, because naming a different one is the whole of the fix.
 */
final class ThemeNotFound extends InvalidInput
{
    /**
     * @param list<string> $available every theme name that resolves, local and built-in
     */
    public static function named(string $name, array $available): self
    {
        return new self(sprintf(
            'There is no theme called "%s". Available themes: %s.',
            $name,
            $available === [] ? 'none' : implode(', ', $available),
        ));
    }

    /**
     * A path that names no theme: neither a directory holding one nor the manifest of one.
     *
     * The name is echoed because it is what the author wrote, not a path this machine
     * resolved — but a name may itself be a path, so the resolved form is not put in the
     * message. See {@see self::outside()} for why.
     *
     * The second sentence is there because the path may well be there: a file that is not
     * a manifest reads as "there is no theme directory named this", which says nothing
     * about the file the author wrote and a good deal about a directory they did not.
     */
    public static function noDirectory(string $name): self
    {
        return new self(sprintf(
            'There is no theme directory named "%s". A theme is a directory holding a theme.yml, or the theme.yml itself.',
            $name,
        ));
    }

    /**
     * A directory that exists but is not a theme.
     *
     * As with {@see self::noDirectory()}, the name is echoed as written and the resolved
     * path is not.
     */
    public static function noManifest(string $name): self
    {
        return new self(sprintf(
            '"%s" is not a theme: it has no %s.',
            $name,
            'theme.yml',
        ));
    }

    public static function aUrl(string $name): self
    {
        return new self(sprintf(
            '"%s" is a URL. A theme is on this machine, not something to fetch.',
            $name,
        ));
    }

    /**
     * A theme name resolved to somewhere this render may not read.
 *
 * The roots are named and the path is not. A `theme:` is written by the document, so the
 * path it resolved to is a path the document chose to ask about, and echoing its resolved
 * form — through a symlink, on a firmlink, wherever the platform puts it — answers a
 * question about this machine that the document had no business asking. The roots are the
 * opposite: they are this program's own, they are the same for every caller, and an author
 * who has to move a theme out of them is entitled to know what they are.
 *
 * @param list<string> $roots the directories a document-written path may resolve into
 */
    public static function outside(string $name, array $roots): self
    {
        return new self(sprintf(
            'The theme "%s" is outside the directories a document may name: %s.',
            $name,
            $roots === [] ? 'none' : implode(', ', $roots),
        ));
    }
}
