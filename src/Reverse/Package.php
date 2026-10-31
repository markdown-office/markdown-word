<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Xml;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\UnreadableDocument;
use ZipArchive;

/**
 * Reads the parts of a `.docx` archive that describe a document's content.
 *
 * A `.docx` is a zip of XML parts. The reverse converter needs four of them —
 * the document itself, the relationship part that resolves hyperlinks and
 * images, the style definitions that say what a `w:pStyle` means, and the
 * numbering definitions that say what a `w:numId` means — and this class hands
 * them over as parsed documents without leaking the archive into the rest of the
 * code.
 *
 * The archive is not this library's, and a zip says how its contents are laid out
 * and nothing about how much room they will take up: forty kilobytes of a
 * document part is forty megabytes of paragraph, and the entry count is whatever
 * the writer felt like. So {@see Options::$maxPartBytes} and
 * {@see Options::$maxEntries} bound what is read before it is inflated, and the
 * archive is opened consistently rather than as far as the bytes happen to
 * stretch.
 */
final class Package
{
    private const DOCUMENT = 'word/document.xml';
    private const RELATIONSHIPS = 'word/_rels/document.xml.rels';
    private const STYLES = 'word/styles.xml';
    private const NUMBERING = 'word/numbering.xml';

    /** @var array<string, \DOMDocument|null> */
    private array $parts = [];

    private ?string $scratchPath = null;

    private bool $closed = false;

    private function __construct(
        private readonly \ZipArchive $zip,
        private readonly Options $options = new Options(),
    ) {
    }

    public static function open(string $path, Options $options = new Options()): self
    {
        return self::openArchive(new ZipArchive(), $path, $options);
    }

    public static function fromString(string $bytes, Options $options = new Options()): self
    {
        $path = self::writeScratchFile($bytes);

        try {
            $package = self::openArchive(new ZipArchive(), $path, $options);
        } catch (\Throwable $failure) {
            // Nothing took ownership of the scratch file, so it is removed here
            // rather than being left for a clean-up that is never registered.
            @unlink($path);

            throw $failure;
        }

        $package->scratchPath = $path;

        return $package;
    }

    /**
     * Release the archive, removing the scratch file if one was needed.
     *
     * Safe to call more than once: the destructor calls it after the caller has
     * already closed the package.
     */
    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->zip->close();
        }

        if ($this->scratchPath !== null) {
            @unlink($this->scratchPath);
            $this->scratchPath = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function document(): \DOMDocument
    {
        return $this->part(self::DOCUMENT, required: true);
    }

    public function styles(): ?\DOMDocument
    {
        return $this->part(self::STYLES);
    }

    public function numbering(): ?\DOMDocument
    {
        return $this->part(self::NUMBERING);
    }

    /**
     * The relationship targets of the document part, keyed by relationship id.
     *
     * @return array<string, string>
     */
    public function relationships(): array
    {
        $dom = $this->part(self::RELATIONSHIPS);
        $targets = [];

        if ($dom === null) {
            return $targets;
        }

        foreach ($dom->documentElement?->childNodes ?? [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $id = $node->getAttribute('Id');
            if ($id === '') {
                continue;
            }

            $targets[$id] = $node->getAttribute('Target');
        }

        return $targets;
    }

    /**
     * The bytes of a part inside the archive, for example an image.
     */
    public function contentsOf(string $part): ?string
    {
        $contents = $this->read($part);

        return $contents === false ? null : $contents;
    }

    private static function openArchive(ZipArchive $zip, string $path, Options $options): self
    {
        // `CHECKCONS` refuses an archive whose central directory disagrees with
        // its own contents, which is a document assembled to be read as something
        // it is not.
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new UnreadableDocument(sprintf('Unable to open "%s" as a zip archive.', $path));
        }

        $package = new self($zip, $options);

        if ($zip->numFiles > $options->maxEntries) {
            // The count is read before the archive is closed: closing it resets the
            // number of files to zero, and a message that says zero sends the
            // reader looking for the wrong thing.
            $entries = $zip->numFiles;

            $package->close();

            throw new UnreadableDocument(sprintf(
                'The document has %d parts in it, which is more entries than the %d allowed.',
                $entries,
                $options->maxEntries,
            ));
        }

        return $package;
    }

    /**
     * The bytes of a part, once it is known to be small enough to hold.
     *
     * The size is the one the central directory declares, which costs one lookup
     * and no decompression. A part that lies about it downward does not get past
     * the check the archive was opened with: `CHECKCONS` reads the directory
     * against the entries and refuses the archive before a part of it is read, so
     * what is measured here is what comes back.
     *
     * @throws UnreadableDocument When the part declares more than `maxPartBytes`.
     */
    private function read(string $name): string|false
    {
        $stat = $this->zip->statName($name);
        $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;

        if ($size > $this->options->maxPartBytes) {
            throw new UnreadableDocument(sprintf(
                'The part "%s" claims to be %d bytes uncompressed, which is larger than the %d allowed.',
                $name,
                $size,
                $this->options->maxPartBytes,
            ));
        }

        return $this->zip->getFromName($name);
    }

    private function part(string $name, bool $required = false): ?\DOMDocument
    {
        if (array_key_exists($name, $this->parts)) {
            return $this->parts[$name];
        }

        $xml = $this->read($name);

        if ($xml === false) {
            if ($required) {
                throw new MalformedDocument(sprintf('The document is missing "%s".', $name));
            }

            return $this->parts[$name] = null;
        }

        $dom = Xml::parse($xml);

        if ($dom === null) {
            if ($required) {
                throw new MalformedDocument(sprintf('"%s" is not valid XML.', $name));
            }

            return $this->parts[$name] = null;
        }

        return $this->parts[$name] = $dom;
    }

    /**
     * Write the bytes to a scratch file so the archive can be opened.
     *
     * `ext-zip` only opens files, and a library has no business writing into the
     * tree it was installed in, so a document in memory goes to the system temp
     * directory — which is also the only place that is writable when the
     * install is a read-only phar.
     */
    private static function writeScratchFile(string $bytes): string
    {
        $directory = rtrim(sys_get_temp_dir(), '/') . '/mdword';

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new FileNotWritable(sprintf('Unable to create the scratch directory "%s".', $directory));
        }

        $path = $directory . '/mdword-' . bin2hex(random_bytes(8)) . '.docx';

        if (@file_put_contents($path, $bytes) === false) {
            throw new FileNotWritable(sprintf('Unable to write the document to "%s".', $directory));
        }

        return $path;
    }
}
