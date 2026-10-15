<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use DOMDocument;
use DOMElement;
use MarkdownWord\Exception\UnreadableDocument;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Xml;
use ZipArchive;

/**
 * Makes this library's heading styles the built-in ones a reader recognises.
 *
 * A heading style PHPWord writes is missing two things, and only one of them stops a
 * table of contents from being built.
 *
 * **The name, which Word needs.** A `.docx` identifies a built-in style by the `w:name`
 * in its definition, and Word spells those `heading 1` … `heading 9`. A definition of
 * `w:styleId="Heading1"` carrying `w:name="Heading1"` is a style *called* Heading1,
 * which resembles the built-in heading and is not it, and Word collects nothing:
 *
 * > Es wurden keine Einträge für das Inhaltsverzeichnis gefunden.
 *
 * That is the whole of what Word needs. A document whose names are right and whose
 * levels are missing collects every heading, with page numbers — checked against Word
 * 16.113 rather than reasoned about, which is the only way it could have been known.
 *
 * **The outline level, which Word does not need.** A heading style is also expected to
 * say what level it is, in `w:outlineLvl`, zero-based so heading 1 is `0`, and Word
 * writes one on every heading style in its own template. Word does not collect by it:
 * `TOC \o` reads the style. Other readers do — LibreOffice reads the level and not the
 * name, and says so in its own markup. The same document is `<text:p>` without the
 * level and `<text:h>` with it, so to anything that is not Word this library's headings
 * were body text wearing a heading's size.
 *
 * PHPWord offers no hook for either. `Writer\Word2007\Part\Styles` writes `w:name`
 * from the same string as the `w:styleId`, and spells `heading N` in exactly one
 * place — the `if ($styleType == 'title')` branch of `writeFontStyle()`, which only
 * `addTitleStyle()` reaches. `writeParagraphStyle()` writes the name verbatim, and a
 * heading reaches the registry through `addFontStyle()`, whose style type is `text`.
 * `w:outlineLvl` is written for a numbering style and nothing else, with no setter
 * anywhere. Both are supplied here, after the file is written.
 *
 * A `w:styleId` of exactly `Heading1`…`Heading9` is matched: those are Word's nine
 * built-in headings, and a template carrying the seventh is as wrong as one carrying
 * the first. This is a wider list than {@see \MarkdownWord\Configuration\LookAndFeel}'s,
 * which defines six — the extra three are never written by this library, and the two
 * have to be kept in step by hand, because nothing makes them agree. `Heading10` and a
 * template's own `Heading1Corporate` match nothing and keep what they came with. A slot
 * a document pointed at a style of its own is deliberately untouched — the registrar
 * skips writing a definition for it, so there is nothing here to correct.
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
     * @throws UnreadableDocument When the package cannot be opened at all.
     * @throws MalformedDocument When `word/styles.xml` is not XML.
     */
    public function applyTo(string $docxPath): void
    {
        $zip = new ZipArchive();

        if ($zip->open($docxPath) !== true) {
            // `UnreadableDocument` and not `FileNotWritable`, because this is the same
            // condition every sibling pass raises and it is a document that cannot be
            // read rather than a destination that cannot be written — the pass opens the
            // package it is about to change, and a caller catching `InvalidInput` for
            // "that is not a Word document" would otherwise miss it.
            throw new UnreadableDocument(sprintf('Unable to open "%s" as a zip archive.', $docxPath));
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
        $id = $style->getAttributeNS(self::W_NS, 'styleId');

        return preg_match('/^Heading([1-9])$/', $id, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * @return bool Whether the name changed.
     */
    private function name(DOMElement $style, string $name): bool
    {
        $element = $this->child($style, 'name');

        if ($element === null || $element->getAttributeNS(self::W_NS, 'val') === $name) {
            return false;
        }

        $element->setAttributeNS(self::W_NS, 'w:val', $name);

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
        $changed = false;

        if ($existing !== null) {
            $changed = $existing->getAttributeNS(self::W_NS, 'val') !== $wanted;
            $existing->setAttributeNS(self::W_NS, 'w:val', $wanted);
        } elseif ($paragraph !== null) {
            // Before `w:rPr` for the same reason {@see self::addParagraphProperties()}
            // places the whole `w:pPr` there: `CT_PPr` is the `CT_PPrBase` sequence and
            // then `rPr?`, so a level appended after one is a definition a validating
            // reader does not find.
            $paragraph->insertBefore(
                $this->element($document, 'outlineLvl', $wanted),
                $this->child($paragraph, 'rPr'),
            );
            $changed = true;
        } else {
            $this->addParagraphProperties($document, $style, $wanted);
            $changed = true;
        }

        return $changed;
    }

    /**
     * Gives a definition that arrived without paragraph properties a set of them.
     *
     * Nothing this library writes produces such a definition — PHPWord opens `w:pPr`
     * unconditionally — but a template's stylesheet can carry a heading without them,
     * and without a `w:pPr` there is nowhere to put the level at all.
     */
    private function addParagraphProperties(DOMDocument $document, DOMElement $style, string $level): void
    {
        $paragraph = $this->element($document, 'pPr');
        $paragraph->appendChild($this->element($document, 'outlineLvl', $level));

        // Before `w:rPr`, because `CT_Style` puts it there and a reader that finds
        // them the other way round does not find the definition at all.
        $style->insertBefore($paragraph, $this->child($style, 'rPr'));
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
            $element->setAttributeNS(self::W_NS, 'w:val', $value);
        }

        return $element;
    }
}
