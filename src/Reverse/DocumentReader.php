<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

use DOMElement;
use DOMXPath;

/**
 * Reads a `.docx` package into the block tree {@see MarkdownWriter} turns back
 * into Markdown.
 *
 * Word's document is a flat list of paragraphs and tables, so the structure
 * Markdown has is rebuilt by grouping passes in a fixed order. Lists are grouped
 * before quotes so that a list inside a quote is one list which then lands inside
 * the quote, rather than a quote interrupted by stray paragraphs.
 *
 * A *unit* is one entry of that flat sequence, and its type is `Block`
 * throughout: a paragraph, a table, or — once the lists are grouped — a list.
 *
 * The paragraphs are read here, the text inside them by {@see InlineReader}, and
 * the passes that give the flat sequence its Markdown shape live in
 * {@see CodeBlockGrouper}, {@see ListGrouper} and {@see QuoteGrouper}.
 */
final class DocumentReader
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private readonly CodeBlockGrouper $codeBlocks;

    private readonly ListGrouper $lists;

    private readonly QuoteGrouper $quotes;

    public function __construct(
        private readonly Options $options,
        private readonly StyleTable $styles,
        private readonly NumberingTable $numbering,
    ) {
        $this->codeBlocks = new CodeBlockGrouper($options);
        $this->lists = new ListGrouper($numbering);
        $this->quotes = new QuoteGrouper($options);
    }

    /**
     * @return list<Block>
     */
    public function read(Package $package): array
    {
        $relationships = $package->relationships();

        $document = $package->document();
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::W_NS);

        $body = $xpath->query('/w:document/w:body')->item(0);
        if (!$body instanceof DOMElement) {
            return [];
        }

        $inline = new InlineReader($this->options, $relationships, $document);

        $units = $this->readUnits($xpath, $body, $inline);
        $units = $this->codeBlocks->group($units);
        $units = $this->lists->group($units);

        return $this->quotes->nest($units);
    }

    /**
     * @return list<Block>
     */
    private function readUnits(DOMXPath $xpath, DOMElement $body, InlineReader $inline): array
    {
        $units = [];

        foreach ($xpath->query('./w:p | ./w:tbl', $body) ?: [] as $node) {
            /** @var DOMElement $node */
            $unit = $node->localName === 'tbl'
                ? $this->readTable($xpath, $node, $inline)
                : $this->readParagraph($xpath, $node, $inline);

            if ($unit !== null) {
                $units[] = $unit;
            }
        }

        return $units;
    }

    /**
     * A paragraph, a heading, or a horizontal rule.
     */
    private function readParagraph(DOMXPath $xpath, DOMElement $paragraph, InlineReader $inline): ?Block
    {
        // The style first: it says what every run in the paragraph already looks
        // like, which is not emphasis the author typed.
        $properties = $this->paragraphProperties($xpath, $paragraph);
        $inlines = $inline->read($paragraph, $this->inheritedEmphasis($properties['style']));

        if ($this->isRule($xpath, $paragraph, $inlines)) {
            // The indentation rides along so that a rule inside a block quote is
            // recognised as being inside it.
            return Block::rule()->withAttrs(['indent' => $properties['indent']]);
        }

        return Block::paragraph($inlines, $properties['level'], [
            'style' => $properties['style'],
            'indent' => $properties['indent'],
            'alignment' => $properties['alignment'],
            'numId' => $properties['numId'],
            'listLevel' => $properties['listLevel'],
            'tight' => $properties['tight'],
        ]);
    }

    /**
     * The block properties the grouping passes need.
     *
     * @return array{style: string, indent: int, alignment: string, level: ?int,
     *               numId: ?int, listLevel: int, tight: bool}
     */
    private function paragraphProperties(DOMXPath $xpath, DOMElement $paragraph): array
    {
        $style = '';
        $styleNode = $xpath->query('./w:pPr/w:pStyle', $paragraph)?->item(0);
        if ($styleNode instanceof DOMElement) {
            $style = $styleNode->getAttributeNS(self::W_NS, 'val');
        }

        $level = $this->headingLevel($style);

        // Direct formatting wins; otherwise the style decides. The outermost block
        // quote carries no indentation of its own because it inherits one, so the
        // style has to be consulted for the nesting depth to be recoverable.
        $indent = 0;
        $indentation = $xpath->query('./w:pPr/w:ind', $paragraph)?->item(0);
        if ($indentation instanceof DOMElement) {
            $left = $indentation->getAttributeNS(self::W_NS, 'left');
            $indent = $left === '' ? 0 : (int) $left;
        } elseif ($style !== '') {
            $indent = $this->styles->indentOf($style);
        }

        $alignment = '';
        $alignmentNode = $xpath->query('./w:pPr/w:jc', $paragraph)?->item(0);
        if ($alignmentNode instanceof DOMElement) {
            $alignment = $alignmentNode->getAttributeNS(self::W_NS, 'val');
        } elseif ($style !== '') {
            $alignment = $this->styles->alignmentOf($style);
        }

        $numId = null;
        $listLevel = 0;

        $numIdNode = $xpath->query('./w:pPr/w:numPr/w:numId', $paragraph)?->item(0);
        if ($numIdNode instanceof DOMElement) {
            $candidate = (int) $numIdNode->getAttributeNS(self::W_NS, 'val');

            // A number that is not in the numbering part cannot be turned into a
            // marker, so the paragraph stays what it visibly is.
            if ($this->numbering->knows($candidate)) {
                $numId = $candidate;
            }
        }

        $levelNode = $xpath->query('./w:pPr/w:numPr/w:ilvl', $paragraph)?->item(0);
        if ($levelNode instanceof DOMElement) {
            $listLevel = (int) $levelNode->getAttributeNS(self::W_NS, 'val');
        }

        return [
            'style' => $style,
            'indent' => $indent,
            'alignment' => $alignment,
            'level' => $level,
            'numId' => $numId,
            'listLevel' => $listLevel,
            // A tight list has its items run together, which Word records as
            // paragraphs with no space above or below; a loose one leaves the
            // spacing alone, so the difference is visible.
            'tight' => $this->isTight($xpath, $paragraph),
        ];
    }

    /**
     * Whether a paragraph opts out of the spacing between list items.
     */
    private function isTight(DOMXPath $xpath, DOMElement $paragraph): bool
    {
        $spacing = $xpath->query('./w:pPr/w:spacing', $paragraph)?->item(0);

        if (!$spacing instanceof DOMElement) {
            return false;
        }

        return $spacing->getAttributeNS(self::W_NS, 'before') === '0'
            && $spacing->getAttributeNS(self::W_NS, 'after') === '0';
    }

    /**
     * The heading level a paragraph style stands for, or null.
     *
     * A custom style is a corporate decision rather than Markdown structure, so
     * unless its name carries the level it is read as body text.
     */
    private function headingLevel(string $style): ?int
    {
        if ($style === '') {
            return null;
        }

        $matched = null;

        foreach ($this->options->headingStyles as $candidate) {
            if (stripos($style, $candidate) !== 0) {
                continue;
            }

            $suffix = substr($style, strlen($candidate));

            if ($suffix === '') {
                $matched ??= 1;

                continue;
            }

            if (ctype_digit($suffix) && (int) $suffix >= 1 && (int) $suffix <= 6) {
                return (int) $suffix;
            }
        }

        return $matched;
    }

    /**
     * An empty paragraph carrying a bottom border is a horizontal rule.
     *
     * @param list<Inline> $inlines
     */
    private function isRule(DOMXPath $xpath, DOMElement $paragraph, array $inlines): bool
    {
        if ($inlines !== []) {
            foreach ($inlines as $inline) {
                if ($inline->kind === Inline::BREAK || trim($inline->text) !== '') {
                    return false;
                }
            }
        }

        return $xpath->query('./w:pPr/w:pBdr/w:bottom', $paragraph)?->length > 0;
    }

    private function readTable(DOMXPath $xpath, DOMElement $table, InlineReader $inline): Block
    {
        $rows = [];
        $alignments = [];

        foreach ($xpath->query('./w:tr', $table) ?: [] as $rowNode) {
            /** @var DOMElement $rowNode */
            $cells = [];

            foreach ($xpath->query('./w:tc', $rowNode) ?: [] as $cellNode) {
                /** @var DOMElement $cellNode */
                $cells[] = Block::cell($this->readUnits($xpath, $cellNode, $inline));
            }

            if ($rows === []) {
                // The alignment lives on the paragraphs inside each cell, because
                // that is the only place a Word cell can carry it.
                foreach ($xpath->query('./w:tc', $rowNode) ?: [] as $index => $cellNode) {
                    $alignments[$index] = $this->cellAlignment($xpath, $cellNode);
                }
            }

            $rows[] = Block::row($cells);
        }

        ksort($alignments);

        return Block::table($rows, $alignments);
    }

    private function cellAlignment(DOMXPath $xpath, DOMElement $cell): string
    {
        $alignment = $xpath->query('./w:p[1]/w:pPr/w:jc', $cell)?->item(0);

        if (!$alignment instanceof DOMElement) {
            return '';
        }

        return $alignment->getAttributeNS(self::W_NS, 'val');
    }

    /**
     * What the paragraph's own style already makes every run in it look like.
     *
     * @return array{bold: bool, italic: bool, strike: bool}
     */
    private function inheritedEmphasis(string $style): array
    {
        return $style === ''
            ? ['bold' => false, 'italic' => false, 'strike' => false]
            : $this->styles->emphasisOf($style);
    }
}
