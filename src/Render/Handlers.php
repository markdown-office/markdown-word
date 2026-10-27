<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use MarkdownWord\Render\Directive\Caption;
use MarkdownWord\Render\Directive\DocxOnly;
use MarkdownWord\Render\Directive\Image;
use MarkdownWord\Render\Directive\PageBreak;
use MarkdownWord\Render\Directive\SectionBreak;
use MarkdownWord\Render\Directive\TextStyle;

/**
 * The handlers for the directives only some documents use.
 *
 * A table of contents and a footnote are always drawn — every document may
 * write either — and the rest are the ones only some documents need: a picture,
 * a caption, a page break, a section break, and the three only a `.docx` can
 * carry.
 *
 * A handler that is null is not drawn, and the registry then draws fewer names
 * than {@see Directives::names()} says: a caller with no picture to place has
 * no use for a handler that places one.
 */
final class Handlers
{
    public function __construct(
        public readonly ?TextStyle $textStyle = null,
        public readonly ?Image $image = null,
        public readonly ?Caption $caption = null,
        public readonly ?PageBreak $pageBreak = null,
        public readonly ?SectionBreak $section = null,
        public readonly ?DocxOnly $docxOnly = null,
    ) {
    }
}
