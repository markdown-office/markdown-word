<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use MarkdownWord\Configuration\LookAndFeel;
use MarkdownWord\Configuration\Styles;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style;
use PhpOffice\PhpWord\Style\Paragraph;

/**
 * Writes the style *definitions* a freshly generated document needs.
 *
 * A fresh `styles.xml` carries only `Normal` and `FootnoteReference`, so the
 * `w:pStyle` a heading carries has nothing to resolve against. Defining them keeps a
 * standalone document self-contained while still using the styleIds Word recognises.
 * What that does not do is make a heading one: a definition is missing both the built-in
 * name and the outline level, and both are corrected afterwards by
 * {@see \MarkdownWord\Writer\BuiltInHeadingPass} rather than here.
 *
 * The name is the one Word collects by. PHPWord writes a definition's `w:name` from the
 * same string as its `w:styleId`, and a name of `Heading1` is a style *called* Heading1
 * rather than the built-in `heading 1`, so a `TOC \o` finds nothing to collect. There
 * is no hook for it here: PHPWord lowercases a name only on the branch where the style
 * came from a `Paragraph`, and a heading reaches the registry through `addFontStyle()`.
 *
 * The outline level is the one Word does not collect by and every other reader does.
 * It was the obvious suspect for a table of contents that stayed empty once the name was
 * right — LibreOffice reads the level rather than the name, so a document without one
 * is body text to it — but asking Word settles it the other way, and a guess dressed as
 * a finding is worse than no finding.
 *
 * The definition is taken from the slot rather than from a table here, so a document
 * that overrides a heading writes a definition of its own and not the library's.
 *
 * It is what the ODF writer resolves a paragraph's `styleName` against, so the
 * spacing of a heading survives into an `.odt` as well as its size and colour; RTF
 * has no stylesheet and uses the properties written on the paragraph instead.
 *
 * Not run when rendering into a template, which is the authority there.
 */
final class StyleRegistrar
{
    public function __construct(private readonly Styles $styles)
    {
    }

    public function register(PhpWord $phpWord): void
    {
        $owned = LookAndFeel::ownedStyleIds();

        foreach (LookAndFeel::slots() as $slot => $look) {
            $styleId = $look['styleName'] ?? null;

            // A slot with no built-in id behind it has no definition to write, and a
            // name outside the library's belongs to the template it came from.
            if (!is_string($styleId) || !in_array($styleId, $owned, true)) {
                continue;
            }

            $configured = $this->styles->get($slot);

            if (is_array($configured) && ($configured['styleName'] ?? null) === $styleId) {
                $this->define($phpWord, $styleId, $configured);
            }
        }
    }

    private function define(PhpWord $phpWord, string $id, array $definition): void
    {
        // `setStyleValues()` skips its whole body once a name is taken, so a
        // duplicate id does not update the registry — a static, cleared only by
        // `new PhpWord()`. Rendering into one `PhpWord` twice therefore keeps the
        // first definition, which is what this guard makes explicit.
        if (Style::getStyle($id) !== null) {
            return;
        }

        // The array form maps each key to `set<Key>()` and silently ignores one
        // that does not exist, so the keys are the property names PHPWord's Font
        // style has — `italic`, not `italics`.
        $font = [];
        foreach (Styles::FONT_KEYS as $key) {
            if (array_key_exists($key, $definition)) {
                $font[$key] = $definition[$key];
            }
        }

        // `styleName` is skipped: `Writer\Word2007\Part\Styles` writes a Paragraph's
        // own style name as the style's `w:basedOn`, so setting one here would make
        // `Heading1` its own parent. The name is already this method's argument.
        $paragraph = new Paragraph();
        foreach (Styles::PARAGRAPH_KEYS as $key) {
            if ($key === 'styleName' || !array_key_exists($key, $definition)) {
                continue;
            }

            $setter = 'set' . ucfirst($key);
            if (method_exists($paragraph, $setter)) {
                $paragraph->{$setter}($definition[$key]);
            }
        }

        // The font properties go in as an array, never as a Font object: an
        // `AbstractStyle` of the same class is adopted in place of the one
        // `addFontStyle()` built, and the paragraph went in as a constructor
        // argument to that discarded one. What comes out is a character style
        // with no `w:styleId` and no `w:pPr` — headings render as body text.
        $phpWord->addFontStyle($id, $font, $paragraph);
    }
}