<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

/**
 * The formatting that applies to a single run of text.
 *
 * Word has no notion of nested emphasis, so this is a flat set of flags — enough
 * for every inline construct in CommonMark and GFM, bold inside a link inside
 * italic being three of them at once.
 */
final class InlineStyle
{
    public function __construct(
        public readonly bool $bold = false,
        public readonly bool $italic = false,
        public readonly bool $strikethrough = false,
        public readonly bool $code = false,
        public readonly ?string $url = null,
        public readonly ?string $linkTitle = null,
        /**
         * Font properties forced onto every run in this scope. This is how a table
         * header is made bold and a cell's alignment applied, since PHPWord's cell
         * style carries neither.
         *
         * A property the Markdown's own emphasis names is the more particular
         * statement and wins: a block that says its text is not italic does not
         * cancel an `*emphasis*` in it. {@see StyleResolver::fontFor()} says where
         * the layers go.
         *
         * @var array<string, mixed>
         */
        public readonly array $forcedFont = [],
    ) {
    }

    public function withForcedFont(array $font): self
    {
        return $this->derive(forcedFont: $font + $this->forcedFont);
    }

    public function withBold(): self
    {
        return $this->derive(bold: true);
    }

    public function withItalic(): self
    {
        return $this->derive(italic: true);
    }

    public function withStrikethrough(): self
    {
        return $this->derive(strikethrough: true);
    }

    public function withCode(): self
    {
        return $this->derive(code: true);
    }

    public function withLink(string $url, ?string $title = null): self
    {
        return $this->derive(url: $url, linkTitle: $title);
    }

    private function derive(
        ?bool $bold = null,
        ?bool $italic = null,
        ?bool $strikethrough = null,
        ?bool $code = null,
        ?string $url = null,
        ?string $linkTitle = null,
        ?array $forcedFont = null,
    ): self {
        return new self(
            $bold ?? $this->bold,
            $italic ?? $this->italic,
            $strikethrough ?? $this->strikethrough,
            $code ?? $this->code,
            $url ?? $this->url,
            $linkTitle ?? $this->linkTitle,
            $forcedFont ?? $this->forcedFont,
        );
    }

    public function isLink(): bool
    {
        return $this->url !== null;
    }
}
