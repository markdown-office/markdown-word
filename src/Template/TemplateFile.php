<?php

declare(strict_types=1);

namespace MarkdownWord\Template;

use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Exception\TemplateNotFound;
use MarkdownWord\Files\FileFinder;
use MarkdownWord\Filesystem\PathScope;

/**
 * Turns the `template_file:` a document names into a path, and says so when it cannot.
 *
 * A Markdown file is text and `template_file:` is a path it writes, so it is held to
 * the same rule as `theme:`: resolved with `realpath()` and landed inside a root the
 * calling program allowed. A relative name is written from beside the document, which is
 * where relative image paths resolve from too.
 *
 * The roots are the working directory and the document's own, so a document in
 * `docs/` names the template beside it whether the run started in `docs/` or above
 * it. What neither root covers is a `../` that climbs out, which is the case
 * {@see PathScope} exists for and the reason this is not a bare concatenation.
 *
 * **The template comes from the finder.** A source that holds it as a file answers a
 * path, and the path is held to the roots as any other path is. A source that holds
 * the bytes answers no path, and the bytes are staged into a file of the library's
 * own — a `.dotx` is a package, and a package is opened as a file.
 *
 * **A staged file belongs to this resolver.** It is there for as long as the template
 * is in use, and it is removed when the resolver gives it back: by
 * {@see self::release()} when the template is done with, and by the destructor for a
 * caller who lets the resolver go without saying so. Nothing is left in the temporary
 * directory either way — not after a conversion that worked and not after one that
 * failed.
 */
final class TemplateFile
{
    /** @var list<string> */
    private readonly array $roots;

    /** The files this resolver staged and has not given back yet. */
    private array $staged = [];

    /**
     * @param string|null $directory The directory the document was read from, which
     *   a relative name is written from. Null for a document handed in as a string:
     *   there is no file beside it, so a relative name has nowhere to resolve from
     *   and is reported rather than guessed at.
     * @param list<string> $roots Further directories a document may name a template
     *   in, for a shared library beside the project. As with a theme's this is the
     *   calling program's decision, made before a document is read.
     * @param FileFinder|null $files Where the template comes from, for a program whose
     *   templates are not in a directory. The document's own directory and the roots
     *   remain where a name is looked for when there is no finder.
     */
    public function __construct(
        private readonly ?string $directory = null,
        array $roots = [],
        private readonly string $workingDirectory = '.',
        private readonly ?FileFinder $files = null,
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

        $found = $this->fromFinder($written);

        if ($found !== null) {
            return $found;
        }

        $base = $this->baseFor($written);

        return $this->held($written, $base);
    }

    /**
     * Give back the files this resolver staged.
     *
     * A staged template is the library's own file, and it goes when the template is
     * done with. The caller says so here; a caller who lets the resolver go without
     * saying so has it said for them.
     */
    public function release(): void
    {
        foreach ($this->staged as $path) {
            @unlink($path);
        }

        $this->staged = [];
    }

    public function __destruct()
    {
        $this->release();
    }

    /**
     * The template in the source the document's files come from, or null when it has
     * none.
     *
     * A source that holds the template as a file answers a path, and one that holds
     * the bytes answers no path: the bytes are the caller's word for them.
     */
    private function fromFinder(string $written): ?string
    {
        $files = $this->files;

        if ($files === null) {
            return null;
        }

        $path = $files->path($written, 'document');

        if ($path !== null) {
            return $this->held($written, $path);
        }

        return $this->fromBytes($written, $files);
    }

    /**
     * The template's bytes, staged into a file of the library's own.
     */
    private function fromBytes(string $written, FileFinder $files): ?string
    {
        $bytes = $files->read($written, 'document');

        if ($bytes === null) {
            return null;
        }

        return $this->stage($bytes, $written);
    }

    /**
     * The path a name resolved to, held to the roots.
     *
     * One rule for both: a path from the disk and a path from the caller's source are
     * held to the roots alike, because a `template_file:` is written by the document
     * either way.
     *
     * @throws TemplateNotFound when the path is not a file, or is out of reach
     */
    private function held(string $written, string $path): string
    {
        $canonical = PathScope::canonical($path);

        if ($canonical === null || !is_file($canonical)) {
            throw TemplateNotFound::aMissingFile($written, $this->searchedIn());
        }

        $files = new PathScope($this->roots);

        if (!$files->holds($canonical)) {
            throw TemplateNotFound::aRefusedPath($written, $files->roots());
        }

        return $canonical;
    }

    /**
     * The template's bytes, in a file of the library's own.
     *
     * @throws FileNotWritable when the file cannot be made or written
     */
    private function stage(string $bytes, string $written): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mdword_tpl_');

        if ($path === false) {
            throw new FileNotWritable(sprintf(
                'Unable to create a temporary file for the template in "%s".',
                sys_get_temp_dir(),
            ));
        }

        // Written into the file `tempnam()` made rather than over it, so that the
        // mode it was given stands: a template in flight through a shared temporary
        // directory is not readable by every account on the machine.
        if (file_put_contents($path, $bytes) === false) {
            @unlink($path);

            throw new FileNotWritable(sprintf('Unable to stage the template "%s".', $written));
        }

        @chmod($path, 0o600);

        $this->staged[] = $path;

        return $path;
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
