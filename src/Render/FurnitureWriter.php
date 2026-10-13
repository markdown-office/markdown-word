<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use MarkdownWord\Configuration\Furniture;
use MarkdownWord\Document\Frontmatter;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Parser\MarkdownParserInterface;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Header;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\TextRun;

/**
 * Writes the header and the footer a document's frontmatter describes onto its section.
 *
 * The body of a running head is Markdown and is drawn by {@see DocumentRenderer} — the
 * same walk, into a different container, which is the whole point: a `**bold**` word in a
 * header is the same rendering as a `**bold**` word in the body, and a second
 * implementation of the walk would be a second thing to keep right. What is *not* the
 * renderer's is everything around it — which pages the furniture is for, where the page
 * number goes, whether there is a rule — so the renderer is handed in rather than built
 * here, and this class only decides where the elements it produces go.
 *
 * Three things about the result are deliberate and are the reason this is not a method on
 * the frontmatter:
 *
 * - **Nothing configured means no part.** A document naming no `header:` gets no
 *   `word/header1.xml`, which is also why it is reported in no loss: {@see
 *   \MarkdownWord\Format} asks {@see \MarkdownWord\Writer\Survey} whether a feature was
 *   used, and a part nobody asked for would put a sentence about a running head in front
 *   of a document that has none. An empty band at the top of every page is worse than
 *   nothing, and it would also erase a template's own header.
 * - **A format that cannot carry it is reported, not refused.** RTF writes a header and a
 *   footer; ODT writes neither — `Writer\ODText` has no part for either — and halves the
 *   top and bottom margins when a section has one, so an `.odt` from a document with a
 *   running head has a narrower band at the top and nothing in it. Both are the
 *   {@see \MarkdownWord\Format} losses `header` and `footer` exist to say, and neither is
 *   something this class may decide on the writer's behalf.
 * - **A picture and a link are carried; a vector and its alt text are not.** A link
 *   becomes a real `w:hyperlink` with its own relationship in the header part, and a
 *   raster picture is embedded there by PHPWord itself — both checked by rendering a
 *   document and looking at it. What a header loses is what the passes add afterwards:
 *   `ImageDescriptionPass` and `SvgPass` rewrite `word/document.xml` alone, so a picture
 *   in a header is drawn with no alternative text beside it and an SVG in one comes out
 *   as the raster, without the vector a Word reader would prefer. Neither is extended to
 *   reach a header here: a silent caption is better than none, and a raster beside a
 *   vector is a picture rather than a fault.
 *   A deferred link is the exception — the template path holds the placeholders back
 *   until the content is in, and rewrites the body part only, so a header in a template
 *   would keep the placeholder. `MarkdownTemplate` renders into a scratch section rather
 *   than into the finished document, which is why the furniture never reaches one.
 */
final class FurnitureWriter
{
    /**
     * @param MarkdownParserInterface $parser The dialect the running head is read with.
     *        GFM, deliberately rather than the document's own parser: a `---` under
     *        `body:` is a thematic break, and a running head is not a document that can
     *        carry a frontmatter block — with the document's parser in hand it would be
     *        swallowed, along with everything after it, and a header is exactly where a
     *        stray `---` is likeliest.
     */
    public function __construct(private readonly MarkdownParserInterface $parser = new CommonMarkParser())
    {
    }

    /**
     * Put whatever the frontmatter describes onto the section.
     *
     * @param Furniture|null $template What a template already has in this slot, and what
     *        a document overrides a key at a time. See {@see Furniture::withAll()} for
     *        why the merge is per key and not a replacement.
     */
    public function draw(
        DocumentRenderer $renderer,
        Frontmatter $frontmatter,
        Section $section,
        ?Furniture $template = null,
    ): void {
        $this->write($renderer, $frontmatter->header(), $section, $template, true);
        $this->write($renderer, $frontmatter->footer(), $section, $template, false);
    }

    private function write(
        DocumentRenderer $renderer,
        Furniture $written,
        Section $section,
        ?Furniture $template,
        bool $header,
    ): void {
        $furniture = ($template ?? new Furniture())->withAll($written->toArray());

        if ($furniture->isEmpty()) {
            return;
        }

        $style = new FurnitureStyle($furniture, $this->textColumn($section));

        foreach ($this->pages($furniture, $section) as $type) {
            $part = $header ? $section->addHeader($type) : $section->addFooter($type);

            $this->fill($renderer, $part, $furniture, $style);
        }
    }

    /**
     * Which pages of the section this furniture is for.
     *
     * `Header::AUTO` is every page, and is what a document that says nothing about pages
     * gets. Naming one of the other two is the only way to say which: a letterhead that
     * is not on page one is `firstPage`, and a book that changes sides is `even`, and both
     * are one flag on a block that can only describe one piece of furniture — so a
     * document wanting a running head *and* a different one on its first page cannot say
     * both here, and that limit is the shape of {@see Furniture} rather than of this
     * method.
     *
     * `firstPage` needs no flag of its own on the document: `Section::hasDifferentFirstPage()`
     * is derived from a `Header::FIRST` existing, and the Word2007 writer emits
     * `<w:titlePg/>` from it. There is no setter, and inventing one would be a second
     * source of truth for something the writer already derives.
     *
     * `even` does need one, because `<w:evenAndOddHeaders/>` is a document setting rather
     * than a section's, and PHPWord has no way to reach it from a section.
     *
     * @return list<string>
     */
    private function pages(Furniture $furniture, Section $section): array
    {
        $types = [Header::AUTO];

        // The default header stays. A `Header::FIRST` on its own replaces the odd-page
        // header rather than adding to it, and Word then shows a header on page 1 and
        // nothing at all from page 2 onwards — which is not what asking for a first
        // page of its own means.
        if ($furniture->firstPage === true) {
            $types[] = Header::FIRST;
        }

        if ($furniture->even === true) {
            $types[] = Header::EVEN;
            $this->askForEvenAndOdd($section);
        }

        return $types;
    }

    /**
     * `<w:evenAndOddHeaders/>`, which is a document setting and not a section's.
     *
     * Nothing else turns it on, and without it Word reads the even header as the odd one
     * and prints the same band on every page — which looks like the switch having worked
     * and is the reason this is set rather than left to the writer.
     */
    private function askForEvenAndOdd(Section $section): void
    {
        $section->getPhpWord()?->getSettings()->setEvenAndOddHeaders(true);
    }

    private function fill(
        DocumentRenderer $renderer,
        AbstractContainer $part,
        Furniture $furniture,
        FurnitureStyle $style,
    ): void {
        $tokens = new FurnitureTokens($furniture->dateFormat);

        $this->body($renderer, $part, $furniture, $style, $tokens);
        $this->switches($part, $tokens, $furniture, $style);
    }

    /**
     * The Markdown body, drawn into the part and then given the furniture's paragraph
     * style.
     *
     * The style goes on afterwards rather than into the renderer's slot lookup because
     * the body is a document in its own right and knows nothing about the page it is
     * printed on; what is being described is the paragraph, and every one the walk made
     * is one the furniture applies to.
     */
    private function body(
        DocumentRenderer $renderer,
        AbstractContainer $part,
        Furniture $furniture,
        FurnitureStyle $style,
        FurnitureTokens $tokens,
    ): void {
        $body = $furniture->body;

        if ($body === null || trim($body) === '') {
            return;
        }

        $renderer->render($this->parser->parse($tokens->substitute($body)), $part);

        foreach ($part->getElements() as $element) {
            $style->applyTo($element);

            if ($element instanceof TextRun) {
                $tokens->expand($element);
            }
        }
    }

    /**
     * The page numbers and the rest, on a paragraph of their own below the body.
     *
     * The tab is a tab character rather than a `w:tab`: PHPWord has no element for one,
     * and a literal tab inside a `w:t` with `xml:space="preserve"` is what its own text
     * writer produces. Checked by rendering rather than by reading the markup — a `.docx`
     * with `align: right`, `page: true` and `total: true` puts `1 2` at the right margin,
     * and one with `align: center` puts `1` under the centre of the words above it.
     *
     * A space between two switches is all this knows about the wording, which is why the
     * tokens exist: a header that wants `Page 3 of 12` says so in its body rather than
     * asking for two switches and getting `3 12`.
     */
    private function switches(
        AbstractContainer $part,
        FurnitureTokens $tokens,
        Furniture $furniture,
        FurnitureStyle $style,
    ): void {
        $fields = $tokens->switches($furniture);

        if ($fields === []) {
            return;
        }

        $run = $part->addTextRun($style->switchStyle());

        if ($style->leadsWithTab()) {
            $run->addText("\t");
        }

        foreach ($fields as $index => $field) {
            if ($index > 0) {
                $run->addText(' ');
            }

            $run->addField($field['field'], $field['properties']);
        }
    }

    /**
     * The text column the furniture sits in, in twips.
     *
     * {@see TableLayout} is the only thing here that knows a section's page geometry, and
     * a table in a header is measured against the very same width — so it is asked for
     * rather than a second answer written out.
     */
    private function textColumn(Section $section): int
    {
        return (new TableLayout($section))->columnWidths([1])[0];
    }

}
