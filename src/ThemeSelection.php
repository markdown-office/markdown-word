<?php

declare(strict_types=1);

namespace MarkdownWord;

use MarkdownWord\Document\Frontmatter;
use MarkdownWord\Files\FileFinder;
use MarkdownWord\Render\Directives;
use MarkdownWord\Theme\LoadedTheme;
use MarkdownWord\Theme\ThemeResolver;

/**
 * The theme a converter's documents are drawn in.
 *
 * A theme named in code answers for the document, so the frontmatter is not read;
 * otherwise the frontmatter names one. The name given in code is resolved once,
 * when it is given, so the document is drawn in the theme that was found there
 * rather than in one found again later.
 *
 * A selection is a value: naming a theme gives a new selection rather than
 * changing this one. A converter that is cloned shares the selection it had, and
 * a theme named on the clone is the clone's alone.
 */
final class ThemeSelection
{
    /**
     * @param string|null $name The theme named in code, if one was named.
     * @param LoadedTheme|null $resolved The theme that name resolved to.
     * @param string|null $directory Where a theme beside the document is found.
     * @param list<string> $roots Further directories a theme may be found in.
     * @param FileFinder|null $files Where the themes come from.
     */
    private function __construct(
        private readonly ?string $name,
        private readonly ?LoadedTheme $resolved,
        private readonly ?string $directory,
        private readonly array $roots,
        private readonly ?FileFinder $files,
    ) {
    }

    /**
     * A selection that names no theme, so that a document's own frontmatter is read.
     *
     * @param string|null $directory The directory the document was read from, which is
     *        where a theme beside it is found: a document names a theme, and the theme
     *        that travels with it is the one next to it rather than the one next to
     *        wherever the program happens to be. Null for a document handed in as a
     *        string, which has no directory of its own; the working directory is what a
     *        name resolves against then.
     * @param list<string> $roots Further directories a document may name a theme in,
     *        for a shared library of themes beside the project. As with a theme's this
     *        is the calling program's decision, made before a document is read.
     * @param FileFinder|null $files Where the themes come from: a program whose themes
     *        are not in a directory.
     */
    public static function none(
        ?string $directory = null,
        array $roots = [],
        ?FileFinder $files = null,
    ): self {
        return new self(null, null, $directory, $roots, $files);
    }

    /**
     * The theme named in code, resolved once.
     *
     * A new selection rather than a change to this one, so that a converter that is
     * cloned cannot have its theme changed by the clone.
     *
     * @throws Exception\ThemeNotFound when the theme is not one that resolves. Checked
     *         here so a typo is reported before the document is read rather than partway
     *         through writing it.
     * @throws Exception\MalformedTheme when the theme's own file does not describe a theme
     * @throws Exception\UnknownStyleSlot when the theme names a slot that does not exist
     * @throws Exception\InvalidConfiguration when the theme names a text style after a
     *         directive this library draws
     * @throws Exception\Exception when the theme's own file cannot be read or parsed
     */
    public function select(?string $name): self
    {
        if ($name === null) {
            return new self(null, null, $this->directory, $this->roots, $this->files);
        }

        $theme = $this->themes()->resolve($name);

        Directives::assertStylesAreDrawable($theme->textStyles());

        // Kept, so that the name is resolved once: the document is drawn in the
        // theme that was found here rather than in one found again later.
        return new self($name, $theme, $this->directory, $this->roots, $this->files);
    }

    /**
     * The theme this document is drawn in, or null when it names none.
     *
     * A theme named in code answers for the document, so the frontmatter's `theme:` is not read —
     * which is also why a document naming a theme that does not resolve is not reported
     * here: the name was overridden, and a caller that passed a theme of its own asked for
     * that one. This is the only source that is *silently* ignored, and it is ignored only
     * because something louder replaced it.
     *
     * @throws Exception\ThemeNotFound when the theme in play is not one that resolves
     * @throws Exception\MalformedTheme when the theme's own file does not describe a theme
     * @throws Exception\UnknownStyleSlot when the theme names a slot that does not exist
     * @throws Exception\Exception when the theme's own file cannot be read or parsed
     */
    public function forDocument(Frontmatter $frontmatter): ?LoadedTheme
    {
        if ($this->name !== null) {
            // The name in code was resolved when it was given, and it is resolved
            // once: a source that counts its reads would say so.
            return $this->resolved;
        }

        $name = $frontmatter->themeName();

        return $name === null ? null : $this->themes()->resolve($name);
    }

    private function themes(): ThemeResolver
    {
        return new ThemeResolver(
            workingDirectory: getcwd() ?: '.',
            documentDirectory: $this->directory,
            roots: $this->roots,
            files: $this->files,
        );
    }
}
