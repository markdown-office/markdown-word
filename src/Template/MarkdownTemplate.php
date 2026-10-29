<?php

declare(strict_types=1);

namespace MarkdownWord\Template;

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Node\Block\Document;
use MarkdownWord\Configuration;
use MarkdownWord\Document\Frontmatter;
use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Exception\NoContentControl;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Exception\TemplateNotFound;
use MarkdownWord\Files\FileFinder;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Parser\MarkdownParserInterface;
use MarkdownWord\Writer\DocxWriter;
use MarkdownWord\Writer\OutputEscaping;
use MarkdownWord\Writer\NumberingMerger;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Renders Markdown into an existing Word document used as a template: the
 * template supplies the styles, the letterhead and the values, and says where the
 * content goes.
 *
 * Everything the template says is something Word already understands, because a
 * template comes from Word and anything else would have to be invented here:
 *
 * - **A value** is a `MERGEFIELD`, the field a person inserts with Ctrl+F9. Word
 *   stores it as a field element, not as text, and {@see WordFields} fills it and then
 *   takes it out again so a finished document holds no fields.
 * - **A place for the content** is a **rich-text content control** named `body`, which
 *   is the placeholder Word's Developer tab inserts and the only thing in Word that can
 *   hold whole paragraphs where the author chose. {@see ContentControl} fills it and
 *   removes it.
 *
 * The control is optional, because a template need not be one this library's generator
 * wrote. A letterhead a company made in Word to be filled in by a person has fields and
 * no control, and refusing those would leave the library able to use only the templates
 * it wrote itself. A template with no control of that name takes the content at the end
 * of its body, or says so and refuses, per {@see ContentFallback}. Filling the fields is
 * independent of it: a template can have values and nowhere to put the prose.
 *
 * A template that is not there is a {@see TemplateNotFound}. Every other way this can
 * fail is a {@see FileNotWritable} — the template was fine and the machine was not —
 * except {@see NoContentControl}, which is the template's to fix and is off unless asked
 * for.
 *
 * The staged document is moved into place rather than written there, so the
 * template on disk is never opened for writing: a path that is a hard link to it
 * would otherwise empty it.
 *
 * The move is the shape of {@see \MarkdownWord\Writer\Staging::move()}: a
 * symlink is followed rather than replaced, and a link whose target is not there
 * yet is followed all the same. A move onto another filesystem is a copy that
 * succeeds rather than a failure — PHP's `rename()` copies the file across,
 * gives the copy the source's owner and mode, and unlinks the source — so what
 * lands is the staged file, permissions and all.
 *
 * **The document's files come from the caller's source.** The template renders a
 * document of its own, so the converter behind it is handed the finder the caller
 * brought: the theme the inserted document names is resolved through it, and the
 * pictures it shows are read through it.
 */
final class MarkdownTemplate
{
    private readonly TemplateProcessor $processor;

    private readonly TemplatePackage $package;

    private readonly MarkdownToWord $converter;

    private readonly PhpWord $scratch;

    private readonly NumberingMerger $numbering;

    /** @var array<string, string> */
    private array $fields = [];

    /** @var list<AbstractElement>|null Rendered but not yet written; null when there is none. */
    private ?array $content = null;

    /** Which content control the content goes into, named by the template. */
    private string $control = ContentControl::BODY;

    /** Where the content goes when the template has no control of that name. */
    private ContentFallback $fallback = ContentFallback::Append;

    /**
     * @param array<string, string|int|float> $values Values for the template's fields, for
     *        a caller that has them somewhere other than the document. A document's own
     *        `fields:` block is the ordinary source, and {@see self::insert()} reads it.
     * @param MarkdownParserInterface|null $parser The dialect the Markdown is read with.
     *        Null keeps a parser carrying `FrontMatterExtension`, because the values come
     *        from the document's block and a parser without it would print that block at
     *        the top of the document instead of reading it.
     * @param Configuration|array|null $overrides The layer above the frontmatter of the
     *        Markdown being inserted. An array is the form to prefer here: a
     *        `Configuration` names every setting there is and would put all of them,
     *        defaults included, on top.
     * @param string|null $directory The directory the document being inserted was read
     *        from, which is where a theme beside it is found. Null for a document handed
     *        in as a string, which has no directory of its own.
     * @param FileFinder|null $files Where the inserted document's files come from: the
     *        theme it names and the pictures it shows. Null for the disk default, which
     *        looks for a theme beside the document and beside the working directory.
     * @throws TemplateNotFound when the template is not a file.
     */
    public function __construct(
        string $template,
        private readonly Configuration $config = new Configuration(),
        array $values = [],
        private readonly ?MarkdownParserInterface $parser = null,
        Configuration|array|null $overrides = null,
        ?string $theme = null,
        ?string $directory = null,
        private readonly ?FileFinder $files = null,
    ) {
        if (!is_file($template)) {
            throw new TemplateNotFound(sprintf('The template "%s" does not exist.', $template));
        }

        // Settled before the processor is handed the file: a `.dotx` is normalised to
        // a document's package here, so the processor reads the body of whichever part
        // the declarations name and writes the finished document back out as one.
        $this->package = TemplatePackage::of($template);
        $this->processor = new TemplateProcessor($this->package->path());

        // The elements rendered below are copied into a different document, so
        // hyperlinks are written as placeholders and resolved once the output
        // document exists.
        $this->converter = new MarkdownToWord(
            null,
            $config->withOptions(['deferredHyperlinks' => true]),
            $parser,
            $overrides,
            detached: true,
            directory: $directory,
            files: $files,
        );

        // Applied here rather than by the caller, because this converter is built with a
        // null source and renders each region separately: a theme named in code reaches
        // the document through the converter, and this is the only converter the template
        // has.
        if ($theme !== null) {
            $this->converter->withTheme($theme);
        }

        // Every insert is rendered into one shared scratch document, so the list
        // definitions it accumulates are unique across the whole template.
        $this->scratch = new PhpWord();
        $this->numbering = new NumberingMerger($this->scratch);

        // Folded rather than filled here: the fields live in the finished document, and
        // filling them before the content has been inserted would be filling a
        // document this class is still going to write over.
        $this->fields($values);
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
     * The values for the fields in the template, by name.
     *
     * The names are the ones a `MERGEFIELD` carries and are matched
     * case-insensitively, because Word folds them to upper case when it stores
     * them. A name no field asks for is kept and then ignored: a document may
     * carry values for a template it is not always rendered into, and a value
     * that goes unused is not an error.
     */
    public function fields(array $fields): self
    {
        foreach ($fields as $name => $value) {
            $this->fields[strtoupper((string) $name)] = (string) $value;
        }

        return $this;
    }

    /**
     * Where the content goes when the template names no place for it.
     *
     * {@see ContentFallback::Append} by default, because a template written for this
     * library carries the control and a template a company made in Word to be filled in
     * by a person does not, and a library that only worked with the first kind would be
     * no use against the second.
     *
     * @throws NoContentControl when {@see ContentFallback::Error} is chosen and the
     *         template has no control of the name {@see self::insert()} was given.
     */
    public function contentFallback(ContentFallback $fallback = ContentFallback::Append): self
    {
        $this->fallback = $fallback;

        return $this;
    }

/**
     * Render the Markdown, ready for {@see self::save()} to write where the template says.
     *
     * Separate from the write because the content goes into the *finished* document: the
     * template is written out first and this is written over it, so anything placed
     * earlier would be lost.
     *
     * A template has one place for the content, so a second call replaces the first
     * rather than adding to it. Appending is what a caller means when it hands over one
     * Markdown document, and two blocks with nowhere to put them would leave the
     * template's own content control holding one of them.
     *
     * @throws FileNotWritable when the Markdown cannot be rendered
     */
    public function insert(string $markdown, string $control = ContentControl::BODY): self
    {
        // The document's own values, read here rather than handed in: a document whose
        // client is in a PHP array is a document whose client is in two places, and
        // changing one does not change the other.
        $document = $this->dialect()->parse($markdown);
        $this->fields(Frontmatter::fromDocument($document, $markdown)->fields());

        $this->content = $this->render($document);
        $this->control = $control;

        return $this;
    }

    /**
     * The dialect the document is read with.
     *
     * {@see CommonMarkParser::DEFAULT_FLAVOUR} — which is GFM plus the directive
     * extension — and not the `gfm` it was, because the template renderer is a second
     * entry point into the same library and a document naming `::toc` should not get
     * different syntax for being filled into a `.dotx` than for being converted. It
     * differs on the frontmatter instead: there the block is where the values for the
     * template's fields are, and without the extension a leading `---` is a thematic
     * break and the block is printed at the top of the document.
     */
    private function dialect(): MarkdownParserInterface
    {
        return $this->parser ?? new CommonMarkParser([
            ...CommonMarkParser::FLAVOURS[CommonMarkParser::DEFAULT_FLAVOUR],
            FrontMatterExtension::class,
        ]);
    }

    /**
     * Write the filled-in document to a file.
     *
     * @throws FileNotWritable when the document cannot be staged, its directory
     *         cannot be made, or the result cannot be written.
     * @throws NoContentControl under {@see ContentFallback::Error}, when the template
     *         has nowhere to put the content.
     */
    public function save(string $path): void
    {
        $temp = self::stage();

        try {
            $this->finish($temp);
            self::move($temp, $path);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * The raw bytes of the resulting document.
     *
     * @throws FileNotWritable when the document cannot be staged or read back.
     * @throws NoContentControl under {@see ContentFallback::Error}, when the template
     *         has nowhere to put the content.
     */
    public function toString(): string
    {
        $temp = self::stage();

        try {
            $this->finish($temp);

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
     * Every pass that turns the staged copy of the template into the finished document.
     *
     * In this order, and the order is the whole of it:
     *
     * 1. PHPWord writes the template out unchanged.
     * 2. The content goes in where the content control is. It has to be before the
     *    hyperlink and numbering passes, because both edit the parts this one puts
     *    elements into — a link written after the content is in would not find its
     *    placeholder, and a list would come over pointing at a numbering part that the
     *    third step has not written yet.
     * 3. Hyperlinks whose label contains emphasis become real `w:hyperlink` elements.
     * 4. The list definitions are carried over and the references pointed at them.
     * 5. The fields are filled and unlinked, last, so a value can arrive in a
     *    paragraph the earlier steps created.
     *
     * @throws FileNotWritable when a pass cannot write the staged document.
     * @throws NoContentControl under {@see ContentFallback::Error}, when the template
     *         has nowhere to put the content.
     */
    private function finish(string $temp): void
    {
        OutputEscaping::enabled(function () use ($temp): void {
            $this->processor->saveAs($temp);
        });

        if ($this->content !== null) {
            $blocks = array_map(
                fn (AbstractElement $element): string => $this->numbering->renderElement($element),
                $this->content,
            );

            $control = new ContentControl();

            $this->place($temp, $control, $blocks);
        }

        $payloads = $this->converter->pendingHyperlinks();

        if ($payloads !== []) {
            DocxWriter::patchHyperlinks($temp, $payloads);
        }

        if ($this->numbering->hasNumbering()) {
            $this->numbering->applyTo($temp);
        }

        (new WordFields($this->fields))->applyTo($temp);
    }

    /**
     * Put the content where the template says, or where it says nothing means.
     *
     * @param list<string> $blocks The rendered blocks. They are rendered once here and
     *        passed down rather than rendered again per branch, because rendering is
     *        what assigns the numbering references and doing it twice would give each
     *        block the second pass's references.
     *
     * @throws NoContentControl under {@see ContentFallback::Error}, when the template
     *         has no control of that name to fill.
     */
    private function place(string $temp, ContentControl $control, array $blocks): void
    {
        if ($control->fill($temp, $this->control, $blocks)) {
            return;
        }

        if ($this->fallback === ContentFallback::Error) {
            throw new NoContentControl($this->control, $control->tagsIn($temp));
        }

        $control->appendTo($temp, $blocks);
    }

    /**
     * @return list<AbstractElement>
     */
    private function render(Document $markdown): array
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
     * Deliberately the shape of {@see \MarkdownWord\Writer\Staging::move()}
     * rather than a second one.
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
