<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

/**
 * Nests the units that are drawn with a quote style into block quotes.
 *
 * A quote is not marked in the text but drawn: the style says that it is one
 * and the indentation says how deep it is.
 */
final class QuoteGrouper
{
    public function __construct(
        private readonly Options $options,
    ) {
    }

    /**
     * Nest the units that are drawn with a quote style.
     *
     * Depth comes from the indentation divided by {@see Options::$quoteIndent},
     * and only a quote style opens a quote at all — an indented list is a list.
     *
     * A quote ends only when the unit that follows is *shallower* than it, and a
     * unit at a depth that is already open lands in the quote that is already
     * there. Closing a quote at the depth that has just been entered would turn
     * the second paragraph of a two-paragraph quote into a quote of its own, and
     * a block quote spanning two paragraphs is about the most ordinary one there
     * is.
     *
     * @param list<Block> $units
     * @return list<Block>
     */
    public function nest(array $units): array
    {
        $result = [];

        /** @var list<array{depth: int, blocks: list<Block>}> $frames */
        $frames = [];

        foreach ($units as $unit) {
            $depth = $this->quoteDepth($unit);

            if ($depth === null) {
                $result = $this->closeDeeperQuotes($frames, $result, $unit);
            } else {
                // Only frames deeper than this unit's depth close.
                while ($frames !== [] && $frames[count($frames) - 1]['depth'] > $depth) {
                    $result = $this->closeQuote($frames, $result);
                }

                if ($this->openQuoteDepth($frames) !== $depth) {
                    $frames[] = ['depth' => $depth, 'blocks' => []];
                }
            }

            $result = $this->add($frames, $result, $unit);
        }

        while ($frames !== []) {
            $result = $this->closeQuote($frames, $result);
        }

        return $result;
    }

    private function isQuoteStyle(string $style): bool
    {
        foreach ($this->options->quoteStyles as $candidate) {
            if (strcasecmp($style, $candidate) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{depth: int, blocks: list<Block>}> $frames
     */
    private function openQuoteDepth(array $frames): ?int
    {
        return $frames === [] ? null : $frames[count($frames) - 1]['depth'];
    }

    /**
     * @param list<array{depth: int, blocks: list<Block>}> $frames
     * @param list<Block>                                    $result
     * @return list<Block>
     */
    private function closeDeeperQuotes(array &$frames, array $result, Block $unit): array
    {
        $indent = (int) $unit->attr('indent', 0);
        $step = max(1, $this->options->quoteIndent);

        while ($frames !== [] && $indent < $frames[count($frames) - 1]['depth'] * $step) {
            $result = $this->closeQuote($frames, $result);
        }

        return $result;
    }

    private function quoteDepth(Block $unit): ?int
    {
        if (!$unit->is(Block::PARAGRAPH) || !$this->isQuoteStyle((string) $unit->attr('style', ''))) {
            return null;
        }

        $indent = (int) $unit->attr('indent', 0);
        $step = max(1, $this->options->quoteIndent);

        return $indent <= 0 ? 1 : max(1, (int) round($indent / $step));
    }

    /**
     * @param list<array{depth: int, blocks: list<Block>}> $frames
     * @param list<Block>                                    $result
     * @return list<Block>
     */
    private function add(array &$frames, array $result, Block $block): array
    {
        if ($frames === []) {
            $result[] = $block;

            return $result;
        }

        $index = count($frames) - 1;
        $frames[$index]['blocks'][] = $block;

        return $result;
    }

    /**
     * @param list<array{depth: int, blocks: list<Block>}> $frames
     * @param list<Block>                                    $result
     * @return list<Block>
     */
    private function closeQuote(array &$frames, array $result): array
    {
        $closed = array_pop($frames);

        if ($closed === null || $closed['blocks'] === []) {
            return $result;
        }

        return $this->add($frames, $result, Block::quote($closed['blocks']));
    }
}
