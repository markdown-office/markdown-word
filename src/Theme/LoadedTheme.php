<?php

declare(strict_types=1);

namespace MarkdownWord\Theme;

use MarkdownWord\Configuration\Styles;

/**
 * A theme as a directory holds it: what it calls itself, and the slots it draws from.
 *
 * A `Styles` rather than a raw array because the merge is the interesting half, and it is
 * the merge {@see \MarkdownWord\Configuration\Styles} already defines: a theme that names
 * one property of a slot keeps the rest of the built-in look under it, and a theme that
 * names a slot as a style id or as `null` replaces it whole.
 */
final class LoadedTheme
{
    public function __construct(
        private readonly string $directory,
        private readonly Theme $theme,
        private readonly Styles $styles,
    ) {
    }

    /** The canonical directory this was read from. */
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
}