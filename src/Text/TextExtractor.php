<?php

declare(strict_types=1);

namespace MarkdownWord\Text;

use MarkdownWord\Render\LinkPayloadCollector;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Footnote;
use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\PhpWord;

/**
 * Recovers the plain text of a rendered document, for indexing, for
 * accessibility fallbacks, and for asserting a conversion lost nothing.
 */
final class TextExtractor
{
    /** Inserted where a Word line break or a paragraph boundary occurs. */
    public const LINE_BREAK = "\n";

    /**
     * @param list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}> $hyperlinks
     *        Payloads of links still represented by a placeholder in the element
     *        tree; each one's label is substituted in, so what comes out is the
     *        document's text and not the writer's intermediate form.
     */
    public static function fromPhpWord(
        PhpWord $phpWord,
        string $separator = self::LINE_BREAK,
        array $hyperlinks = [],
    ): string {
        $parts = [];
        foreach ($phpWord->getSections() as $section) {
            $parts[] = self::fromContainer($section, $separator, $hyperlinks);
        }

        return self::join($parts, $separator);
    }

    /**
     * @param list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}> $hyperlinks
     */
    public static function fromContainer(
        AbstractContainer $container,
        string $separator = self::LINE_BREAK,
        array $hyperlinks = [],
    ): string {
        $parts = [];

        foreach ($container->getElements() as $element) {
            $text = self::fromElement($element, $separator, $hyperlinks);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return self::join($parts, $separator);
    }

    /**
     * @param list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}> $hyperlinks
     */
    private static function fromElement(mixed $element, string $separator, array $hyperlinks = []): string
    {
        if ($element instanceof Text) {
            return LinkPayloadCollector::substitute($element->getText(), $hyperlinks);
        }

        if ($element instanceof Link) {
            return $element->getText();
        }

        if ($element instanceof TextBreak) {
            return self::LINE_BREAK;
        }

        if ($element instanceof Image) {
            return '';
        }

        if ($element instanceof ListItem) {
            return $element->getText();
        }

        if ($element instanceof TextRun) {
            return self::runs($element, $separator, $hyperlinks);
        }

        if ($element instanceof Table) {
            return self::table($element, $separator, $hyperlinks);
        }

        if ($element instanceof AbstractContainer) {
            // A footnote's body is not part of the sentence the mark sits in: Word
            // draws it at the foot of the page, and PHPWord holds it as a container
            // inside the run. Read as text it would land mid-sentence — a claim
            // about the document's wording that is true nowhere in the document.
            if ($element instanceof Footnote) {
                return '';
            }

            return self::fromContainer($element, $separator, $hyperlinks);
        }

        return '';
    }

    /**
     * @param list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}> $hyperlinks
     */
    private static function runs(AbstractContainer $container, string $separator, array $hyperlinks = []): string
    {
        $parts = [];

        foreach ($container->getElements() as $child) {
            if ($child instanceof Text) {
                $parts[] = LinkPayloadCollector::substitute($child->getText(), $hyperlinks);
            } elseif ($child instanceof Link) {
                $parts[] = $child->getText();
            } elseif ($child instanceof Image) {
                $parts[] = $child->getName() ?? '';
            } elseif ($child instanceof TextBreak) {
                $parts[] = self::LINE_BREAK;
            } elseif ($child instanceof AbstractContainer) {
                $parts[] = self::fromElement($child, $separator, $hyperlinks);
            }
        }

        // One paragraph is one line in Word, so its runs are joined with no separator.
        return implode('', $parts);
    }

    /**
     * @param list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}> $hyperlinks
     */
    private static function table(Table $table, string $separator, array $hyperlinks = []): string
    {
        $rows = [];

        foreach ($table->getRows() as $row) {
            $cells = [];
            foreach ($row->getCells() as $cell) {
                $cells[] = self::fromContainer($cell, $separator, $hyperlinks);
            }
            $rows[] = implode("\t", $cells);
        }

        return implode(self::LINE_BREAK, $rows);
    }

    /** @param list<string> $parts */
    private static function join(array $parts, string $separator): string
    {
        $text = implode($separator, $parts);

        // Only line breaks are stripped; leading spaces are content in a code block.
        return trim($text, "\n\r");
    }
}
