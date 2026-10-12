<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * A template that names no place for the content.
 *
 * A document rendered into a template has to land somewhere, and a content control is
 * how a template says where. A template without one does not mean "at the end" — a
 * letterhead whose closing line and signature block come last would then receive the
 * body above them, and the result is a document that looks finished and is not.
 *
 * An {@see InvalidInput}, because it is the template's author who can fix it by
 * inserting a content control, and the caller's mistake is naming this template rather
 * than one that has somewhere to put the content.
 */
final class NoContentControl extends InvalidInput
{
    /**
     * The controls the template does have, so naming a different one is the whole of
     * the fix — the same reason {@see ThemeNotFound::named()} lists the themes.
     *
     * @param list<string> $available
     */
    public function __construct(
        public readonly string $wanted,
        public readonly array $available = [],
    ) {
        parent::__construct(sprintf(
            'The template has no "%s" content control.%s',
            $wanted,
            $available === []
                ? ' A template says where the content goes with a rich-text content control,'
                    . ' named by its tag — the Developer tab in Word inserts one.'
                : ' It does have: ' . implode(', ', $available) . '.',
        ));
    }
}
