<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use PhpOffice\PhpWord\Style\Paragraph;

/**
 * Helpers for assembling PHPWord styles.
 *
 * PHPWord applies each top-level style key by calling a setter, so a merged
 * style array is the natural way to layer a customisation on top of a default
 * one. Nested keys such as `spacing` and `indentation` are merged key by key.
 */
final class ParagraphStyle
{
    /**
     * The keys that describe characters rather than the paragraph itself.
     *
     * A Word paragraph has no character formatting of its own — `w:rPr` belongs
     * to the runs inside it — so PHPWord silently discards these when they are
     * handed to a paragraph style. Splitting them out lets the renderer apply
     * them to the runs instead, which is what makes
     * `heading.1 => ['size' => 20, 'bold' => true]` actually large and bold.
     */
    private const FONT_KEYS = [
        'name',
        'size',
        'color',
        'bold',
        'italic',
        'italics',
        'strikethrough',
        'underline',
        'superscript',
        'subscript',
        'highlight',
        'bgColor',
        'fgColor',
    ];

    /**
     * @param  array<string, mixed>|string|Paragraph|null  $style
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>|string|Paragraph|null
     */
    public static function merge(array|string|Paragraph|null $style, array $extra): array|string|Paragraph|null
    {
        if ($style === null) {
            return $extra;
        }

        // A named Word style is a black box: PHPWord resolves it to its own
        // properties at write time, so an override cannot be layered on it. The
        // named style wins and the caller falls back to an array.
        if (is_string($style)) {
            return $style;
        }

        if ($style instanceof Paragraph) {
            $style->setStyleByArray($extra);

            return $style;
        }

        $merged = $style;

        foreach ($extra as $key => $value) {
            if (isset($merged[$key]) && is_array($merged[$key]) && is_array($value)) {
                $merged[$key] = array_merge($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    public static function table(array $style): array
    {
        return $style + ['alignment' => 'left', 'layout' => 'autofit'];
    }

    /**
     * A named style with an extra indentation layered on top of it.
     *
     * Word resolves a `w:pStyle` wholesale, so a named style cannot be given
     * additional properties through the style itself. A `Paragraph` object can
     * carry both: the writer emits the `w:pStyle` from the style name and the
     * indentation from the object's own properties, with the latter winning.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function namedWith(string $styleName, array $extra): Paragraph
    {
        $paragraph = new Paragraph();
        $paragraph->setStyleName($styleName);
        $paragraph->setStyleByArray($extra);

        return $paragraph;
    }

    /**
     * The character half of a style definition, ready to be forced onto runs.
     *
     * @return array<string, mixed>
     */
    public static function fontPart(array|string|null $style): array
    {
        if (!is_array($style)) {
            return [];
        }

        $font = array_intersect_key($style, array_flip(self::FONT_KEYS));

        // PHPWord spells it `italic`; accept the plural some people write.
        if (isset($font['italics']) && !isset($font['italic'])) {
            $font['italic'] = $font['italics'];
        }

        unset($font['italics']);

        return $font;
    }

    /**
     * A style definition with the character half removed, so only paragraph
     * properties reach PHPWord's paragraph style.
     *
     * @param  array<string, mixed>|string|null  $style
     * @return array<string, mixed>|string|null
     */
    public static function paragraphPart(array|string|null $style): array|string|null
    {
        if (!is_array($style)) {
            return $style;
        }

        return array_diff_key($style, array_flip(self::FONT_KEYS));
    }

    /**
     * The one key that turns a definition into a reference to a named style, and an
     * empty array for one that names nothing.
     *
     * A paragraph that names a style says where its look comes from, which is what
     * {@see \MarkdownWord\Reverse\StyleTable} reads to tell formatting the author
     * typed from formatting the style already had.
     *
     * @return array{styleName?: string}
     */
    public static function styleNameOf(array|string|null $style): array
    {
        $name = is_array($style) ? ($style['styleName'] ?? null) : null;

        return is_string($name) ? ['styleName' => $name] : [];
    }
}
