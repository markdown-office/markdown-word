<?php

declare(strict_types=1);

namespace MarkdownWord\Document;

/**
 * The text of a frontmatter block, kept so that a key can be pointed at in the file.
 *
 * The parse tree keeps positions for what comes after the block and not for the block
 * itself, so a message that could say "line 7" instead of listing the keys to go
 * looking for has to come from here. It follows the indentation rather than parsing
 * the YAML again, which is enough for the block style people write and gives up —
 * returning null, and so a message without a line — on the flow style and the
 * anchors, where guessing would be worse than saying nothing.
 *
 * {@see Frontmatter} holds one of these and asks it questions; it is a separate
 * object because the answers are about the *file* while everything else that class
 * holds is about the values, and the two go out of step in exactly the way a caller
 * would not notice: a block whose text was never kept still has values.
 */
final class BlockLines
{
    /**
     * @param list<string> $lines The block's own lines, fences excluded. Empty when
     *        the document had no block, or when it was not written in a shape this
     *        can follow — both of which {@see self::firstLine()} answers the same way.
     * @param int $offset The file line that `$lines[0]` is on.
     */
    private function __construct(private readonly array $lines, private readonly int $offset)
    {
    }

    /**
     * The block at the top of a document, or an empty one when there is none.
     *
     * Only a block that opens the document is read. The extension is the same: a
     * `---` further down is a thematic break and there is nothing here to find.
     */
    public static function of(string $markdown): self
    {
        $lines = $markdown === '' ? [] : (preg_split('/\R/', $markdown) ?: []);

        if (rtrim($lines[0] ?? '') !== '---') {
            return new self([], 1);
        }

        $body = [];

        for ($index = 1, $total = \count($lines); $index < $total; $index++) {
            $trimmed = rtrim($lines[$index]);

            // The closing fence, and the alternative spelling YAML allows for one at
            // the end of a stream.
            if ($trimmed === '---' || $trimmed === '...') {
                return new self($body, 2);
            }

            $body[] = $lines[$index];
        }

        return new self([], 1);
    }

    /**
     * The file line a configuration key sits on, or null when the block's text was
     * not kept or the key is written in a shape this cannot follow.
     *
     * `options/images` is the path, separated by a slash rather than a dot because a
     * slot name has one in it: `heading.1` is the commonest slot there is.
     *
     * @return callable(string): ?int
     */
    public function locator(): callable
    {
        return fn (string $path): ?int => $this->lineOf($path);
    }

    /**
     * The file line a path sits on, or null when it cannot be followed.
     */
    public function lineOf(string $path): ?int
    {
        $indent = -1;
        $index = 0;

        foreach (explode('/', $path) as $segment) {
            if (!$this->find($segment, $indent, $index)) {
                return null;
            }
        }

        return $this->offset + $index - 1;
    }

    /**
     * The line the block's first entry is on, for a problem with the block itself.
     */
    public function firstLine(): ?int
    {
        return $this->lines === [] ? null : $this->offset;
    }

    /**
     * Advance `$index` past the block whose key is `$segment`, recording how deep it
     * sits. False when the segment is not in the block being searched.
     */
    private function find(string $segment, int &$indent, int &$index): bool
    {
        for ($position = $index, $total = \count($this->lines); $position < $total; $position++) {
            $line = $this->lines[$position];
            $trimmed = ltrim($line);

            // A comment or a blank line is not a boundary: a block routinely has a
            // comment above every key in it.
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $key = self::keyIn($line);

            if ($key === null) {
                continue;
            }

            // Any other key at the same depth or shallower is the end of the block
            // being searched, so the segment is not in it.
            if (self::indentOf($line) <= $indent) {
                $index = $position;

                return false;
            }

            if ($key === $segment) {
                $indent = self::indentOf($line);
                $index = $position + 1;

                return true;
            }
        }

        $index = \count($this->lines);

        return false;
    }

    /**
     * The key a line defines, or null for a line that defines none.
     *
     * The quotes are stripped because `heading.1` is one people write both ways, and
     * because the lookup has to agree with the parser on what the key is.
     */
    private static function keyIn(string $line): ?string
    {
        if (preg_match('/^(?<key>"[^"]*"|\'[^\']*\'|[^\s#:][^:]*?)\s*:(?:\s|$)/', ltrim($line), $match) !== 1) {
            return null;
        }

        return trim($match['key'], "\"'");
    }

    private static function indentOf(string $line): int
    {
        return \strlen($line) - \strlen(ltrim($line));
    }
}
