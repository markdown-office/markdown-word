<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use MarkdownWord\Configuration\Furniture;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Style\Tab;

/**
 * The paragraph a {@see Furniture} asks for: where the text sits, and where the rule
 * under it goes.
 *
 * PHPWord will not do any of this on request, which is the reason it is here rather than
 * three lines inside the writer:
 *
 * - **Alignment** is a `w:jc`, which is all `left` needs and not enough for the other
 *   two. A running head is `title … page`, and pushing the page number to the margin is
 *   a tab stop, not a justification — `w:jc` centres a line of text, which is not the
 *   same thing at all. The centre and right stops are therefore set explicitly, on the
 *   fields' paragraph alone: a paragraph given both answers the same question twice, and
 *   a reader that honours the stop and one that centres the line then disagree.
 *   {@see self::leadsWithTab()} says which stop the paragraph reaches for.
 * - **A border** is a paragraph border, drawn exactly as `thematicBreak => 'border'`
 *   draws its rule in {@see \MarkdownWord\Render\DocumentRenderer}: one edge, one weight,
 *   one automatic colour, and a little air either side so the rule is not welded to the
 *   text. `both` is the one case that is not the same rule twice, because a header with
 *   a rule above and below it is a box and the two edges are the whole of it.
 *
 * Nothing here writes an element. It answers what the paragraph style is, and
 * {@see self::applyTo()} puts that on a paragraph the renderer already made — the
 * renderer owns the body of the document, and a header's body is the same walk with a
 * different destination rather than a second implementation of it.
 */
final class FurnitureStyle
{
    /**
     * One edge of the rule: a hairline in whatever colour the theme is drawing in.
     *
     * The same three values {@see \MarkdownWord\Render\DocumentRenderer} uses for a
     * thematic break, because a rule under a running head is that rule.
     */
    private const RULE = ['Style' => 'single', 'Size' => 6, 'Color' => 'auto'];

    /** The air either side of the rule, for the same reason and in the same amount. */
    private const RULE_SPACE = ['space' => ['before' => 120, 'after' => 120]];

    /** @var list<string> The alignments that are a tab stop rather than a justification. */
    private const STOPPED = ['center', 'right'];

    /**
     * @param Furniture $furniture What the document asked for.
     * @param int $column The width of the text column the furniture sits in, in twips.
     *        A tab stop is a position on the page, so it has to be measured against the
     *        section's own margins rather than a default nobody chose.
     */
    public function __construct(
        private readonly Furniture $furniture,
        private readonly int $column,
    ) {
    }

    /**
     * The paragraph style for the words the author wrote.
     *
     * @return array<string, mixed>
     */
    public function bodyStyle(): array
    {
        $style = [];

        if ($this->furniture->align !== null) {
            $style['alignment'] = $this->furniture->align;
        }

        $style += $this->rule();

        return $style;
    }

    /**
     * The paragraph style for the fields, which is a tab stop and not a justification.
     *
     * The two are different questions and a paragraph answers both at once, so it must
     * answer only one of them: `w:jc` centres the *line* — a tab and a page number are a
     * line — while a tab stop centres the run that follows the tab, which is what a page
     * number in the middle of a running head means. Given both, a reader that honours the
     * stop and one that centres the line disagree, and which of them you get is the
     * reader's business rather than the document's.
     *
     * @return array<string, mixed>
     */
    public function switchStyle(): array
    {
        $stops = $this->stops();

        return $stops === [] ? [] : ['tabs' => $stops];
    }

    /**
     * Whether the paragraph's content is introduced by a tab.
     *
     * True only for the alignments that are tab stops. A `left` running head has nothing
     * to tab to, and a tab there would push the page number away from the margin the
     * alignment named — the opposite of what was asked for.
     */
    public function leadsWithTab(): bool
    {
        return in_array($this->furniture->align, self::STOPPED, true);
    }

    /**
     * Put this furniture's paragraph style onto a paragraph the renderer made.
     *
     * A named style cannot simply be given the extra properties: Word resolves a
     * `w:pStyle` wholesale, so the name is kept and the properties go beside it through
     * {@see ParagraphStyle::namedWith()}, which is what Word reads as a paragraph that
     * inherits a style and then overrides one thing about it.
     */
    public function applyTo(AbstractElement $element): void
    {
        if (!$element instanceof TextRun) {
            return;
        }

        $style = $this->bodyStyle();

        if ($style === []) {
            return;
        }

        $current = $element->getParagraphStyle();

        $element->setParagraphStyle(is_string($current)
            ? ParagraphStyle::namedWith($current, $style)
            : ParagraphStyle::merge($current, $style));
    }

    /**
     * The rule, or nothing at all — the air goes with it rather than being added to a
     * paragraph that asked for no rule, which would be a header with a gap above it.
     *
     * @return array<string, mixed>
     */
    private function rule(): array
    {
        $edges = match ($this->furniture->border) {
            'top' => self::edge('Top'),
            'bottom' => self::edge('Bottom'),
            'both' => self::edge('Top') + self::edge('Bottom'),
            default => null,
        };

        return $edges === null ? [] : $edges + self::RULE_SPACE;
    }

    /**
     * @return array<string, mixed>
     */
    private static function edge(string $side): array
    {
        return [
            'border' . $side . 'Style' => self::RULE['Style'],
            'border' . $side . 'Size' => self::RULE['Size'],
            'border' . $side . 'Color' => self::RULE['Color'],
        ];
    }

    /**
     * The tab stops this alignment reaches for.
     *
     * @return list<Tab>
     */
    private function stops(): array
    {
        return match ($this->furniture->align) {
            'center' => [new Tab(Tab::TAB_STOP_CENTER, intdiv($this->column, 2))],
            'right' => [new Tab(Tab::TAB_STOP_RIGHT, $this->column)],
            default => [],
        };
    }
}
