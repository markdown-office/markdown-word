<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Inline\AbstractStringContainer;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use MarkdownOffice\CommonMark\Directive\Node\DirectiveInline;
use MarkdownWord\Configuration\Options;
use PhpOffice\PhpWord\Element\AbstractContainer;

/**
 * Renders inline nodes into a PHPWord container.
 *
 * Every inline construct is handled by carrying an {@see InlineStyle} down the
 * tree and emitting one Word run per text node. Because the state is inherited
 * rather than matched with regular expressions, arbitrarily nested markup such
 * as `**_both_ and `code`**` renders exactly as written.
 */
final class InlineRenderer
{
    public function __construct(
        private readonly StyleResolver $styles,
        private readonly ImageResolver $images,
        private readonly LinkPayloadCollector $links,
        private readonly HtmlFragmentRenderer $html,
        private readonly bool $deferredHyperlinks = false,
        private readonly Directives $directives = new Directives(),
    ) {
    }

    /**
     * @param iterable<Node>                                  $inlines
     * @param callable():AbstractContainer|null               $onParagraphBreak Invoked when
     *        `softBreak => 'paragraph'` is configured; it must start and return a new
     *        container for the following runs.
     */
    public function render(
        iterable $inlines,
        AbstractContainer $target,
        InlineStyle $style = new InlineStyle(),
        ?callable $onParagraphBreak = null,
    ): AbstractContainer {
        $current = $target;

        foreach ($inlines as $inline) {
            $current = $this->renderNode($inline, $current, $style, $onParagraphBreak);
        }

        return $current;
    }

    /**
     * @param  callable():AbstractContainer|null  $onParagraphBreak
     */
    private function renderNode(
        Node $node,
        AbstractContainer $target,
        InlineStyle $style,
        ?callable $onParagraphBreak,
    ): AbstractContainer {
        return match (true) {
            // Text carries the literal characters; entity references and backslash
            // escapes have already been resolved by the parser.
            $node instanceof Text => $this->renderText($node, $target, $style),

            // One node type for both break kinds.
            $node instanceof Newline => $node->getType() === Newline::HARDBREAK
                ? $this->renderHardBreak($target, $style, $onParagraphBreak)
                : $this->renderSoftBreak($target, $style, $onParagraphBreak),

            $node instanceof Document => $this->render($node->children(), $target, $style, $onParagraphBreak),

            $node instanceof DirectiveInline => $this->renderDirective($node, $target, $style),

            $node instanceof Strong => $this->render($node->children(), $target, $style->withBold(), $onParagraphBreak),

            $node instanceof Emphasis => $this->render($node->children(), $target, $style->withItalic(), $onParagraphBreak),

            $node instanceof Strikethrough => $this->render($node->children(), $target, $style->withStrikethrough(), $onParagraphBreak),

            $node instanceof Code => $this->renderCode($node, $target, $style),

            $node instanceof Link => $this->renderLink($node, $target, $style, $onParagraphBreak),

            $node instanceof Image => $this->renderImage($node, $target, $style),

            $node instanceof TaskListItemMarker => $this->renderTaskMarker($node, $target, $style),

            $node instanceof HtmlInline => $this->renderHtml($node, $target, $style),

            $node instanceof AbstractStringContainer => $this->renderText($node, $target, $style),

            // Unknown inline nodes (footnote references, mentions, embeds, ...) still
            // contribute their children, so no text is silently lost.
            default => $node->hasChildren()
                ? $this->render($node->children(), $target, $style, $onParagraphBreak)
                : $target,
        };
    }

    private function renderText(AbstractStringContainer $node, AbstractContainer $target, InlineStyle $style): AbstractContainer
    {
        $this->addText($node->getLiteral(), $target, $style);

        return $target;
    }

    private function renderCode(Code $node, AbstractContainer $target, InlineStyle $style): AbstractContainer
    {
        // Already stripped of its delimiters by the parser, including the two
        // normalisations the spec does to a code span's contents, so nothing here
        // may collapse what is left.
        $this->addCode($node->getLiteral(), $target, $style);

        return $target;
    }

    private function renderTaskMarker(TaskListItemMarker $node, AbstractContainer $target, InlineStyle $style): AbstractContainer
    {
        // No trailing space: the text after the marker in the source already
        // carries one, and a second would leave the two sitting apart.
        $this->addText($node->isChecked() ? "\u{2612}" : "\u{2610}", $target, $style);

        return $target;
    }

    private function renderHtml(HtmlInline $node, AbstractContainer $target, InlineStyle $style): AbstractContainer
    {
        // The block layer, which knows the configured HTML mode.
        $this->html->renderInline($node->getLiteral(), $target, $style);

        return $target;
    }

    private function renderDirective(DirectiveInline $node, AbstractContainer $target, InlineStyle $style): AbstractContainer
    {
        if ($this->directives->drawInline($node, $target, $style, $this->drawLabel(...))) {
            return $target;
        }

        $this->renderUnclaimedDirective($node, $target, $style);

        return $target;
    }

    /**
     * Draw the runs of a directive's label, for a handler that draws the label rather
     * than its source text.
     *
     * A label is parsed as inline Markdown, so a directive may hold several runs and
     * only the renderer knows how to draw them.
     *
     * @param callable(iterable<Node>, AbstractContainer, InlineStyle): void
     */
    private function drawLabel(iterable $nodes, AbstractContainer $target, InlineStyle $style): void
    {
        $this->render($nodes, $target, $style);
    }

    /**
     * A directive name nothing claims, drawn as the author wrote it.
     *
     * Its label is a child, so without this the fallthrough above would draw the
     * label and drop the rest — which is what happened before any name was
     * registered, and it loses prose the moment directives are the default:
     * `Status :green[go]` would come out as `Status go`, with the `:green[` gone
     * and nothing to say where it went. `__toString()` round-trips to the source
     * syntax, so drawing the node gives back the characters as typed.
     */
    private function renderUnclaimedDirective(DirectiveInline $node, AbstractContainer $target, InlineStyle $style): void
    {
        $this->addText((string) $node, $target, $style);
    }

    private function renderLink(
        Link $node,
        AbstractContainer $target,
        InlineStyle $style,
        ?callable $onParagraphBreak = null,
    ): AbstractContainer {
        $url = trim($node->getUrl());
        $title = $node->getTitle();
        $linked = $style->withLink($url, $title);

        if ($url === '') {
            // `<>` or an unresolvable reference is not a hyperlink; render the
            // label alone.
            return $this->render($node->children(), $target, $style, $onParagraphBreak);
        }

        // A link whose body is a bare image, as in `[![logo](logo.png)](url)`,
        // is a hyperlink around a drawing rather than around text. Taking this
        // branch is the whole of the decision: `ImageResolver` clears the link
        // style on `isLink()`, so nothing needs to be told about it.
        if ($this->isOnlyImages($node)) {
            return $this->render(
                $node->children(),
                $target,
                $linked,
                $onParagraphBreak,
            );
        }

        return $this->renderLinkLabel($node, $target, $linked, $url, $title);
    }

    /**
     * The label of a link: either text PHPWord can link itself, or runs the
     * writer has to wrap in a hyperlink afterwards.
     */
    private function renderLinkLabel(
        Link $node,
        AbstractContainer $target,
        InlineStyle $linked,
        string $url,
        ?string $title,
    ): AbstractContainer {
        // A plain-text label is expressible with PHPWord's own Link element, which
        // produces a real w:hyperlink — unless the elements are destined for
        // another document, where the relationship it refers to would not travel
        // with them, or unless it has a title, which that element has nowhere to
        // put. Both go through the writer instead.
        if (!$this->hasFormatting($node) && !$this->deferredHyperlinks && ($title === null || $title === '')) {
            $label = $this->collectText($node);
            if ($label !== '') {
                $target->addLink($url, $label, $this->styles->fontFor($linked), null, $this->styles->isInternalLink($url));
            }

            return $target;
        }

        // Otherwise the label contains emphasis. Word models that as a
        // w:hyperlink wrapping several runs, which PHPWord cannot express, so the
        // runs are collected and swapped in by the writer afterwards. The
        // placeholder stands in for the link's text, so the element tree still
        // reports the document's real content in the meantime.
        return $this->links->render($node, $target, $linked);
    }

    private function renderImage(Image $node, AbstractContainer $target, InlineStyle $style): AbstractContainer
    {
        $this->images->render($node, $target, $this->collectText($node), $style);

        return $target;
    }

    /**
     * One the author asked for, so a real Word line break by default rather than
     * a collapsed space.
     *
     * @param  callable():AbstractContainer|null  $onParagraphBreak
     */
    private function renderHardBreak(
        AbstractContainer $target,
        InlineStyle $style,
        ?callable $onParagraphBreak,
    ): AbstractContainer {
        $mode = $this->styles->hardBreakMode();

        if ($mode === Options::BREAK_PARAGRAPH && $onParagraphBreak !== null) {
            return $onParagraphBreak();
        }

        if ($mode !== Options::BREAK_REMOVE) {
            $target->addTextBreak(1, $this->styles->fontFor($style));

            return $target;
        }

        $this->addText(' ', $target, $style);

        return $target;
    }

    /**
     * Just a source line ending, which a browser collapses to a space — so that is
     * the spec-faithful default.
     *
     * @param  callable():AbstractContainer|null  $onParagraphBreak
     */
    private function renderSoftBreak(
        AbstractContainer $target,
        InlineStyle $style,
        ?callable $onParagraphBreak,
    ): AbstractContainer {
        $mode = $this->styles->softBreakMode();

        if ($mode === Options::SOFT_BREAK_LINE) {
            $target->addTextBreak(1, $this->styles->fontFor($style));

            return $target;
        }

        if ($mode === Options::SOFT_BREAK_PARAGRAPH && $onParagraphBreak !== null) {
            return $onParagraphBreak();
        }

        $this->addText(' ', $target, $style);

        return $target;
    }

    /**
     * Any whitespace inside the literal is collapsed, because a browser collapses
     * it and so must the document. This matters for content that only looks like
     * ordinary text, such as `foo&#10;&#10;bar`, where the entity decodes to real
     * newlines but is displayed as a space.
     *
     * Code blocks never pass through here, so their whitespace is untouched.
     */
    private function addText(string $text, AbstractContainer $target, InlineStyle $style): void
    {
        $text = (string) preg_replace('/[ \t\r\n]+/u', ' ', $text);

        if ($text === '') {
            return;
        }

        $target->addText($text, $this->styles->fontFor($style));
    }

    /**
     * Text whose whitespace is content, so none of it is collapsed.
     *
     * A code span keeps its spaces. CommonMark normalises exactly two things in one —
     * line endings become spaces, and one space is taken off each end when both ends
     * have one and the string is not all spaces — and {@see Code} arrives having
     * already had both done to it by the parser. Collapsing what is left would change
     * what the code says: `` `a   b` `` is three spaces and came out as one, which
     * makes `foo(  bar)` and `foo( bar)` the same line of code and the second one
     * usually a bug the reader is looking for.
     *
     * The all-spaces case is worth stating, because it is the one place the two rules
     * disagree: `` ` ` `` is a code span holding a single space, and it is not empty.
     */
    private function addCode(string $text, AbstractContainer $target, InlineStyle $style): void
    {
        $target->addText($text, $this->styles->fontFor($style->withCode()));
    }

    private function isOnlyImages(Node $node): bool
    {
        $hasImage = false;

        foreach ($node->children() as $child) {
            if ($child instanceof Image) {
                $hasImage = true;
                continue;
            }

            // Whitespace between images is not content.
            if ($child instanceof Text && trim($child->getLiteral()) === '') {
                continue;
            }

            if ($child instanceof Strong || $child instanceof Emphasis || $child instanceof Strikethrough) {
                if (!$this->isOnlyImages($child)) {
                    return false;
                }
                $hasImage = true;
                continue;
            }

            return false;
        }

        return $hasImage;
    }

    private function hasFormatting(Node $node): bool
    {
        foreach ($node->children() as $child) {
            if ($child instanceof Strong
                || $child instanceof Emphasis
                || $child instanceof Strikethrough
                || $child instanceof Code
            ) {
                return true;
            }

            if ($this->hasFormatting($child)) {
                return true;
            }
        }

        return false;
    }

    private function collectText(Node $node): string
    {
        $text = '';

        foreach ($node->children() as $child) {
            if ($child instanceof Text || $child instanceof Code || $child instanceof HtmlInline) {
                $text .= $child->getLiteral();
            } elseif ($child instanceof Newline) {
                $text .= ' ';
            } elseif ($child->hasChildren()) {
                $text .= $this->collectText($child);
            }
        }

        return $text;
    }
}
