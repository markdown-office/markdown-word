<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use MarkdownWord\Exception\FileNotWritable;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * The staging half of writing a document, shared by every format.
 *
 * A document is finished in the system temp directory and moved into place in one
 * step, so a conversion that fails leaves nothing where someone will open it, and
 * a document in flight through a shared temporary directory is never readable by
 * every account on the machine. That guarantee is a property of the *move*, not of
 * any one writer, which is why it lives here rather than in the DOCX path alone.
 *
 * The format's own passes — {@see HyperlinkPass} and the rest — run on the staged
 * file between the write and the move, so a patch that throws is as harmless as a
 * writer that throws: the destination is never touched.
 */
final class Staging
{
    /** How many hops {@see self::followLink()} follows before it gives up on a cycle. */
    private const MAX_LINKS = 10;

    /**
     * Write the document to a file, and hand back what was written.
     *
     * The bytes are read before the move, so a caller wanting both the file and
     * the content gets the content of the file that landed.
     *
     * @param callable(string):void $patch Applied to the staged file, in place.
     *
     * @throws FileNotWritable When the document cannot be staged, read, or put in
     *         place: no temporary file to be had, a directory that cannot be made,
     *         or a destination the process cannot write to.
     */
    public static function write(PhpWord $phpWord, string $writer, string $path, ?callable $patch = null): string
    {
        $temp = self::stage($phpWord, $writer, $patch);

        try {
            $contents = self::read($temp);

            self::move($temp, $path);

            return $contents;
        } finally {
            // `move()` has already renamed the archive away on the happy path, so
            // this only runs when something went wrong: a failed conversion should
            // not leave a whole document behind in the temporary directory.
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Write the document and return it as a string, without touching the disk
     * beyond the staging file.
     *
     * @param callable(string):void $patch Applied to the staged file, in place.
     *
     * @throws FileNotWritable When the document cannot be staged or read.
     */
    public static function toString(PhpWord $phpWord, string $writer, ?callable $patch = null): string
    {
        $temp = self::stage($phpWord, $writer, $patch);

        try {
            return self::read($temp);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Write the document to a temporary file and hand its path back.
     *
     * @param callable(string):void $patch
     * @return string The path of the finished document. It belongs to the caller,
     *         which must unlink it unless it moves it into place.
     * @throws FileNotWritable When no temporary file can be made.
     */
    private static function stage(PhpWord $phpWord, string $writer, ?callable $patch): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mdword_');

        if ($path === false) {
            throw new FileNotWritable('Unable to create a temporary file.');
        }

        OutputEscaping::enabled(static function () use ($phpWord, $path, $writer): void {
            IOFactory::createWriter($phpWord, $writer)->save($path);
        });

        // `save()` unlinks the 0600 file `tempnam()` made and writes its own at
        // the process umask, usually 0644: a document in flight through a shared
        // temporary directory is readable by every account on the machine, and
        // this is the only moment its permissions can be narrowed.
        @chmod($path, 0o600);

        if ($patch !== null) {
            $patch($path);
        }

        return $path;
    }

    /**
     * @throws FileNotWritable When the staged file cannot be read.
     */
    private static function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new FileNotWritable('Unable to read the generated document.');
        }

        return $contents;
    }

    /**
     * Two things about the destination are worth the trouble.
     *
     *  - It may be a symlink, and `rename()` replaces a link with a regular file,
     *    so writing through `report.docx -> published/report.docx` would leave
     *    the link gone and the file it named holding its old contents. The link
     *    is followed instead.
     *  - It may be on another filesystem, which `rename()` cannot cross however
     *    the permissions stand — what a container with the output on a mounted
     *    volume gives, a destination that is perfectly writable and refused all
     *    the same. Copying is the fallback, and it creates the destination at
     *    the umask rather than carrying over the staged file's mode.
     *
     * @throws FileNotWritable When the destination cannot be made or written.
     */
    private static function move(string $from, string $to): void
    {
        $to = self::followLink($to);
        $directory = dirname($to);

        // A path given to a converter rarely has its directory made for it, and
        // `rename()` does not create one.
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new FileNotWritable(sprintf('Unable to create the directory "%s".', $directory));
        }

        if (@rename($from, $to)) {
            return;
        }

        $existed = file_exists($to);

        if (@copy($from, $to)) {
            @unlink($from);

            return;
        }

        // A copy that fails part way through leaves the destination half a
        // document, which is what staging it was there to prevent. Only a file
        // that was not there before is removed: one the caller had has been
        // truncated by `copy()` either way, so it cannot be taken back.
        if (!$existed) {
            @unlink($to);
        }

        throw new FileNotWritable(sprintf('Unable to write the document to "%s".', $to));
    }

    /**
     * A link is followed even when what it points at is not there yet, since
     * that is how a deployment says where a document goes; a chain is followed
     * to its end.
     */
    private static function followLink(string $path): string
    {
        for ($hop = 0; $hop < self::MAX_LINKS; $hop++) {
            $target = @readlink($path);

            if ($target === false) {
                return $path;
            }

            $path = self::isAbsolute($target) ? $target : dirname($path) . '/' . $target;
        }

        return $path;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
    }
}