<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

/**
 * The images that had to be decoded before they could be embedded.
 *
 * Word has no support for WebP and this library will not add any, so a `.webp` in a
 * document is decoded with GD and written out as PNG. That is the right default —
 * the alternative is a document that looks finished with the picture missing — but
 * it is not free: the file is larger than what went in, the picture is a
 * re-encoding rather than the original bytes, and a PNG of a photograph is several
 * times the size of the WebP it came from. Anyone who would rather choose should be
 * able to see that it happened.
 *
 * This outlives a single document, as the hyperlink collector does, so several
 * documents rendered through one converter stay in step.
 */
final class ImageConversionCollector
{
    /** @var list<array{source: string, format: string, embeddedAs: string}> */
    private array $conversions = [];

    public function add(string $source, string $format): void
    {
        $this->conversions[] = [
            'source' => $source,
            'format' => $format,
            'embeddedAs' => 'PNG',
        ];
    }

    /**
     * @return list<array{source: string, format: string, embeddedAs: string}>
     */
    public function all(): array
    {
        return $this->conversions;
    }
}