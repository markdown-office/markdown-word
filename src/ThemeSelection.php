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
 */
final class ThemeSelection
{
    /**
     * The theme named by {@see \MarkdownWord\MarkdownToWord::withTheme()} or the
     * command line, if either named one.
     */
    private ?string $name = null;

    /**
     * The theme {@see self::select()} resolved, kept so that the name is resolved
     * once.
     *
     * A source that counts its reads would say so: the name is asked for when it is
     * given, and the theme that came back is the one the document is drawn in.
     */
    private ?LoadedTheme $resolved = null;

    /**
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
    public function __construct(
        private readonly ?string $directory = null,
        private readonly array $roots = [],
        private readonly ?FileFinder $files = null,
    ) {
    }

    /**
     * The theme named in code, resolved once.
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
    public function select(?string $name): void
    {
        if ($name !== null) {
            $theme = $this->themes()->resolve($name);

            Directives::assertStylesAreDrawable($theme->textStyles());

            // Kept, so that the name is resolved once: the document is drawn in the
            // theme that was found here rather than in one found again later.
            $this->resolved = $theme;
        } else {
            $this->resolved = null;
        }

        $this->name = $name;
    }

    /**
     * The theme this document is drawn in, or null when it names none.
     *
     * A theme named in code answers for the document, so the frontmatter is not read —
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
