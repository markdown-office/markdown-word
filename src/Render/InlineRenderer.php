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
        // Text carries the literal characters; entity references and backslash
        // escapes have already been resolved by the parser.
        if ($node instanceof Text) {
            $this->addText($node->getLiteral(), $target, $style);

            return $target;
        }

        // One node type for both break kinds.
        if ($node instanceof Newline) {
            return $node->getType() === Newline::HARDBREAK
                ? $this->renderHardBreak($target, $style, $onParagraphBreak)
                : $this->renderSoftBreak($target, $style, $onParagraphBreak);
        }

        if ($node instanceof Document) {
            return $this->render($node->children(), $target, $style, $onParagraphBreak);
        }

        if ($node instanceof Strong) {
            return $this->render($node->children(), $target, $style->withBold(), $onParagraphBreak);
        }

        if ($node instanceof Emphasis) {
            return $this->render($node->children(), $target, $style->withItalic(), $onParagraphBreak);
        }

        if ($node instanceof Strikethrough) {
            return $this->render($node->children(), $target, $style->withStrikethrough(), $onParagraphBreak);
        }

        if ($node instanceof Code) {
            // Already stripped of its delimiters by the parser, including the two
            // normalisations the spec does to a code span's contents, so nothing here
            // may collapse what is left.
            $this->addCode($node->getLiteral(), $target, $style);

            return $target;
        }

        if ($node instanceof Link) {
            return $this->renderLink($node, $target, $style, $onParagraphBreak);
        }

        if ($node instanceof Image) {
            $this->renderImage($node, $target, $style);

            return $target;
        }

        if ($node instanceof TaskListItemMarker) {
            // No trailing space: the text after the marker in the source already
            // carries one, and a second would leave the two sitting apart.
            $this->addText($node->isChecked() ? "\u{2612}" : "\u{2610}", $target, $style);

            return $target;
        }

        if ($node instanceof HtmlInline) {
            // The block layer, which knows the configured HTML mode.
            $this->html->renderInline($node->getLiteral(), $target, $style);

            return $target;
        }

        if ($node instanceof AbstractStringContainer) {
            $this->addText($node->getLiteral(), $target, $style);

            return $target;
        }

        // Unknown inline nodes (footnote references, mentions, embeds, ...) still
        // contribute their children, so no text is silently lost.
        if ($node->hasChildren()) {
            return $this->render($node->children(), $target, $style, $onParagraphBreak);
        }

        return $target;
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

    private function renderImage(Image $node, AbstractContainer $target, InlineStyle $style): void
    {
        $this->images->render($node, $target, $this->collectText($node), $style);
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
        switch ($this->styles->softBreakMode()) {
            case Options::SOFT_BREAK_LINE:
                $target->addTextBreak(1, $this->styles->fontFor($style));

                return $target;

            case Options::SOFT_BREAK_PARAGRAPH:
                if ($onParagraphBreak !== null) {
                    return $onParagraphBreak();
                }
                $this->addText(' ', $target, $style);

                return $target;

            default:
                $this->addText(' ', $target, $style);

                return $target;
        }
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

            if ($child instanceof Text) {
                // Whitespace between images is not content.
                if (trim($child->getLiteral()) !== '') {
                    return false;
                }
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
