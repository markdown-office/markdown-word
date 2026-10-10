<?php

declare(strict_types=1);

namespace MarkdownWord\Console\Command;

use MarkdownWord\Format;

/**
 * `mdword to-rtf` — Markdown in, a Rich Text Format out.
 *
 * {@see ToDocx} with a different format, and therefore with the most left out of
 * the result of the three: RTF is the oldest format here and PHPWord's writer for
 * it is the one that leaves out the most. {@see Format::Rtf} lists what that is,
 * and a run that loses any of it says so on standard error.
 */
final class ToRtf extends ToDocx
{
    protected function format(): Format
    {
        return Format::Rtf;
    }

    protected function summary(): string
    {
        return 'convert Markdown to a Rich Text Format document.';
    }

    protected static function formats(): array
    {
        return ['rtf'];
    }
}