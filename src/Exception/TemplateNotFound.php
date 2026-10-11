<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * The caller named a template that is not there, and naming a different one is
 * the whole of the fix — which is what makes this an {@see InvalidInput}. A
 * template that is fine failing for a different reason is the machine's, and
 * that is a {@see FileNotWritable}.
 *
 * A document that names its own template through `template_file:` is a document
 * writing a path, and these messages hold to the same rule as
 * {@see ThemeNotFound}'s: the name is echoed as written and the path it resolved
 * to is not, because the resolved form is a path the document chose to ask about
 * and echoing it answers a question about this machine it had no business asking.
 */
final class TemplateNotFound extends InvalidInput
{
    public static function aUrl(string $written): self
    {
        return new self(sprintf(
            'The template "%s" is a URL. A template is a file on this machine, not something to fetch.',
            $written,
        ));
    }

    /**
     * @param list<string> $searched the directories a relative name was looked for in,
     *        for a failure that has to say where it went looking
     */
    public static function aMissingFile(string $written, array $searched): self
    {
        return new self(sprintf(
            'The template "%s" does not exist.%s',
            $written,
            $searched === []
                ? ' It is relative and the Markdown came from standard input, so there is no file beside it to look next to.'
                : ' It was looked for beside the Markdown, in ' . implode(', ', $searched) . '.',
        ));
    }

    public static function aRelativeNameWithNoDirectory(string $written): self
    {
        return new self(sprintf(
            'The template "%s" is relative, and the Markdown came from standard input, so there is nothing to resolve it against. '
            . 'Name an absolute path, or write the template into the file itself with --template.',
            $written,
        ));
    }

    /**
     * @param list<string> $roots the directories a document-written path may resolve into
     */
    public static function aRefusedPath(string $written, array $roots): self
    {
        return new self(sprintf(
            'The template "%s" is outside the directories a document may name: %s. '
            . 'A template_file: is written by the document, so where it is written from says nothing about it; what is allowed is where it lands.',
            $written,
            $roots === [] ? 'none' : implode(', ', $roots),
        ));
    }
}