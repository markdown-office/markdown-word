<?php

declare(strict_types=1);

namespace MarkdownWord\Render\Directive;

use MarkdownOffice\CommonMark\Directive\Node\DirectiveInline;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Format;
use MarkdownWord\Render\InlineStyle;
use MarkdownWord\Render\ParagraphStyle;
use MarkdownWord\Render\StyleResolver;
use PhpOffice\PhpWord\Element\AbstractContainer;

/**
 * `:footnote[…]` — a Word footnote, with its mark where the directive stood.
 *
 * The mark goes in the run the directive was written in, and that is the whole of
 * why there is no pass over the finished document. PHPWord writes
 * `Writer\Word2007\Element\Footnote` as a paragraph of its own, so a footnote added
 * to a section splits the sentence it sits in — but `AbstractContainer::checkValidity()`
 * lists `TextRun` among the containers a `Footnote` may go in, and the writer's
 * `startElementP()`/`endElementP()` write nothing when they are inside one. Adding
 * the note to the run therefore emits exactly what Word emits: a `w:r` carrying
 * `w:footnoteReference` between the words either side, inside the one `w:p`.
 *
 * That also settles the id. Both halves come from PHPWord's own arithmetic —
 * `getRelationId() + 1` in `Element\Footnote` for the mark and in
 * `Part\Footnotes` for the body — so a mark cannot end up pointing at a note other
 * than its own, and `word/footnotes.xml`, its relationship and its content-type
 * entry are written by the code that already gets them right.
 *
 * **The body is text, never Markdown.** The directives package parses a leaf label
 * into a `Text` child rather than as inline Markdown
 * ({@see \MarkdownOffice\CommonMark\Directive\Parser\DirectiveInlineParser} says
 * why), so there is nothing to render: `getLabel()` is what the author wrote between
 * the brackets and it goes in as written.
 *
 * `.odt` and `.rtf` have no footnote writer at all — there is no
 * `Element/Footnote.php` under either `Writer/ODText/` or `Writer/RTF/` — so they
 * are given the source form instead, the same answer an unclaimed directive gets,
 * and {@see self::isUsed()} reports the loss.
 */
final class Footnote
{
    /**
     * Word's own style for the text of a footnote.
     *
     * Passed as a bare name rather than as a `Paragraph`: `Style\Paragraph` has no
     * constructor, so an array given to `new Paragraph()` is accepted and discarded,
     * and `setStyleByArray()`'s `textStyle` — which is the key PHPWord's own
     * documentation example uses — maps to a setter that does not exist and is dropped
     * for the same reason. The name is the only form that reaches the part.
     *
     * Referenced and not defined. That is the point: Word resolves `FootnoteText` to
     * its own look from the reference alone, so a document that does not define it
     * still draws the note as a footnote rather than as body text. Nothing here writes
     * a definition, and adding one would take the reader's look away.
     */
    private const BODY_STYLE = 'FootnoteText';

    /** Whether the document asked for a footnote, whatever format it is written in. */
    private bool $used = false;

    /**
     * @param bool $carries Whether a footnote drawn here can reach the finished
     *        document. False when the rendered elements are going to be copied into a
     *        document this library is not writing: the elements travel and the
     *        `word/footnotes.xml` they were registered in does not.
     */
    public function __construct(
        private readonly StyleResolver $styles,
        private readonly ?Format $format = null,
        private readonly bool $carries = true,
    ) {
    }

    public function __invoke(DirectiveInline $node, AbstractContainer $target, InlineStyle $style): void
    {
        $body = $node->getLabel() ?? '';

        // A mark pointing at an empty note is a mark the reader cannot resolve, so a
        // directive with no body is drawn as what it is rather than as a footnote. The
        // use is not recorded either: nothing was lost, because nothing was asked for
        // that this library could have carried.
        if ($body === '') {
            $target->addText((string) $node, $this->styles->fontFor($style));

            return;
        }

        $this->used = true;

        // Only `.docx` can carry a footnote. The token a placeholder-and-pass design
        // would leave behind is not emitted at all, so there is nothing for a writer
        // that cannot do this to fail to take back out.
        if (!$this->carries || ($this->format ?? Format::Docx) !== Format::Docx) {
            $target->addText((string) $node, $this->styles->fontFor($style));

            return;
        }

        $note = $target->addFootnote(self::BODY_STYLE);
        $note->addText($body, $this->bodyFont());
    }

    /**
     * Whether a footnote was asked for.
     *
     * Asked here rather than read back off the element tree, because a writer that
     * cannot carry a footnote is given none — and that is exactly the difference
     * between a loss to report and a document that never wanted the thing.
     */
    public function isUsed(): bool
    {
        return $this->used;
    }

    /**
     * The document's body face, which is what a note is set in.
     *
     * Not the face of the paragraph the mark sits in: a footnote under a heading
     * would otherwise be drawn in the heading's size and weight.
     *
     * @return array<string, mixed>|null
     */
    private function bodyFont(): ?array
    {
        $font = ParagraphStyle::fontPart($this->styles->slot(Styles::PARAGRAPH));

        // `size` and `color` go, and the rest stays. The run needs the document's face
        // so a mark sitting in a heading does not set the note in that heading's type,
        // but a direct `w:sz` outranks the `FootnoteText` paragraph style above it, and
        // a note drawn at body size is not a note — which is the whole of what
        // {@see self::BODY_STYLE} is there to let the reader decide.
        unset($font['size'], $font['color']);

        return $font === [] ? null : $font;
    }
}
