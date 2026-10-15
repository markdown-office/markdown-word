<?php

declare(strict_types=1);

namespace MarkdownWord\Filesystem;

/**
 * The directories a path a document wrote may point into, and the one question about it.
 *
 * A Markdown file is text, and `theme:` is a path it writes. Resolved by string
 * concatenation — `rtrim($directory, '/') . '/' . $written`, or the same with an
 * absolute path taken as it stands — `../` walks out of the document's own directory
 * and any absolute path is read from wherever it points. The document did not have to
 * be trying: one written by a model, from a file it was given, is enough.
 *
 * So a path a document writes is resolved with `realpath()` and has to land inside
 * one of the roots here. The roots are the working directory by default, plus the
 * themes that ship with this package, plus whatever else the calling program
 * allowed — a shared library of themes beside the project is a real case, and it is
 * the *caller's* decision rather than the document's, which is the whole distinction.
 *
 * `realpath()` rather than a string prefix because a prefix is not containment: `..`
 * and a symlink both defeat one, and a symlink pointing outside the roots is the
 * ordinary way a path that looks harmless turns into a read of the wrong file.
 */
final class PathScope
{
    /**
     * A `scheme://` prefix, which names a stream wrapper rather than a path at all.
     *
     * PHP hands one of those to the wrapper the name gives it: `phar://` reads an
     * archive out of the file system and unpacks it, `http://` makes the read a request.
     * A document cannot write a path like that and mean it, so it is refused before the
     * name reaches any of them.
     */
    private const SCHEME = '#\A[a-zA-Z][a-zA-Z0-9+.-]*://#';

    /** The archive prefix, which is the one wrapper this library can find itself inside. */
    private const PHAR = 'phar://';

    /** @var list<string> */
    private readonly array $roots;

    /**
     * @param list<string> $roots The directories a document-written path may resolve into.
     *   Resolved once here rather than on every path: a directory that does not exist when
     *   the scope is built is not one of them, which is what keeps the roots and the paths
     *   they are compared against in one spelling.
     *
     *   A root that resolves to the filesystem root is refused rather than kept. `/` is
     *   already a prefix of every absolute path, so {@see self::holds()} would answer true
     *   for anything at all and the scope would stop being one — silently, which is the
     *   failure this class exists to prevent. It is a plausible root in a container
     *   started with `WORKDIR /` or in a service that has chdir'd to `/`, so it is a
     *   configuration mistake rather than a hypothetical one.
     */
    public function __construct(array $roots = [])
    {
        $resolved = [];

        foreach ($roots as $root) {
            if (($real = self::root($root)) !== null && $real !== DIRECTORY_SEPARATOR) {
                $resolved[] = $real;
            }
        }

        $this->roots = array_values(array_unique($resolved));
    }

    /**
     * A root in the spelling paths will be compared against.
     *
     * {@see self::canonical()} for a directory on disk, and {@see self::literal()} for one
     * inside the archive this is running from — which is what the built-in themes are when
     * this library has been bundled into a `.phar`, and is the case
     * {@see self::canonical()} cannot serve
     * because `realpath()` does not resolve a `phar://` path at all.
     */
    private static function root(string $path): ?string
    {
        return self::canonical($path) ?? self::literal($path);
    }

    /**
     * A path resolved by walking its own segments, for one the file system cannot resolve.
     *
     * Only for a path inside this library's own archive, and that restriction is the
     * reason this is safe: a document cannot hand us a `phar://` path, because
     * {@see self::isUrl()} refuses every `scheme://` name before it gets here, so the only
     * archive paths this ever sees are the ones {@see __DIR__} produced.
     *
     * The `..` handling is what keeps it a boundary rather than a formality. Without it a
     * theme named `../../../etc` would resolve lexically to a path outside the archive —
     * inside a `phar://` URL, so not a filesystem read, but still a root that holds things
     * it must not.
     */
    private static function literal(string $path): ?string
    {
        if (!str_starts_with($path, self::PHAR)) {
            return null;
        }

        // `phar://` plus the archive's own path, which may itself contain slashes, plus
        // the path inside it. The split point is the first slash after the archive.
        $rest = substr($path, strlen(self::PHAR));
        $split = explode('/', $rest, 2);
        $archive = $split[0];
        $segments = [];

        foreach (explode('/', $split[1] ?? '') as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? null : self::PHAR . $archive . '/' . implode('/', $segments);
    }

    /**
     * A path this library's own directory resolved, however it had to be resolved.
     *
     * For the built-in themes, which are on disk under a checkout and inside the archive
     * when the library has been bundled into a `.phar`. Null where neither resolved,
     * which every caller here reads as "there
     * is no such thing".
     */
    public static function resolve(string $path): ?string
    {
        return self::root($path);
    }

    /** Whether a path a document wrote names a stream wrapper rather than a file. */
    public static function isUrl(string $written): bool
    {
        return preg_match(self::SCHEME, $written) === 1;
    }

    /**
     * A path with `..` and symlinks resolved, or null where there is no such file.
     *
     * Null rather than the path itself on failure, because every caller here is asking
     * whether a real file is there: a caller that only wanted a canonical spelling would
     * be asking `realpath()` and catching `ValueError`, which is what it throws for a
     * path holding a null byte — and a document can write one.
     */
    public static function canonical(string $path): ?string
    {
        try {
            $real = realpath($path);
        } catch (\ValueError) {
            return null;
        }

        return $real === false ? null : $real;
    }

    /**
     * Whether a canonical path is inside one of the roots.
     *
     * The roots themselves count as inside: a theme directory that *is* the working
     * directory is a strange but contained thing to be named.
     */
    public function holds(string $path): bool
    {
        foreach ($this->roots as $root) {
            if ($path === $root || str_starts_with($path, self::prefixed($root))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The roots as they were resolved, for a failure that has to name what would have
     * worked.
     *
     * @return list<string>
     */
    public function roots(): array
    {
        return $this->roots;
    }

    /** The root with a separator on the end, so `/srv/doc` cannot contain `/srv/doc-elsewhere`. */
    private static function prefixed(string $root): string
    {
        return str_ends_with($root, '/') ? $root : $root . '/';
    }
}