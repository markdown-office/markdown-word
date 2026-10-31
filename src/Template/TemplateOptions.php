<?php

declare(strict_types=1);

namespace MarkdownWord\Template;

/**
 * What a template may hold before it is read.
 *
 * A template is somebody else's file, and a zip says how its contents are laid out
 * and nothing about how much room they will take up: forty kilobytes of a part is
 * forty megabytes of paragraph, and the entry count is whatever the writer felt like.
 * The read path bounds both, in {@see \MarkdownWord\Reverse\Options}; the write path
 * bounds them here.
 *
 * The numbers are the read path's, so that a document is as welcome as a template.
 */
final class TemplateOptions
{
    /**
     * @param int $maxPartBytes The largest one part of the archive may be once
     *        decompressed.
     * @param int $maxEntries The largest number of parts the archive may have, read
     *        from its central directory and costing no decompression.
     */
    public function __construct(
        public readonly int $maxPartBytes = 268435456,
        public readonly int $maxEntries = 4096,
    ) {
    }

    /**
     * The same limits with a different bound on the size of one part.
     */
    public function withMaxPartBytes(int $bytes): self
    {
        return new self($bytes, $this->maxEntries);
    }

    /**
     * The same limits with a different bound on the number of parts.
     */
    public function withMaxEntries(int $entries): self
    {
        return new self($this->maxPartBytes, $entries);
    }
}
