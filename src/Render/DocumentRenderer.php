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
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use MarkdownOffice\CommonMark\Directive\Node\DirectiveBlock;
use MarkdownOffice\CommonMark\Directive\Node\DirectiveInline;
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\LookAndFeel;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Render\Directive\SectionBreak;
use PhpOffice\PhpWord\Element\AbstractContainer;
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

    private readonly TableRenderer $tables;

    public function __construct(
        private readonly Configuration $config,
        private readonly StyleResolver $styles,
        private readonly InlineRenderer $inlines,
        private readonly NumberingRegistry $numbering,
        private readonly HtmlFragmentRenderer $html,
        private readonly Directives $directives = new Directives(),
        private readonly ?SectionBreak $sections = null,
    ) {
        $this->tables = new TableRenderer($config, $styles, $inlines);
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

            // A `::section` starts a new section, and what follows belongs to it.
            // The directive draws the section; this is where the document is told
            // to go on in it.
            $target = $this->sections?->take($target) ?? $target;
        }
    }

    private function renderNode(Node $node, AbstractContainer $target, RenderContext $context): void
    {
        match (true) {
            $node instanceof Heading => $this->renderHeading($node, $target, $context),
            $node instanceof Paragraph => $this->renderParagraph($node, $target, $context),
            $node instanceof ThematicBreak => $this->renderThematicBreak($target, $context),
            $node instanceof FencedCode || $node instanceof IndentedCode => $this->renderCodeBlock($node, $target, $context),
            $node instanceof BlockQuote => $this->renderChildren($node->children(), $target, $context->enterQuote()),
            $node instanceof ListBlock => $this->renderList($node, $target, $context),
            $node instanceof MarkdownTable => $this->tables->render($node, $target, $context, $this->renderNode(...)),
            $node instanceof TableSection => $this->renderChildren($node->children(), $target, $context),
            // Only block-level HTML comes through here. Inline HTML is part of a
            // paragraph and is handled by the inline renderer, which has to splice it
            // into the run rather than start a new one.
            $node instanceof HtmlBlock => $this->renderHtml($node, $target, $context),
            $node instanceof Document => $this->renderChildren($node->children(), $target, $context),
            // A container directive holds Markdown, so a container nothing claims
            // falls through to the same descent every other unhandled block gets —
            // its body is drawn and the fence is not, which is the most a container
            // can mean without a name for what it is.
            $node instanceof DirectiveBlock && $this->directives->drawBlock($node, $target, $context) => null,
            $node->hasChildren() => $this->renderChildren($node->children(), $target, $context),
            default => $this->renderUnclaimedDirective($node, $target, $context),
        };
    }

    /**
     * A directive with a name nothing claims, drawn as the author wrote it.
     *
     * The alternative is to drop it, which is what happened before any name was
     * registered, and it is wrong once directives are on by default: `Status
     * :green[go]` is a sentence, and a parser that silently removed the
     * `:green[` out of it would be changing prose rather than reading it.
     *
     * A leaf has no body, so dropping it loses its label as well and leaves a
     * hole where a paragraph used to be. Drawn as its source form it is at least
     * the line the author can see and go and fix.
     *
     * `__toString()` round-trips to the source syntax, which is why this is
     * three lines rather than a reconstruction of the name, label and attributes.
     */
    private function renderUnclaimedDirective(Node $node, AbstractContainer $target, RenderContext $context): void
    {
        if ($node instanceof DirectiveBlock) {
            $this->emitParagraph([new Text((string) $node)], $target, $context, Styles::PARAGRAPH);

            return;
        }

        if ($node instanceof DirectiveInline) {
            $this->inlines->render([new Text((string) $node)], $target);
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
        $span = $this->styles->fontFor(new InlineStyle(code: true));
        $block = ParagraphStyle::fontPart($this->styles->slot(Styles::CODE_BLOCK));

        // A `codeFont` given as a style *name* is not a set of properties to merge.
        // PHPWord applies a string style to a run as an `rStyle` reference, which is
        // the only way a name can reach a run at all — casting it to an array gives
        // `['MrdCode']`, whose key `0` is not a property, so the name is dropped and
        // the block comes out in the body's typeface. An inline code span takes the
        // same name straight through and works, which is what makes this easy to miss.
        $font = match (true) {
            $block !== [] => array_merge(is_string($span) ? [] : (array) $span, $block),
            is_string($span) => $span,
            default => (array) $span,
        };

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

        if (!$this->config->getOptions()->codeBlockShading || ParagraphStyle::hasShading($style)) {
            return $style;
        }

        return ParagraphStyle::merge($style, [
            'shading' => ['fill' => LookAndFeel::CODE_BACKGROUND],
            'space' => ['before' => 0, 'after' => 0],
        ]);
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
