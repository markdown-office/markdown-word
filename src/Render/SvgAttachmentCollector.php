<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

/**
 * Collects the SVG originals behind the pictures Word will draw as a vector.
 *
 * PHPWord writes every image as VML, and VML has nowhere to hang a vector, so the SVG
 * cannot go in through `addImage()` at all. It is carried here and
 * {@see \MarkdownWord\Writer\SvgPass} rewrites the picture to reference it once the file
 * is written.
 *
 * What pairs the two halves is the picture's position. It is not the raster it was
 * given: PHPWord names media parts as it writes them, so a part name says nothing about
 * which picture became it, and two pictures of the same bytes are one part and two
 * elements. A position names the element directly, and it is the same ordering
 * {@see \MarkdownWord\Writer\ImageDescriptionPass} already matches on.
 *
 * The part name is decided here rather than derived from a raster beside it, so two
 * different vectors in one document cannot land on the same file.
 */
final class SvgAttachmentCollector
{
    /** @var list<array{position: int, name: string, vector: string, raster: bool}> */
    private array $attachments = [];

    private int $offset = 0;

    private bool $lastBatchWithoutRaster = false;

    /**
     * @param int    $position the picture's index among the images added so far
     * @param string $name     the part name the vector takes in the archive
     * @param string $vector   the SVG itself
     * @param bool   $raster   whether a raster of the SVG is in the file beside it
     */
    public function add(int $position, string $name, string $vector, bool $raster): void
    {
        $this->attachments[] = [
            'position' => $position,
            'name' => $name,
            'vector' => $vector,
            'raster' => $raster,
        ];
    }

    /**
     * One document's vectors: a document is written as soon as its elements are
     * complete, so a batch ends there.
     *
     * Without this a second document written through the same converter would be handed
     * the first one's vectors, and because they carry a position they would be attached
     * to whatever picture happened to be at that index — a vector this document never
     * mentioned, on a picture that had nothing to do with it.
     *
     * @return list<array{position: int, name: string, vector: string, raster: bool}>
     */
    public function take(): array
    {
        $batch = \array_slice($this->attachments, $this->offset);
        $this->offset = \count($this->attachments);
        $this->lastBatchWithoutRaster = false;

        foreach ($batch as $attachment) {
            if ($attachment['raster'] === false) {
                $this->lastBatchWithoutRaster = true;

                break;
            }
        }

        return $batch;
    }

    /**
     * Whether the batch just taken has a vector with no raster behind it.
     *
     * A separate question from {@see self::take()}, which a caller asking whether this
     * document is compatible with an older reader asks after the document is written and
     * the batch is gone.
     */
    public function lastBatchWithoutRaster(): bool
    {
        return $this->lastBatchWithoutRaster;
    }

    /**
     * Whether a vector in the document being rendered has no raster behind it.
     *
     * {@see \MarkdownWord\Writer\Survey} is asked before the document is written, so it
     * wants the batch that has not been taken yet.
     */
    public function pendingWithoutRaster(): bool
    {
        foreach (\array_slice($this->attachments, $this->offset) as $attachment) {
            if ($attachment['raster'] === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any vector is waiting to be attached to the document being rendered.
     *
     * {@see self::lastBatchWithoutRaster()} answers the same question about the batch
     * already taken, which is the document that was written rather than the one being
     * built. {@see \MarkdownWord\Writer\Survey} is asked before the write, so it wants
     * this one.
     */
    public function pendingAnyAttached(): bool
    {
        return \count($this->attachments) > $this->offset;
    }
}