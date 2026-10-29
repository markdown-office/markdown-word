<?php

declare(strict_types=1);

namespace MarkdownWord\Files;

/**
 * Where the files a document names come from.
 *
 * The library composes *names* and asks; a finder answers. What a theme is, and
 * what the files of one are called, is the library's business: the finder answers
 * for the names it is handed, and lists what is under a name it is handed. That is
 * what lets a directory of themes on disk and a database of themes that never had a
 * directory serve the same library.
 *
 * **A finder answers; it does not refuse.** A file that is not there is null, and
 * nothing else. The question of what this render may read stays with the library,
 * which holds the path a finder answered to its own scope and refuses what is out
 * of reach in its own words: {@see \MarkdownWord\Theme\ThemeResolver::resolve()} and
 * {@see \MarkdownWord\Theme\ThemeLoader::load()} are where that is asked.
 *
 * The reason is not politeness. Each package of this suite has its own exception
 * classes, so a finder that threw would throw one package's exception and the other
 * package would not catch it — and the two finders have the same signatures on
 * purpose, so that one adapter can serve both.
 *
 * The role is the kind of file — `theme`, `picture` or `document` — and it says which
 * world a name is resolved in. It is a hint and never a filter: it says where to
 * look, not what may be served, so a source that keeps its themes in a database may
 * answer for a picture as readily as for a theme. The three role names are the same
 * in both halves of this suite, so a finder written once serves both.
 *
 * The names are the library's own: a theme's manifest is `corporate/theme.yml`, a
 * picture is `logo.png`, a document's own file is `report.docx`. A name is looked
 * for in the places the finder was told about.
 */
interface FileFinder
{
    /**
     * The contents of a file a name identifies, or null when there is none.
     *
     * What the library wants for YAML, for Markdown and for a picture: the bytes
     * themselves. A source that has bytes and no path — the plugin that keeps its
     * themes in a database — serves a document this way, and the library stages
     * them into a file of their own.
     */
    public function read(string $name, ?string $role = null): ?string;

    /**
     * The path of a file a name identifies, when this finder holds it as a file.
     *
     * For the thing a document cannot be handed as bytes: a `template_file:`, which
     * PHPWord opens as a package. A finder with no path says so, and the library
     * stages the bytes into a file of its own.
     */
    public function path(string $name, ?string $role = null): ?string;

    /**
     * The themes that resolve, sorted.
     *
     * For a failure that has to name what would have worked: a document naming a
     * theme that is not there is told which themes are.
     *
     * @return list<string>
     */
    public function themes(): array;

    /**
     * The names of the files of a theme, sorted.
     *
     * For the question a null cannot answer on its own: a file the theme does not
     * have and a file this source cannot serve are different problems, and the
     * listing says which one it is.
     *
     * @return list<string>
     */
    public function files(string $theme): array;
}
