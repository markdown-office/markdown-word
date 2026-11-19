<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Style\Section as WordSection;

/**
 * How wide a table may be, and how that width is shared out between its columns.
 *
 * A Markdown table carries no widths at all — GFM's delimiter row says alignment
 * and nothing else — so every viewer has to invent them, and the widths have to
 * be written into the document for Word to invent them the same way twice.
 *
 * Word invents them badly without help. Given a `w:tblGrid` of empty `w:gridCol`
 * elements and cells with no `w:tcW`, it has nothing to go on and narrows each
 * column to the least it can hold: one or two characters. Left alone it is also
 * free to widen a column past the page, which is why a cell used to be told not
 * to wrap — an accident that made Word size columns to their content and hid the
 * missing widths rather than filling them in.
 */
final class TableLayout
{
    /**
     * The narrowest a column may be left, in twips: half an inch, which holds
     * about six characters and so reads as a column rather than as a seam.
     *
     * A purely proportional split does not respect this. A table with one
     * four-character column and one long one gives the short one a few percent of
     * the width, which is where "one character wide" comes from.
     */
    private const MIN_COLUMN = 720;

    /**
     * Characters of text at which a column is as wide as it wants to be.
     *
     * Past this the column is widened no further, so one cell holding a paragraph
     * cannot take the whole table away from the columns beside it. Forty is a
     * little over a line of body text, which is about where a reader stops caring
     * which column a cell belongs to.
     */
    private const WIDTH_CAP = 40;

    /** A cell's own left and right margin, in twips: the renderer's default. */
    private const CELL_PADDING = 160;

    public function __construct(private readonly AbstractContainer $target)
    {
    }

    /**
     * A width for every column, in twips, summing to exactly the width available.
     *
     * @param  list<int>  $contentWidths  characters of text in each column
     * @return list<int>
     */
    public function columnWidths(array $contentWidths): array
    {
        $available = $this->usableWidth();
        $count = count($contentWidths);

        if ($count === 0) {
            return [];
        }

        if ($count === 1) {
            return [$available];
        }

        $weights = array_map(
            static fn (int $characters): int => max(1, min($characters, self::WIDTH_CAP)),
            $contentWidths,
        );

        // Every column is given its minimum first, and only what is left over is
        // shared out. Doing it in that order means the floors cannot overrun the
        // width — a table with more columns than there is room for shrinks them
        // instead — and the split afterwards never has to be undone.
        $floor = min(self::MIN_COLUMN, intdiv($available, $count));
        $spare = $available - ($floor * $count);
        $total = array_sum($weights);

        $widths = [];
        foreach ($weights as $index => $weight) {
            $widths[$index] = $floor + intdiv($spare * $weight, $total);
        }

        // `intdiv()` truncates, so the columns together come up short by less than
        // one twip each. Handing the shortfall out one apiece, in order, keeps
        // every column within a twip of every other — the total is not always
        // divisible by the number of columns, and a column that differs from its
        // neighbour by more than that is a fault the arithmetic can avoid.
        $shortfall = $available - array_sum($widths);

        foreach ($widths as $index => $width) {
            if ($shortfall === 0) {
                break;
            }

            $widths[$index] = $width + 1;
            $shortfall--;
        }

        return $widths;
    }

    /**
     * The width of the text column the table sits in, in twips.
     *
     * A header or a footer is the case this cannot answer: PHPWord records page
     * geometry on the section alone and offers no way to reach the section from
     * either, so a table in one is measured against a page with no setup of its
     * own. {@see self::defaultTextWidth()} is what that is, and it is the right
     * guess far more often than not.
     */
    private function usableWidth(): int
    {
        if ($this->target instanceof Cell) {
            return $this->withinCell($this->target);
        }

        $style = $this->target instanceof Section ? $this->target->getStyle() : null;

        if (!$style instanceof WordSection) {
            return self::defaultTextWidth();
        }

        $width = (float) $style->getPageSizeW()
            - (float) $style->getMarginLeft()
            - (float) $style->getMarginRight();

        // A section set in columns holds a table in one of them, not across all
        // of them, and the spacing between them is not the table's to use.
        $columns = max(1, (int) $style->getColsNum());

        if ($columns > 1) {
            $width = ($width - (($columns - 1) * (float) $style->getColsSpace())) / $columns;
        }

        return max(self::MIN_COLUMN, (int) round($width));
    }

    /**
     * A table inside a cell is as wide as the cell holding it, less the padding.
     * That cell's width is one this renderer set, so it is known rather than
     * guessed; a cell built by hand has none, and falls back with the rest.
     */
    private function withinCell(Cell $cell): int
    {
        $width = $cell->getWidth();

        if ($width === null) {
            return self::defaultTextWidth();
        }

        return max(self::MIN_COLUMN, $width - self::CELL_PADDING);
    }

    /**
     * A4 with an inch of margin, taken from PHPWord's own defaults rather than
     * written out here, because the two move together and one of them is not
     * this library's to change.
     */
    private static function defaultTextWidth(): int
    {
        return (int) round(WordSection::DEFAULT_WIDTH - (2 * WordSection::DEFAULT_MARGIN));
    }
}
