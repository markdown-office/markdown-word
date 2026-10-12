<?php

declare(strict_types=1);

namespace MarkdownWord\Template;

use MarkdownWord\Exception\TemplateNotFound;
use MarkdownWord\Filesystem\PathScope;

/**
 * Turns the `template_file:` a document names into a path, and says so when it cannot.
 *
 * A Markdown file is text and `template_file:` is a path it writes, so it is held to
 * the same rule as `theme:`: resolved with `realpath()` and landed inside a root the
 * calling program allowed. The difference from a theme is where a relative name is
 * written from — beside the document, which is where relative image paths already
 * resolve from too.
 *
 * The roots are the working directory and the document's own, so a document in
 * `docs/` names the template beside it whether the run started in `docs/` or above
 * it. What neither root covers is a `../` that climbs out, which is the case
 * {@see PathScope} exists for and the reason this is not a bare concatenation.
 */
final class TemplateFile
{
    /** @var list<string> */
    private readonly array $roots;

    /**
     * @param string|null $directory The directory the document was read from, which
     *   a relative name is written from. Null for a document handed in as a string:
     *   there is no file beside it, so a relative name has nowhere to resolve from
     *   and is reported rather than guessed at.
     * @param list<string> $roots Further directories a document may name a template
     *   in, for a shared library beside the project. As with a theme's this is the
     *   calling program's decision, made before a document is read.
     */
    public function __construct(
        private readonly ?string $directory = null,
        array $roots = [],
        private readonly string $workingDirectory = '.',
    ) {
        $this->roots = $roots === []
            ? array_values(array_filter([$this->workingDirectory, $directory]))
            : $roots;
    }

    /**
     * @throws TemplateNotFound when the name is not a template this may read, or is
     *         not one at all. Both are the same failure to the document's author, so
     *         they are reported together, with the reason.
     */
    public function resolve(string $written): string
    {
        if (PathScope::isUrl($written)) {
            throw TemplateNotFound::aUrl($written);
        }

        $base = $this->baseFor($written);
        $canonical = PathScope::canonical($base);

        if ($canonical === null || !is_file($canonical)) {
            throw TemplateNotFound::aMissingFile($written, $this->searchedIn());
        }

        $files = new PathScope($this->roots);

        if (!$files->holds($canonical)) {
            throw TemplateNotFound::aRefusedPath($written, $files->roots());
        }

        return $canonical;
    }

    private function baseFor(string $written): string
    {
        if (str_starts_with($written, '/')) {
            return $written;
        }

        if ($this->directory === null) {
            throw TemplateNotFound::aRelativeNameWithNoDirectory($written);
        }

        return rtrim($this->directory, '/') . '/' . $written;
    }

    /**
     * Where a relative name was looked for, for a failure that has to say.
     *
     * @return list<string>
     */
    private function searchedIn(): array
    {
        return $this->directory === null ? [] : [$this->directory];
    }
}