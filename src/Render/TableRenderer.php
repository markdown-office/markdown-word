<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use League\CommonMark\Extension\Table\Table as MarkdownTable;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Configuration\Styles;
use PhpOffice\PhpWord\Element\AbstractContainer;

/**
 * Draws a Markdown table as a Word table.
 *
 * A table is the one block whose shape is not a paragraph's: it has columns, and
 * a column has a width. A Markdown table offers no widths of its own, so they
 * are measured over the text the cells hold — which is why the measurement is
 * made over the text nodes rather than over the rendered runs, because a column
 * of images has no characters in it and should not be laid out as if it were a
 * column of long ones.
 *
 * A cell may hold blocks rather than a paragraph, so the drawing of those is
 * left to whoever walks the document.
 */
final class TableRenderer
{
    /**
     * Cells ask Word to let their text wrap, unless a cell style says otherwise.
     *
     * PHPWord's cell style defaults `noWrap` to true, which writes `<w:noWrap/>`
     * — Word's "Wrap text" cell option, unchecked — into every cell of every
     * table. Word then lays each cell out on a single line and widens the column
     * to fit it, so a table whose cells hold a sentence runs off the page and the
     * line cannot break anywhere. LibreOffice reads `w:noWrap` as a hint it may
     * ignore, which is why the file looks right there and wrong in Word.
     */
    private const CELL_WRAPPING = ['noWrap' => false];

    public function __construct(
        private readonly Configuration $config,
        private readonly StyleResolver $styles,
        private readonly InlineRenderer $inlines,
    ) {
    }

    /**
     * @param  callable(Node, AbstractContainer, RenderContext): void  $renderBlock
     */
    public function render(
        MarkdownTable $node,
        AbstractContainer $target,
        RenderContext $context,
        callable $renderBlock,
    ): void {
        $options = $this->config->getOptions();
        $tableStyle = $this->styles->slot(Styles::TABLE);

        $table = match (true) {
            $tableStyle === null => $target->addTable($this->defaultTableStyle($options)),
            // A named table style comes from the target document — typically a
            // Word built-in grid — and is used exactly as the template defines it.
            is_string($tableStyle) => $target->addTable($tableStyle),
            // A custom definition merges over the same default the `null` case builds,
            // so naming the one thing a theme is about — a border colour — keeps the
            // rules, their weight and the cell margins. Handing PHPWord the definition
            // on its own produced a table with no borders at all, because `borderColor`
            // sets the colour of rules that have to be asked for separately.
            default => $target->addTable(ParagraphStyle::table(
                ParagraphStyle::merge($this->defaultTableStyle($options), $tableStyle),
            )),
        };

        $alignments = $this->columnAlignments($node);
        $headerRowStyle = $this->styles->slot(Styles::TABLE_HEADER_ROW);
        $cellStyle = $this->styles->slot(Styles::TABLE_CELL);
        $widths = $this->columnWidths($node, $target, $cellStyle);

        $isHeader = true;

        foreach ($this->tableRows($node) as $row) {
            $table->addRow(null, $isHeader ? $this->rowStyle($headerRowStyle) : null);

            $column = 0;
            foreach ($this->rowCells($row) as $cell) {
                $align = $alignments[$column] ?? null;

                $tableCell = $table->addCell($widths[$column] ?? null, $this->cellStyle($cellStyle));
                $this->renderCellContent(
                    $cell,
                    $tableCell,
                    $context,
                    $renderBlock,
                    $this->cellFont($cellStyle, $isHeader, $options),
                    $this->cellAlignment($align, $cellStyle),
                );
                $column++;
            }

            $isHeader = false;
        }
    }

    /**
     * A width for every column, or none at all.
     *
     * A cell style that names its own unit is measuring itself: the widths here
     * are twips, and a `w:tcW` in twips labelled as a percentage is not a narrower
     * table but an unreadable one. Someone who has configured a unit has said what
     * they want, so nothing is imposed on top of it.
     *
     * @return list<int>
     */
    private function columnWidths(MarkdownTable $node, AbstractContainer $target, mixed $cellStyle): array
    {
        if (is_array($cellStyle) && isset($cellStyle['unit'])) {
            return [];
        }

        return (new TableLayout($target))->columnWidths($this->columnContentWidths($node));
    }

    /**
     * The width of the widest cell in each column, in characters — the only thing
     * a Markdown table offers in place of a width. Measured over the text nodes
     * rather than the rendered runs, because a column of images has no characters
     * in it and should not be laid out as if it were a column of long ones.
     *
     * @return list<int>
     */
    private function columnContentWidths(MarkdownTable $node): array
    {
        $widths = [];

        foreach ($this->tableRows($node) as $row) {
            foreach ($this->rowCells($row) as $index => $cell) {
                $widths[$index] = max($widths[$index] ?? 0, $this->textLength($cell));
            }
        }

        return $widths;
    }

    private function textLength(TableCell $cell): int
    {
        $length = 0;

        foreach ($cell->children() as $child) {
            $length += $child instanceof Text ? mb_strlen($child->getLiteral()) : $this->blockTextLength($child);
        }

        return $length;
    }

    private function blockTextLength(Node $node): int
    {
        if (!$node->hasChildren()) {
            return 0;
        }

        $length = 0;

        foreach ($node->children() as $child) {
            $length += $child instanceof Text ? mb_strlen($child->getLiteral()) : $this->blockTextLength($child);
        }

        return $length;
    }

    /**
     * @param  array<string, mixed>  $forcedFont
     * @param  callable(Node, AbstractContainer, RenderContext): void  $renderBlock
     */
    private function renderCellContent(
        TableCell $cell,
        AbstractContainer $tableCell,
        RenderContext $context,
        callable $renderBlock,
        array $forcedFont = [],
        ?string $alignment = null,
    ): void {
        $inlineStyle = new InlineStyle(forcedFont: $forcedFont);
        $paragraphStyle = $alignment === null ? null : ['alignment' => $alignment];
        $blocks = 0;

        foreach ($cell->children() as $child) {
            if ($child instanceof Paragraph) {
                $run = $tableCell->addTextRun($paragraphStyle);
                $this->inlines->render($child->children(), $run, $inlineStyle);
            } elseif ($child instanceof AbstractBlock) {
                $renderBlock($child, $tableCell, $context);
            } else {
                $run = $tableCell->addTextRun($paragraphStyle);
                $this->inlines->render([$child], $run, $inlineStyle);
            }
            $blocks++;
        }

        if ($blocks === 0) {
            $tableCell->addTextRun($paragraphStyle);
        }
    }

    /**
     * @return list<TableRow>
     */
    private function tableRows(MarkdownTable $table): array
    {
        $rows = [];

        foreach ($table->children() as $section) {
            if ($section instanceof TableSection) {
                foreach ($section->children() as $row) {
                    if ($row instanceof TableRow) {
                        $rows[] = $row;
                    }
                }
            } elseif ($section instanceof TableRow) {
                $rows[] = $section;
            }
        }

        return $rows;
    }

    /**
     * @return list<TableCell>
     */
    private function rowCells(TableRow $row): array
    {
        $cells = [];
        foreach ($row->children() as $cell) {
            if ($cell instanceof TableCell) {
                $cells[] = $cell;
            }
        }

        return $cells;
    }

    /**
     * GFM stores the column alignment in the delimiter row of the header.
     *
     * @return list<string|null>
     */
    private function columnAlignments(MarkdownTable $table): array
    {
        $alignments = [];

        foreach ($table->children() as $section) {
            if (!$section instanceof TableSection || $section->getType() !== TableSection::TYPE_HEAD) {
                continue;
            }

            foreach ($section->children() as $row) {
                if (!$row instanceof TableRow) {
                    continue;
                }
                foreach ($this->rowCells($row) as $index => $cell) {
                    $alignments[$index] = $cell->getAlign();
                }
            }
        }

        return $alignments;
    }

    /**
     * PHPWord's cell style has no font or alignment properties, so those parts of
     * the configured cell style are extracted and applied to the runs and
     * paragraphs inside the cell instead. Only the genuinely cell-level
     * properties (shading, margins, borders) are passed to the cell itself.
     *
     * @return array<string, mixed>
     */
    private function cellStyle(mixed $configured): array
    {
        if (!is_array($configured)) {
            return self::CELL_WRAPPING;
        }

        $cellOnly = $configured;
        unset($cellOnly['bold'], $cellOnly['italic'], $cellOnly['alignment']);

        // `+` and not `array_merge`, so it fills in the default rather than
        // overwriting a `noWrap` the cell style asked for.
        return $cellOnly + self::CELL_WRAPPING;
    }

    /**
     * @return array<string, mixed>
     */
    private function cellFont(mixed $configured, bool $isHeader, Options $options): array
    {
        $font = is_array($configured) ? $configured : [];
        unset($font['alignment']);

        if ($isHeader && $options->tableHeaderBold) {
            $font['bold'] = true;
        }

        return $font;
    }

    /**
     * The horizontal alignment of a column: the one written in the delimiter row,
     * unless the configured cell style overrides it.
     */
    private function cellAlignment(?string $markdownAlign, mixed $configured): ?string
    {
        $configuredAlign = is_array($configured) ? ($configured['alignment'] ?? null) : null;

        if (is_array($configuredAlign)) {
            return $configuredAlign['horizontal'] ?? null;
        }

        if (is_string($configuredAlign)) {
            return $configuredAlign;
        }

        return match ($markdownAlign) {
            'right' => 'right',
            'center' => 'center',
            'left' => 'left',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowStyle(mixed $configured): ?array
    {
        return is_array($configured) ? $configured : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultTableStyle(Options $options): array
    {
        $style = $this->widthStyle($options) + [
            'alignment' => 'left',
            'layout' => 'autofit',
        ];

        if ($options->tableBorders) {
            $style['borderColor'] = '000000';
            $style['borderSize'] = 6;
            $style['cellMargin'] = 80;
        }

        return $style;
    }

    /**
     * The table's width, the way OOXML wants it.
     *
     * PHPWord's default is `w:tblW w:w="0" w:type="auto"`, and a zero width makes
     * every viewer shrink the table to its shortest content rather than filling
     * the text column. A percentage of the column is the stable way to ask for
     * full width: it follows the page size and the margins.
     *
     * @return array<string, mixed>
     */
    private function widthStyle(Options $options): array
    {
        if ($options->tableWidth <= 0) {
            return ['width' => 0, 'unit' => 'auto'];
        }

        return ['width' => $options->tableWidth, 'unit' => 'pct'];
    }
}
