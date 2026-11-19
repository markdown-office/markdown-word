<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

/**
 * Writes the inline runs of a block as Markdown.
 *
 * The other half of {@see MarkdownWriter}: that one writes the blocks, and this
 * one writes what is inside them. The two are apart because the rules are
 * apart — a block is a line of its own, and a run is a span of characters that
 * may open an emphasis and leave it open for the runs beside it.
 */
final class InlineWriter
{
    /**
     * Write a run of inlines as Markdown.
     *
     * The emphasis delimiters are held open across runs rather than opened and
     * closed one run at a time. A bold run followed by a bold italic one then
     * comes out as `**a*b***`, because the `**` the first run opened is still open
     * when the second adds its `*`, so the two spans meet. Closing each run on its
     * own would give `**a*****b***`, whose run of five asterisks in the middle is
     * ambiguous and a parser may not agree on where the spans stop.
     *
     * @param list<Inline> $inlines
     */
    public function write(array $inlines, bool $inTable = false): string
    {
        $out = '';
        $open = [];
        $lineStart = true;

        $runs = $this->withoutEdgeBreaks($this->coalesce($inlines));

        foreach ($runs as $inline) {
            if ($inline->is(Inline::BREAK)) {
                $out = $this->closeAll($out, $open);
                $out .= "  \n";
                $lineStart = true;

                continue;
            }

            if ($inline->is(Inline::LINK, Inline::IMAGE)) {
                $out = $this->linkOrImage($out, $open, $inline, $inTable);
                $lineStart = false;

                continue;
            }

            $out = $this->emphasis($out, $open, $inline, $lineStart, $inTable);
            $lineStart = false;
        }

        return $this->closeAll($out, $open);
    }

    /**
     * The runs without the breaks at either end of them.
     *
     * A break at either end of a paragraph says nothing: it is the boundary
     * between one paragraph and the next, not a line break inside one.
     *
     * @param list<Inline> $runs
     * @return list<Inline>
     */
    private function withoutEdgeBreaks(array $runs): array
    {
        while ($runs !== [] && $runs[0]->is(Inline::BREAK)) {
            array_shift($runs);
        }

        while ($runs !== [] && $runs[count($runs) - 1]->is(Inline::BREAK)) {
            array_pop($runs);
        }

        return $runs;
    }

    /**
     * A link or an image, written with every open emphasis closed around it.
     *
     * @param list<string> $open
     */
    private function linkOrImage(string $out, array &$open, Inline $inline, bool $inTable): string
    {
        $out = $this->closeAll($out, $open);

        // `![` is an image, so an exclamation mark written straight
        // before a link has to be escaped. The document said "!foo"; the
        // Markdown must not say "an image of foo".
        if ($out !== '' && str_ends_with($out, '!')) {
            $out = substr($out, 0, -1) . '\\!';
        }

        return $out . ($inline->is(Inline::LINK) ? $this->link($inline, $inTable) : $this->image($inline));
    }

    /**
     * Write one text run, keeping open any emphasis the neighbouring runs share.
     *
     * @param string       $out    Everything written before this run.
     * @param list<string> $open   The delimiters still open around it, outermost
     *        first, which this run may close, add to or leave alone.
     * @param Inline       $inline The run to write.
     */
    private function emphasis(string $out, array &$open, Inline $inline, bool $lineStart, bool $inTable): string
    {
        if ($inline->code) {
            // A code span is verbatim, so its content is written as it stands:
            // escaping inside it would make the backslashes visible. A pipe is
            // the exception, because a pipe ends a cell and the reader of the
            // table takes the backslash for the escape it is rather than for a
            // character of the content.
            $span = Escaping::codeSpan($inline->text, $inTable);

            return $out . ($span ?? $this->hardenNewlines(
                Escaping::text($inline->text, $lineStart, $inTable),
            ));
        }

        $wanted = $this->markers($inline);

        // Drop the markers this run does not carry, innermost first, so that
        // what stays open is a prefix of what the run wants.
        foreach (array_reverse(array_keys($open), true) as $index) {
            if (!in_array($open[$index], $wanted, true)) {
                $out = $this->close($out, $open[$index]);
                unset($open[$index]);
            }
        }

        $open = array_values($open);

        $text = $this->hardenNewlines(Escaping::text($inline->text, $lineStart, $inTable));

        // A delimiter has to sit against the text it delimits, so whitespace that
        // opens or closes a run is written outside the markers: a `*` followed by
        // a space cannot open anything, and a `*` preceded by one cannot close
        // anything.
        $leading = '';
        if ($text !== '' && preg_match('/^(\s+)(.*)$/s', $text, $matches) === 1) {
            [$leading, $text] = [$matches[1], $matches[2]];
        }

        $out .= $leading;

        foreach ($wanted as $marker) {
            if (!in_array($marker, $open, true)) {
                $out .= $marker;
                $open[] = $marker;
            }
        }

        // A run that is nothing but whitespace has nothing to emphasise, and
        // delimiters around it would not parse.
        return $text === '' ? $out : $out . $text;
    }

    /**
     * Turn the newlines inside a run into hard line breaks.
     *
     * A newline in a run's text is a line break Word held inside one paragraph,
     * not a boundary between two. A hard break keeps it a line break on the way
     * back; a soft one would collapse to a space and quietly join two lines of a
     * `<pre>` block into one.
     */
    private function hardenNewlines(string $text): string
    {
        if (!str_contains($text, "\n")) {
            return $text;
        }

        return implode("  \n", explode("\n", str_replace("\r", '', $text)));
    }

    /**
     * The delimiters a run's formatting calls for, outermost first.
     *
     * @return list<string>
     */
    private function markers(Inline $inline): array
    {
        $markers = [];

        if ($inline->strike) {
            $markers[] = '~~';
        }

        if ($inline->bold) {
            $markers[] = '**';
        }

        if ($inline->italic) {
            $markers[] = '*';
        }

        return $markers;
    }

    /**
     * Close an open delimiter, stepping over any whitespace that would stop it
     * from closing.
     */
    private function close(string $out, string $marker)
    {
        if (preg_match('/^(.*?)(\s*)$/s', $out, $matches) === 1 && $matches[2] !== '') {
            return $matches[1] . $marker . $matches[2];
        }

        return $out . $marker;
    }

    /**
     * @param list<string> $open
     */
    private function closeAll(string $out, array &$open): string
    {
        while ($open !== []) {
            $out = $this->close($out, array_pop($open));
        }

        return $out;
    }

    private function image(Inline $inline): string
    {
        $alt = Escaping::text($inline->alt, lineStart: false, inTable: true);
        $target = Escaping::text($inline->target, lineStart: false, inTable: true);

        // A destination holding whitespace, a parenthesis or an angle bracket has
        // to be put in angle brackets, which is the only form that can carry one.
        if (preg_match('/[\s()<>]/', $target) === 1) {
            $target = '<' . str_replace(['<', '>'], ['\\<', '\\>'], $target) . '>';
        }

        return '![' . $alt . '](' . $target . ')';
    }

    private function link(Inline $inline, bool $inTable): string
    {
        $label = $this->write($inline->children, $inTable);

        $url = $inline->url;
        $title = $inline->title;

        if ($inTable) {
            // A pipe ends a cell whatever surrounds it, so the destination and
            // the title are written with the pipe escaped.
            $url = str_replace('|', '\\|', $url);
            $title = $title === null ? null : str_replace('|', '\\|', $title);
        }

        if (preg_match('/[\s()<>]/', $url) === 1) {
            $url = '<' . str_replace(['<', '>'], ['\\<', '\\>'], $url) . '>';
        }

        if ($title !== null && $title !== '') {
            $url .= ' "' . str_replace('"', '\\"', $title) . '"';
        }

        return '[' . $label . '](' . $url . ')';
    }

    /**
     * Join neighbouring runs that are formatted identically, as
     * {@see InlineReader::merge()} does.
     *
     * @param list<Inline> $inlines
     * @return list<Inline>
     */
    private function coalesce(array $inlines): array
    {
        $out = [];

        foreach ($inlines as $inline) {
            $last = $out === [] ? null : $out[count($out) - 1];

            if (
                $last !== null
                && $last->is(Inline::TEXT)
                && $inline->is(Inline::TEXT)
                && $last->sameFormatting($inline)
            ) {
                $out[count($out) - 1] = $last->withText($last->text . $inline->text);

                continue;
            }

            $out[] = $inline;
        }

        return $out;
    }
}
