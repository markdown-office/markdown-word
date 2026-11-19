<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

/**
 * Joins the paragraphs that stand for a block of code.
 *
 * A run of them in a monospaced face is a block of code in everything but name,
 * and this is where such a run is found.
 */
final class CodeBlockGrouper
{
    public function __construct(
        private readonly Options $options,
    ) {
    }

    /**
     * Join runs of monospaced paragraphs into a single verbatim block.
     *
     * Word has no code block, so one becomes a paragraph per line in a
     * monospaced face. A run of two or more is a block; a single paragraph is
     * left alone, because a paragraph holding only an inline code span looks
     * exactly the same and is far more common.
     *
     * @param list<Block> $units
     * @return list<Block>
     */
    public function group(array $units): array
    {
        if (!$this->options->fenceCodeBlocks) {
            return $units;
        }

        $result = [];
        $pending = [];

        foreach ($units as $unit) {
            if ($this->isMonospaceParagraph($unit)) {
                $pending[] = $unit;

                continue;
            }

            $result = array_merge($result, $this->flushCodeBlock($pending));
            $pending = [];
            $result[] = $unit;
        }

        return array_merge($result, $this->flushCodeBlock($pending));
    }

    /**
     * @param list<Block> $pending
     * @return list<Block>
     */
    private function flushCodeBlock(array $pending): array
    {
        if (count($pending) < 2) {
            return $pending;
        }

        $lines = [];
        foreach ($pending as $unit) {
            $lines[] = $this->plainText($unit->inlines);
        }

        // The indentation of the first line rides along, for the same reason a
        // rule's does: a code block inside a block quote has to stay inside it.
        return [Block::code(implode("\n", $lines))->withAttrs([
            'indent' => (int) ($pending[0]->attr('indent', 0)),
        ])];
    }

    private function isMonospaceParagraph(Block $unit): bool
    {
        if (
            !$unit->is(Block::PARAGRAPH)
            || $unit->attr('level') !== null
            || $unit->inlines === []
        ) {
            return false;
        }

        foreach ($unit->inlines as $inline) {
            if ($inline->kind !== Inline::TEXT || !$inline->code) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Inline> $inlines
     */
    private function plainText(array $inlines): string
    {
        $text = '';

        foreach ($inlines as $inline) {
            $text .= $inline->kind === Inline::TEXT ? $inline->text : "\n";
        }

        return $text;
    }
}
