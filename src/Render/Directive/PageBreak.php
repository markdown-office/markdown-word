<?php

declare(strict_types=1);

namespace MarkdownWord\Render\Directive;

use MarkdownOffice\CommonMark\Directive\Node\DirectiveBlock;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Section;

/**
 * `::pagebreak` — a page break.
 *
 * A page break belongs to a section, and PHPWord's own validity table says so:
 * a page break in a table cell is refused with an exception, which is not what
 * a document should get for a directive it wrote. So a directive that stands
 * where there is no page to break is drawn as the characters the author wrote,
 * the same answer an unclaimed directive gets.
 *
 * All three writers have an element for it, so nothing is lost in any of them.
 */
final class PageBreak
{
    public function __invoke(DirectiveBlock $node, AbstractContainer $target): void
    {
        if (!$target instanceof Section) {
            $target->addTextRun()->addText((string) $node);

            return;
        }

        $target->addPageBreak();
    }
}
