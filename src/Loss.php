<?php

declare(strict_types=1);

namespace MarkdownWord;

/**
 * Something a format's writer cannot carry, said in a sentence the reader of the
 * finished document can act on.
 *
 * A conversion is handed to somebody who did not write it, and a document that is
 * quietly missing its lists is worse than one that says it has none: the loss is
 * only visible by comparing the Markdown with the result. {@see MarkdownToWord::pendingLosses()}
 * collects these and the command line prints them, so the loss is announced at the
 * moment it happens rather than found later.
 */
final readonly class Loss implements \Stringable
{
    /**
     * @param string $feature The key {@see \MarkdownWord\Writer\Survey} answers for,
     *        which is also {@see Format::carries()}'s vocabulary.
     * @param string $message What the reader of the finished document has lost.
     */
    public function __construct(
        public string $feature,
        public string $message,
    ) {
    }

    public function __toString(): string
    {
        return $this->message;
    }
}