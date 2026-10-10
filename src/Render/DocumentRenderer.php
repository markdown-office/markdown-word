<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\Table\Table as MarkdownTable;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\LookAndFeel;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Configuration\Styles;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Style\Paragraph as WordParagraph;

/**
 * Walks the CommonMark document tree and emits the equivalent Word elements.
 *
 * The traversal is an explicit chain of `instanceof` rather than a visitor
 * registry: naming every node type that is handled makes it obvious when
 * something would be silently dropped.
 */
final class DocumentRenderer
{
    /** One level of block quote, in twips (a twentieth of a point): half an inch. */
    private const QUOTE_INDENT = 720;

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
        private readonly NumberingRegistry $numbering,
        private readonly HtmlFragmentRenderer $html,
    ) {
    }

    public function render(Document $document, AbstractContainer $target): void
    {
        $this->renderChildren($document->children(), $target, new RenderContext());
    }

    /**
     * @param iterable<Node> $nodes
     */
    private function renderChildren(iterable $nodes, AbstractContainer $target, RenderContext $context): void
    {
        foreach ($nodes as $node) {
            $this->renderNode($node, $target, $context);
        }
    }

    private function renderNode(Node $node, AbstractContainer $target, RenderContext $context): void
    {
        if ($node instanceof Heading) {
            $this->renderHeading($node, $target, $context);

            return;
        }

        if ($node instanceof Paragraph) {
            $this->renderParagraph($node, $target, $context);

            return;
        }

        if ($node instanceof ThematicBreak) {
            $this->renderThematicBreak($target, $context);

            return;
        }

        if ($node instanceof FencedCode || $node instanceof IndentedCode) {
            $this->renderCodeBlock($node, $target, $context);

            return;
        }

        if ($node instanceof BlockQuote) {
            $this->renderChildren($node->children(), $target, $context->enterQuote());

            return;
        }

        if ($node instanceof ListBlock) {
            $this->renderList($node, $target, $context);

            return;
        }

        if ($node instanceof MarkdownTable) {
            $this->renderTable($node, $target, $context);

            return;
        }

        if ($node instanceof TableSection) {
            $this->renderChildren($node->children(), $target, $context);

            return;
        }

        // Only block-level HTML comes through here. Inline HTML is part of a
        // paragraph and is handled by the inline renderer, which has to splice it
        // into the run rather than start a new one.
        if ($node instanceof HtmlBlock) {
            $this->renderHtml($node, $target, $context);

            return;
        }

        if ($node instanceof Document) {
            $this->renderChildren($node->children(), $target, $context);

            return;
        }

        if ($node->hasChildren()) {
            $this->renderChildren($node->children(), $target, $context);
        }
    }

    private function renderHeading(Heading $node, AbstractContainer $target, RenderContext $context): void
    {
        $maxLevel = $this->config->getOptions()->maxHeadingLevel;
        $level = $node->getLevel();

        // Inside a block quote a heading would break out of the quote visually,
        // so it is drawn as an emphasised paragraph instead.
        $slot = $level <= $maxLevel && !$context->inQuote()
            ? 'heading.' . $level
            : Styles::PARAGRAPH;

        $this->emitParagraph($node->children(), $target, $context, $slot);
    }

    private function renderParagraph(Paragraph $node, AbstractContainer $target, RenderContext $context): void
    {
        $this->emitParagraph($node->children(), $target, $context, Styles::PARAGRAPH);
    }

    /**
     * The style slot is resolved here so its character half can be applied to the
     * runs: a Word paragraph carries no formatting of its own, so PHPWord would
     * discard it there.
     *
     * @param iterable<Node> $inlines
     */
    private function emitParagraph(
        iterable $inlines,
        AbstractContainer $target,
        RenderContext $context,
        string $slot,
    ): void {
        $style = $this->blockStyle($slot, $context);
        $forcedFont = $this->slotFont($slot, $context);

        $run = $target->addTextRun($style);

        $this->inlines->render(
            $inlines,
            $run,
            new InlineStyle(forcedFont: $forcedFont),
            function () use ($target, $style, $forcedFont): AbstractContainer {
                // `softBreak => 'paragraph'`: the inline renderer asks for a new
                // container whenever a soft break should become a real paragraph.
                $next = $target->addTextRun($style);
                $this->inlines->render([], $next, new InlineStyle(forcedFont: $forcedFont));

                return $next;
            },
        );
    }

    /**
     * The character formatting a style slot asks for. Inside a quote a plain
     * paragraph or list item takes the quote's slot instead.
     *
     * @return array<string, mixed>
     */
    private function slotFont(string $slot, RenderContext $context): array
    {
        if ($context->inQuote() && in_array($slot, [Styles::PARAGRAPH, Styles::LIST_PARAGRAPH], true)) {
            $slot = Styles::BLOCK_QUOTE;
        }

        return ParagraphStyle::fontPart($this->styles->slot($slot));
    }

    private function renderThematicBreak(AbstractContainer $target, RenderContext $context): void
    {
        $style = $this->styles->paragraphStyleFor(Styles::THEMATIC_BREAK);

        if ($this->config->getOptions()->thematicBreak !== 'text' && $style === null) {
            // Word has no horizontal rule element, so a rule is a paragraph with
            // a bottom border.
            $style = [
                'borderBottomStyle' => 'single',
                'borderBottomSize' => 6,
                'borderBottomColor' => 'auto',
                'space' => ['before' => 120, 'after' => 120],
            ];
        }

        $run = $target->addTextRun($this->applyContext($style, $context));
        $run->addText($this->config->getOptions()->thematicBreak === 'text' ? str_repeat('-', 40) : '');
    }

    private function renderCodeBlock(FencedCode|IndentedCode $node, AbstractContainer $target, RenderContext $context): void
    {
        $literal = rtrim($node->getLiteral(), "\n");
        if ($literal === '') {
            return;
        }

        $paragraphStyle = $this->codeBlockStyle($context);

        // A code block's own font slot wins over the code span font, so a
        // configured `codeBlock` style can change the typeface of the block.
        $font = array_merge(
            (array) $this->styles->fontFor(new InlineStyle(code: true)),
            ParagraphStyle::fontPart($this->styles->slot(Styles::CODE_BLOCK)),
        );

        foreach (explode("\n", $literal) as $line) {
            $run = $target->addTextRun($paragraphStyle);
            $run->addText($line, $font);
        }
    }

    /**
     * The code block's slot with the built-in background layered on top of it.
     *
     * The background is not in the default slot, because `codeBlockShading` is the
     * switch that decides whether a code block has one at all, and a slot that
     * brings its own keeps it: the option says whether, not which colour.
     *
     * @return array<string, mixed>|string|null
     */
    private function codeBlockStyle(RenderContext $context): array|string|null
    {
        $style = $this->blockStyle(Styles::CODE_BLOCK, $context);

        if (!$this->config->getOptions()->codeBlockShading || $this->hasShading($style)) {
            return $style;
        }

        return ParagraphStyle::merge($style, [
            'shading' => ['fill' => LookAndFeel::CODE_BACKGROUND],
            'space' => ['before' => 0, 'after' => 0],
        ]);
    }

    /**
     * @param array<string, mixed>|string|null $style
     */
    private function hasShading(array|string|null $style): bool
    {
        return is_array($style) && ($style['shading']['fill'] ?? '') !== '';
    }

    private function renderList(ListBlock $node, AbstractContainer $target, RenderContext $context): void
    {
        $data = $node->getListData();
        $ordered = $data->type === ListBlock::TYPE_ORDERED;
        $styleName = $this->numbering->styleFor($ordered, $data->start, $data->delimiter);

        $this->renderListChildren($node, $target, $context, $styleName, $node->isTight());
    }

    private function renderListChildren(
        ListBlock $list,
        AbstractContainer $target,
        RenderContext $context,
        string $styleName,
        bool $tight,
    ): void {
        foreach ($list->children() as $item) {
            if (!$item instanceof ListItem) {
                continue;
            }
            $this->renderListItem($item, $target, $context, $styleName, $tight);
        }
    }

    private function renderListItem(
        ListItem $item,
        AbstractContainer $target,
        RenderContext $context,
        string $styleName,
        bool $tight,
    ): void {
        $depth = $context->listDepth;
        $paragraphStyle = $this->listParagraphStyle($tight, $context);
        $itemFont = $this->slotFont(Styles::LIST_PARAGRAPH, $context);
        $rendered = 0;

        foreach ($item->children() as $child) {
            if ($child instanceof ListBlock) {
                $nestedData = $child->getListData();
                $nestedStyle = $this->numbering->styleFor(
                    $nestedData->type === ListBlock::TYPE_ORDERED,
                    $nestedData->start,
                    $nestedData->delimiter,
                );
                $this->renderListChildren(
                    $child,
                    $target,
                    $context->enterList($depth + 1),
                    $nestedStyle,
                    $child->isTight(),
                );
                continue;
            }

            if ($child instanceof Paragraph) {
                $itemRun = $target->addListItemRun($depth, $styleName, $paragraphStyle);
                $this->inlines->render($child->children(), $itemRun, new InlineStyle(forcedFont: $itemFont));
                $rendered++;
                continue;
            }

            // A code block, quote or table inside a list item cannot be a Word
            // list item, so it is emitted as a normal block right after it.
            if ($child instanceof AbstractBlock) {
                $this->renderNode($child, $target, $context);
                $rendered++;
            }
        }

        // `- ` with no content still has to produce a visible bullet.
        if ($rendered === 0) {
            $target->addListItemRun($depth, $styleName, $paragraphStyle);
        }
    }

    /**
     * A list inside a block quote belongs to the quote, so it is indented by the
     * quote's depth. The quote's own named style cannot simply be reused: Word
     * resolves a named style wholesale, while a list item needs an indentation of
     * its own for the list level, so the offset goes on as an inline style. The
     * name goes alongside it, because the item's runs already carry the quote's
     * character formatting and a paragraph that does not say where that came from
     * reads back as emphasis the author typed.
     *
     * @return string|array|WordParagraph|null
     */
    private function listParagraphStyle(bool $tight, RenderContext $context): string|array|WordParagraph|null
    {
        $style = ParagraphStyle::paragraphPart($this->styles->slot(Styles::LIST_PARAGRAPH));

        if ($tight) {
            $style = ParagraphStyle::merge($style, ['space' => ['before' => 0, 'after' => 0]]);
        }

        if (!$context->inQuote()) {
            return $style;
        }

        $offset = ['indentation' => ['left' => self::QUOTE_INDENT * $context->quoteDepth]]
            + ParagraphStyle::styleNameOf($this->styles->slot(Styles::BLOCK_QUOTE));

        return is_string($style) ? $offset : ParagraphStyle::merge($style, $offset);
    }

    private function renderTable(MarkdownTable $node, AbstractContainer $target, RenderContext $context): void
    {
        $options = $this->config->getOptions();
        $tableStyle = $this->styles->slot(Styles::TABLE);

        $table = match (true) {
            $tableStyle === null => $target->addTable($this->defaultTableStyle($options)),
            // A named table style comes from the target document — typically a
            // Word built-in grid — and is used exactly as the template defines it.
            is_string($tableStyle) => $target->addTable($tableStyle),
            // A custom definition still gets the configured width, so that
            // styling a table does not quietly make it narrow again.
            default => $target->addTable(ParagraphStyle::table(
                ParagraphStyle::merge($this->widthStyle($options), $tableStyle),
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
     */
    private function renderCellContent(
        TableCell $cell,
        AbstractContainer $tableCell,
        RenderContext $context,
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
                $this->renderNode($child, $tableCell, $context);
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

    private function renderHtml(Node $node, AbstractContainer $target, RenderContext $context): void
    {
        $run = $target->addTextRun($this->blockStyle(Styles::HTML_FALLBACK, $context));

        $this->html->render($this->literalOf($node), $run);
    }

    private function literalOf(Node $node): string
    {
        if ($node instanceof Text || $node instanceof HtmlBlock || $node instanceof HtmlInline) {
            return $node->getLiteral();
        }

        $text = '';
        foreach ($node->children() as $child) {
            $text .= $this->literalOf($child);
        }

        return $text;
    }

    /**
     * Inside a block quote an ordinary paragraph is drawn with the quote style
     * instead, which is what makes quoted text look quoted without the Markdown
     * author having to say so.
     *
     * @return string|array|WordParagraph|null
     */
    private function blockStyle(string $slot, RenderContext $context): string|array|WordParagraph|null
    {
        // Any level of block quote is drawn with the quote style; only the
        // indentation changes with depth.
        $style = $slot === Styles::PARAGRAPH && $context->inQuote()
            ? $this->styles->paragraphStyleFor(Styles::BLOCK_QUOTE)
            : $this->styles->slot($slot);

        $style = ParagraphStyle::paragraphPart($style);

        return $this->applyContext($style, $context);
    }

    /**
     * A quoted paragraph is indented by {@see self::QUOTE_INDENT} for each level
     * of nesting, so a quote inside a quote visibly steps in. The outermost level
     * uses a configured named style verbatim, because that style already carries
     * its own indentation.
     *
     * @param  string|array|WordParagraph|null  $style
     * @return string|array|WordParagraph|null
     */
    private function applyContext(string|array|WordParagraph|null $style, RenderContext $context): string|array|WordParagraph|null
    {
        if (!$context->inQuote()) {
            return $style;
        }

        $indent = ['indentation' => ['left' => self::QUOTE_INDENT * $context->quoteDepth]];

        if (is_string($style)) {
            return $context->quoteDepth === 1
                ? $style
                : ParagraphStyle::namedWith($style, $indent);
        }

        return ParagraphStyle::merge($style, $indent);
    }
}
