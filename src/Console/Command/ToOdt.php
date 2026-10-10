<?php

declare(strict_types=1);

namespace MarkdownWord\Console\Command;

use MarkdownWord\Format;

/**
 * `mdword to-odt` — Markdown in, an OpenDocument Text out.
 *
 * {@see ToDocx} with a different format, and therefore with less in the result:
 * {@see Format::Odt} lists what the ODF writer drops, and a run that loses any of
 * it says so on standard error.
 */
final class ToOdt extends ToDocx
{
    protected function format(): Format
    {
        return Format::Odt;
    }

    protected function summary(): string
    {
        return 'convert Markdown to an OpenDocument Text document.';
    }

    protected static function formats(): array
    {
        return ['odt'];
    }
}