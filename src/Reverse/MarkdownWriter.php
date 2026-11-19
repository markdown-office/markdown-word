<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

/**
 * Turns the block tree back into Markdown.
 *
 * Nothing here throws: every construct the writer can be handed has a string it
 * can be written as. The exceptions a conversion raises come from the package
 * around it — {@see \MarkdownWord\Exception\UnreadableDocument} for bytes that
 * are not a zip archive, {@see \MarkdownWord\Exception\MalformedDocument} for a
 * required part that is missing or will not parse, and
 * {@see \MarkdownWord\Exception\FileNotWritable} when the scratch file a package
 * held in memory needs cannot be written.
 */
final class MarkdownWriter
{
    /** @var list<int> The number the next ordered item at each open level carries. */
    private array $counters = [];

    /** What is inside a block, written as Markdown. */
    private readonly InlineWriter $inlines;

    public function __construct(private readonly Options $options)
    {
        $this->inlines = new InlineWriter();
    }

    /**
     * @param list<Block> $blocks
     */
    public function write(array $blocks): string
    {
        return rtrim($this->blocks($blocks), "\n");
    }

    /**
     * The blocks, one after another, separated by the blank line that makes a
     * list loose again after it has been interrupted.
     *
     * They are kept apart until here rather than joined and tidied afterwards: a
     * blank line inside a verbatim block is content, while a run of them between
     * two blocks is only ever a way of writing a blank line, and tidying the
     * finished document cannot tell the two apart.
     *
     * @param list<Block> $blocks
     * @param string       $separator A single newline in a tight list, a blank
     *        line in a loose one.
     */
    private function blocks(array $blocks, string $separator = "\n\n"): string
    {
        $parts = [];

        foreach ($blocks as $block) {
            $part = $this->block($block);

            if ($part !== '') {
                // A block never begins or ends with a line break of its own, so
                // the separator alone decides how far apart two blocks stand.
                $parts[] = trim($part, "\n");
            }
        }

        return implode($separator, $parts);
    }

    private function block(Block $block): string
    {
        return match ($block->kind) {
            Block::PARAGRAPH => $this->paragraph($block),
            Block::RULE => '---',
            Block::CODE => $this->code($block),
            Block::LIST => $this->list($block),
            Block::QUOTE => $this->quote($block),
            Block::TABLE => $this->table($block),
            default => '',
        };
    }

    private function paragraph(Block $block): string
    {
        // A newline at either end of a paragraph is an artefact of the block that
        // held it rather than something the reader should see.
        $text = trim($this->inlines->write($block->inlines), "\n");
        $level = $block->attr('level');

        if (!is_int($level)) {
            return $text;
        }

        if ($this->options->headingSetext && $level <= 2) {
            return $text . "\n" . str_repeat($level === 1 ? '=' : '-', 3);
        }

        return str_repeat('#', min(6, $level)) . ' ' . $this->closeAtx($text);
    }

    /**
     * Keep a trailing run of hashes from becoming an ATX closing sequence.
     *
     * `## foo ##` is a heading whose content is `foo` — the second pair of hashes
     * is swallowed as the closing sequence — so the hash that would be read as one
     * is escaped. A hash that was already escaped in the document is left alone.
     */
    private function closeAtx(string $text): string
    {
        return (string) preg_replace('/(?<!\\\\)(#+)$/', '\\\\$1', $text);
    }

    /**
     * A verbatim block, fenced so that its whitespace survives a reader.
     */
    private function code(Block $block): string
    {
        $text = (string) $block->attr('text', '');
        $info = (string) $block->attr('info', '');

        // A run of backticks in the content has to be shorter than the fence, or
        // it would close the block early; {@see Escaping::longestRun()} finds the
        // longest in a single pass.
        $length = max(3, Escaping::longestRun($text, '`') + 1);

        $fence = str_repeat('`', $length);

        return $fence . $info . "\n" . $text . "\n" . $fence;
    }

    private function quote(Block $block): string
    {
        $body = $this->blocks($block->children);

        if ($body === '') {
            return '';
        }

        // Every line of the quote is prefixed, including the blank ones, because
        // an unprefixed blank line ends the quote.
        $lines = array_map(
            static fn (string $line): string => $line === '' ? '>' : '> ' . $line,
            explode("\n", $body),
        );

        return implode("\n", $lines);
    }

    private function list(Block $block): string
    {
        $ordered = (bool) $block->attr('ordered', false);
        $start = (int) $block->attr('start', 1);
        $format = (string) $block->attr('format', 'decimal');
        $delimiter = (string) $block->attr('delimiter', '.');
        $numbering = new ListMarker($format);
        $marker = $ordered ? ($numbering->marker($start) . $delimiter) : '-';

        $this->counters[] = $start;

        // A tight list runs its items together; a loose one puts a blank line
        // between them.
        $separator = $block->attr('tight', false) === true ? "\n" : "\n\n";
        $lines = [];

        foreach ($block->children as $item) {
            $lines[] = $this->item($item, $marker, $separator);

            if ($ordered) {
                $this->counters[count($this->counters) - 1]++;
                $marker = $numbering->marker($this->counters[count($this->counters) - 1]) . $delimiter;
            }
        }

        array_pop($this->counters);

        return implode($separator, $lines);
    }

    /**
     * One list item, whose body is either several blocks separated by blank lines
     * or a nested list.
     */
    private function item(Block $item, string $marker, string $separator): string
    {
        $blocks = $item->children;
        $checkbox = $this->checkbox($blocks);

        if ($checkbox !== '') {
            $blocks[0] = $blocks[0]->withInlines($this->withoutCheckbox($blocks[0]->inlines));
        }

        $body = $this->blocks($blocks, $separator);

        if ($body === '') {
            return $marker . ' ' . $checkbox;
        }

        $indent = str_repeat(' ', strlen($marker) + 1);
        $lines = explode("\n", $body);

        // The first line sits next to the marker; every later line is indented
        // to line up with it, which is what keeps a nested list nested.
        $out = $marker . ' ' . $checkbox . $lines[0];

        foreach (array_slice($lines, 1) as $line) {
            $out .= "\n" . ($line === '' ? '' : $indent . $line);
        }

        return $out;
    }

    /**
     * The `[ ] ` or `[x] ` that opens a task list item, if there is one.
     *
     * Word has no checkbox, so a task list item is drawn with the character GFM
     * uses. Putting it back is what keeps such an item a task list item.
     *
     * @param list<Block> $blocks
     */
    private function checkbox(array $blocks): string
    {
        $first = $blocks[0] ?? null;

        if ($first === null || !$first->is(Block::PARAGRAPH) || $first->inlines === []) {
            return '';
        }

        $text = $first->inlines[0]->kind === Inline::TEXT ? $first->inlines[0]->text : '';

        // The space after the character is optional: the text that followed the
        // marker in the source usually has one of its own, and it is removed
        // along with the character so the two do not end up side by side.
        return match (true) {
            preg_match('/^\x{2610}\s?/u', $text) === 1 => '[ ] ',
            preg_match('/^\x{2612}\s?/u', $text) === 1 => '[x] ',
            default => '',
        };
    }

    /**
     * @param list<Inline> $inlines
     * @return list<Inline>
     */
    private function withoutCheckbox(array $inlines): array
    {
        $first = array_shift($inlines);

        return [$first->withText((string) preg_replace('/^[\x{2610}\x{2612}]\s?/u', '', $first->text)), ...$inlines];
    }

    private function table(Block $block): string
    {
        /** @var list<Block> $rows */
        $rows = $block->children;
        $alignments = (array) $block->attr('alignments', []);

        if ($rows === []) {
            return '';
        }

        $cells = array_map(
            fn (Block $row): array => array_map($this->cell(...), $row->children),
            $rows,
        );

        $width = max(array_map('count', $cells));

        // GFM tables always have a header row and a delimiter row, and a Word table
        // records neither: the delimiter row is a row of dashes Markdown invents
        // and there is nothing to derive it from, and a header is marked with
        // `w:trPr/w:tblHeader`, which this reader does not look for. So the first
        // row becomes the header on the assumption that is usually right. See
        // {@see Options::$tableHeader}.
        [$header, $body] = $this->options->tableHeader
            ? [array_shift($cells), $cells]
            : [[], $cells];

        if ($header === []) {
            $header = array_fill(0, $width, '');
        }

        $lines = [
            $this->tableRow($header, $width),
            $this->delimiterRow($alignments, $width),
        ];

        foreach ($body as $row) {
            $lines[] = $this->tableRow($row, $width);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $cells
     */
    private function tableRow(array $cells, int $width): string
    {
        $padded = array_pad($cells, $width, '');

        return '| ' . implode(' | ', $padded) . ' |';
    }

    /**
     * @param list<string> $alignments
     */
    private function delimiterRow(array $alignments, int $width): string
    {
        $markers = [];

        for ($index = 0; $index < $width; $index++) {
            $markers[] = match ($alignments[$index] ?? '') {
                'center' => ':-:',
                'right' => '--:',
                default => ':--',
            };
        }

        return '| ' . implode(' | ', $markers) . ' |';
    }

    /**
     * A cell holds blocks; GFM holds one line, so a paragraph break becomes the
     * one way of writing more than one line in a cell.
     */
    private function cell(Block $cell): string
    {
        $parts = [];

        foreach ($cell->children as $block) {
            $text = $this->block($block);

            if ($text !== '') {
                $parts[] = str_replace("\n", '<br>', $text);
            }
        }

        return implode('<br>', $parts);
    }
}
