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
     * @return list<array{position: int, name: string, vector: string, raster: bool}>
     */
    public function all(): array
    {
        return $this->attachments;
    }

    /**
     * Whether an SVG has been embedded as a vector at all.
     *
     * A separate question from {@see self::all()}, which a writer that cannot reattach
     * the vector never asks. Only {@see \MarkdownWord\Writer\SvgPass} can put it back,
     * so a writer that never calls it loses something, and {@see \MarkdownWord\Format}
     * has to be able to say so.
     */
    public function anyAttached(): bool
    {
        return $this->attachments !== [];
    }

    /**
     * Whether every vector in the batch has a raster behind it.
     *
     * A document that mixes the two is one whose older readers see some of its pictures
     * and not others, which is worse than either arrangement on its own.
     */
    public function allHaveRasters(): bool
    {
        foreach ($this->attachments as $attachment) {
            if ($attachment['raster'] === false) {
                return false;
            }
        }

        return true;
    }
}