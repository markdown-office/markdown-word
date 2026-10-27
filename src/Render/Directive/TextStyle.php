<?php

declare(strict_types=1);

namespace MarkdownWord\Render\Directive;

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
 * style is a word in that style, and `**` inside a label is not Markdown at all.
 *
 * **A label is plain text.** The directives package stores it as a string rather
 * than parsing it as inline Markdown, because CommonMark's inline engine offers no
 * way for a parser to say "the rest of this run is mine". A label that holds a
 * *complete* Markdown construct is therefore drawn as the directive's own source
 * text rather than as the word it names: the asterisks the author wrote reach the
 * document, which is the same answer an unknown name gets, and the one this half
 * gives to everything it cannot draw.
 *
 * A bare `*` or `_` is not Markdown. `2 * 3` and `snake_case` are ordinary text,
 * and a rule that refused every label holding one of them would refuse correct
 * documents all day; what is refused is a construct that is complete.
 */
final class TextStyle
{
    /**
     * The complete Markdown constructs a label may not hold.
     *
     * The `_` is the only one with a rule of its own: CommonMark does not open
     * emphasis inside a word, so `snake_case_name` is not emphasis and a label
     * holding it is text.
     *
     * The link cannot be one — a label holds no brackets at all, since the first
     * `]` ends it — and is here because it is one of the constructs the vocabulary
     * names.
     */
    private const MARKDOWN = [
        '/\*\*.+\*\*/s',
        '/(?<![\w])__[^_\s](?:[^_]*[^_\s])?__(?![\w])/',
        '/\*[^*\s](?:[^*]*[^*\s])?\*/',
        '/(?<![\w])_[^_\s](?:[^_]*[^_\s])?_(?![\w])/',
        '/`[^`]+`/',
        '/\[[^\]]*\]\([^)]*\)/',
    ];

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
        $name = self::fold($node->getName());

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
     * a name this library does not draw and a label it cannot honour come out the
     * same way — as the characters the author typed.
     */
    public function draw(DirectiveInline $node, AbstractContainer $target, InlineStyle $style): bool
    {
        $label = $node->getLabel() ?? '';

        if ($label === '' || self::holdsMarkdown($label)) {
            return false;
        }

        $attributes = $this->attributesFor($node);

        if ($attributes === null) {
            return false;
        }

        $font = TextStyles::fontFor($attributes);

        if ($font === null) {
            return false;
        }

        $target->addText($label, $this->resolver->fontFor($style->withForcedFont($font)));

        return true;
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
        $name = self::fold($node->getName());

        if ($name === TextStyles::RESERVED) {
            return $attributes === [] ? null : $attributes;
        }

        $style = $this->styles->get($name);

        if ($style === null) {
            return null;
        }

        return array_merge($style, $attributes);
    }

    /**
     * Whether a label holds a complete Markdown construct.
     */
    private static function holdsMarkdown(string $label): bool
    {
        foreach (self::MARKDOWN as $pattern) {
            if (preg_match($pattern, $label) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Directive names are matched without regard to case, and the theme's styles are
     * folded the same way.
     */
    private static function fold(string $name): string
    {
        return strtolower(trim($name));
    }
}
