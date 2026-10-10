<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\UnreadableDocument;
use MarkdownWord\Format;
use MarkdownWord\Render\ImageDescriptionCollector;
use MarkdownWord\Render\LinkPayloadCollector;
use PhpOffice\PhpWord\PhpWord;

/**
 * Writes a `PhpWord` document to `.odt`.
 *
 * An `.odt` is a zip like a `.docx` is, so the passes are the same shape: the
 * archive is staged in the temporary directory, rewritten in place, and moved into
 * place in one step. Only two of the DOCX passes have a counterpart here —
 * {@see OdfHyperlinkPass} and {@see OdfImageDescriptionPass} — because the third,
 * {@see SvgPass}, exists to attach a vector to a picture element only Word has.
 * ODF draws an SVG as a vector on its own, so an SVG has nothing to put back.
 *
 * What the writer cannot express at all is said by {@see Format::Odt}, not here.
 */
final class OdtWriter
{
    /**
     * @throws FileNotWritable When the archive cannot be staged, read, or put in place.
     * @throws UnreadableDocument When a pass cannot reopen the staged archive.
     * @throws MalformedDocument When a part of the staged archive is not XML.
     */
    public static function write(
        PhpWord $phpWord,
        string $path,
        ?LinkPayloadCollector $links = null,
        ?ImageDescriptionCollector $images = null,
    ): string {
        return Staging::write(
            $phpWord,
            Format::Odt->writer(),
            $path,
            static function (string $staged) use ($links, $images): void {
                self::patch($staged, $links, $images);
            },
        );
    }

    /**
     * @throws FileNotWritable When the archive cannot be staged or read.
     * @throws UnreadableDocument When a pass cannot reopen the staged archive.
     * @throws MalformedDocument When a part of the staged archive is not XML.
     */
    public static function toString(
        PhpWord $phpWord,
        ?LinkPayloadCollector $links = null,
        ?ImageDescriptionCollector $images = null,
    ): string {
        return Staging::toString(
            $phpWord,
            Format::Odt->writer(),
            static function (string $staged) use ($links, $images): void {
                self::patch($staged, $links, $images);
            },
        );
    }

    private static function patch(
        string $odtPath,
        ?LinkPayloadCollector $links,
        ?ImageDescriptionCollector $images,
    ): void {
        if ($links?->hasPayloads() === true) {
            (new OdfHyperlinkPass($links->payloads()))->applyTo($odtPath);
        }

        $descriptions = $images?->take() ?? [];

        if ($descriptions !== []) {
            (new OdfImageDescriptionPass($descriptions))->applyTo($odtPath);
        }
    }
}