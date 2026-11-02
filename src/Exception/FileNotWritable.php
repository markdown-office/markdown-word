<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * The input may have been perfectly good: a full disk, a directory that cannot
 * be made, a path the process cannot write to. All of it is this side rather
 * than the caller's mistake, which is why this is not an {@see InvalidInput}.
 */
final class FileNotWritable extends Exception
{
    /**
     * A path that names something a document cannot be written to.
     *
     * A device, a directory or a named pipe is not a file, and the writer opens whatever it
     * is given — which is how writing a document to `/dev/null` reported a document written
     * rather than a sentence that says what was wrong.
     */
    public static function notAFile(string $path): self
    {
        return new self(sprintf(
            'The path "%s" is not a regular file, so nothing can be written to it. A document is written to a file.',
            $path,
        ));
    }
}
