<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * A floating box placed off the edge of the page without saying that it means to be.
 *
 * A box that hangs off the side of the page is a thing a document may want: the rule that
 * runs past the margin, the shape that is cut off by the edge of the paper. It is also a
 * thing a document may get wrong, and the two are told apart by whether the author said so.
 *
 * The slides half has the same rule and the same word for it — `bleed=true` — so that a
 * document and a deck are written the same way.
 */
final class BleedingBox extends InvalidInput
{
    public static function offThePage(string $attribute, float $given): self
    {
        return new self(sprintf(
            'The text box is placed at a %s of %s, which is off the edge of the page. A box lies within the page unless '
            . 'it is written to bleed, so write %s=0 rather than a negative one — or write bleed=true if the box is meant '
            . 'to hang off the edge.',
            $attribute,
            self::written($given),
            $attribute,
        ));
    }

    /**
     * The number as it was written, without a fraction of nothing.
     *
     * `sprintf()` rather than `number_format()`, which drops the sign of a number that rounds
     * to zero: a `left` of `-0.001` came out as `0`, and the refusal told the author to write
     * the number they had already written.
     */
    private static function written(float $given): string
    {
        $written = rtrim(rtrim(sprintf('%.4F', $given), '0'), '.');

        if ($written === '-0') {
            $written = rtrim(rtrim(sprintf('%.8F', $given), '0'), '.');
        }

        return $written === '' || $written === '-' ? '0' : $written;
    }
}
