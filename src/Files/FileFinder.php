<?php

declare(strict_types=1);

namespace MarkdownWord\Files;

use MarkdownWord\Exception\InvalidInput;

/**
 * Where the files a document names come from.
 *
 * The library composes *names* and asks; a finder answers. What a theme is, and
 * what the files of one are called, is the library's business: the finder answers
 * for the names it is handed, and lists what is under a name it is handed. That is
 * what lets a directory of themes on disk and a database of themes that never had a
 * directory serve the same library.
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
 *
 * A file that is there and out of reach is not the same as a file that is not there:
 * the first is a document reaching for something and the second is a name that
 * resolves to nothing, and the library reports the two differently. So a finder
 * refuses the first rather than answering null for it.
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
     *
     * @throws InvalidInput when the file is there and this render may not read it.
     */
    public function read(string $name, ?string $role = null): ?string;

    /**
     * The path of a file a name identifies, when this finder holds it as a file.
     *
     * For the thing a document cannot be handed as bytes: a `template_file:`, which
     * PHPWord opens as a package. A finder with no path says so, and the library
     * stages the bytes into a file of its own.
     *
     * @throws InvalidInput when the file is there and this render may not read it.
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
