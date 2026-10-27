<?php

declare(strict_types=1);

namespace MarkdownWord\Render\Directive;

use MarkdownOffice\CommonMark\Directive\Node\DirectiveBlock;
use MarkdownWord\Render\RenderContext;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Style\Section as SectionStyle;

/**
 * `::section{columns=2}` — a section break, and the columns of the section it starts.
 *
 * A section break is a new section: everything after the directive belongs to it,
 * and the columns are its own. The renderer asks for the new section once the
 * directive has been drawn — {@see self::take()} — because a directive draws what
 * it draws and does not decide where the rest of the document goes.
 *
 * The new section keeps the page setup of the one it follows. A section is a
 * page, and a document whose second half is on a different page size from its
 * first is a document nobody meant to write.
 *
 * **The columns are carried by `.docx` and `.odt`, and not by `.rtf`.** RTF has no
 * columns at all — not a section property it writes and not a control word it
 * understands — so the section break survives and the columns do not. That is
 * reported through {@see \MarkdownWord\Format}, and the feature is recorded here
 * because the columns are not in the element tree for a survey to find.
 */
final class SectionBreak
{
    /** The feature key of a section's columns. */
    public const COLUMNS = 'columns';

    /** The section the document continues in, until one is started. */
    private ?Section $pending = null;

    /**
     * @param bool $carries Whether a section break drawn here can reach the finished
     *        document. False when the rendered elements are going to be copied into a
     *        document this library is not writing: the elements travel and the
     *        sections they were drawn in do not, so the text after the break would
     *        be in a section of its own that nobody copies.
     * @param Usage $usage What the document asked of a writer that cannot carry it.
     */
    public function __construct(
        private readonly bool $carries = true,
        private readonly Usage $usage = new Usage(),
    ) {
    }

    public function __invoke(DirectiveBlock $node, AbstractContainer $target, RenderContext $context): void
    {
        $columns = max(1, $node->getAttributes()->getInt('columns', 1));

        $phpWord = $target instanceof Section ? $target->getPhpWord() : null;

        // A section break is a break between two parts of a document, and a part of
        // a document that is a quote or a list item is not one: the section it
        // would start would hold the rest of the quote and nothing else.
        if (!$this->carries || $phpWord === null || $context->inQuote() || $context->listDepth > 0) {
            $target->addTextRun()->addText((string) $node);

            return;
        }

        $section = $phpWord->addSection($this->styleFor($target, $columns));

        $this->pending = $section;

        if ($columns > 1) {
            $this->usage->add(self::COLUMNS);
        }
    }

    /**
     * The section the document continues in, and the one it was in before.
     *
     * Asked once per block by the renderer: a directive draws what it draws, and
     * the rest of the document belongs to the section the directive started.
     */
    public function take(AbstractContainer $current): AbstractContainer
    {
        $pending = $this->pending;
        $this->pending = null;

        return $pending ?? $current;
    }

    /**
     * The new section's page setup: the one it follows, with the columns on it.
     */
    private function styleFor(Section $current, int $columns): SectionStyle
    {
        $style = $current->getStyle();
        $style = $style instanceof SectionStyle ? clone $style : new SectionStyle();

        $style->setColsNum($columns);

        return $style;
    }
}
