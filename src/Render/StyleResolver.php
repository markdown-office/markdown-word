<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;

/**
 * Translates renderer concerns into concrete Word styles, so the rest of the
 * renderer never has to think about style names or defaults.
 *
 * Whether a style is already defined in the target document is a different
 * question, and {@see StyleRegistrar} is where that is answered.
 */
final class StyleResolver
{
    public function __construct(private readonly Configuration $config)
    {
    }

    private function styles(): Styles
    {
        return $this->config->getStyles();
    }

    /**
     * The raw value configured for a slot: a Word style name, an inline style
     * array, or nothing at all.
     */
    public function slot(string $name): mixed
    {
        return $this->styles()->get($name);
    }

    /**
     * The font style — an inline array, or the name of a registered style — for
     * a run with the given inline formatting.
     *
     * The layers that go into it, from the least particular to the most:
     *
     * 1. the look of the scope the run sits in — the character half of the style
     *    slot the block, the cell or the note came from;
     * 2. the default of the construct the run is — the `codeFont` or `linkFont`
     *    slot;
     * 3. the Markdown's own emphasis.
     *
     * A statement about one run beats a statement about a whole scope, and an
     * explicit statement beats a default. So a bold table header stays bold
     * around italic text, a code span is in the code font, and an emphasis
     * survives a block that says its text is not italic.
     *
     * @return array<string, mixed>|string|null
     */
    public function fontFor(InlineStyle $style): array|string|null
    {
        $font = $this->normalizeFontArray($style->forcedFont);

        $slots = [];

        if ($style->code) {
            $slots[] = Styles::CODE_FONT;
        }

        if ($style->isLink()) {
            $slots[] = Styles::LINK_FONT;
        }

        // A slot configured as a plain string names a style from the target
        // document (typically one defined in your Word template). It can only be
        // used verbatim when it is the *only* styling on the run; otherwise we
        // fall back to the built-in array so the combination still resolves.
        $namedOnly = [];
        foreach ($slots as $slot) {
            $value = $this->styles()->get($slot);
            if (is_string($value)) {
                $namedOnly[] = $value;
                continue;
            }
            if (is_array($value)) {
                $font = array_merge($font, $this->normalizeFontArray($value));
            }
        }

        // The emphasis comes after the look of the scope, and not before it: an
        // emphasis is about one run and a block's look is about every run in it,
        // so where the two name the same property the emphasis is the more
        // particular statement and wins. A block that says its text is not italic
        // does not cancel an `*emphasis*` in it — it says what the text looks like
        // where the document says nothing.
        if ($style->bold) {
            $font['bold'] = true;
        }

        if ($style->italic) {
            $font['italic'] = true;
        }

        if ($style->strikethrough) {
            $font['strikethrough'] = true;
        }

        if ($font !== []) {
            return $font;
        }

        if ($namedOnly !== []) {
            return count($namedOnly) === 1 ? $namedOnly[0] : null;
        }

        return null;
    }

    /**
     * The paragraph style for a block, or null for "no styling".
     */
    public function paragraphStyleFor(string $slot): string|array|null
    {
        $value = $this->styles()->get($slot);

        return is_string($value) || is_array($value) || $value === null ? $value : null;
    }

    public function softBreakMode(): string
    {
        return $this->config->getOptions()->softBreak;
    }

    public function hardBreakMode(): string
    {
        return $this->config->getOptions()->hardBreak;
    }

    public function isInternalLink(string $url): bool
    {
        $target = $this->config->getOptions()->linkTarget;

        return $target === '_self' || str_starts_with($url, '#');
    }

    /**
     * Translate the shorthand a style definition may use into the keys PHPWord's
     * Font style understands.
     *
     * Only `shading` needs translating: it is spelled `bgColor` there, and a key
     * Font has no setter for is silently dropped by `setStyleByArray()`. Paragraph
     * shading is a different thing entirely and never reaches this — it is a
     * paragraph property, added by the renderer.
     *
     * @param  array<string, mixed>  $font
     * @return array<string, mixed>
     */
    private function normalizeFontArray(array $font): array
    {
        if (isset($font['shading']) && !isset($font['bgColor'])) {
            $font['bgColor'] = $font['shading'];
        }
        unset($font['shading']);

        return $font;
    }
}
