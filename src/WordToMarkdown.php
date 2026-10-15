<?php

declare(strict_types=1);

namespace MarkdownWord;

use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Exception\NothingToConvert;
use MarkdownWord\Reverse\Block;
use MarkdownWord\Reverse\DocumentReader;
use MarkdownWord\Reverse\Inline;
use MarkdownWord\Reverse\MarkdownWriter;
use MarkdownWord\Reverse\NumberingTable;
use MarkdownWord\Reverse\Options;
use MarkdownWord\Reverse\Package;
use MarkdownWord\Reverse\StyleTable;

/**
 * Converts a Word document back into Markdown — the inverse of
 * {@see \MarkdownWord\MarkdownToWord}, so a document can be checked by converting
 * it to Word, back to Markdown, and comparing the text the reader ends up with.
 *
 * The round trip preserves what Word was told to keep and is honest about the
 * rest. These are the distinctions Word does not record, and README says each in
 * full with an example:
 *
 *  - a table's header row comes back bold, and its delimiter row is rewritten,
 *    though the column alignment itself survives;
 *  - a fenced code block comes back without its language, and one of a single
 *    line as an inline span instead;
 *  - a quote written as plain indentation rather than as a style comes back as a
 *    plain paragraph;
 *  - the last line has no newline after it.
 *
 * The document is not this library's: the names in it and the contents of what
 * it names are the sender's to choose. So the result is written from a staged
 * copy rather than opened in place, and an image is only written out once both
 * its name and its bytes say that it is an image.
 */
final class WordToMarkdown implements Converter
{
    /**
     * The formats Word embeds, and no others: a name outside this list is a
     * document asking for a file to be written under a name the sender chose.
     */
    private const IMAGE_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'bmp', 'tiff', 'webp', 'emf', 'wmf',
    ];

    /**
     * @param string|null $source The document to convert: a path, or the bytes of
     *        one, read as {@see Input} reads a string. Null leaves the choice to
     *        {@see self::toMarkdown()}.
     */
    public function __construct(
        private readonly ?string $source = null,
        private readonly Options $options = new Options(),
    ) {
    }

    public function getOptions(): Options
    {
        return $this->options;
    }

    /**
     * @throws NothingToConvert   when no source was given.
     * @throws FileNotWritable    when there is a target and it cannot be written.
     * @throws \MarkdownWord\Exception\UnreadableFile     when the source names a
     *         file that cannot be read.
     * @throws \MarkdownWord\Exception\UnreadableDocument when the source is not a
     *         document this can read, or is too large to be worth reading.
     * @throws \MarkdownWord\Exception\MalformedDocument  when a part of it is
     *         missing or does not parse.
     */
    public function convert(?string $target = null): string
    {
        if ($this->source === null) {
            throw new NothingToConvert(
                'There is no document to convert. Give one to the constructor, '
                . 'or the bytes to ' . self::class . '::toMarkdown().',
            );
        }

        $package = Package::fromString(Input::document($this->source), $this->options);

        try {
            $markdown = $this->write($package);
        } finally {
            $package->close();
        }

        if ($target !== null) {
            self::writeTo($target, $markdown);
        }

        return $markdown;
    }

    /**
     * @throws FileNotWritable when the target cannot be written.
     */
    public function save(string $target): void
    {
        $this->convert($target);
    }

    /**
     * Convert a document held in memory, the counterpart of
     * {@see \MarkdownWord\MarkdownToWord::toDocx()}.
     *
     * @throws \MarkdownWord\Exception\UnreadableDocument when the bytes are not a
     *         document this can read, or are too large to be worth reading.
     * @throws \MarkdownWord\Exception\MalformedDocument when a part of them is
     *         missing or does not parse.
     */
    public function toMarkdown(string $bytes): string
    {
        $package = Package::fromString($bytes, $this->options);

        try {
            return $this->write($package);
        } finally {
            $package->close();
        }
    }

    /**
     * The block tree a document converts to, for callers that want to inspect it
     * rather than serialise it.
     *
     * @return list<Block>
     * @throws \MarkdownWord\Exception\UnreadableDocument when the path is not a
     *         document this can read, or is too large to be worth reading.
     * @throws \MarkdownWord\Exception\MalformedDocument when a part of it is
     *         missing or does not parse.
     */
    public function read(string $path): array
    {
        $package = Package::open($path, $this->options);

        try {
            return $this->reader($package)->read($package);
        } finally {
            $package->close();
        }
    }

    private function write(Package $package): string
    {
        $blocks = $this->reader($package)->read($package);

        // The images live inside the archive under names the writer invented, so
        // asking for a media directory means taking them out of it: a reference
        // to a name that is not on disk resolves to nothing.
        if ($this->options->mediaDirectory !== null) {
            $this->extractMedia($package, $blocks, $this->options->mediaDirectory);
        }

        $markdown = (new MarkdownWriter($this->options))->write($blocks);

        return $this->options->lineEnding === "\n"
            ? $markdown
            : str_replace("\n", $this->options->lineEnding, $markdown) . $this->options->lineEnding;
    }

    /**
     * Put bytes in a file, through a staged copy.
     *
     * The file is moved into place rather than opened where it lies, because
     * `file_put_contents()` opens an existing name with `O_TRUNC` and a name can
     * be a hard link — the caller naming `alias.docx` for a document that is
     * `original.docx`, where the two are one inode, would empty the document still
     * being read. `realpath()`
     * sees two paths, so an overwrite guard built on it cannot see the case
     * either. Moving replaces the name and leaves the inode alone — which matters
     * for the Markdown a caller named and for an image, whose name came out of
     * the document being read.
     *
     * The staged copy is a file in the target's own directory, the only one
     * `rename()` can move within a single filesystem.
     *
     * @throws FileNotWritable when the target cannot be written.
     */
    private static function writeTo(string $target, string $contents): void
    {
        $directory = \dirname($target);

        // A path given to a converter rarely has its directory made for it, and
        // a rename does not create one.
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new FileNotWritable(sprintf('Unable to create the directory "%s".', $directory));
        }

        $staged = @tempnam($directory, '.mdword-');

        if ($staged === false || realpath(\dirname($staged)) !== realpath($directory)) {
            // A directory that cannot be written to takes the staging file
            // somewhere else, and a rename across a filesystem boundary fails;
            // either way the target is not writable, and that is the fact worth
            // reporting.
            throw new FileNotWritable(sprintf('Unable to write "%s".', $target));
        }

        try {
            if (@file_put_contents($staged, $contents) === false) {
                throw new FileNotWritable(sprintf('Unable to write "%s".', $target));
            }

            if (!@rename($staged, $target)) {
                throw new FileNotWritable(sprintf('Unable to write "%s".', $target));
            }
        } finally {
            // The rename has already moved the file by the time this runs, so
            // there is nothing left to remove unless the write never got that
            // far.
            if (is_file($staged)) {
                @unlink($staged);
            }
        }
    }

    /**
     * Write every image the document uses into the configured media directory.
     *
     * A file is only written when both halves of the document agree about it: the
     * name has to be one of the image formats Word embeds, and the bytes have to be
     * an image. The document chooses both, so either on its own is a request to
     * write a file the sender named — a `.php` beside the Markdown, or an
     * `.htaccess` that makes the server treat every other file there as one.
     *
     * A name already taken in the directory is left alone: the document does not
     * get to overwrite a file that was there first, which is what keeps a second
     * conversion of the same document from replacing what the first wrote.
     *
     * @param string        $directory Where the images go. Never empty: the options
     *        turn `''` into null, so a caller who passed one takes the branch in
     *        {@see self::write()} and never reaches here with a path at the root of
     *        the filesystem.
     * @param list<Block>   $blocks
     * @throws FileNotWritable when the directory or a file in it cannot be written.
     */
    private function extractMedia(Package $package, array $blocks, string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new FileNotWritable(sprintf('Unable to create the media directory "%s".', $directory));
        }

        foreach ($this->mediaTargets($blocks) as $reference) {
            // The reference is the path the image is written to, so the name
            // inside the archive is recovered from it rather than remembered.
            $name = basename($reference);
            $contents = $package->contentsOf('word/media/' . $name) ?? $package->contentsOf('word/' . $name);

            if ($contents === null || !$this->isImage($name, $contents)) {
                continue;
            }

            $target = $directory . '/' . $name;

            if (file_exists($target)) {
                continue;
            }

            self::writeTo($target, $contents);
        }
    }

    /**
     * The paths the document's images are written to, as the Markdown refers to
     * them. With a media directory configured these are the paths beside the
     * Markdown rather than the names inside the archive, because the reader has
     * already had them rewritten.
     *
     * @param list<Block> $blocks
     * @return list<string>
     */
    private function mediaTargets(array $blocks): array
    {
        $targets = [];

        $walk = static function (array $nodes) use (&$walk, &$targets): void {
            foreach ($nodes as $node) {
                foreach ($node->inlines as $inline) {
                    if ($inline->kind === Inline::IMAGE && $inline->target !== '') {
                        $targets[$inline->target] = true;
                    }
                }

                $walk($node->children);
            }
        };

        $walk($blocks);

        return array_keys($targets);
    }

    /**
     * Whether a name and the bytes behind it are an image.
     *
     * The extension is checked first because it is free, and because the name is
     * the half that has to end up on disk. A leading dot is refused along with it:
     * `.htaccess` has an "extension" in the loose sense that a split on a dot
     * produces, and it is a configuration file rather than a picture.
     *
     * The bytes are checked because the name is chosen by the sender, and an
     * extension is a claim rather than a fact. `getimagesize()` knows the raster
     * formats and says so by returning false for everything else, which includes
     * the two vector formats Word embeds: those are recognised by their own headers
     * instead, or the document would lose a chart.
     */
    private function isImage(string $name, string $bytes): bool
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (str_starts_with($name, '.') || !in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return false;
        }

        if ($extension === 'emf') {
            return self::isEnhancedMetafile($bytes);
        }

        if ($extension === 'wmf') {
            return self::isMetafile($bytes);
        }

        return @getimagesizefromstring($bytes) !== false;
    }

    /**
     * An enhanced metafile: an `EMR_HEADER` record, which is record type 1 and
     * carries the signature ` EMF` at a fixed offset, and a header that fits in
     * the bytes there are. The signature is what makes this a check rather than
     * a coincidence: four bytes at the start of a file are not evidence of
     * anything, and `<?php` is not four zero bytes.
     */
    private static function isEnhancedMetafile(string $bytes): bool
    {
        if (strlen($bytes) < 88 || substr($bytes, 0, 4) !== "\x01\x00\x00\x00") {
            return false;
        }

        if (substr($bytes, 40, 4) !== ' EMF') {
            return false;
        }

        /** @var array{1: int} $header */
        $header = unpack('V', substr($bytes, 4, 4));

        return $header[1] >= 88 && $header[1] <= strlen($bytes);
    }

    /**
     * A Windows metafile: either a bare `METAHEADER` record, or the Aldus
     * placeable wrapper around one — a fixed 30-byte header, after which comes
     * the very record a bare metafile begins with. The placeable form is checked
     * through to that record rather than on its key alone, because the key is
     * four fixed bytes at the start of a file, which is the easiest possible
     * thing for a script to begin with.
     */
    private static function isMetafile(string $bytes): bool
    {
        $offset = 0;

        if (substr($bytes, 0, 4) === "\xd7\xcd\xc6\x9a") {
            $offset = 30;
        }

        // Four bytes of type and header size, then the size itself.
        if (strlen($bytes) < $offset + 6) {
            return false;
        }

        /** @var array{type: int, size: int} $header */
        $header = unpack('vtype/vsize', substr($bytes, $offset, 4));

        return $header['type'] === 1 && in_array($header['size'], [9, 12], true);
    }

    private function reader(Package $package): DocumentReader
    {
        return new DocumentReader(
            $this->options,
            new StyleTable($package->styles(), $this->options->maxStyleDepth),
            new NumberingTable($package->numbering()),
        );
    }
}
