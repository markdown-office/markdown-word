<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

/**
 * The marker a list item carries: the numbering a `w:numFmt` value stands for.
 *
 * Markdown spells these as marker text, so a Word list numbered `a, b, c`
 * comes back as `a. b. c.`. A format the table does not know is written as
 * the number itself, which a reader can still make sense of.
 */
final class ListMarker
{
    public function __construct(private readonly string $format)
    {
    }

    public function marker(int $number): string
    {
        return match ($this->format) {
            'lowerLetter' => $this->alphabet($number, false),
            'upperLetter' => $this->alphabet($number, true),
            'lowerRoman' => strtolower($this->roman($number)),
            'upperRoman' => $this->roman($number),
            'decimalZero' => $this->decimalZero($number),
            default => (string) $number,
        };
    }

    /**
     * The letters of a spreadsheet column, which is how Word goes on numbering a
     * list past the twenty-sixth item: `a` to `z`, then `aa`, `ab` and so on.
     *
     * The count carries no zero in it, so the run continues rather than starting
     * again, and what is left of the count goes in front of the letter.
     */
    private function alphabet(int $number, bool $upper): string
    {
        if ($number < 1) {
            return (string) $number;
        }

        $letters = '';

        while ($number > 0) {
            $letters = chr(ord('a') + ($number - 1) % 26) . $letters;
            $number = intdiv($number - 1, 26);
        }

        return $upper ? strtoupper($letters) : $letters;
    }

    /**
     * A number padded to two digits, which is the whole of what `decimalZero`
     * means; past ninety-nine there is nothing left to pad to.
     */
    private function decimalZero(int $number): string
    {
        return str_pad((string) $number, 2, '0', STR_PAD_LEFT);
    }

    /**
     * A Roman numeral. There is none for a number above three thousand nine
     * hundred and ninety-nine, so past that the number itself is written.
     */
    private function roman(int $number): string
    {
        if ($number < 1 || $number > 3999) {
            return (string) $number;
        }

        $values = [
            'M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100,
            'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9,
            'V' => 5, 'IV' => 4, 'I' => 1,
        ];

        $roman = '';
        foreach ($values as $letters => $value) {
            while ($number >= $value) {
                $roman .= $letters;
                $number -= $value;
            }
        }

        return $roman;
    }
}
