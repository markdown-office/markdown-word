<?php

declare(strict_types=1);

namespace MarkdownWord\Render\Directive;

use MarkdownOffice\CommonMark\Directive\Node\DirectiveBlock;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Format;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Render\ParagraphStyle;
use MarkdownWord\Render\RenderContext;
use MarkdownWord\Render\StyleResolver;
use PhpOffice\PhpWord\Element\AbstractContainer;

/**
 * `::toc` — a Word table-of-contents field, with nothing in it but the field.
 *
 * PHPWord's `TOC` element cannot draw one. It writes a `PAGEREF` for every title
 * registered in `$phpWord->getTitles()`, and this library never registers any: it
 * draws a heading as a paragraph, not through `addTitle()`. With none registered
 * it writes a closing `fldChar` and no `begin`, so the document carries a field
 * that starts nowhere. The field is therefore built by
 * {@see \MarkdownWord\Writer\TocPass} from what this class records here.
 *
 * **No cached entries.** PHPWord's cached page numbers are all `1`, and a reader
 * that does not refresh a field shows them as they are: a table of contents in
 * which every entry claims to be on page 1 is worse than one that says plainly
 * what to do. So the field goes out empty, marked dirty, with
 * {@see self::UNBUILT} between `separate` and `end` for a reader that never
 * updates it, and `w:updateFields` set so that Word builds it on open.
 *
 * The attributes are `depth` (how many heading levels, defaulting to
 * {@see Options::maxHeadingLevel} — a heading the library will not draw cannot
 * be in the table), `numbers` (page numbers off, Word's `\n`) and `title`
 * (a heading above the field).
 *
 * A paragraph with a heading style on it is what `TOC \o` collects, so the title
 * is drawn in the heading look without the heading style name; see
 * {@see self::drawTitle()}.
 */
final class Toc
{
    /**
     * The token that reserves a spot in the element tree for a field;
     * {@see \MarkdownWord\Writer\TocPass} swaps it for the five runs of the field.
     *
     * U+2063 INVISIBLE SEPARATOR brackets each end, for the reason
     * {@see \MarkdownWord\Render\LinkPlaceholder} gives: it cannot collide with
     * anything an author typed, and no part of it is XML whitespace, so the whole
     * token stays inside the one `w:t` the pass matches.
     */
    public const MARKER = "\u{2063}MDWT\u{2063}";

    /**
     * What the field shows until it is built.
     *
     * F9 is what Word and LibreOffice Writer both update a field with, so it names
     * the way rather than the menu, which is not the same in either.
     */
    public const UNBUILT = 'Press F9 to build the table of contents.';

    /** Word has nine heading levels; this library draws six and collects only what it draws. */
    private const DEEPEST = 6;

    /** @var list<array{placeholder: string, instruction: string}> */
    private array $payloads = [];

    private int $counter = 0;

    /** Whether any document asked for a table of contents, whatever it was written as. */
    private bool $used = false;

    public function __construct(
        private readonly Options $options,
        private readonly StyleResolver $styles,
        private readonly ?Format $format = null,
    ) {
    }

    public function __invoke(DirectiveBlock $node, AbstractContainer $target, RenderContext $context): void
    {
        $title = $node->getAttributes()->getString('title');

        if ($title !== null && $title !== '') {
            $this->drawTitle($title, $target);
        }

        // Only `.docx` can carry the field. Emitting the token regardless would put
        // a run of invisible separators and the letters MDWT into an `.odt` or an
        // `.rtf`, where {@see \MarkdownWord\Writer\TocPass} never runs to take it
        // back out — so a writer that cannot do it is given the source form instead,
        // the same answer an unclaimed directive gets.
        //
        // The use is still recorded, because {@see \MarkdownWord\Format} reports a
        // loss to whoever reads the finished document and a document that never
        // asked for a table of contents must not be told it lost one.
        $this->used = true;

        if (($this->format ?? Format::Docx) !== Format::Docx) {
            $target->addTextRun($this->styles->paragraphStyleFor(Styles::PARAGRAPH))->addText((string) $node);

            return;
        }

        $this->payloads[] = [
            'placeholder' => $placeholder = self::MARKER . $this->counter++ . self::MARKER,
            'instruction' => $this->instruction($node),
        ];

        $target->addTextRun($this->styles->paragraphStyleFor(Styles::PARAGRAPH))->addText($placeholder);

        // Asked of the document the container belongs to rather than of one handed
        // in, because a converter renders into more than one: a template fills
        // several documents out of a single converter, and each is a file of its own.
        //
        // Set here and not by the writer, so that it is set exactly when a document
        // asked for a table of contents. Word re-evaluates every field in a document
        // that carries it, which is not something to turn on for one that has none.
        $target->getPhpWord()?->getSettings()->setUpdateFields(true);
    }

    /**
     * The field's instruction, as Word writes one.
     *
     * The switches are the ones Word itself writes for an inserted table of
     * contents, so what comes out of here is the same field a person gets from
     * References → Table of Contents with the default options.
     */
    private function instruction(DirectiveBlock $node): string
    {
        $attributes = $node->getAttributes();

        $depth = max(1, min(self::DEEPEST, $attributes->getInt('depth', $this->options->maxHeadingLevel)));

        $switches = '\\o "1-' . $depth . '"';

        if (!$attributes->getBool('numbers', true)) {
            $switches .= ' \\n';
        }

        return ' TOC ' . $switches . ' \\h \\z \\u ';
    }

    private function drawTitle(string $title, AbstractContainer $target): void
    {
        $slot = $this->styles->slot(Styles::HEADING_1);

        // `\o` collects by built-in heading style, so a title that carries one
        // lists itself as the first entry of the contents it titles. Word's own
        // answer is a "TOC Heading" style, which is a heading that is deliberately
        // not one; the look is kept here and the name is what has to go.
        if (is_array($slot)) {
            unset($slot['styleName']);
        } else {
            // A slot configured as a bare style name has nothing to give but the
            // name, and the name is the part being left off.
            $slot = $this->styles->slot(Styles::PARAGRAPH);
        }

        // The character half goes to the run: a Word paragraph carries no character
        // formatting of its own, so PHPWord would discard it there.
        $font = ParagraphStyle::fontPart($slot);

        $target->addTextRun(ParagraphStyle::paragraphPart($slot))->addText($title, $font === [] ? null : $font);
    }

    /**
     * Every field drawn so far, each with the placeholder standing in for it in
     * the element tree until the file is written.
     *
     * @return list<array{placeholder: string, instruction: string}>
     */
    public function payloads(): array
    {
        return $this->payloads;
    }

    /**
     * Whether a table of contents was asked for.
     *
     * Asked here rather than inferred from {@see self::payloads()}, because a writer
     * that cannot carry the field asks for none of them while the document still
     * used one — and that is exactly the difference between a loss to report and a
     * document that never wanted the thing.
     */
    public function isUsed(): bool
    {
        return $this->used;
    }
}
