<?php

declare(strict_types=1);

namespace MarkdownWord\Configuration;

/**
 * The formatting a slot has before anybody configures it.
 *
 * A default is written as properties rather than as a reference to a style in
 * Word's catalogue, and that is what makes the same Markdown look the same in a
 * `.docx`, an `.odt` and an `.rtf`: the ODF and RTF writers resolve a named style
 * against a stylesheet of their own, so a heading that is only `Heading1` comes
 * out of either of them as body text.
 *
 * `styleName` is the exception, and what it buys is narrower than it looks. It
 * names the style the paragraph references, and
 * {@see \MarkdownWord\Render\StyleRegistrar} writes a definition of it into a
 * document built from scratch, so the `w:pStyle` a `.docx` heading carries
 * resolves to something. It does not make the heading a heading: PHPWord writes
 * the definition's `w:name` from the same string as its `w:styleId`, and
 * `Heading1` is not the canonical `heading 1`, so a reader that maps built-in
 * styles by name treats it as a style of its own. Direct formatting rides
 * alongside the name rather than instead of it, and it is the properties every
 * reader sees: the name is what a template's own `Heading1` has to be called for
 * the template's look to apply, and nothing more.
 *
 * A style name alone is not an outline level, and nothing here pretends it is.
 * PHPWord's paragraph writer emits `w:outlineLvl` only for a numbered paragraph,
 * so there is no setting here that would change that —
 * `tests/Unit/look-and-feel.php` says what the name does and does not amount
 * to.
 *
 * A slot configured with a style *name* means the opposite — a template saying
 * what its own `Heading1` looks like — and is left exactly as it was written.
 * {@see \MarkdownWord\Configuration::withBuiltInHeadingStyles()} is how Word's own
 * styles are asked for.
 *
 * Sizes are in points, spacings in twips (a twentieth of a point), and colours are
 * six hexadecimal digits with no leading `#`, which is the spelling
 * `w:color` takes.
 *
 * {@see Styles::FONT_KEYS} and {@see Styles::PARAGRAPH_KEYS} are the two lists every
 * key below has to be in, because the registrar and the validator read them and a
 * key in neither is one that is accepted and then dropped.
 */
final class LookAndFeel
{
    /** The background a code block is drawn on when `codeBlockShading` is on. */
    public const CODE_BACKGROUND = 'F2F2F2';

    /**
     * The two heading colours, which are the only colours a heading has.
     *
     * Named because a reader of {@see self::slots()} is looking at a table of defaults
     * and the point of a table is that a value used twice is one value, not two that
     * happen to match. Nothing about the hierarchy depends on which heading takes
     * which: these are what this library's documents look like, not what Word's own
     * heading styles look like.
     */
    private const HEADING_COLOR = '2F5496';
    private const HEADING_COLOR_DEEP = '1F3763';

    /**
     * The properties of every slot that has one, keyed by slot.
     *
     * `Styles::defaults()` is this with the slots that are not a look at all added
     * to it — the numbering definitions a list points at, the table slots, which are
     * handed a `Table`, and the ones nobody styled.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function slots(): array
    {
        return [
            Styles::HEADING_1 => [
                'styleName' => 'Heading1',
                'bold' => true, 'size' => 16, 'color' => self::HEADING_COLOR,
                'space' => ['before' => 240, 'after' => 120], 'keepNext' => true,
            ],
            Styles::HEADING_2 => [
                'styleName' => 'Heading2',
                'bold' => true, 'size' => 13, 'color' => self::HEADING_COLOR,
                'space' => ['before' => 200, 'after' => 100], 'keepNext' => true,
            ],
            Styles::HEADING_3 => [
                'styleName' => 'Heading3',
                'bold' => true, 'size' => 12, 'color' => self::HEADING_COLOR_DEEP,
                'space' => ['before' => 160, 'after' => 80], 'keepNext' => true,
            ],
            Styles::HEADING_4 => [
                'styleName' => 'Heading4',
                'bold' => true, 'italic' => true, 'size' => 11, 'color' => self::HEADING_COLOR,
                'space' => ['before' => 140, 'after' => 80], 'keepNext' => true,
            ],
            Styles::HEADING_5 => [
                'styleName' => 'Heading5',
                'bold' => true, 'size' => 11, 'color' => self::HEADING_COLOR,
                'space' => ['before' => 120, 'after' => 60], 'keepNext' => true,
            ],
            Styles::HEADING_6 => [
                'styleName' => 'Heading6',
                'italic' => true, 'size' => 11, 'color' => self::HEADING_COLOR_DEEP,
                'space' => ['before' => 120, 'after' => 60], 'keepNext' => true,
            ],

            Styles::PARAGRAPH => [
                'space' => ['after' => 120],
                'lineHeight' => 1.15,
            ],

            // Indented on both sides, which is what tells a reader the text is being
            // quoted rather than merely set apart. A rule down the left would say it
            // better and only Word's writer carries one.
            Styles::BLOCK_QUOTE => [
                'styleName' => 'IntenseQuote',
                'italic' => true, 'color' => '404040',
                'indentation' => ['left' => 720, 'right' => 720],
                'space' => ['before' => 120, 'after' => 120],
            ],

            // No typeface and no colour of its own: those are `codeFont`, so a
            // code span and a code block agree without either of them naming the
            // other. The background is not here either, because `codeBlockShading`
            // is the switch that decides whether there is one.
            Styles::CODE_BLOCK => [
                'indentation' => ['left' => 360],
                'space' => ['before' => 0, 'after' => 0],
            ],

            Styles::LIST_PARAGRAPH => [
                'space' => ['after' => 60],
            ],

            Styles::CODE_FONT => [
                'name' => 'Consolas',
                'size' => 9,
                'color' => 'A31515',
            ],

            Styles::LINK_FONT => [
                'color' => '0563C1',
                'underline' => 'single',
            ],
        ];
    }

    /**
     * The Word style ids this library defines rather than leaving to a template.
     *
     * A name outside this set belongs to whoever wrote it — {@see \MarkdownWord\Render\StyleRegistrar}
     * defines the built-ins into a document built from scratch and stays out of the
     * way of a document whose styles are already there.
     *
     * @return list<string>
     */
    public static function ownedStyleIds(): array
    {
        $ids = [];

        foreach (self::slots() as $definition) {
            if (isset($definition['styleName']) && is_string($definition['styleName'])) {
                $ids[] = $definition['styleName'];
            }
        }

        return array_values(array_unique($ids));
    }
}