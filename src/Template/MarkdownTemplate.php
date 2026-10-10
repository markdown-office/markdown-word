<?php

declare(strict_types=1);

namespace MarkdownWord\Template;

use MarkdownWord\Configuration;
use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Exception\TemplateNotFound;
use MarkdownWord\Parser\MarkdownParserInterface;
use MarkdownWord\Writer\DocxWriter;
use MarkdownWord\Writer\OutputEscaping;
use MarkdownWord\Writer\NumberingMerger;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Renders Markdown into an existing Word document used as a template: the
 * template supplies the styles and decides where the content goes.
 *
 * Content is inserted into a *region* — a `${name}` marker, a `${slot}` marker on
 * a paragraph of its own, and a matching `${/name}` marker:
 *
 * ```
 * Report for ${customer}
 * ${body}
 * ${slot}
 * ${/body}
 * ```
 *
 * {@see self::insert()} clones the region once per rendered block and replaces the
 * slot paragraph of each clone; {@see self::repeat()} clones it once per row and
 * fills `${name#1}`…`${name#N}` instead. A plain `${name}` macro is for a single-line
 * value ({@see self::set()}).
 *
 * A template that is not there is a {@see TemplateNotFound}; every other way this
 * can fail is a {@see FileNotWritable} — the template was fine and the machine was
 * not.
 *
 * The staged document is moved into place rather than written there, so the
 * template on disk is never opened for writing: a path that is a hard link to it
 * would otherwise empty it.
 */
final class MarkdownTemplate
{
    /** The variable the renderer fills inside a region. */
    public const SLOT = 'slot';

    private readonly TemplateProcessor $processor;

    private readonly MarkdownToWord $converter;

    private readonly PhpWord $scratch;

    private readonly NumberingMerger $numbering;

    /**
     * @param array<string, string|int|float> $values Values substituted into single-line `${name}` placeholders.
     * @param MarkdownParserInterface|null $parser The dialect the Markdown inside the template is read with. Null keeps
     *        the default, which has no frontmatter extension — so a `---` at the top of a
     *        Markdown region is a thematic break rather than configuration. Pass one with
     *        `FrontMatterExtension` to read it as frontmatter.
     * @param Configuration|array|null $overrides The layer above the frontmatter of the
     *        Markdown being inserted. An array is the form to prefer here: a
     *        `Configuration` names every setting there is and would put all of them,
     *        defaults included, on top.
     * @throws TemplateNotFound when the template is not a file.
     */
    public function __construct(
        string $template,
        private readonly Configuration $config = new Configuration(),
        array $values = [],
        ?MarkdownParserInterface $parser = null,
        Configuration|array|null $overrides = null,
    ) {
        if (!is_file($template)) {
            throw new TemplateNotFound(sprintf('The template "%s" does not exist.', $template));
        }

        $this->processor = new TemplateProcessor($template);

        // The elements rendered below are copied into a different document, so
        // hyperlinks are written as placeholders and resolved once the output
        // document exists.
        $this->converter = new MarkdownToWord(
            null,
            $config->withOptions(['deferredHyperlinks' => true]),
            $parser,
            $overrides,
        );

        // Every insert is rendered into one shared scratch document, so the list
        // definitions it accumulates are unique across the whole template.
        $this->scratch = new PhpWord();
        $this->numbering = new NumberingMerger($this->scratch);

        foreach ($values as $name => $value) {
            $this->set((string) $name, (string) $value);
        }
    }

    /**
     * The underlying PHPWord processor, for anything this class does not wrap —
     * replacing images, applying an XSL style sheet, and so on.
     */
    public function processor(): TemplateProcessor
    {
        return $this->processor;
    }

    /**
     * Substitute a single-line value.
     *
     * @throws FileNotWritable when the document cannot be written, which the
     *         processor reports by refusing to touch the template.
     */
    public function set(string $name, string $value): self
    {
        // PHPWord leaves output escaping off by default, which would write a
        // value containing `<` or `&` into the document as raw markup and
        // produce a file Word cannot open.
        OutputEscaping::enabled(function () use ($name, $value): void {
            $this->processor->setValue($name, $value);
        });

        return $this;
    }

    /**
     * Insert rendered Markdown into a `${name}`…`${/name}` region. The region
     * must contain a `${slot}` paragraph, which is where the content lands; see
     * the class docblock for the shape of the template.
     *
     * @throws FileNotWritable when the region cannot be resolved into the
     *         document, or the document cannot be written.
     */
    public function insert(string $region, string $markdown): self
    {
        $elements = $this->render($markdown);

        // Cloning the region zero times removes it. `TemplateProcessor::deleteBlock()`
        // is not used: it assumes the markers share a paragraph and, when they do
        // not, leaves an unbalanced `w:p` behind and the document stops parsing.
        $this->processor->cloneBlock($region, count($elements), true, true);

        foreach ($elements as $index => $element) {
            // The XML is written here rather than through `setComplexBlock` so the
            // numbering references can be pointed at this template's own list
            // definitions; a list copied over unchanged would lose its bullets.
            $this->processor->replaceXmlBlock(
                self::SLOT . '#' . ($index + 1),
                $this->numbering->renderElement($element),
                'w:p',
            );
        }

        return $this;
    }

    /**
     * Repeat a `${name}`…`${/name}` region once per row. The region holds one
     * macro per column, so a row of `['item' => 'Consulting', 'price' => '42
     * EUR']` fills `${item}` and `${price}` in the first copy, the second copy,
     * and so on.
     *
     * @param list<array<string, string|int|float>> $rows
     * @throws FileNotWritable when the region cannot be resolved into the
     *         document, or the document cannot be written.
     */
    public function repeat(string $region, array $rows): self
    {
        // Zero clones drops the region; see the note in insert() about why
        // `deleteBlock()` is avoided.
        $this->processor->cloneBlock($region, count($rows), true, true);

        if ($rows === []) {
            return $this;
        }

        foreach ($rows as $index => $row) {
            foreach ($row as $name => $value) {
                $this->set((string) $name . '#' . ($index + 1), (string) $value);
            }
        }

        return $this;
    }

    /**
     * Write the filled-in document to a file.
     *
     * @throws FileNotWritable when the document cannot be staged, its directory
     *         cannot be made, or the result cannot be written.
     */
    public function save(string $path): void
    {
        $temp = self::stage();

        try {
            OutputEscaping::enabled(function () use ($temp): void {
                $this->processor->saveAs($temp);
            });

            $payloads = $this->converter->pendingHyperlinks();

            if ($payloads !== []) {
                // Links whose label contains emphasis are written as placeholders;
                // the writer turns them into real w:hyperlink elements in place.
                DocxWriter::patchHyperlinks($temp, $payloads);
            }

            if ($this->numbering->hasNumbering()) {
                $this->numbering->applyTo($temp);
            }

            self::move($temp, $path);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * The raw bytes of the resulting document.
     *
     * @throws FileNotWritable when the document cannot be staged or read back.
     */
    public function toString(): string
    {
        $temp = self::stage();

        try {
            OutputEscaping::enabled(function () use ($temp): void {
                $this->processor->saveAs($temp);
            });

            $contents = file_get_contents($temp);

            if ($contents === false) {
                throw new FileNotWritable(sprintf('Unable to read the document staged in "%s".', $temp));
            }

            return $contents;
        } finally {
            @unlink($temp);
        }
    }

    /**
     * @return list<AbstractElement>
     */
    private function render(string $markdown): array
    {
        $section = $this->scratch->addSection();

        $this->converter->renderIntoContainer($markdown, $section, $this->scratch);
        $this->numbering->collect();

        return array_values($section->getElements());
    }

    /**
     * A file to build the document in, which both output paths need: PHPWord
     * writes a document through a file rather than to a string. It is removed by
     * the caller on the way out.
     *
     * @throws FileNotWritable when no such file can be made.
     */
    private static function stage(): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'mdword_tpl_');

        if ($temp === false) {
            throw new FileNotWritable(
                'Unable to create a temporary file for the template output in "'
                . sys_get_temp_dir() . '".',
            );
        }

        return $temp;
    }

    /**
     * Deliberately the shape of {@see \MarkdownWord\Writer\DocxWriter::move()}
     * rather than a second one: a symlink is followed instead of replaced, a
     * `rename()` that fails because the two paths are on different filesystems
     * falls back to a copy, and a copy that fails part way does not leave a
     * half-written document behind.
     *
     * The link following is the part that is deliberately not the same:
     * {@see self::followLink()} needs its target to exist, so a link to a document
     * not written yet is replaced by a regular file rather than followed.
     */
    private static function move(string $from, string $to): void
    {
        $to = self::followLink($to);
        $directory = dirname($to);

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new FileNotWritable(sprintf('Unable to create the directory "%s".', $directory));
        }

        if (@rename($from, $to)) {
            return;
        }

        // Two paths on different filesystems cannot be renamed between, which is
        // the normal case when the output is on a mounted volume.
        $existed = file_exists($to);

        if (@copy($from, $to)) {
            @unlink($from);

            return;
        }

        if (!$existed) {
            @unlink($to);
        }

        throw new FileNotWritable(sprintf('Unable to write the document to "%s".', $to));
    }

    /**
     * Write to what a symlink points at, rather than replacing the link.
     *
     * @see \MarkdownWord\Writer\DocxWriter::move()
     */
    private static function followLink(string $path): string
    {
        $seen = 0;

        while (is_link($path) && $seen++ < 32) {
            $target = readlink($path);

            if ($target === false) {
                break;
            }

            $path = str_starts_with($target, '/')
                ? $target
                : dirname($path) . '/' . $target;
        }

        return $path;
    }
}
