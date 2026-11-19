<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

/**
 * Escapes text read out of a Word document so it survives a Markdown parser.
 *
 * One rule is behind all of it: escaping must never change what the reader sees,
 * but leaving a character unescaped can. It is applied conservatively — where a
 * character is only ambiguous in some position, it is escaped only there, which
 * is what keeps the output readable. `a < b` stays as it is while `<div>` becomes
 * `\<div>`, and `snake_case` is left alone because CommonMark does not let an
 * underscore inside a word delimit anything.
 */
final class Escaping
{
    /** Characters that are Markdown syntax wherever they appear in a paragraph. */
    private const INLINE = [
        '\\' => '\\\\',
        '`' => '\\`',
        '*' => '\\*',
        '[' => '\\[',
        ']' => '\\]',
    ];

    /** Characters that only start a block when they begin a line. */
    private const LINE_START = '#>+=~|-';

    /**
     * @param string $text     The characters as they appear in the document.
     * @param bool   $lineStart Whether the run begins a line, where a `#`, a `-`
     *        or a `1.` would otherwise become a heading, a rule or a list.
     * @param bool   $inTable  Whether the run sits inside a table cell, where a
     *        pipe would end the cell.
     */
    public static function text(string $text, bool $lineStart = false, bool $inTable = false): string
    {
        if ($text === '') {
            return '';
        }

        // The backslash goes first, or it would escape the escapes added after it.
        $text = strtr($text, self::INLINE);
        $text = self::escapeUnderscores($text);

        // `~~` is GFM strikethrough; a single tilde is ordinary prose.
        $text = str_replace('~~', '\\~\\~', $text);

        // `&` is only a problem where it could start a character reference, and
        // `&amp;` in the output would decode back to a bare ampersand. A numeric
        // reference is a `#` and its digits, with or without the semicolon; a
        // named one is a name and a semicolon.
        $text = (string) preg_replace('/&(#\d+;?|#x[0-9a-fA-F]+;?)/', '\\&$1', $text);
        $text = (string) preg_replace('/&([A-Za-z][A-Za-z0-9]*;)/', '\\&$1', $text);

        // A `<` only matters where it could open a tag or an autolink, which is
        // why `a < b` is left alone and `<div>` is not. The delimiter is `~`
        // because the character class itself contains a slash.
        $text = (string) preg_replace('~<(?=[A-Za-z!/?])~', '\\<', $text);

        if ($inTable) {
            $text = str_replace('|', '\\|', $text);
        }

        return $lineStart ? self::escapeLineStart($text) : $text;
    }

    /**
     * Wrap a code span's content in a backtick fence that cannot occur inside it.
     *
     * The specification strips one space from each end of a code span when both
     * are present, so content that genuinely starts or ends with whitespace is
     * padded once more to keep it. Content that begins or ends with a backtick
     * needs the same padding, or the span would end at the wrong place.
     *
     * Returns null when the content cannot be written as a code span at all: a
     * span may not contain a blank line, and there is then nothing to fall back
     * on but writing the characters as they are.
     */
    public static function codeSpan(string $literal): ?string
    {
        // A code span ends at a blank line rather than at the fence, so one that
        // spans several paragraphs has no valid form.
        if (preg_match('/\n[ \t]*\n/', $literal) === 1) {
            return null;
        }

        $fence = str_repeat('`', self::longestRun($literal, '`') + 1);

        $pad = 0;
        if (str_starts_with($literal, '`') || str_ends_with($literal, '`')) {
            $pad++;
        }

        if (trim($literal) !== '' && str_starts_with($literal, ' ') && str_ends_with($literal, ' ')) {
            $pad++;
        }

        if ($pad > 0) {
            $literal = str_repeat(' ', $pad) . $literal . str_repeat(' ', $pad);
        }

        return $fence . $literal . $fence;
    }

    /**
     * Escape the beginning of a line so it is not read as a block.
     *
     * Only the first significant character is escaped, which is enough: once it
     * is literal, the block construct it could have started no longer matches.
     */
    private static function escapeLineStart(string $text): string
    {
        if (!preg_match('/^(\s*)(.*)$/s', $text, $matches)) {
            return $text;
        }

        [, $space, $rest] = $matches;

        if ($rest === '') {
            return $text;
        }

        $escaped = self::escapeLeading($rest);

        return $escaped === null ? $text : $space . $escaped;
    }

    /**
     * What the line begins with, with the character that has to be escaped put
     * before it, or null when the line starts nothing that could open a block.
     */
    private static function escapeLeading(string $rest): ?string
    {
        if (str_contains(self::LINE_START, $rest[0])) {
            return '\\' . $rest;
        }

        // An ordered list marker, as in a paragraph that opens with "1986. It was…".
        if (preg_match('/^\d{1,9}([.)])(?=\s|$)/', $rest, $marker) === 1) {
            $length = strlen($marker[0]);

            return substr($rest, 0, $length - 1) . '\\' . $marker[1] . substr($rest, $length);
        }

        return null;
    }

    /**
     * The decision follows the specification's own flanking rules rather than a
     * guess, and that is what keeps ordinary text clean. `snake_case` needs
     * nothing, because an underscore between two word characters cannot delimit
     * anything. `__ foo bar__` needs nothing either: the opening run is followed
     * by a space so it cannot open, and the closing run has nothing to close.
     * When no run can open, none of them can pair up.
     */
    private static function escapeUnderscores(string $text): string
    {
        if (!str_contains($text, '_')) {
            return $text;
        }

        $length = strlen($text);

        preg_match_all('/_+/', $text, $matches, PREG_OFFSET_CAPTURE);

        $runs = [];
        $opens = false;

        /** @var array{0: string, 1: int} $match */
        foreach ($matches[0] as [$run, $position]) {
            $end = $position + strlen($run);
            $before = $position > 0 ? $text[$position - 1] : '';
            $after = $end < $length ? $text[$end] : '';

            $canOpen = self::canOpen($before, $after);
            $opens = $opens || $canOpen;

            $runs[] = [$position, $end, $run, $canOpen, self::canClose($before, $after)];
        }

        $out = '';
        $offset = 0;

        /** @var array{0: int, 1: int, 2: string, 3: bool, 4: bool} $entry */
        foreach ($runs as [$position, $end, $run, $canOpen, $canClose]) {
            $out .= substr($text, $offset, $position - $offset);

            $out .= $opens && ($canOpen || $canClose)
                // Every underscore is escaped, not just the run: `\_` is one
                // literal underscore, so `\__` reads as an underscore followed by
                // a delimiter rather than as two underscores.
                ? str_replace('_', '\\_', $run)
                : $run;

            $offset = $end;
        }

        return $out . substr($text, $offset);
    }

    /**
     * A run opens when it is left-flanking and, unless preceded by punctuation, is
     * not also right-flanking. That last clause is what makes `snake_case` inert.
     */
    private static function canOpen(string $before, string $after): bool
    {
        if (self::isWhitespace($after)) {
            return false;
        }

        $leftFlanking = !self::isPunctuation($after) || self::isWhitespace($before) || self::isPunctuation($before);
        $rightFlanking = !self::isWhitespace($before);

        return $leftFlanking && (! $rightFlanking || self::isPunctuation($before));
    }

    /**
     * Whether a delimiter run could close emphasis, the mirror of
     * {@see self::canOpen()}.
     */
    private static function canClose(string $before, string $after): bool
    {
        if (self::isWhitespace($before)) {
            return false;
        }

        $rightFlanking = !self::isPunctuation($before) || self::isWhitespace($after) || self::isPunctuation($after);
        $leftFlanking = !self::isWhitespace($after);

        return $rightFlanking && (! $leftFlanking || self::isPunctuation($after));
    }

    /**
     * Whether a character is whitespace, which the specification treats the same
     * way as the end of a line.
     */
    private static function isWhitespace(string $character): bool
    {
        return $character === '' || ($character < "\x80" && ctype_space($character));
    }

    /**
     * Whether a character is ASCII punctuation, which is what the flanking rules
     * are written against.
     */
    private static function isPunctuation(string $character): bool
    {
        return $character !== ''
            && $character < "\x80"
            && str_contains('!"#$%&\'()*+,-./:;<=>?@[\]^_`{|}~', $character);
    }

    /**
     * The length of the longest run of a character in the text.
     *
     * The fence has to be one longer than any run the content holds or the span
     * or block would end at the wrong place. One pass finds that run: searching
     * the text again for each candidate is a search per character, and time
     * quadratic in the size of the text.
     *
     * @param string $character It has to be a single character, since the count
     *        is in characters and the text is walked a byte at a time.
     */
    public static function longestRun(string $text, string $character): int
    {
        $longest = 0;
        $current = 0;
        $length = strlen($text);

        for ($index = 0; $index < $length; $index++) {
            $current = $text[$index] === $character ? $current + 1 : 0;

            if ($current > $longest) {
                $longest = $current;
            }
        }

        return $longest;
    }
}
