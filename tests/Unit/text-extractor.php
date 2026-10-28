<?php

declare(strict_types=1);

use MarkdownWord\Text\TextExtractor;
use PhpOffice\PhpWord\PhpWord;

/*
 * What the text extractor makes of a run that holds no text.
 *
 * A `Text` may have no text of its own: PHPWord builds its form field and its
 * content control with none, and a checkbox takes one only if it is given one.
 * Reading such a run raised a `TypeError` — `str_contains()` with a null haystack
 * — so a document carrying one could not be read back at all.
 *
 * The rule is one sentence: a run contributes the text it holds, and a run that
 * holds none contributes nothing. A form field holds its content in its default
 * and a content control in its value; a checkbox holds neither, because it is a
 * box and the name it answers to is the form's identifier rather than the
 * document's wording.
 */

it('reads a run that holds no text as nothing, rather than as an error', function () {
    $phpWord = new PhpWord();
    $section = $phpWord->addSection();

    // A run whose text was taken away, and a checkbox, which is a box with a name
    // rather than a box with words in it.
    $section->addText('a run')->setText(null);
    $section->addCheckBox('a name', null);

    expect(TextExtractor::fromPhpWord($phpWord))->toBe('');
});

it('reads a content control as the text it holds', function () {
    $phpWord = new PhpWord();
    $section = $phpWord->addSection();

    $control = $section->addSDT('plainText');
    $control->setValue('Vienna');

    expect(TextExtractor::fromPhpWord($phpWord))->toBe('Vienna');
});

it('reads a list item whose text was taken away as nothing', function () {
    $phpWord = new PhpWord();
    $section = $phpWord->addSection();

    $section->addListItem('an item')->getTextObject()->setText(null);

    expect(TextExtractor::fromPhpWord($phpWord))->toBe('');
});
