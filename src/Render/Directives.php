<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use MarkdownOffice\CommonMark\Directive\Node\DirectiveBlock;
use MarkdownOffice\CommonMark\Directive\Node\DirectiveInline;
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
 */
final class Directives
{
    /** @var array<string, callable(DirectiveBlock, AbstractContainer, RenderContext): void> */
    private readonly array $block;

    /** @var array<string, callable(DirectiveInline, AbstractContainer, InlineStyle): void> */
    private readonly array $inline;

    /**
     * @param array<string, callable(DirectiveBlock, AbstractContainer, RenderContext): void> $block
     * @param array<string, callable(DirectiveInline, AbstractContainer, InlineStyle): void> $inline
     */
    public function __construct(array $block = [], array $inline = [])
    {
        $this->block = self::fold($block);
        $this->inline = self::fold($inline);
    }

    /**
     * @param callable(DirectiveBlock, AbstractContainer, RenderContext): void $draw
     */
    public function withBlock(string $name, callable $draw): self
    {
        return new self([...$this->block, self::foldName($name) => $draw], $this->inline);
    }

    /**
     * @param callable(DirectiveInline, AbstractContainer, InlineStyle): void $draw
     */
    public function withInline(string $name, callable $draw): self
    {
        return new self($this->block, [...$this->inline, self::foldName($name) => $draw]);
    }

    public function drawsBlock(DirectiveBlock $node): bool
    {
        return $node->getName() !== null && isset($this->block[self::foldName($node->getName())]);
    }

    public function drawsInline(DirectiveInline $node): bool
    {
        return isset($this->inline[self::foldName($node->getName())]);
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
        $name = $node->getName();

        if ($name === null || !isset($this->block[self::foldName($name)])) {
            return false;
        }

        ($this->block[self::foldName($name)])($node, $target, $context);

        return true;
    }

    public function drawInline(DirectiveInline $node, AbstractContainer $target, InlineStyle $style): bool
    {
        $name = self::foldName($node->getName());

        if (!isset($this->inline[$name])) {
            return false;
        }

        ($this->inline[$name])($node, $target, $style);

        return true;
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

    /**
     * The names this library draws, for a message that has to name them.
     *
     * @return list<string>
     */
    public function blockNames(): array
    {
        return array_keys($this->block);
    }

    /**
     * @return list<string>
     */
    public function inlineNames(): array
    {
        return array_keys($this->inline);
    }
}