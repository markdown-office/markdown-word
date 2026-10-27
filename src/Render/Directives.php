<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use MarkdownOffice\CommonMark\Directive\Node\DirectiveBlock;
use MarkdownOffice\CommonMark\Directive\Node\DirectiveInline;
use MarkdownWord\Configuration\TextStyles;
use MarkdownWord\Render\Directive\Footnote;
use MarkdownWord\Render\Directive\TextStyle;
use MarkdownWord\Render\Directive\Toc;
use PhpOffice\PhpWord\Element\AbstractContainer;

/**
 * Which directive names this library draws, and what it draws them as.
 *
 * The Generic Directives RFC describes no vocabulary: `::anything` is a
 * directive, and what one *means* is for whoever consumes it to decide. So the
 * names live here rather than in the parser, and a name that is not here is not
 * a mistake — it is a word the author used.
 *
 * The two maps are separate because the shapes are separate. `::toc` is a block
 * and `:footnote` is inline, and a handler that implements both would carry a
 * method it can never be called through. A callable that never fires is a
 * method nobody tests, which is worse than no method.
 *
 * Names are matched case-insensitively, because a document cannot be asked to
 * care about case and `::TOC` is not a different directive from `::toc`.
 *
 * A theme's text styles are names too, and they are not fixed: they are the
 * ones the theme's `styles.yml` brings, so they are held apart from the two
 * maps rather than written into them. The handler that draws them is asked
 * whether a name is its own before anything else happens, which is what lets a
 * name that is not a style be drawn as the source form it was written as.
 */
final class Directives
{
    /** The block directive this library draws. */
    public const TOC = 'toc';

    /** The inline directive this library draws. */
    public const FOOTNOTE = 'footnote';

    /** @var array<string, callable(DirectiveBlock, AbstractContainer, RenderContext): void> */
    private readonly array $block;

    /** @var array<string, callable(DirectiveInline, AbstractContainer, InlineStyle): void> */
    private readonly array $inline;

    /**
     * @param array<string, callable(DirectiveBlock, AbstractContainer, RenderContext): void> $block
     * @param array<string, callable(DirectiveInline, AbstractContainer, InlineStyle): void> $inline
     * @param TextStyle|null $textStyle The inline styles, whose names a theme brings.
     */
    public function __construct(
        array $block = [],
        array $inline = [],
        private readonly ?TextStyle $textStyle = null,
    ) {
        $this->block = self::fold($block);
        $this->inline = self::fold($inline);
    }

    /**
     * The registry this library draws with.
     *
     * The handlers are the caller's: a table of contents and a footnote need the
     * document's styles and the format it is written in, and neither is known here.
     */
    public static function standard(Toc $toc, Footnote $footnote, ?TextStyle $textStyle = null): self
    {
        return (new self(textStyle: $textStyle))
            ->withBlock(self::TOC, $toc)
            ->withInline(self::FOOTNOTE, $footnote);
    }

    /**
     * The names this library draws.
     *
     * A theme's text styles are not on this list: a theme names its own, and a
     * list that changed with the theme would be a list nobody could check.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return [self::TOC, self::FOOTNOTE, TextStyles::RESERVED];
    }

    /**
     * The names this registry draws.
     *
     * @return list<string>
     */
    public function draws(): array
    {
        return [
            ...array_keys($this->block),
            ...array_keys($this->inline),
            ...($this->textStyle?->names() ?? []),
        ];
    }

    /**
     * @param callable(DirectiveBlock, AbstractContainer, RenderContext): void $draw
     */
    public function withBlock(string $name, callable $draw): self
    {
        return new self([...$this->block, self::foldName($name) => $draw], $this->inline, $this->textStyle);
    }

    /**
     * @param callable(DirectiveInline, AbstractContainer, InlineStyle): void $draw
     */
    public function withInline(string $name, callable $draw): self
    {
        return new self(
            $this->block,
            [...$this->inline, self::foldName($name) => $draw],
            $this->textStyle,
        );
    }

    public function drawsBlock(DirectiveBlock $node): bool
    {
        return self::claims($node, $this->block);
    }

    public function drawsInline(DirectiveInline $node): bool
    {
        $name = self::foldName($node->getName());

        return isset($this->inline[$name])
            || ($this->textStyle !== null && $this->textStyle->claims($node));
    }

    /**
     * Draw a block directive, or report that nothing claimed it.
     *
     * A name may be null — a container written `::: {.note}` has none — and
     * there is then nothing to look up, which is the same answer as an unknown
     * name and is returned rather than raised.
     */
    public function drawBlock(DirectiveBlock $node, AbstractContainer $target, RenderContext $context): bool
    {
        if (!self::claims($node, $this->block)) {
            return false;
        }

        ($this->block[self::foldName((string) $node->getName())])($node, $target, $context);

        return true;
    }

    /**
     * Whether a block directive is one of ours.
     *
     * The name is not enough on its own. A container and a leaf are different
     * directive shapes rather than two spellings of one, so `:::toc` is not `::toc`
     * written with a fence too many — it is a container named `toc`, and this library
     * draws no containers. Matching on the name claimed it anyway, and the handler
     * drew a table of contents and stopped: the body went with it, and the reader was
     * told nothing. `Renderer\DocumentRenderer` descends into a container nothing
     * claims and draws the body with the fence dropped, which is what this leaves
     * `:::toc` to.
     *
     * @param array<string, callable(DirectiveBlock, AbstractContainer, RenderContext): void> $claimed
     */
    private static function claims(DirectiveBlock $node, array $claimed): bool
    {
        $name = $node->getName();

        return $name !== null
            && !$node->isContainer()
            && isset($claimed[self::foldName($name)]);
    }

    public function drawInline(DirectiveInline $node, AbstractContainer $target, InlineStyle $style): bool
    {
        $name = self::foldName($node->getName());

        if (isset($this->inline[$name])) {
            ($this->inline[$name])($node, $target, $style);

            return true;
        }

        // A theme's text style is asked whether it can draw this before anything
        // else happens, so that a name it cannot honour comes out as the source
        // form — the same answer a name nothing claims gets.
        if ($this->textStyle !== null && $this->textStyle->draw($node, $target, $style)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, callable>  $handlers
     * @return array<string, callable>
     */
    private static function fold(array $handlers): array
    {
        $folded = [];

        foreach ($handlers as $name => $handler) {
            $folded[self::foldName($name)] = $handler;
        }

        return $folded;
    }

    /**
     * `trim()` as well as lowercasing, because the RFC allows `:: toc` with a
     * space and a reader would not think those were two different directives.
     */
    private static function foldName(string $name): string
    {
        return strtolower(trim($name));
    }
}
