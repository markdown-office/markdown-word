<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\UnreadableDocument;
use MarkdownWord\Format;
use MarkdownWord\Render\ImageDescriptionCollector;
use MarkdownWord\Render\LinkPayloadCollector;
use MarkdownWord\Render\SvgAttachmentCollector;
use PhpOffice\PhpWord\PhpWord;

/**
 * Writes a `PhpWord` document to `.docx`.
 *
 * On top of PHPWord's own writer this makes three extra passes over the archive,
 * each reopening it:
 *
 *  - {@see HyperlinkPass} replaces the placeholders left behind by
 *    {@see \MarkdownWord\Render\LinkPayloadCollector} with real `w:hyperlink`
 *    elements. Without it, a link whose label contains emphasis would lose
 *    either the link or the formatting.
 *  - {@see ImageDescriptionPass} fills in the alternative text of the images,
 *    which PHPWord writes as an empty string.
 *  - {@see SvgPass} puts the vector back behind the raster Word draws, for the SVG
 *    images PHPWord could only have taken as a picture.
 *
 * All three are OOXML concerns: each edits a part of a `.docx` that the other two
 * formats do not have. {@see OdtWriter} and {@see RtfWriter} are the same
 * arrangement with whichever of the three the format can actually express.
 *
 * {@see self::write()} and {@see self::toString()} both stage the archive in the
 * system temp directory and patch it there, so neither a document written to
 * disk nor one handed back as a string is ever half-finished. That is
 * {@see Staging}'s to give, and it is given for every format.
 */
final class DocxWriter
{
    /**
     * Write the document to a file, and hand back what was written.
     *
     * The bytes are read before the move, so a caller wanting both the file and
     * the content gets the content of the file that landed.
     *
     * @throws FileNotWritable When the archive cannot be staged, read, or put in
     *         place: no temporary file to be had, a directory that cannot be
     *         made, or a destination the process cannot write to.
     * @throws UnreadableDocument When a pass cannot reopen the staged archive.
     * @throws MalformedDocument When a part of the staged archive is not XML.
     */
    public static function write(
        PhpWord $phpWord,
        string $path,
        ?LinkPayloadCollector $links = null,
        ?ImageDescriptionCollector $images = null,
        ?SvgAttachmentCollector $vectors = null,
    ): string {
        return Staging::write(
            $phpWord,
            Format::Docx->writer(),
            $path,
            static function (string $staged) use ($links, $images, $vectors): void {
                self::patch($staged, $links, $images, $vectors);
            },
        );
    }

    /**
     * Write the document and return it as a string, without touching the disk
     * beyond the staging file.
     *
     * @throws FileNotWritable When the archive cannot be staged or read.
     * @throws UnreadableDocument When a pass cannot reopen the staged archive.
     * @throws MalformedDocument When a part of the staged archive is not XML.
     */
    public static function toString(
        PhpWord $phpWord,
        ?LinkPayloadCollector $links = null,
        ?ImageDescriptionCollector $images = null,
        ?SvgAttachmentCollector $vectors = null,
    ): string {
        return Staging::toString(
            $phpWord,
            Format::Docx->writer(),
            static function (string $staged) use ($links, $images, $vectors): void {
                self::patch($staged, $links, $images, $vectors);
            },
        );
    }

    /**
     * The file is edited in place, so this is public: the template renderer
     * builds its document with PHPWord's own template processor and patches it
     * here afterwards.
     *
     * @param list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}> $payloads
     *
     * @throws UnreadableDocument When the file is not a zip archive at all.
     * @throws MalformedDocument When the document part or the relationship part is
     *         missing, or when either of those parts is not XML.
     */
    public static function patchHyperlinks(string $docxPath, array $payloads): void
    {
        (new HyperlinkPass($payloads))->applyTo($docxPath);
    }

    private static function patch(
        string $docxPath,
        ?LinkPayloadCollector $links,
        ?ImageDescriptionCollector $images,
        ?SvgAttachmentCollector $vectors,
    ): void {
        if ($links?->hasPayloads() === true) {
            self::patchHyperlinks($docxPath, $links->payloads());
        }

        $descriptions = $images?->take() ?? [];

        if ($descriptions !== []) {
            (new ImageDescriptionPass($descriptions))->applyTo($docxPath);
        }

        $attachments = $vectors?->all() ?? [];

        if ($attachments !== []) {
            (new SvgPass())->applyTo($docxPath, $attachments);
        }
    }
}