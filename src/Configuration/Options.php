<?php

declare(strict_types=1);

namespace MarkdownWord\Configuration;

/**
 * Behavioural switches for the cases where Markdown and Word do not map onto
 * each other one-to-one; {@see Styles} decides how things look.
 *
 * Every `with*()` returns a new instance and is called on the result of the last
 * one in a chain, so each has to carry the rest of the configuration forward
 * rather than just the property it was given; {@see self::with()} arranges that.
 *
 * The two ways in from an array read a `null` differently, and deliberately so:
 * {@see self::fromArray()} has nothing to lose by ignoring one and reads it as
 * "not configured", while {@see self::withAll()} is handed a receiver that already
 * holds a value and reads it as "not mentioned", keeping it.
 */
final class Options
{
    /** Soft line breaks inside a paragraph collapse to a single space (spec behaviour). */
    public const SOFT_BREAK_SPACE = 'space';

    /** Soft line breaks become a real Word line break. */
    public const SOFT_BREAK_LINE = 'lineBreak';

    /** Soft line breaks split the paragraph into separate Word paragraphs. */
    public const SOFT_BREAK_PARAGRAPH = 'paragraph';

    public const BREAK_REMOVE = 'remove';
    public const BREAK_LINE = 'line';
    public const BREAK_PARAGRAPH = 'paragraph';

    /** Remove the tags from raw HTML but keep the text they wrap, as a browser would. */
    public const HTML_STRIP = 'strip';

    /** Keep raw HTML as literal, monospaced text. */
    public const HTML_PRESERVE = 'preserve';

    /** Discard raw HTML and the text inside it. */
    public const HTML_DROP = 'drop';

    public const IMAGE_EMBED = 'embed';
    public const IMAGE_PLACEHOLDER = 'placeholder';
    public const IMAGE_SKIP = 'skip';

    /** Write an SVG with a raster of itself beside it, for readers that cannot take the vector. */
    public const SVG_FALLBACK_RASTER = 'raster';

    /** Write the SVG alone, for readers that take the vector. */
    public const SVG_FALLBACK_NONE = 'none';

    /**
     * The properties where `null` is a value in its own right rather than the
     * absence of one; everywhere else it means "not configured" — or, in
     * {@see self::withAll()}, "not mentioned" — and the value already there stands.
     *
     * {@see \MarkdownWord\Reverse\Options} keeps the same list for the same reason,
     * and a property added to one of them without a line in the other is a property
     * whose `null` is handled wrongly.
     *
     * @var list<string>
     */
    private const NULLABLE = ['imageBasePath', 'orderedListSuffix'];

    /**
     * @param float           $imageMaxWidth     Maximum image width in centimetres; `0` disables scaling.
     * @param string          $svgFallback       `raster` writes a PNG beside every SVG for readers
     *        that cannot take the vector — Google Docs among them — at the cost of a rasteriser and
     *        the bytes it produces. `none` writes the vector alone, which Word 2016 and later and
     *        recent LibreOffice draw, and which every other reader draws as nothing at all.
     * @param int             $maxHeadingLevel   Headings deeper than this are rendered as paragraphs.
     * @param string          $orderedListFormat `w:numFmt` value for ordered lists (decimal, lowerLetter, ...).
     * @param string|null     $orderedListSuffix Separator between the number and the text: `tab`, `space` or `nothing`.
     * @param int             $tableWidth        Table width in fiftieths of a percent of the text column.
     *        `5000` — the default — is the full width, which is what a table read as
     *        a table rather than as a fragment of one should be. `0` leaves the width
     *        to Word's automatic sizing.
     * @param string          $thematicBreak     `border` (paragraph rule) or `text` (a row of dashes).
     * @param bool            $deferredHyperlinks Write every link as a placeholder and resolve it while the
     *        file is written, instead of letting PHPWord emit `w:hyperlink` directly. Needed when the
     *        rendered elements are copied into another document — as the template renderer does — because
     *        a hyperlink refers to a relationship that belongs to the document it was created in.
     */
    public function __construct(
        public readonly string $softBreak = self::SOFT_BREAK_SPACE,
        public readonly string $hardBreak = self::BREAK_LINE,
        public readonly string $html = self::HTML_STRIP,
        public readonly string $images = self::IMAGE_EMBED,
        public readonly ?string $imageBasePath = null,
        public readonly float $imageMaxWidth = 15.0,
        public readonly string $svgFallback = self::SVG_FALLBACK_RASTER,
        public readonly int $maxHeadingLevel = 6,
        public readonly string $orderedListFormat = 'decimal',
        public readonly ?string $orderedListSuffix = 'tab',
        public readonly bool $tableBorders = true,
        public readonly bool $tableHeaderBold = true,
        public readonly int $tableWidth = 5000,
        public readonly bool $codeBlockShading = true,
        public readonly string $linkTarget = '_blank',
        public readonly string $thematicBreak = 'border',
        public readonly bool $deferredHyperlinks = false,
    ) {
    }

    /**
     * Build a configuration from a plain array, for a `config.php` or a JSON
     * document.
     *
     * A `null` for a property that is not nullable by design means "not configured"
     * here, so the default below fills it back in. The alternative is worse than a
     * type error: the cast clamps `tableWidth`, and `(int) null` is 0, which is the
     * documented "let Word size it" value rather than the 5000 that was configured —
     * a document that comes out wrong with nothing to show for having asked. A `null`
     * for a property that *is* nullable by design is a value, and is kept.
     *
     * {@see self::withAll()} reads the same null the other way round, because it has
     * a receiver that would lose a value: there it means "not mentioned".
     *
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        // Unknown keys are dropped rather than passed on, so a config file may
        // carry extra entries without breaking the renderer.
        $known = (new self())->toArray();
        $given = array_intersect_key($options, $known);

        return new self(...self::cast(array_merge($known, self::withoutUnconfigured($given))));
    }

    public function withSoftBreak(string $mode): self
    {
        return $this->with('softBreak', $mode);
    }

    public function withHardBreak(string $mode): self
    {
        return $this->with('hardBreak', $mode);
    }

    public function withHtml(string $mode): self
    {
        return $this->with('html', $mode);
    }

    public function withImages(string $mode, ?string $basePath = null, ?float $maxWidth = null): self
    {
        // The nulls go before `withAll()` rather than being left to it: a null
        // for `imageBasePath` is a value there, since the property is nullable,
        // and `withImages($mode)` is about the mode.
        return $this->withAll(array_filter([
            'images' => $mode,
            'imageBasePath' => $basePath,
            'imageMaxWidth' => $maxWidth,
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function withMaxHeadingLevel(int $level): self
    {
        return $this->with('maxHeadingLevel', $level);
    }

    /**
     * Whether an SVG is written with a raster of itself beside it, or on its own.
     *
     * A setter rather than a second argument to {@see self::withImages()}: the two
     * answer different questions — what to do with an image, and what to put beside an
     * SVG that is going in — and only the second has a value worth setting for a
     * document whose images are all raster already.
     */
    public function withSvgFallback(string $mode): self
    {
        return $this->with('svgFallback', $mode);
    }

    public function withTableBorders(bool $borders): self
    {
        return $this->with('tableBorders', $borders);
    }

    public function withTableWidth(int $width): self
    {
        return $this->with('tableWidth', $width);
    }

    public function withCodeBlockShading(bool $shading): self
    {
        return $this->with('codeBlockShading', $shading);
    }

    /**
     * Merge a batch of options over this one.
     *
     * A `null` for a property that is not nullable by design means "not mentioned",
     * so whatever the receiver holds for it is kept. "Reset to the default" is the
     * other possible reading of a null, and it is the one {@see self::fromArray()}
     * needs — there is no receiver, so nothing can be lost by ignoring it — but it
     * is wrong here: the caller named a key and no value, and reverting it would
     * change an option they never mentioned. That is the same silent data loss the
     * chain exists to prevent, one layer up, and it is the shape a config file takes
     * when an array of overrides is built up with `+` and reaches a key nobody has
     * given a value for yet.
     *
     * A `null` for a property that is nullable by design is the value, and is
     * applied: `['imageBasePath' => null]` is how a base path is cleared.
     *
     * An empty string is a value too, not an absence. {@see self::cast()} reads
     * `imageBasePath: ''` as "no base path" in a merge as much as in
     * {@see self::fromArray()}; the two ways in must not read the same array
     * differently.
     *
     * @param array<string, mixed> $options
     */
    public function withAll(array $options): self
    {
        return self::fromArray(array_merge($this->toArray(), self::withoutUnconfigured($options)));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'softBreak' => $this->softBreak,
            'hardBreak' => $this->hardBreak,
            'html' => $this->html,
            'images' => $this->images,
            'imageBasePath' => $this->imageBasePath,
            'imageMaxWidth' => $this->imageMaxWidth,
            'svgFallback' => $this->svgFallback,
            'maxHeadingLevel' => $this->maxHeadingLevel,
            'orderedListFormat' => $this->orderedListFormat,
            'orderedListSuffix' => $this->orderedListSuffix,
            'tableBorders' => $this->tableBorders,
            'tableHeaderBold' => $this->tableHeaderBold,
            'tableWidth' => $this->tableWidth,
            'codeBlockShading' => $this->codeBlockShading,
            'linkTarget' => $this->linkTarget,
            'thematicBreak' => $this->thematicBreak,
            'deferredHyperlinks' => $this->deferredHyperlinks,
        ];
    }

    /**
     * Return a copy with one property changed, leaving this one alone.
     *
     * The copy is built by merging over `$this`, never by starting from the
     * defaults: `fromArray()` fills in every property it is not given, so
     * rebuilding from it alone would quietly reset the other options to their
     * defaults. That is silent data loss rather than a visible mistake —
     * `withTableBorders(false)->withMaxHeadingLevel(3)` would hand back
     * `tableBorders: true` — and it is why this goes through `withAll()`, which
     * already merges, rather than straight to `fromArray()`.
     *
     * The value still goes through the cast, so a setter cannot be the way round a
     * range check that `fromArray()` applies.
     *
     * A setter takes a typed argument, so it cannot pass the `null` that
     * `withAll()` reads as "not mentioned": only the array form can, and
     * {@see self::withImages()} filters its own nulls out before handing them on.
     */
    private function with(string $property, mixed $value): self
    {
        return $this->withAll([$property => $value]);
    }

    /**
     * The keys of `$options` that say nothing: a `null` for a property that does
     * not accept one, so it cannot be told apart from the absence of a value.
     *
     * Both entry points drop them, and they mean different things afterwards —
     * {@see self::fromArray()} falls back to the default, {@see self::withAll()}
     * to the receiver — but whether a `null` is a value or an absence is a property
     * of the class rather than of the caller, so it is decided here, once, and by
     * the list in {@see self::NULLABLE}.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function withoutUnconfigured(array $options): array
    {
        foreach (array_keys($options) as $property) {
            if ($options[$property] === null && !in_array($property, self::NULLABLE, true)) {
                unset($options[$property]);
            }
        }

        return $options;
    }

    /**
     * Coerce loosely typed values (typically from a PHP, JSON or YAML config
     * file) into the exact types the constructor demands.
     *
     * Every numeric and every boolean property is listed here, and a new one has to
     * be added: a number the renderer puts into the document has to reach it as a
     * number, and a wrong one changes the document rather than raising, so a value
     * that is neither cast nor bounded silently becomes whatever `(int)` or `(bool)`
     * makes of it.
     *
     * The string properties are deliberately not cast: a mode that is not one of the
     * constants is a mistake in the config file, and casting it would mean inventing
     * a fallback mode, which is a decision this class does not get to make.
     *
     * The array handed in has already been merged over the defaults, so every
     * property is present and any `null` has already been resolved.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function cast(array $options): array
    {
        $bool = ['tableBorders', 'tableHeaderBold', 'codeBlockShading', 'deferredHyperlinks'];
        foreach ($bool as $key) {
            if (isset($options[$key])) {
                $options[$key] = (bool) $options[$key];
            }
        }

        if (isset($options['maxHeadingLevel'])) {
            $options['maxHeadingLevel'] = max(1, min(6, (int) $options['maxHeadingLevel']));
        }

        if (isset($options['imageMaxWidth'])) {
            $options['imageMaxWidth'] = max(0.0, (float) $options['imageMaxWidth']);
        }

        if (array_key_exists('tableWidth', $options)) {
            $options['tableWidth'] = max(0, min(5000, (int) $options['tableWidth']));
        }

        if (array_key_exists('imageBasePath', $options) && $options['imageBasePath'] === '') {
            $options['imageBasePath'] = null;
        }

        return $options;
    }
}
