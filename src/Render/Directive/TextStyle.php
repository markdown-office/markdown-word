<?php

declare(strict_types=1);

namespace MarkdownWord\Render\Directive;

use League\CommonMark\Node\Node;
use MarkdownOffice\CommonMark\Directive\Node\DirectiveInline;
use MarkdownWord\Configuration\TextStyles;
use MarkdownWord\Render\InlineStyle;
use MarkdownWord\Render\StyleResolver;
use PhpOffice\PhpWord\Element\AbstractContainer;

/**
 * `:name[text]` — a piece of text in a style.
 *
 * Three forms, one grammar: a name a theme defines and no attributes, a name a
 * theme defines and attributes that override it property by property, and the
 * reserved name `style` with attributes and no theme style behind it.
 *
 * The properties are forced onto the run rather than merged into the paragraph
 * style, so they reach the text whatever the Markdown around it says — a word in a
 * style is a word in that style.
 *
 * **A label is parsed as inline Markdown.** The directives package parses it, so
 * `:brand[**Corp**]` is the word `Corp` in the style and in bold, and the
 * asterisks are markup rather than characters. The runs are the ones the label
 * makes, so a word in a style may hold emphasis of its own.
 *
 * A bare `*` or `_` is not Markdown. `2 * 3` and `snake_case` are ordinary text,
 * and the parser reads them as text for the same reason: a delimiter run that
 * opens an emphasis may not be followed by whitespace, and one that closes it may
 * not be preceded by whitespace.
 */
final class TextStyle
{
    /**
     * @param TextStyles $styles The styles a theme brings, and the reserved name
     *        behind them.
     */
    public function __construct(
        private readonly TextStyles $styles,
        private readonly StyleResolver $resolver,
    ) {
    }

    /**
     * Whether the name is one this library draws.
     *
     * The name rather than the whole directive: a theme's style is this library's
     * name even when the label beside it holds something that cannot be drawn, and
     * the difference is the difference between a name that is not mine and a label
     * that cannot be honoured.
     */
    public function claims(DirectiveInline $node): bool
    {
        $name = TextStyles::fold($node->getName());

        return $name === TextStyles::RESERVED || $this->styles->has($name);
    }

    /**
     * The names this handler draws: the reserved one and the theme's own.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return [TextStyles::RESERVED, ...$this->styles->names()];
    }

    /**
     * Draws the text in the style, or reports that it cannot be drawn.
     *
     * The report is what the caller draws the directive's own source text from, so
     * a name this library does not draw and a label with no text in it come out the
     * same way — as the characters the author typed.
     *
     * @param callable(iterable<Node>, AbstractContainer, InlineStyle): void|null $draw The
     *        renderer that draws the label's runs. A caller with none gets the label
     *        as one run of text.
     */
    public function draw(
        DirectiveInline $node,
        AbstractContainer $target,
        InlineStyle $style,
        ?callable $draw = null,
    ): bool {
        $font = $this->drawableFont($node);

        if ($font === null) {
            return false;
        }

        $style = $style->withOwnFont($font);

        if ($draw === null) {
            $target->addText($node->getLabel() ?? '', $this->resolver->fontFor($style));

            return true;
        }

        $draw($node->children(), $target, $style);

        return true;
    }

    /**
     * The font a directive's label and attributes amount to, or null when the
     * directive cannot be drawn.
     *
     * @return array<string, mixed>|null
     */
    private function drawableFont(DirectiveInline $node): ?array
    {
        $label = $node->getLabel() ?? '';

        if ($label === '') {
            return null;
        }

        $attributes = $this->attributesFor($node);

        if ($attributes === null) {
            return null;
        }

        return TextStyles::fontFor($attributes);
    }

    /**
     * The attributes a directive carries, over the ones a named style carries.
     *
     * The raw form has no named style behind it, so its attributes are all of it —
     * and it needs at least one, because a style with neither a named style nor a
     * property says nothing.
     *
     * @return array<string, mixed>|null Null when the directive is not one that can be drawn.
     */
    private function attributesFor(DirectiveInline $node): ?array
    {
        $attributes = $node->getAttributes()->toArray();
        $name = TextStyles::fold($node->getName());

        if ($name === TextStyles::RESERVED) {
            return $attributes === [] ? null : $attributes;
        }

        $style = $this->styles->get($name);

        if ($style === null) {
            return null;
        }

        return array_merge($style, $attributes);
    }
}
