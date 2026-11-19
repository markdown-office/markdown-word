<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

/**
 * Turns the paragraphs that carry a numbering reference into nested lists.
 *
 * The nesting is exact rather than guessed at from the indentation, because the
 * document records the level of every list item.
 */
final class ListGrouper
{
    public function __construct(
        private readonly NumberingTable $numbering,
    ) {
    }

    /**
     * Turn units that reference a numbering definition into nested lists.
     *
     * The level comes from the paragraph, so nesting is exact rather than guessed
     * at from indentation. A run of paragraphs sharing a definition at the same
     * level is one list and a different definition starts a new one, which is how
     * two adjacent Markdown lists stay two lists. Two adjacent lists that share a
     * definition cannot be told apart and come back as one.
     *
     * @param list<Block> $units
     * @return list<Block>
     */
    public function group(array $units): array
    {
        $result = [];

        /** @var list<array{numId: int, level: int, indent: int, list: Block, parent: int, item: ?int}> $stack */
        $stack = [];

        foreach ($units as $unit) {
            $numId = $this->listNumberId($unit);

            if ($numId === null) {
                $this->closeLists($stack, $result);
                $result[] = $unit;

                continue;
            }

            $level = (int) $unit->attr('listLevel', 0);
            $indent = (int) $unit->attr('indent', 0);

            // A deeper item, or one belonging to a different list, ends whatever
            // is open.
            $this->closeLists($stack, $result, $numId, $level, $indent);

            if ($this->depth($stack) !== $level) {
                $list = Block::list([], $this->listAttributes($numId, $level) + [
                    // A list inside a block quote is only recorded as an indented
                    // numbered paragraph; the indentation is what keeps it inside
                    // the quote and tells it from the same list outside it.
                    'indent' => $indent,
                    'tight' => (bool) $unit->attr('tight', false),
                ]);

                $stack[] = [
                    'numId' => $numId,
                    'level' => $level,
                    'indent' => $indent,
                    'list' => $list,
                    // Where this list belongs inside the item it is nested in.
                    // Remembered rather than acted on now: the list is still empty
                    // and its parent is still being built.
                    'parent' => $stack === [] ? -1 : count($stack) - 1,
                    'item' => $this->lastItem($stack),
                ];
            }

            $this->appendItem($stack, Block::item([$unit->withAttrs(['numId' => null])]));
        }

        $this->closeLists($stack, $result);

        return $result;
    }

    /**
     * The numbering definition a unit belongs to, or null if it is not a list.
     */
    private function listNumberId(Block $unit): ?int
    {
        if (!$unit->is(Block::PARAGRAPH)) {
            return null;
        }

        $numId = $unit->attr('numId');

        return is_int($numId) ? $numId : null;
    }

    /**
     * How a list's marker is written, as recorded in the numbering definition.
     *
     * @return array<string, mixed>
     */
    private function listAttributes(int $numId, int $level): array
    {
        $definition = $this->numbering->level($numId, $level)
            ?? $this->numbering->root($numId)
            ?? ['format' => 'decimal', 'text' => '%1.', 'start' => 1];

        return [
            'numId' => $numId,
            'level' => $level,
            'ordered' => $definition['format'] !== 'bullet',
            'format' => $definition['format'],
            'start' => $definition['start'],
            // The marker is baked into the numbering text, so its last character
            // is what tells `1.` from `1)`.
            'delimiter' => str_ends_with($definition['text'], ')') ? ')' : '.',
        ];
    }

    /**
     * Close every open list the paragraph that follows ends.
     *
     * A list is identified by its numbering definition, its level and its
     * indentation; the last of those is what separates two lists that share a
     * definition. It is not handed to the document when it opens but when it
     * closes, because it is still growing then. A nested one has already been
     * attached to the item it belongs to; a top-level one lands here.
     *
     * @param list<array{numId: int, level: int, indent: int, list: Block, parent: int, item: ?int}> $stack
     * @param list<Block>                                                                            $result
     */
    private function closeLists(
        array &$stack,
        array &$result,
        ?int $numId = null,
        ?int $level = null,
        ?int $indent = null,
    ): void {
        while ($stack !== []) {
            $top = $stack[count($stack) - 1];

            $ends = $numId === null
                || $top['level'] > $level
                || ($top['level'] === $level && ($top['numId'] !== $numId || $top['indent'] !== $indent));

            if (!$ends) {
                break;
            }

            $closed = array_pop($stack);

            if ($closed['parent'] < 0) {
                $result[] = $closed['list'];

                continue;
            }

            // A nested list goes into the item it belongs under. That item is
            // still the last one of its list, because a sibling at the same level
            // would have closed this list before it was reached.
            $index = $closed['parent'];
            $items = array_values($stack[$index]['list']->children);
            $item = $closed['item'];

            if ($item !== null && isset($items[$item])) {
                $items[$item] = $items[$item]->withChildren([...$items[$item]->children, $closed['list']]);
                $stack[$index]['list'] = $stack[$index]['list']->withChildren($items);
            }
        }
    }

    /**
     * @param list<array{numId: int, level: int, indent: int, list: Block, parent: int, item: ?int}> $stack
     */
    private function lastItem(array $stack): ?int
    {
        if ($stack === []) {
            return null;
        }

        $items = array_values($stack[count($stack) - 1]['list']->children);

        return $items === [] ? null : array_key_last($items);
    }

    /**
     * @param list<array{numId: int, level: int, indent: int, list: Block, parent: int, item: ?int}> $stack
     */
    private function appendItem(array &$stack, Block $item): void
    {
        $index = count($stack) - 1;
        $stack[$index]['list'] = $stack[$index]['list']->withChildren(
            [...$stack[$index]['list']->children, $item],
        );
    }

    /**
     * @param list<array{numId: int, level: int, indent: int, list: Block, parent: int, item: ?int}> $stack
     */
    private function depth(array $stack): ?int
    {
        return $stack === [] ? null : $stack[count($stack) - 1]['level'];
    }
}
