<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Format;
use MarkdownWord\Render\LinkPayloadCollector;
use PhpOffice\PhpWord\PhpWord;

/**
 * Writes a `PhpWord` document to `.rtf`.
 *
 * RTF is not a zip, so none of the DOCX passes apply to it: there is no archive to
 * reopen and no part to replace. The one that does carry over is
 * {@see RtfHyperlinkPass}, and it works on the finished text rather than on a part
 * of a package — which is why the staging guarantee still needs spelling out.
 *
 * {@see Staging} gives it: the document is written whole into the temporary
 * directory, patched there, and moved into place in one step. A patch that throws
 * is as harmless as a writer that throws, because the destination is only ever
 * named by the `rename()` that ends the run.
 */
final class RtfWriter
{
    /**
     * @throws FileNotWritable When the document cannot be staged, read, or put in place.
     */
    public static function write(PhpWord $phpWord, string $path, ?LinkPayloadCollector $links = null): string
    {
        return Staging::write(
            $phpWord,
            Format::Rtf->writer(),
            $path,
            static function (string $staged) use ($links): void {
                if ($links?->hasPayloads() === true) {
                    (new RtfHyperlinkPass($links->payloads()))->applyTo($staged);
                }
            },
        );
    }

    /**
     * @throws FileNotWritable When the document cannot be staged or read.
     */
    public static function toString(PhpWord $phpWord, ?LinkPayloadCollector $links = null): string
    {
        return Staging::toString(
            $phpWord,
            Format::Rtf->writer(),
            static function (string $staged) use ($links): void {
                if ($links?->hasPayloads() === true) {
                    (new RtfHyperlinkPass($links->payloads()))->applyTo($staged);
                }
            },
        );
    }
}