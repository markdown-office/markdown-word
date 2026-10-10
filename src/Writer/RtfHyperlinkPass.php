<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Render\LinkPlaceholder;
use PhpOffice\PhpWord\Escaper\Rtf;

/**
 * Rewrites a finished `.rtf`, replacing the placeholder PHPWord wrote for a link
 * whose label contains emphasis with a real `HYPERLINK` field.
 *
 * The RTF counterpart of {@see HyperlinkPass}, for the same reason it exists:
 * `Writer\RTF\Element\Link` takes a single plain string, so a label like
 * `**Release** notes` would lose either the link or the bold.
 *
 * There is no archive to reopen here, so the placeholder is found in the finished
 * text, in the form the RTF writer actually left it in. That form is computed
 * rather than guessed: {@see self::token()} reproduces what PHPWord's escaping does
 * to the marker, which comes out the same whether its output escaping is on or off,
 * because both write `\uc0{\uNNNN}` for everything above ASCII.
 */
final class RtfHyperlinkPass
{
    /**
     * @param list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}> $payloads
     */
    public function __construct(private readonly array $payloads)
    {
    }

    /**
     * @throws FileNotWritable When the staged file cannot be read or written back.
     */
    public function applyTo(string $rtfPath): void
    {
        $contents = file_get_contents($rtfPath);

        if ($contents === false) {
            throw new FileNotWritable(sprintf('Unable to read the generated .rtf file "%s".', $rtfPath));
        }

        $updated = $contents;

        foreach ($this->payloads as $payload) {
            $token = self::token($payload['placeholder']);

            if (str_contains($updated, $token)) {
                $updated = str_replace($token, $this->field($payload), $updated);
            }
        }

        if ($updated !== $contents && file_put_contents($rtfPath, $updated) === false) {
            throw new FileNotWritable(sprintf('Unable to write the generated .rtf file "%s".', $rtfPath));
        }
    }

    /**
     * The placeholder as the RTF writer leaves it.
     *
     * A digit index makes the token unique even where one is a prefix of another:
     * the token always ends `…\uc0{\uNNNN}MDWL…`, so no shorter index can be a
     * prefix of a longer one's.
     */
    private static function token(string $placeholder): string
    {
        // `/u`, so the subject is walked one code point at a time: without it a
        // three-byte separator becomes three matches and the token comes out wrong.
        return (string) preg_replace_callback(
            '/[^\x20-\x7E]/u',
            static fn (array $match): string => sprintf('\uc0{\u%d}', mb_ord($match[0], 'UTF-8')),
            $placeholder,
        );
    }

    /**
     * @param  array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}  $payload
     */
    private function field(array $payload): string
    {
        $runs = '';

        foreach ($payload['runs'] as $run) {
            $runs .= '{' . $this->controlWords($run['style']) . self::escape($run['text']) . '}';
        }

        // Wrapped in braces of its own so the field sits inside whatever run group
        // the writer put the placeholder in, and that group's own font carries over
        // to the runs the field holds.
        return sprintf(
            '{\field{\*\fldinst {HYPERLINK "%s"}}{\fldrslt {%s}}}',
            self::escape($payload['url']),
            $runs,
        );
    }

    /**
     * Only the control words that name no entry in a table.
     *
     * A typeface and a colour are written as an index into `\fonttbl` and
     * `\colortbl`, and an index with no entry behind it is what a reader draws
     * as, so they are left out and {@see \MarkdownWord\Format} says so. An
     * index can still be written for a colour that happens to be in the table
     * because a registered style carried the same one — which is why this is a
     * default rather than a promise that the words are never wrong.
     *
     * @param array<string, mixed> $style
     */
    private function controlWords(array $style): string
    {
        $words = [];

        if (!empty($style['bold'])) {
            $words[] = '\b';
        }

        if (!empty($style['italic'])) {
            $words[] = '\i';
        }

        if (!empty($style['strikethrough'])) {
            $words[] = '\strike';
        }

        if (isset($style['underline']) && $style['underline'] !== 'none') {
            $words[] = '\ul';
        }

        if (!empty($style['size'])) {
            $words[] = '\\fs' . (int) round(((float) $style['size']) * 2);
        }

        return $words === [] ? '' : implode('', $words) . ' ';
    }

    /**
     * The escaping the RTF writer applied to everything else, so a `{` in a URL or
     * a label cannot close a group it does not belong to. PHPWord's own escaper,
     * because `Writer\RTF\Element\AbstractElement::writeText()` uses it for the
     * document this pass is rewriting.
     */
    private static function escape(string $value): string
    {
        return (new Rtf())->escape($value);
    }
}