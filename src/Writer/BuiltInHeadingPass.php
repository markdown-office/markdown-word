<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use DOMDocument;
use DOMElement;
use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Xml;
use ZipArchive;

/**
 * Makes this library's heading styles the built-in ones a reader recognises.
 *
 * A heading style PHPWord writes is missing two things, and each one breaks a table of
 * contents on its own. Correcting the first is what produces the second message, which
 * is why a document that was fixed once still did not work:
 *
 * > Ein Inhaltsverzeichnis erstellen? Beginnen Sie damit, dass Sie ein
 * > Überschriftenformat aus dem Formatvorlagenkatalog auf den ausgewählten Text
 * > anwenden.
 *
 * … and, once the name was right:
 *
 * > Es wurden keine Einträge für das Inhaltsverzeichnis gefunden.
 *
 * **The name.** A `.docx` identifies a built-in style by the `w:name` in its
 * definition, and Word spells those `heading 1` … `heading 9`. A definition of
 * `w:styleId="Heading1"` carrying `w:name="Heading1"` is a style *called* Heading1,
 * which resembles the built-in heading and is not it.
 *
 * **The outline level.** A heading is collected by its level, and the level belongs to
 * the style rather than to the paragraph — `w:outlineLvl`, zero-based, so heading 1 is
 * `0`. A style that carries the built-in name and redefines `w:pPr` without one leaves
 * its paragraphs at no level, and a paragraph at no level is a paragraph. LibreOffice
 * reads it that way, and says so in its own markup: the same document is `<text:p>`
 * without the level and `<text:h>` with it.
 *
 * PHPWord offers no hook for either. `Writer\Word2007\Part\Styles` writes `w:name`
 * from the same string as the `w:styleId`, lowercasing only on the branch where the
 * style came from a `Paragraph`, and a heading reaches the registry through
 * `addFontStyle()`. `w:outlineLvl` is written for a numbering style and nothing else,
 * with no setter anywhere. Both are supplied here, after the file is written.
 *
 * A `w:styleId` on this library's own list is matched exactly: `Heading1` is the
 * built-in heading 1, while `Heading10` and a template's own `Heading1Corporate` are
 * not anything on that list and keep what they came with. A slot a document pointed at
 * a style of its own is deliberately untouched — the registrar skips writing a
 * definition for it, so there is nothing here to correct.
 *
 * Nothing else in a definition is changed. A document that set a size, a colour or a
 * spacing means them, and rewriting a definition to fix what a reader notices only on
 * F9 would discard what the author asked for.
 */
final class BuiltInHeadingPass
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** The part the definitions are in, spelled once so `applyTo()` and nothing else agree. */
    private const STYLES_PART = 'word/styles.xml';

    /**
     * @throws FileNotWritable When the package cannot be written back.
     * @throws MalformedDocument When `word/styles.xml` is not XML.
     */
    public function applyTo(string $docxPath): void
    {
        $zip = new ZipArchive();

        if ($zip->open($docxPath) !== true) {
            throw new FileNotWritable(sprintf('Unable to open "%s" for writing.', $docxPath));
        }

        try {
            $styles = $zip->getFromName(self::STYLES_PART);

            if (!is_string($styles)) {
                return;
            }

            $corrected = $this->correct($styles);

            if ($corrected !== $styles) {
                $zip->deleteName(self::STYLES_PART);
                $zip->addFromString(self::STYLES_PART, $corrected);
            }
        } finally {
            $zip->close();
        }
    }

    private function correct(string $styles): string
    {
        $dom = Xml::parseOrFail($styles, self::STYLES_PART . ' is not valid XML.');
        $changed = false;

        // Namespaced rather than `getElementsByTagName('w:style')`, which matches on
        // the qualified name and finds nothing in a parsed part: the prefix is a
        // binding, not part of the name, and only the namespace-aware call sees it.
        foreach ($dom->getElementsByTagNameNS(self::W_NS, 'style') as $style) {
            $level = $this->builtInLevelOf($style);

            if ($level === null) {
                continue;
            }

            if ($this->name($style, 'heading ' . $level)) {
                $changed = true;
            }

            if ($this->outlineLevel($dom, $style, $level - 1)) {
                $changed = true;
            }
        }

        return $changed ? (string) $dom->saveXML() : $styles;
    }

    /**
     * The heading level this definition stands for, or null when it stands for none.
     *
     * The level is the single source for both corrections rather than one source for
     * the name and another for the arithmetic: a name spelled out in a list of nine and
     * a level read from the id are two things to keep in step, and nothing would say so
     * if they drifted.
     */
    private function builtInLevelOf(DOMElement $style): ?int
    {
        $id = $style->getAttribute('w:styleId');

        return preg_match('/^Heading([1-9])$/', $id, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * @return bool Whether the name changed.
     */
    private function name(DOMElement $style, string $name): bool
    {
        $element = $this->child($style, 'name');

        if ($element === null || $element->getAttribute('w:val') === $name) {
            return false;
        }

        $element->setAttribute('w:val', $name);

        return true;
    }

    /**
     * @return bool Whether the outline level changed.
     *
     * Written last inside `w:pPr`, which is where the schema puts it: of `CT_PPrBase`
     * only `divId` and `cnfStyle` follow, and a paragraph style carries neither.
     *
     * An existing level is corrected rather than trusted. A heading whose name says
     * level one and whose level says level two is collectable at neither depth a reader
     * expects, and there is no way to ask for that today — which is why it is settled
     * here rather than left to whoever gets there first. One that already agrees is
     * left alone, and reporting no change is what keeps a template's own stylesheet
     * from being reserialised for nothing.
     */
    private function outlineLevel(DOMDocument $document, DOMElement $style, int $level): bool
    {
        $paragraph = $this->child($style, 'pPr');
        $existing = $paragraph === null ? null : $this->child($paragraph, 'outlineLvl');
        $wanted = (string) $level;

        if ($existing !== null) {
            if ($existing->getAttribute('w:val') === $wanted) {
                return false;
            }

            $existing->setAttribute('w:val', $wanted);

            return true;
        }

        $element = $this->element($document, 'outlineLvl', $wanted);

        if ($paragraph !== null) {
            $paragraph->appendChild($element);

            return true;
        }

        // A definition with no `w:pPr` at all — nothing this library writes produces
        // one, but a template's stylesheet may carry a heading without paragraph
        // properties — gets one, before `w:rPr` because `CT_Style` puts it there.
        $paragraph = $this->element($document, 'pPr');
        $paragraph->appendChild($element);
        $run = $this->child($style, 'rPr');

        $style->insertBefore($paragraph, $run);

        return true;
    }

    private function child(DOMElement $parent, string $localName): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement
                && $child->localName === $localName
                && $child->namespaceURI === self::W_NS) {
                return $child;
            }
        }

        return null;
    }

    private function element(DOMDocument $document, string $localName, string $value = ''): DOMElement
    {
        $element = $document->createElementNS(self::W_NS, 'w:' . $localName);

        if ($value !== '') {
            $element->setAttribute('w:val', $value);
        }

        return $element;
    }
}
