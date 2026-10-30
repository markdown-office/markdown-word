<?php

declare(strict_types=1);

namespace MarkdownWord\Files;

/**
 * Where the files a document names come from.
 *
 * The library composes *names* and asks; a finder answers. What a theme is, and what
 * the files of one are called, is the library's business: the finder answers for the
 * names it is handed, and lists what is under a name it is handed. That is what lets
 * a directory of themes on disk and a database of themes that never had a directory
 * serve the same library.
 *
 * The role is the kind of file — `theme`, `picture` or `document` — and it says which
 * world a name is resolved in. It is a hint and never a filter: it says where to look,
 * not what may be served. The three role names are the same in both halves of this
 * suite, so a finder written once serves both.
 *
 * The names are the library's own: a theme's manifest is `corporate/theme.yml`, a
 * picture is `logo.png` and a template is `report.dotx`. A name is looked for in the
 * places the finder was told about.
 *
 * **A finder answers; it does not refuse.** A file that is not there is null, and
 * nothing else. Each half of this suite has its own exceptions, so a finder that
 * refused would throw one half's exception and the other half would not catch it —
 * and the two interfaces would stop being the same, which is the whole reason there
 * is one shape rather than two. What may be read therefore stays with the library,
 * which holds every path a finder answers to its own roots before it reads it, and
 * whose refusals carry the messages.
 */
interface FileFinder
{
    /**
     * The contents of a file a name identifies, or null when there is none.
     *
     * What the library wants for a theme's YAML and for a picture: the bytes
     * themselves. A source that has bytes and no path — the plugin that keeps its
     * themes in a database — serves a document this way. A theme's YAML is parsed
     * from the bytes it was given; a picture and a template are staged into a file
     * of the library's own.
     */
    public function read(string $name, ?string $role = null): ?string;

    /**
     * The path of a file a name identifies, when this finder holds it as a file.
     *
     * Null for a source that holds the bytes and no file: the library reads them
     * instead — a theme's YAML is parsed from them, and a picture or a template is
     * staged into a file of its own.
     */
    public function path(string $name, ?string $role = null): ?string;

    /**
     * The themes that resolve, sorted.
     *
     * @return list<string>
     */
    public function themes(): array;

    /**
     * The names of the files of a theme, sorted.
     *
     * @return list<string>
     */
    public function files(string $theme): array;
}
