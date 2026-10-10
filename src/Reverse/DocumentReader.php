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
 */
final class DocumentReader
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const A_NS = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const WP_NS = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
    private const V_NS = 'urn:schemas-microsoft-com:vml';
    private const O_NS = 'urn:schemas-microsoft-com:office:office';

    /** @var list<array<int, string>> Typefaces considered monospaced, lower-cased. */
    private array $monospace = [];

    /** @var array<string, string> Relationship id => target. */
    private array $relationships = [];

    public function __construct(
        private readonly Options $options,
        private readonly StyleTable $styles,
        private readonly NumberingTable $numbering,
    ) {
        $this->monospace = array_map(
            static fn (string $font): string => mb_strtolower($font),
            $options->monospaceFonts,
        );
    }

    /**
     * @return list<Block>
     */
    public function read(Package $package): array
    {
        $this->relationships = $package->relationships();

        $document = $package->document();
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::W_NS);
        $xpath->registerNamespace('r', self::R_NS);
        $xpath->registerNamespace('a', self::A_NS);
        $xpath->registerNamespace('wp', self::WP_NS);
        $xpath->registerNamespace('v', self::V_NS);
        $xpath->registerNamespace('o', self::O_NS);

        $body = $xpath->query('/w:document/w:body')->item(0);
        if (!$body instanceof DOMElement) {
            return [];
        }

        $units = $this->readUnits($xpath, $body);
        $units = $this->groupCodeBlocks($units);
        $units = $this->groupLists($units);

        return $this->nestQuotes($units);
    }

    /**
     * @return list<Block>
     */
    private function readUnits(DOMXPath $xpath, DOMElement $body): array
    {
        $units = [];

        foreach ($xpath->query('./w:p | ./w:tbl', $body) ?: [] as $node) {
            /** @var DOMElement $node */
            $unit = $node->localName === 'tbl'
                ? $this->readTable($xpath, $node)
                : $this->readParagraph($xpath, $node);

            if ($unit !== null) {
                $units[] = $unit;
            }
        }

        return $units;
    }

    /**
     * A paragraph, a heading, or a horizontal rule.
     */
    private function readParagraph(DOMXPath $xpath, DOMElement $paragraph): ?Block
    {
        // The style first: it says what every run in the paragraph already looks
        // like, which is not emphasis the author typed.
        $properties = $this->paragraphProperties($xpath, $paragraph);
        $inlines = $this->readInlines($xpath, $paragraph, $this->inheritedEmphasis($properties['style']));

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

    private function isQuoteStyle(string $style): bool
    {
        foreach ($this->options->quoteStyles as $candidate) {
            if (strcasecmp($style, $candidate) === 0) {
                return true;
            }
        }

        return false;
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

    private function readTable(DOMXPath $xpath, DOMElement $table): Block
    {
        $rows = [];
        $alignments = [];

        foreach ($xpath->query('./w:tr', $table) ?: [] as $rowNode) {
            /** @var DOMElement $rowNode */
            $cells = [];

            foreach ($xpath->query('./w:tc', $rowNode) ?: [] as $cellNode) {
                /** @var DOMElement $cellNode */
                $cells[] = Block::cell($this->readUnits($xpath, $cellNode));
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

    /**
     * @param array{bold: bool, italic: bool, strike: bool} $inherited
     * @return list<Inline>
     */
    private function readInlines(DOMXPath $xpath, DOMElement $paragraph, array $inherited = []): array
    {
        $inlines = [];

        foreach ($paragraph->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            switch ($child->localName) {
                case 'hyperlink':
                    $inlines = array_merge($inlines, $this->readHyperlink($xpath, $child, $inherited));

                    break;

                case 'r':
                    $inlines = array_merge($inlines, $this->readRun($xpath, $child, $inherited));

                    break;

                // A break written outside any run, which is how PHPWord emits a
                // line break that is not itself formatted.
                case 'br':
                case 'cr':
                    if ($child->getAttributeNS(self::W_NS, 'type') === '') {
                        $inlines[] = Inline::break();
                    }

                    break;

                case 'tab':
                    $inlines[] = Inline::text("\t");

                    break;

                case 'drawing':
                case 'pict':
                    $image = $this->readImage($xpath, $child);
                    if ($image !== null) {
                        $inlines[] = $image;
                    }

                    break;

                // Anything else is a run-level property change, which Word writes
                // around a field and which carries no content.
                default:
                    break;
            }
        }

        return $this->merge($inlines);
    }

    /**
     * @param array{bold: bool, italic: bool, strike: bool} $inherited
     * @return list<Inline>
     */
    private function readHyperlink(DOMXPath $xpath, DOMElement $hyperlink, array $inherited = []): array
    {
        $id = $hyperlink->getAttributeNS(self::R_NS, 'id');
        $url = $this->relationships[$id] ?? '';

        $title = $hyperlink->getAttributeNS(self::W_NS, 'tooltip');
        $title = $title === '' ? null : $title;

        // A link with no resolvable destination is not a link, as on the way in.
        if ($url === '') {
            return $this->readChildren($xpath, $hyperlink, $inherited);
        }

        $label = [];
        foreach ($hyperlink->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'r') {
                $label = array_merge($label, $this->readRun($xpath, $child, $inherited));
            }
        }

        return [Inline::link($url, $title, $this->merge($label))];
    }

    /**
     * @param array{bold: bool, italic: bool, strike: bool} $inherited
     * @return list<Inline>
     */
    private function readChildren(DOMXPath $xpath, DOMElement $parent, array $inherited = []): array
    {
        $inlines = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'r') {
                $inlines = array_merge($inlines, $this->readRun($xpath, $child, $inherited));
            }
        }

        return $this->merge($inlines);
    }

    /**
     * Emphasis is what the run adds to the style it sits in, not what the run
     * carries: Word writes the paragraph style's own weight onto the runs too, and
     * reading that as `**` makes every bold heading come back as `# **Heading**`.
     *
     * @param array{bold: bool, italic: bool, strike: bool} $inherited
     * @return list<Inline>
     */
    private function readRun(DOMXPath $xpath, DOMElement $run, array $inherited = []): array
    {
        $bold = false;
        $italic = false;
        $strike = false;
        $code = false;

        $properties = $xpath->query('./w:rPr', $run)?->item(0);
        if ($properties instanceof DOMElement) {
            $bold = $this->isOn($xpath, $properties, 'w:b') && !($inherited['bold'] ?? false);
            $italic = $this->isOn($xpath, $properties, 'w:i') && !($inherited['italic'] ?? false);
            $strike = $this->isOn($xpath, $properties, 'w:strike') && !($inherited['strike'] ?? false);
            $code = $this->isMonospace($xpath, $properties);
        }

        $inlines = [];

        foreach ($run->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            if ($child->localName === 't') {
                $inlines[] = Inline::text($child->textContent, $bold, $italic, $strike, $code);

                continue;
            }

            // `w:br` is a line break the author asked for; a break with a type
            // is a column or page break, which is layout rather than content.
            if ($child->localName === 'br' && $child->getAttributeNS(self::W_NS, 'type') === '') {
                $inlines[] = Inline::break();

                continue;
            }

            if ($child->localName === 'tab') {
                $inlines[] = Inline::text("\t", $bold, $italic, $strike, $code);
            }
        }

        $image = $this->readImage($xpath, $run);
        if ($image !== null) {
            $inlines[] = $image;
        }

        return $inlines;
    }

    private function isOn(DOMXPath $xpath, DOMElement $properties, string $name): bool
    {
        $node = $xpath->query('./' . $name, $properties)?->item(0);

        if (!$node instanceof DOMElement) {
            return false;
        }

        // A toggle property carries an explicit `w:val`; "0", "false" and "off"
        // all mean off, and anything else means on.
        $value = strtolower($node->getAttributeNS(self::W_NS, 'val'));

        return !in_array($value, ['0', 'false', 'off'], true);
    }

    private function isMonospace(DOMXPath $xpath, DOMElement $properties): bool
    {
        $fonts = $xpath->query('./w:rFonts', $properties)?->item(0);

        if (!$fonts instanceof DOMElement) {
            return false;
        }

        foreach (['ascii', 'hAnsi', 'cs'] as $attribute) {
            $name = mb_strtolower($fonts->getAttributeNS(self::W_NS, $attribute));

            if ($name !== '' && in_array($name, $this->monospace, true)) {
                return true;
            }
        }

        return false;
    }

    private function readImage(DOMXPath $xpath, DOMElement $run): ?Inline
    {
        // Two shapes describe an image in OOXML: DrawingML, which is what Word
        // writes today, and VML, the older form PHPWord emits for a plain inline
        // picture and that this library's own documents are full of.
        $blip = $xpath->query('.//a:blip', $run)?->item(0);
        $id = $blip instanceof DOMElement
            ? $blip->getAttributeNS(self::R_NS, 'embed')
            : '';

        $alt = '';

        if ($id !== '') {
            // The description is the alt text, and also the fallback a reader with
            // no image support sees.
            $properties = $xpath->query('.//wp:docPr', $run)?->item(0);
            if ($properties instanceof DOMElement) {
                $alt = $properties->getAttribute('descr');
            }
        } else {
            $imagedata = $xpath->query('.//v:imagedata', $run)?->item(0);

            if (!$imagedata instanceof DOMElement) {
                return null;
            }

            $id = $imagedata->getAttributeNS(self::R_NS, 'id');
            $alt = $imagedata->getAttributeNS(self::O_NS, 'title');
        }

        return Inline::image($alt, $this->mediaPath($this->relationships[$id] ?? ''));
    }

    /**
     * Where an image is referenced from in the Markdown, per
     * {@see Options::$mediaDirectory}.
     */
    private function mediaPath(string $target): string
    {
        if ($target === '') {
            return '';
        }

        $relative = (string) preg_replace('#^\./#', '', $target);

        if ($this->options->mediaDirectory === null) {
            return $relative;
        }

        return rtrim($this->options->mediaDirectory, '/') . '/' . basename($relative);
    }

    /**
     * Merge neighbouring runs that are formatted identically.
     *
     * Word splits a sentence into a run per formatting change, and often into
     * more than that, so plain prose would otherwise come back as fragments.
     *
     * @param list<Inline> $inlines
     * @return list<Inline>
     */
    private function merge(array $inlines): array
    {
        $merged = [];

        foreach ($inlines as $inline) {
            $last = $merged === [] ? null : $merged[count($merged) - 1];

            if (
                $last !== null
                && $last->kind === Inline::TEXT
                && $inline->kind === Inline::TEXT
                && $last->sameFormatting($inline)
            ) {
                $merged[count($merged) - 1] = $last->withText($last->text . $inline->text);

                continue;
            }

            $merged[] = $inline;
        }

        return $merged;
    }

    /**
     * Join runs of monospaced paragraphs into a single verbatim block.
     *
     * Word has no code block, so one becomes a paragraph per line in a
     * monospaced face. A run of two or more is a block; a single paragraph is
     * left alone, because a paragraph holding only an inline code span looks
     * exactly the same and is far more common.
     *
     * @param list<Block> $units
     * @return list<Block>
     */
    private function groupCodeBlocks(array $units): array
    {
        if (!$this->options->fenceCodeBlocks) {
            return $units;
        }

        $result = [];
        $pending = [];
        $index = 0;

        while ($index < count($units)) {
            $unit = $units[$index];

            if ($this->isMonospaceParagraph($unit)) {
                $pending[] = $unit;
                $index++;

                continue;
            }

            $result = array_merge($result, $this->flushCodeBlock($pending));
            $pending = [];
            $result[] = $unit;
            $index++;
        }

        return array_merge($result, $this->flushCodeBlock($pending));
    }

    /**
     * @param list<Block> $pending
     * @return list<Block>
     */
    private function flushCodeBlock(array $pending): array
    {
        if (count($pending) < 2) {
            return $pending;
        }

        $lines = [];
        foreach ($pending as $unit) {
            $lines[] = $this->plainText($unit->inlines);
        }

        // The indentation of the first line rides along, for the same reason a
        // rule's does: a code block inside a block quote has to stay inside it.
        return [Block::code(implode("\n", $lines))->withAttrs([
            'indent' => (int) ($pending[0]->attr('indent', 0)),
        ])];
    }

    private function isMonospaceParagraph(Block $unit): bool
    {
        if (!$unit->is(Block::PARAGRAPH) || $unit->attr('level') !== null) {
            return false;
        }

        if ($unit->inlines === []) {
            return false;
        }

        foreach ($unit->inlines as $inline) {
            if ($inline->kind !== Inline::TEXT || !$inline->code) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Inline> $inlines
     */
    private function plainText(array $inlines): string
    {
        $text = '';

        foreach ($inlines as $inline) {
            $text .= $inline->kind === Inline::TEXT ? $inline->text : "\n";
        }

        return $text;
    }

    /**
     * Turn units that reference a numbering definition into nested lists.
     *
     * The level comes from the paragraph, so nesting is exact rather than guessed
     * at from indentation. A run of paragraphs sharing a definition at the same
     * level is one list and a different definition starts a new one, which is how
     * two adjacent Markdown lists stay two lists. Two adjacent lists that share a
     * definition cannot be told apart and come back as one.
     *
     * @param list<Block> $units
     * @return list<Block>
     */
    private function groupLists(array $units): array
    {
        $result = [];

        /** @var list<array{numId: int, level: int, indent: int, list: Block, parent: int, item: ?int}> $stack */
        $stack = [];

        foreach ($units as $unit) {
            $numId = $this->listNumberId($unit);

            if ($numId === null) {
                $this->closeLists($stack, $result);
                $result[] = $unit;

                continue;
            }

            $level = (int) $unit->attr('listLevel', 0);
            $indent = (int) $unit->attr('indent', 0);

            // A deeper item, or one belonging to a different list, ends whatever
            // is open.
            $this->closeLists($stack, $result, $numId, $level, $indent);

            if ($this->depth($stack) !== $level) {
                $list = Block::list([], $this->listAttributes($numId, $level) + [
                    // A list inside a block quote is only recorded as an indented
                    // numbered paragraph; the indentation is what keeps it inside
                    // the quote and tells it from the same list outside it.
                    'indent' => $indent,
                    'tight' => (bool) $unit->attr('tight', false),
                ]);

                $stack[] = [
                    'numId' => $numId,
                    'level' => $level,
                    'indent' => $indent,
                    'list' => $list,
                    // Where this list belongs inside the item it is nested in.
                    // Remembered rather than acted on now: the list is still empty
                    // and its parent is still being built.
                    'parent' => $stack === [] ? -1 : count($stack) - 1,
                    'item' => $this->lastItem($stack),
                ];
            }

            $this->appendItem($stack, Block::item([$unit->withAttrs(['numId' => null])]));
        }

        $this->closeLists($stack, $result);

        return $result;
    }

    /**
     * The numbering definition a unit belongs to, or null if it is not a list.
     */
    private function listNumberId(Block $unit): ?int
    {
        if (!$unit->is(Block::PARAGRAPH)) {
            return null;
        }

        $numId = $unit->attr('numId');

        return is_int($numId) ? $numId : null;
    }

    /**
     * How a list's marker is written, as recorded in the numbering definition.
     *
     * @return array<string, mixed>
     */
    private function listAttributes(int $numId, int $level): array
    {
        $definition = $this->numbering->level($numId, $level)
            ?? $this->numbering->root($numId)
            ?? ['format' => 'decimal', 'text' => '%1.', 'start' => 1];

        return [
            'numId' => $numId,
            'level' => $level,
            'ordered' => $definition['format'] !== 'bullet',
            'format' => $definition['format'],
            'start' => $definition['start'],
            // The marker is baked into the numbering text, so its last character
            // is what tells `1.` from `1)`.
            'delimiter' => str_ends_with($definition['text'], ')') ? ')' : '.',
        ];
    }

    /**
     * Close every open list the paragraph that follows ends.
     *
     * A list is identified by its numbering definition, its level and its
     * indentation; the last of those is what separates two lists that share a
     * definition. It is not handed to the document when it opens but when it
     * closes, because it is still growing then. A nested one has already been
     * attached to the item it belongs to; a top-level one lands here.
     *
     * @param list<array{numId: int, level: int, indent: int, list: Block, parent: int, item: ?int}> $stack
     * @param list<Block>                                                                            $result
     */
    private function closeLists(
        array &$stack,
        array &$result,
        ?int $numId = null,
        ?int $level = null,
        ?int $indent = null,
    ): void {
        while ($stack !== []) {
            $top = $stack[count($stack) - 1];

            $ends = $numId === null
                || $top['level'] > $level
                || ($top['level'] === $level && ($top['numId'] !== $numId || $top['indent'] !== $indent));

            if (!$ends) {
                break;
            }

            $closed = array_pop($stack);

            if ($closed['parent'] < 0) {
                $result[] = $closed['list'];

                continue;
            }

            // A nested list goes into the item it belongs under. That item is
            // still the last one of its list, because a sibling at the same level
            // would have closed this list before it was reached.
            $index = $closed['parent'];
            $items = array_values($stack[$index]['list']->children);
            $item = $closed['item'];

            if ($item !== null && isset($items[$item])) {
                $items[$item] = $items[$item]->withChildren([...$items[$item]->children, $closed['list']]);
                $stack[$index]['list'] = $stack[$index]['list']->withChildren($items);
            }
        }
    }

    /**
     * @param list<array{numId: int, level: int, indent: int, list: Block, parent: int, item: ?int}> $stack
     */
    private function lastItem(array $stack): ?int
    {
        if ($stack === []) {
            return null;
        }

        $items = array_values($stack[count($stack) - 1]['list']->children);

        return $items === [] ? null : array_key_last($items);
    }

    /**
     * @param list<array{numId: int, level: int, indent: int, list: Block, parent: int, item: ?int}> $stack
     */
    private function appendItem(array &$stack, Block $item): void
    {
        $index = count($stack) - 1;
        $stack[$index]['list'] = $stack[$index]['list']->withChildren(
            [...$stack[$index]['list']->children, $item],
        );
    }

    /**
     * @param list<array{numId: int, level: int, indent: int, list: Block, parent: int, item: ?int}> $stack
     */
    private function depth(array $stack): ?int
    {
        return $stack === [] ? null : $stack[count($stack) - 1]['level'];
    }

    /**
     * Nest the units that are drawn with a quote style.
     *
     * Depth comes from the indentation divided by {@see Options::$quoteIndent},
     * and only a quote style opens a quote at all — an indented list is a list.
     *
     * A quote ends only when the unit that follows is *shallower* than it, and a
     * unit at a depth that is already open lands in the quote that is already
     * there. Closing a quote at the depth that has just been entered would turn
     * the second paragraph of a two-paragraph quote into a quote of its own, and
     * a block quote spanning two paragraphs is about the most ordinary one there
     * is.
     *
     * @param list<Block> $units
     * @return list<Block>
     */
    private function nestQuotes(array $units): array
    {
        $result = [];

        /** @var list<array{depth: int, blocks: list<Block>}> $frames */
        $frames = [];

        foreach ($units as $unit) {
            $depth = $this->quoteDepth($unit);

            if ($depth === null) {
                $result = $this->closeDeeperQuotes($frames, $result, $unit);
            } else {
                // Only frames deeper than this unit's depth close.
                while ($frames !== [] && $frames[count($frames) - 1]['depth'] > $depth) {
                    $result = $this->closeQuote($frames, $result);
                }

                if ($this->openQuoteDepth($frames) !== $depth) {
                    $frames[] = ['depth' => $depth, 'blocks' => []];
                }
            }

            $result = $this->add($frames, $result, $unit);
        }

        while ($frames !== []) {
            $result = $this->closeQuote($frames, $result);
        }

        return $result;
    }

    /**
     * @param list<array{depth: int, blocks: list<Block>}> $frames
     */
    private function openQuoteDepth(array $frames): ?int
    {
        return $frames === [] ? null : $frames[count($frames) - 1]['depth'];
    }

    /**
     * @param list<array{depth: int, blocks: list<Block>}> $frames
     * @param list<Block>                                    $result
     * @return list<Block>
     */
    private function closeDeeperQuotes(array &$frames, array $result, Block $unit): array
    {
        $indent = (int) $unit->attr('indent', 0);
        $step = max(1, $this->options->quoteIndent);

        while ($frames !== [] && $indent < $frames[count($frames) - 1]['depth'] * $step) {
            $result = $this->closeQuote($frames, $result);
        }

        return $result;
    }

    private function quoteDepth(Block $unit): ?int
    {
        if (!$unit->is(Block::PARAGRAPH) || !$this->isQuoteStyle((string) $unit->attr('style', ''))) {
            return null;
        }

        $indent = (int) $unit->attr('indent', 0);
        $step = max(1, $this->options->quoteIndent);

        return $indent <= 0 ? 1 : max(1, (int) round($indent / $step));
    }

    /**
     * @param list<array{depth: int, blocks: list<Block>}> $frames
     * @param list<Block>                                    $result
     * @return list<Block>
     */
    private function add(array &$frames, array $result, Block $block): array
    {
        if ($frames === []) {
            $result[] = $block;

            return $result;
        }

        $index = count($frames) - 1;
        $frames[$index]['blocks'][] = $block;

        return $result;
    }

    /**
     * @param list<array{depth: int, blocks: list<Block>}> $frames
     * @param list<Block>                                    $result
     * @return list<Block>
     */
    private function closeQuote(array &$frames, array $result): array
    {
        $closed = array_pop($frames);

        if ($closed === null || $closed['blocks'] === []) {
            return $result;
        }

        return $this->add($frames, $result, Block::quote($closed['blocks']));
    }
}
