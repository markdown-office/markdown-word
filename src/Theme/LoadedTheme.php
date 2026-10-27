<?php

declare(strict_types=1);

namespace MarkdownWord\Theme;

use MarkdownWord\Configuration\Styles;
use MarkdownWord\Configuration\TextStyles;

/**
 * A theme as it was read: what it calls itself, and the slots it draws from.
 *
 * A `Styles` rather than a raw array because the merge is the interesting half, and it is
 * the merge {@see \MarkdownWord\Configuration\Styles} already defines: a theme that names
 * one property of a slot keeps the rest of the built-in look under it, and a theme that
 * names a slot as a style id or as `null` replaces it whole.
 *
 * The named text styles are a second thing and travel beside the slots: they are not
 * slots, so nothing merges them into the built-in look, and a document that names one
 * writes a word in it.
 */
final class LoadedTheme
{
    public function __construct(
        private readonly string $directory,
        private readonly Theme $theme,
        private readonly Styles $styles,
        private readonly TextStyles $textStyles = new TextStyles(),
    ) {
    }

    /**
     * The canonical directory this was read from: the theme's own directory, or the one
     * that holds the manifest a theme was named by.
     */
    public function directory(): string
    {
        return $this->directory;
    }

    public function theme(): Theme
    {
        return $this->theme;
    }

    /** The slots this theme draws from, already merged over the built-in look. */
    public function styles(): Styles
    {
        return $this->styles;
    }

    /** The named text styles this theme brings, for a document to write a word in. */
    public function textStyles(): TextStyles
    {
        return $this->textStyles;
    }
}