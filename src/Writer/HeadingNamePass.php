<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use DOMElement;
use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Xml;
use ZipArchive;

/**
 * Renames this library's heading styles to the ones Word recognises as built-in.
 *
 * A `.docx` identifies a built-in style by the `w:name` inside its definition, and
 * those names are spelled `heading 1` … `heading 9` — lowercase, with a space. A
 * definition of `w:styleId="Heading1"` carrying `w:name="Heading1"` is a style called
 * *Heading1*, which resembles the built-in heading and is not it. Word resolves a
 * `w:pStyle` to a styleId and a `TOC \o` to a set of *names*, so a document whose
 * headings are the resemblance collects nothing:
 *
 * > Ein Inhaltsverzeichnis erstellen? Beginnen Sie damit, dass Sie ein
 * > Überschriftenformat aus dem Formatvorlagenkatalog auf den ausgewählten Text
 * > anwenden.
 *
 * Nothing else is wrong with such a document. It looks right, its headings carry the
 * right size and colour, and every property is plausible — which is why it survived
 * until someone pressed F9.
 *
 * PHPWord writes `w:name` from the same string as the `w:styleId`, with no hook for
 * the second one: `Writer\Word2007\Part\Styles` lowercases a name only on the branch
 * where the style came from a `Paragraph`, and a heading here reaches the registry
 * through `addFontStyle()`. So the name is corrected here instead, after the file is
 * written.
 *
 * The definition is left otherwise alone, because a document that set a size, a colour
 * or a spacing means them: only the name is wrong, and only the name is changed.
 */
final class HeadingNamePass
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** The part the definitions are in, spelled once so `applyTo()` and nothing else agree. */
    private const STYLES_PART = 'word/styles.xml';

    /**
     * The built-in names, in order, so a level is a position rather than a lookup.
     *
     * @var list<string>
     */
    private const NAMES = [
        'heading 1',
        'heading 2',
        'heading 3',
        'heading 4',
        'heading 5',
        'heading 6',
        'heading 7',
        'heading 8',
        'heading 9',
    ];

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

            $named = $this->rename($styles);

            if ($named !== $styles) {
                $zip->deleteName(self::STYLES_PART);
                $zip->addFromString(self::STYLES_PART, $named);
            }
        } finally {
            $zip->close();
        }
    }

    private function rename(string $styles): string
    {
        $dom = Xml::parseOrFail($styles, self::STYLES_PART . ' is not valid XML.');
        $changed = false;

        // Namespaced rather than `getElementsByTagName('w:style')`, which matches on
        // the qualified name and finds nothing in a parsed part: the prefix is a
        // binding, not part of the name, and only the namespace-aware call sees it.
        foreach ($dom->getElementsByTagNameNS(self::W_NS, 'style') as $style) {
            $name = $this->builtInNameOf($style);

            if ($name === null) {
                continue;
            }

            foreach ($style->childNodes as $child) {
                if ($child->nodeName !== 'w:name' || !$child instanceof DOMElement) {
                    continue;
                }

                $child->setAttribute('w:val', $name);
                $changed = true;
                break;
            }
        }

        return $changed ? (string) $dom->saveXML() : $styles;
    }

    /**
     * The built-in name this definition should carry, or null when it should not have one.
     *
     * Keyed on the `w:styleId` alone, and matched exactly: `Heading1` is the built-in
     * heading 1, while `Heading10` and a template's own `Heading1Corporate` are not
     * anything on this list and keep the name they came with. A document that renamed a
     * heading slot to a style of its own is deliberately not touched — the registrar
     * skips a name outside the library's, and this agrees with it.
     */
    private function builtInNameOf(DOMElement $style): ?string
    {
        $id = $style->getAttribute('w:styleId');

        if (!\is_string($id) || !\preg_match('/^Heading([1-9])$/', $id, $match)) {
            return null;
        }

        return self::NAMES[(int) $match[1] - 1];
    }
}
