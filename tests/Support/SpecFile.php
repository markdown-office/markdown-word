<?php

declare(strict_types=1);

namespace MarkdownWord\Tests\Support;

/**
 * Reads the official Markdown specification test files.
 *
 * The `spec.txt` files distributed by commonmark/commonmark-spec and
 * github/cmark-gfm list every construct with a Markdown input and the expected
 * HTML.
 */
final class SpecFile
{
    /**
     * @param list<SpecExample> $examples
     */
    private function __construct(
        public readonly string $name,
        public readonly array $examples,
    ) {
    }

    public static function load(string $path, string $name): self
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read the spec file "%s".', $path));
        }

        return new self($name, self::parse($contents));
    }

    /**
     * @return list<SpecExample>
     */
    private static function parse(string $contents): array
    {
        $examples = [];

        // The format is a fence of backticks containing "example", the Markdown
        // input, a line with a single dot, the expected HTML, then the closing
        // fence. The fence length varies between the two suites, so it is
        // captured and back-referenced rather than assumed.
        //
        // Both sections run until a line that is exactly the closing fence, which
        // is what lets a body contain a fence of its own. The output section may
        // be empty, because an input that produces no content is a valid case.
        $pattern = '/^(?<fence>`{3,}) example[ \t]*\r?\n'
            . '(?<markdown>(?:(?!\k<fence>[ \t]*$)[\s\S])*?)\r?\n\.[ \t]*\r?\n'
            . '(?<html>(?:(?!\k<fence>[ \t]*$)[\s\S])*?)\r?\n'
            . '\k<fence>[ \t]*$/ms';

        preg_match_all('/^#{1,6} +(?<heading>.+)$/m', $contents, $headings, PREG_OFFSET_CAPTURE);
        $offsets = [];
        foreach ($headings[0] as [$text, $offset]) {
            $offsets[$offset] = trim($text);
        }

        if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return [];
        }

        $example = 0;

        foreach ($matches as $match) {
            $offset = (int) $match[0][1];
            $currentSection = '';
            foreach ($offsets as $headingOffset => $heading) {
                if ($headingOffset < $offset) {
                    $currentSection = $heading;
                }
            }

            // The bodies sit flush with the fence, so leading whitespace is
            // content: an indented code block or a list nested under another
            // list both depend on it and must not be normalised away.
            $examples[] = new SpecExample(
                number: ++$example,
                section: $currentSection,
                markdown: self::restoreTabs($match['markdown'][0]),
                html: trim(self::restoreTabs($match['html'][0])),
            );
        }

        return $examples;
    }

    /**
     * The specs write tabs as a rightwards arrow so they survive being embedded
     * in prose; the parsers need real tab characters.
     */
    private static function restoreTabs(string $text): string
    {
        return str_replace("\u{2192}", "\t", $text);
    }
}
