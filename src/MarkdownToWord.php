<?php

declare(strict_types=1);

namespace MarkdownWord;

use League\CommonMark\Node\Block\Document;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Document\ConfigurationMerger;
use MarkdownWord\Document\Frontmatter;
use MarkdownWord\Exception\NothingToConvert;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Parser\MarkdownParserInterface;
use MarkdownWord\Render\Directive\Footnote;
use MarkdownWord\Render\Directive\Toc;
use MarkdownWord\Render\Directives;
use MarkdownWord\Render\DocumentRenderer;
use MarkdownWord\Render\FurnitureWriter;
use MarkdownWord\Render\HtmlFragmentRenderer;
use MarkdownWord\Render\ImageConversionCollector;
use MarkdownWord\Render\SvgAttachmentCollector;
use MarkdownWord\Render\ImageDescriptionCollector;
use MarkdownWord\Render\ImageResolver;
use MarkdownWord\Render\InlineRenderer;
use MarkdownWord\Render\LinkPayloadCollector;
use MarkdownWord\Render\NumberingRegistry;
use MarkdownWord\Render\StyleRegistrar;
use MarkdownWord\Render\StyleResolver;
use MarkdownWord\Theme\ThemeResolver;
use MarkdownWord\Writer\DocxWriter;
use MarkdownWord\Writer\OdtWriter;
use MarkdownWord\Writer\RtfWriter;
use MarkdownWord\Writer\Survey;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;

/**
 * Converts Markdown into a Word document, read by default with
 * {@see CommonMarkParser} — pass another one to change the dialect. See
 * {@see Converter} for what this and {@see WordToMarkdown} share.
 */
final class MarkdownToWord implements Converter
{
    private ?LinkPayloadCollector $links = null;

    private ?ImageDescriptionCollector $images = null;

    private ?ImageConversionCollector $conversions = null;

    private ?SvgAttachmentCollector $vectors = null;

    private ?Toc $toc = null;

    private ?Footnote $footnotes = null;

    /**
     * The format the last write went through, and what that document asked of the
     * writer, so {@see self::pendingLosses()} has something to answer with.
     *
     * `Docx` rather than null, and rather than nothing: the two public entry points —
     * {@see self::toPhpWord()} and {@see self::renderIntoContainer()} — render without
     * writing and so never set it, and a directive handler reads it to decide whether it
     * can carry what it is drawing. A handler reads it as `$format ?? Format::Docx`, so
     * null would mean docx too and the default would be decoration — which is why it is
     * here, stated rather than relied on.
     */
    private ?Format $format = Format::Docx;

    private ?Survey $survey = null;

    /**
     * The Markdown of the document last rendered, kept for one reason: a wrong key
     * in a `---` block has to be reported with a line number, and the block is only
     * text in the source. The parse tree keeps positions for what is left after the
     * block, so it cannot answer for the block itself.
     */
    private string $lastMarkdown = '';

    /** The theme named by {@see self::withTheme()} or the command line, if either named one. */
    private ?string $theme = null;

    /**
     * @param string|null $source A path, or the Markdown itself; {@see Input}
     *        says which a string is. Null leaves the subject to
     *        {@see self::toDocx()}.
     * @param Configuration|array|null $overrides The layer above the frontmatter. The
     *        array form is the one to reach for: it is sparse, so a key nobody
     *        mentioned leaves the document's own setting standing. A `Configuration`
     *        names every setting there is, so it puts the built-in look above the
     *        frontmatter along with everything else — and, for the same reason, above a
     *        theme as well. See {@see \MarkdownWord\Configuration::withTheme()}.
     * @param bool $detached Whether the rendered elements are going to be copied into
     *        *another* document rather than written from here — which is what the
     *        template renderer does, and what `deferredHyperlinks` exists for.
     *
     *        A part this library writes beside a body cannot travel: the elements are
     *        carried over and the parts they were registered in are not. So a footnote, a
     *        header part and the field a `::toc` placeholder stands for are each drawn as
     *        what they are instead, because emitting them here leaves a document carrying
     *        a footnote reference to a note the package does not contain, and a run of
     *        invisible separators where a table of contents should be.
     * @param string|null $directory The directory the document was read from, which is
     *        where a theme beside it is found: a document names a theme, and the theme
     *        that travels with it is the one next to it rather than the one next to
     *        wherever the program happens to be. Null for a document handed in as a
     *        string, which has no directory of its own; the working directory is what a
     *        name resolves against then.
     */
    public function __construct(
        private readonly ?string $source = null,
        private readonly Configuration $config = new Configuration(),
        private readonly ?MarkdownParserInterface $parser = null,
        private readonly Configuration|array|null $overrides = null,
        private readonly bool $detached = false,
        private readonly ?string $directory = null,
    ) {
    }

    /**
     * A theme to draw this converter's documents in, unless a document names one itself.
     *
     * The same argument a `theme:` in a `---` block takes and the same thing `--theme`
     * passes, so all three resolve the same way. Null — the default — reads the
     * frontmatter and nothing else.
     *
     * Mutating rather than returning a new converter: the receiver holds the Markdown and
     * the parser, and there is no value in a copy that differs in a theme.
     *
     * @throws Exception\ThemeNotFound when the theme is not one that resolves. Checked
     *         here so a typo is reported before the document is read rather than partway
     *         through writing it.
     * @throws Exception\MalformedTheme when the theme's own files do not describe a theme
     * @throws Exception\UnknownStyleSlot when `styles.yml` names a slot that does not exist
     * @throws Exception\Exception when one of the theme's files cannot be read or parsed
     */
    public function withTheme(?string $name): self
    {
        if ($name !== null) {
            $this->themes()->resolve($name);
        }

        $this->theme = $name;

        return $this;
    }

    /**
     * The configuration passed in, without the frontmatter of whichever document was
     * last rendered.
     *
     * That is what the constructor holds and not what was used, because the frontmatter
     * belongs to a document rather than to the converter: a converter handed two
     * documents with different frontmatter renders each with its own.
     */
    public function getConfiguration(): Configuration
    {
        return $this->config;
    }

    /**
     * The configuration a given document is rendered with, frontmatter included.
     *
     * Public because the template path asks before rendering anything into it, and
     * because "which configuration would this document use" is otherwise only
     * answerable by rendering it and looking.
     *
     * @param string|null $markdown The Markdown the document was parsed from, when the
     *        caller still has it. Only the `---` block's line numbers come from it, and
     *        {@see self::toPhpWord()} supplies it on every path that renders.
     *
     * @throws Exception\InvalidConfiguration when the block names nothing, or gives a
     *         value that is not what it is used as.
     * @throws Exception\ThemeNotFound when the document's or the caller's `theme:` does
     *         not resolve. Deliberately not swallowed: see {@see self::themeFor()}.
     * @throws Exception\MalformedTheme when the theme's own files do not describe a theme
     * @throws Exception\UnknownStyleSlot when `styles.yml` names a slot that does not exist
     * @throws Exception\Exception when one of the theme's files cannot be read or parsed
     */
    public function configurationFor(Document $document, ?string $markdown = null): Configuration
    {
        $frontmatter = Frontmatter::fromDocument($document, $markdown ?? $this->lastMarkdown);

        // Checked before it is merged, so a key that names nothing is reported against
        // the file the reader wrote rather than swallowed by the merge — and so the
        // whole set of them arrives at once instead of one per run.
        $frontmatter->assertValid();

        $theme = $this->themeFor($frontmatter);

        // The constructor's configuration is the base rather than a layer over a default
        // one, because it is the only layer that knows which slots were *chosen*: passed
        // through `toArray()` it would name every slot there is and would therefore bury a
        // theme underneath all of it. See {@see Configuration::withTheme()}.
        return ConfigurationMerger::resolve(
            commandLine: is_array($this->overrides) ? $this->overrides : $this->overrides?->toArray(),
            frontmatter: $frontmatter,
            defaults: $theme === null ? $this->config : $this->config->withTheme($theme),
        );
    }

    /**
     * The theme this document is drawn in, or null when it names none.
     *
     * A theme named in code answers for the document, so the frontmatter is not read —
     * which is also why a document naming a theme that does not resolve is not reported
     * here: the name was overridden, and a caller that passed a theme of its own asked for
     * that one. This is the only source that is *silently* ignored, and it is ignored only
     * because something louder replaced it.
     *
     * @throws Exception\ThemeNotFound when the theme in play is not one that resolves
     * @throws Exception\MalformedTheme when the theme's own files do not describe a theme
     * @throws Exception\UnknownStyleSlot when `styles.yml` names a slot that does not exist
     * @throws Exception\Exception when one of the theme's files cannot be read or parsed
     */
    private function themeFor(Frontmatter $frontmatter): ?Styles
    {
        $name = $this->theme ?? $frontmatter->themeName();

        return $name === null ? null : $this->themes()->resolve($name)->styles();
    }

    private function themes(): ThemeResolver
    {
        return new ThemeResolver(
            workingDirectory: getcwd() ?: '.',
            documentDirectory: $this->directory,
        );
    }

    public function parse(string $markdown): Document
    {
        return ($this->parser ?? new CommonMarkParser())->parse($markdown);
    }

    /**
     * The document is made self-contained: the style definitions the renderer
     * references are written into it, so it looks the same everywhere.
     */
    public function toPhpWord(string $markdown, ?PhpWord $phpWord = null): PhpWord
    {
        $phpWord ??= new PhpWord();
        $this->lastMarkdown = $markdown;
        $document = $this->parse($markdown);

        $section = $phpWord->getSections() === []
            ? $phpWord->addSection()
            : $phpWord->getSections()[count($phpWord->getSections()) - 1];

        $this->renderInto($document, $section, $phpWord, defineStyles: true);

        return $phpWord;
    }

    /**
     * Render Markdown into an existing container — a section, a table cell, a
     * header or a footer. No style definitions are written: the destination
     * document, a template most likely, is the authority on what its styles look
     * like, and defining them here would override the author's design.
     *
     * A document already parsed may be given instead of the Markdown it came from,
     * which is what {@see \MarkdownWord\Template\MarkdownTemplate} does: it reads the
     * frontmatter off the parse to find the values for the template's fields, and
     * parsing the same text twice would be two chances at two different answers.
     */
    public function renderIntoContainer(
        string|Document $markdown,
        AbstractContainer $container,
        ?PhpWord $phpWord = null,
    ): AbstractContainer {
        $phpWord ??= new PhpWord();

        if ($markdown instanceof Document) {
            $document = $markdown;
        } else {
            $this->lastMarkdown = $markdown;
            $document = $this->parse($markdown);
        }

        $this->renderInto($document, $container, $phpWord, defineStyles: false);

        return $container;
    }

    /**
 * @throws NothingToConvert             when the converter was built without a source.
 * @throws Exception\UnreadableFile     when the source names a file that cannot be read.
 * @throws Exception\UnsupportedImageFormat when a document names an image that is
 *        on disk and in a format neither Word nor the local GD build can take.
 * @throws Exception\UnreadableDocument when the finished archive cannot be reopened.
 * @throws Exception\MalformedDocument  when a part of it is not XML.
 * @throws Exception\FileNotWritable    when the document cannot be written.
 */
    public function convert(?string $target = null): string
    {
        if ($this->source === null) {
            throw new NothingToConvert(
                'There is no Markdown to convert. Give some to the constructor, '
                . 'or to ' . self::class . '::toDocx().',
            );
        }

        $this->format = Format::Docx;

        return $this->write(Format::Docx, $this->toPhpWord(Input::markdown($this->source)), $target);
    }

    /**
     * {@see self::convert()} for a format other than `.docx`.
     *
     * A separate method rather than a parameter on {@see self::convert()}, because
     * that one is {@see Converter}'s and both directions have to keep the same
     * signature: the other direction has no format to choose.
     *
     * @param string|null $target Where the document goes, or null to hand back the
     *        bytes instead.
     *
     * @throws NothingToConvert             when the converter was built without a source.
     * @throws Exception\UnreadableFile     when the source names a file that cannot be read.
     * @throws Exception\UnsupportedImageFormat when a document names an image that is
     *        on disk and in a format neither Word nor the local GD build can take.
     * @throws Exception\UnreadableDocument when the finished archive cannot be reopened.
     * @throws Exception\MalformedDocument  when a part of it is not XML.
     * @throws Exception\FileNotWritable    when the document cannot be written.
     */
    public function convertTo(Format $format, ?string $target = null): string
    {
        if ($this->source === null) {
            throw new NothingToConvert(
                'There is no Markdown to convert. Give some to the constructor, '
                . 'or to ' . self::class . '::toDocx().',
            );
        }

        // Before the render, for the reason {@see self::to()} gives.
        $this->format = $format;

        return $this->write($format, $this->toPhpWord(Input::markdown($this->source)), $target);
    }

    public function save(string $target): void
    {
        $this->convert($target);
    }

    /**
     * Markdown as the raw bytes of a `.docx`, the counterpart of
     * {@see \MarkdownWord\WordToMarkdown::toMarkdown()}.
     */
    public function toDocx(string $markdown, ?PhpWord $phpWord = null): string
    {
        return $this->to(Format::Docx, $markdown, $phpWord);
    }

    /**
     * Markdown as the raw bytes of an `.odt`.
     *
     * @see self::to() for what the other two formats drop
     */
    public function toOdt(string $markdown, ?PhpWord $phpWord = null): string
    {
        return $this->to(Format::Odt, $markdown, $phpWord);
    }

    /**
     * Markdown as the raw bytes of an `.rtf`.
     *
     * @see self::to() for what RTF drops
     */
    public function toRtf(string $markdown, ?PhpWord $phpWord = null): string
    {
        return $this->to(Format::Rtf, $markdown, $phpWord);
    }

    /**
     * Markdown as the bytes of the named format.
     *
     * The document is the same one every format is given, so a format that cannot
     * carry something drops it rather than doing something else — and
     * {@see self::pendingLosses()} says what. `.docx` is the default everywhere
     * else in the library; naming a format is the only way to get another one.
     *
     * @throws FileNotWritable when the document cannot be written.
     * @throws UnreadableDocument when a pass cannot reopen the staged document.
     * @throws MalformedDocument when a part of it is not XML.
     */
    public function to(Format $format, string $markdown, ?PhpWord $phpWord = null): string
    {
        // Recorded before the document is rendered rather than in {@see self::write()},
        // because what a picture is allowed to be depends on the format: a `.docx` can
        // carry the vector and so may take a placeholder beside it, and a format that
        // drops the vector has only the picture left to draw.
        $this->format = $format;

        return $this->write($format, $this->toPhpWord($markdown, $phpWord), null);
    }

    /**
     * The one place a document is written, so the format is recorded and the
     * document surveyed for every path out — {@see self::convert()} and
     * {@see self::to()} alike — and {@see self::pendingLosses()} cannot disagree
     * with the bytes that were actually produced.
     *
     * @throws FileNotWritable when the document cannot be written.
     * @throws UnreadableDocument when a pass cannot reopen the staged document.
     * @throws MalformedDocument when a part of it is not XML.
     */
    private function write(Format $format, PhpWord $phpWord, ?string $target): string
    {
        $this->format = $format;
        $this->survey = Survey::of($phpWord, $this->images, $this->vectors, $this->toc, $this->footnotes);

        return match ($format) {
            Format::Docx => $target === null
                ? DocxWriter::toString($phpWord, $this->links, $this->images, $this->vectors, $this->toc)
                : DocxWriter::write($phpWord, $target, $this->links, $this->images, $this->vectors, $this->toc),
            Format::Odt => $target === null
                ? OdtWriter::toString($phpWord, $this->links, $this->images)
                : OdtWriter::write($phpWord, $target, $this->links, $this->images),
            Format::Rtf => $target === null
                ? RtfWriter::toString($phpWord, $this->links)
                : RtfWriter::write($phpWord, $target, $this->links),
        };
    }

    /**
     * Every hyperlink payload collected so far — they accumulate across renders —
     * keyed by the placeholder standing in for each in the element tree.
     *
     * @return list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}>
     */
    public function pendingHyperlinks(): array
    {
        return $this->links?->payloads() ?? [];
    }

    /**
     * What the format last written drops that {@see Format::Docx} would have
     * carried.
     *
     * Empty until something has been written, and empty for a document with nothing
     * in it that the writer cannot express: a conversion into `.rtf` of a document
     * with no lists, no tables and no images loses nothing, and saying so would be
     * noise.
     *
     * @return list<Loss>
     */
    public function pendingLosses(): array
    {
        if ($this->format === null || $this->survey === null) {
            return [];
        }

        $losses = $this->format->losses($this->survey);

        // A vector written with no raster beside it is carried perfectly well and still
        // costs something: every reader that does not prefer the vector draws nothing
        // where the picture was. Said here rather than left for the reader of the
        // finished document to work out.
        if ($this->survey->isVectorOnly()) {
            $losses[] = new Loss('svg-fallback',
                'the vector was written with no raster beside it, so a reader that does not prefer the vector draws nothing where an SVG was');
        }

        return $losses;
    }

    /**
     * The images that were decoded into PNG because Word cannot embed them as they
     * were, accumulated across renders.
     *
     * A `.webp` is the case there is: it is what a browser has been asked for for
     * years and PHPWord has never supported it. The picture is embedded rather than
     * replaced by its alt text, but it is re-encoded on the way in and a PNG of a
     * photograph is several times the size of the WebP it came from, so a caller
     * should be able to see that it happened. The command line prints one line per
     * entry.
     *
     * @return list<array{source: string, format: string, embeddedAs: string}>
     */
    public function pendingImageConversions(): array
    {
        return $this->conversions?->all() ?? [];
    }

    private function renderInto(
        Document $document,
        AbstractContainer $container,
        PhpWord $phpWord,
        bool $defineStyles,
    ): void {
        $config = $this->configurationFor($document);
        $styles = new StyleResolver($config);

        if ($defineStyles) {
            (new StyleRegistrar($config->getStyles()))->register($phpWord);
        }

        // Kept across renders so the placeholder indices stay unique, which matters
        // when several documents go through one converter, as the template does.
        $this->links ??= new LinkPayloadCollector($styles);

        $html = new HtmlFragmentRenderer($config->getOptions(), $styles);
        $this->images ??= new ImageDescriptionCollector();
        $this->conversions ??= new ImageConversionCollector();
        $this->vectors ??= new SvgAttachmentCollector();

        // One registry for both renderers: an inline directive is handled by the
        // inline renderer and a block one by the document renderer, and a name
        // means the same thing whichever of the two meets it.
        $directives = new Directives();

        // Rebuilt per render rather than cached on the converter, because each reads
        // `$this->format` when it is drawn and a cached one keeps the format of the
        // *first* render. One converter writing a `.docx` and then an `.odt` would hand
        // the second a handler for the wrong format.
        $this->toc = new Toc($config->getOptions(), $styles, $this->format, carries: !$this->detached);
        $this->footnotes = new Footnote($styles, $this->format, carries: !$this->detached);

        $directives = $directives
            ->withBlock('toc', $this->toc)
            ->withInline('footnote', $this->footnotes);

        $inlines = new InlineRenderer(
            $styles,
            new ImageResolver($config->getOptions(), $this->images, $this->conversions, $this->vectors, $this->format),
            $this->links,
            $html,
            $config->getOptions()->deferredHyperlinks,
            $directives,
        );

        $renderer = new DocumentRenderer(
            $config,
            $styles,
            $inlines,
            // The merged configuration, not `$this->config`: `bulletList` and
            // `orderedList` are style slots like any other, and a `styles:` block, a
            // config file or a theme all name them.
            new NumberingRegistry($config, $phpWord),
            $html,
            $directives,
        );

        $renderer->render($document, $container);

        // The running head and foot the document's frontmatter describes. Only for a
        // whole section: a render into a container of its own — a template's content
        // control, a table cell — is somewhere a page has no top or bottom to put
        // them on, and PHPWord would write a part belonging to no section.
        // Only when the package is ours to write. A header part added to a scratch
        // document the template renderer then empties is a header in no output at all.
        if ($container instanceof Section && !$this->detached) {
            (new FurnitureWriter())->draw(
                $renderer,
                Frontmatter::fromDocument($document, $this->lastMarkdown),
                $container,
            );
        }
    }
}
