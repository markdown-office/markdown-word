<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use MarkdownWord\Render\ImageDescriptionCollector;
use MarkdownWord\Render\SvgAttachmentCollector;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style;
use PhpOffice\PhpWord\Style\Border;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\Numbering;
use PhpOffice\PhpWord\Style\NumberingLevel;
use PhpOffice\PhpWord\Style\Paragraph;
use PhpOffice\PhpWord\Style\Shading;

/**
 * What a rendered document asks of the writer that is about to write it.
 *
 * Asked once per conversion over the same element tree every writer is handed, so
 * the answer is a property of the document and not of any one format.
 * {@see \MarkdownWord\Format} then compares it with what the chosen writer can
 * express, and the difference is what the reader of the finished document is told
 * about.
 *
 * A feature the document never used is not a loss, so a document with no tables
 * reports nothing about table borders — the sentence would be noise about a table
 * that is not there.
 */
final class Survey
{
    /** @var array<string, true> */
    private array $used = [];

    /**
     * Private so that {@see self::of()} is the only way to get one.
     *
     * A survey is an answer to a question about a document somebody walked. One that
     * could be constructed empty would answer "this document uses none of these
     * features" about a document that was never looked at, which is the one answer
     * {@see \MarkdownWord\Format} cannot tell from a real one.
     */
    private function __construct()
    {
    }

    public static function of(
        PhpWord $phpWord,
        ?ImageDescriptionCollector $images = null,
        ?SvgAttachmentCollector $vectors = null,
    ): self {
        $survey = new self();

        foreach ($phpWord->getSections() as $section) {
            $survey->walk($section, false);
        }

        if ($images?->anyDescribed() === true) {
            $survey->used['image-alt-text'] = true;
        }

        if ($vectors?->anyAttached() === true) {
            $survey->used['svg-vector'] = true;
        }

        return $survey;
    }

    public function uses(string $feature): bool
    {
        return isset($this->used[$feature]);
    }

    /**
     * @return list<string>
     */
    public function features(): array
    {
        return array_keys($this->used);
    }

    /**
     * @param bool $inTable A cell is a separate question from a paragraph: every
     *        writer treats the content of a cell differently from the content of the
     *        body, so the same property has to be asked of the two separately.
     */
    private function walk(AbstractContainer $container, bool $inTable): void
    {
        foreach ($container->getElements() as $element) {
            $this->record($element, $inTable);

            if ($element instanceof Table) {
                $this->walkTable($element);

                continue;
            }

            if ($element instanceof Cell || $element instanceof AbstractContainer) {
                $this->walk($element, $inTable || $element instanceof Cell);
            }
        }
    }

    private function walkTable(Table $table): void
    {
        if ($this->borderSize($table->getStyle()) > 0) {
            $this->used['table-borders'] = true;
        }

        // `Row` is not a container, so the cells are reached from the table rather
        // than by walking down into it.
        foreach ($table->getRows() as $row) {
            foreach ($row->getCells() as $cell) {
                if ($this->borderSize($cell->getStyle()) > 0) {
                    $this->used['table-borders'] = true;
                }

                $this->walk($cell, true);
            }
        }
    }

    private function record(mixed $element, bool $inTable): void
    {
        if ($element instanceof ListItemRun) {
            $this->used['lists'] = true;

            if ($this->isOrdered($element)) {
                $this->used['numbered-lists'] = true;
            }
        }

        if ($element instanceof Image && preg_match('/\.jpe?g$/i', $element->getTarget()) === 1) {
            $this->used['jpeg-label'] = true;
        }

        $paragraph = $this->paragraphOf($element);

        // A name rather than a definition: `StyleRegistrar` registers the heading and
        // quote styles under their Word names, and a writer that resolves a name and
        // one that resolves an inline array are not the same code path.
        if (is_string($paragraph)) {
            $this->used['named-styles'] = true;
        } elseif ($paragraph instanceof Paragraph) {
            $this->recordParagraph($paragraph, $inTable);
        }

        if ($element instanceof Text) {
            $this->recordRun($element, $inTable);
        }
    }

    private function recordParagraph(Paragraph $paragraph, bool $inTable): void
    {
        $shading = $paragraph->getShading();

        if ($shading instanceof Shading && $shading->getFill() !== null && $shading->getFill() !== '') {
            $this->used['shading'] = true;
        }

        $bottom = $paragraph->getBorderBottomStyle();

        if (is_string($bottom) && $bottom !== '' && $bottom !== 'none') {
            $this->used['paragraph-border'] = true;
        }

        if ($inTable && $paragraph->getAlignment() !== null && $paragraph->getAlignment() !== '') {
            $this->used['cell-alignment'] = true;
        }
    }

    private function recordRun(Text $text, bool $inTable): void
    {
        $font = $text->getFontStyle();

        if (!$font instanceof Font) {
            return;
        }

        if ($font->getName() !== null || $font->getColor() !== null) {
            $this->used['font-face'] = true;
        }

        // `tableHeaderBold` puts the emphasis on the runs of the first row, which is
        // the one thing about a header that is not a property of the table.
        if ($inTable && ($font->isBold() || $font->isItalic())) {
            $this->used['cell-emphasis'] = true;
        }
    }

    private function borderSize(mixed $style): int
    {
        return $style instanceof Border ? (int) $style->getBorderTopSize() : 0;
    }

    /**
     * Whether the item belongs to a numbering definition whose levels are numbers.
     *
     * The format of the level, not the name of the style: a caller may configure the
     * numbering style to say "ordered list" and give it any name at all, and an
     * ordered list that comes out bulleted is exactly the loss being looked for.
     */
    private function isOrdered(ListItemRun $item): bool
    {
        $name = $item->getStyle()?->getNumStyle();

        if (!is_string($name)) {
            return false;
        }

        $definition = Style::getStyle($name);

        if (!$definition instanceof Numbering) {
            return false;
        }

        $levels = $definition->getLevels();
        $level = $levels[$item->getDepth()] ?? ($levels[0] ?? null);

        return $level instanceof NumberingLevel && $level->getFormat() !== 'bullet';
    }

    /**
     * The paragraph half of an element's style, whichever of the two shapes PHPWord
     * materialises it into: a name for a registered style, a `Paragraph` for an
     * inline array.
     */
    private function paragraphOf(mixed $element): Paragraph|string|null
    {
        if (!method_exists($element, 'getParagraphStyle')) {
            return null;
        }

        $style = $element->getParagraphStyle();

        return $style instanceof Paragraph || is_string($style) ? $style : null;
    }
}