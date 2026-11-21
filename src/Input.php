<?php

declare(strict_types=1);

namespace MarkdownWord;

use MarkdownWord\Exception\UnreadableDocument;
use MarkdownWord\Exception\UnreadableFile;
use MarkdownWord\Filesystem\PathProbe;

/**
 * Where a conversion's input came from.
 *
 * A string naming a file that exists is read from it; anything else is the
 * content itself.
 *
 * The two formats cannot be told apart the same way, and the difference matters.
 * Any text is Markdown, so a string that is not a file is taken as the content
 * without complaint. A Word document is a zip archive beginning `PK\x03\x04`, so
 * a string that is neither a file nor an archive is an error rather than
 * something to be guessed at.
 */
final class Input
{
    /** The first four bytes of a zip archive, and therefore of a `.docx`. */
    public const DOCUMENT_MAGIC = "PK\x03\x04";

    /**
     * @throws UnreadableFile when the string names a file that cannot be read.
     */
    public static function markdown(string $input): string
    {
        return PathProbe::isFile($input) ? self::read($input) : $input;
    }

    /**
     * A file that exists but is not a document is reported by name, because
     * whoever passed it was talking about a file and would not expect to be told
     * about bytes.
     *
     * @throws UnreadableDocument when the input is neither a document nor the
     *         name of one.
     */
    public static function document(string $input): string
    {
        if (str_starts_with($input, self::DOCUMENT_MAGIC)) {
            return $input;
        }

        if (PathProbe::isFile($input)) {
            $bytes = self::read($input);

            if (!str_starts_with($bytes, self::DOCUMENT_MAGIC)) {
                throw new UnreadableDocument(sprintf(
                    '"%s" is not a Word document. A .docx is a zip archive, so its first four bytes are "PK".',
                    $input,
                ));
            }

            return $bytes;
        }

        throw new UnreadableDocument(
            'The input is neither a Word document nor the name of one. '
            . 'A .docx is a zip archive, so its first four bytes are "PK".',
        );
    }

    /**
     * The one definition of what a `.docx` looks like, so the command line and the
     * converters cannot come to disagree about it.
     */
    public static function looksLikeDocument(string $bytes): bool
    {
        return str_starts_with($bytes, self::DOCUMENT_MAGIC);
    }

    private static function read(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new UnreadableFile(sprintf('Unable to read "%s".', $path));
        }

        return $contents;
    }
}
