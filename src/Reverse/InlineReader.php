<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Reads the inline content of a paragraph: its runs, its links and its images.
 *
 * A paragraph's content is not one string but a sequence of runs, each with its
 * own formatting, and both the runs and the style they sit in have to be read
 * to say what the paragraph says.
 */
final class InlineReader
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const A_NS = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const WP_NS = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
    private const V_NS = 'urn:schemas-microsoft-com:vml';
    private const O_NS = 'urn:schemas-microsoft-com:office:office';

    /** @var list<string> Typefaces considered monospaced, lower-cased. */
    private array $monospace;

    private DOMXPath $xpath;

    /**
     * @param array<string, string> $relationships Relationship id => target.
     */
    public function __construct(
        private readonly Options $options,
        private readonly array $relationships,
        DOMDocument $document,
    ) {
        $this->xpath = new DOMXPath($document);
        $this->xpath->registerNamespace('w', self::W_NS);
        $this->xpath->registerNamespace('a', self::A_NS);
        $this->xpath->registerNamespace('wp', self::WP_NS);
        $this->xpath->registerNamespace('v', self::V_NS);

        $this->monospace = array_map(
            static fn (string $font): string => mb_strtolower($font),
            $options->monospaceFonts,
        );
    }

    /**
     * @param array{bold: bool, italic: bool, strike: bool} $inherited
     * @return list<Inline>
     */
    public function read(DOMElement $paragraph, array $inherited = []): array
    {
        $inlines = [];

        foreach ($paragraph->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            switch ($child->localName) {
                case 'hyperlink':
                    $inlines = array_merge($inlines, $this->readHyperlink($child, $inherited));

                    break;

                case 'r':
                    $inlines = array_merge($inlines, $this->readRun($child, $inherited));

                    break;

                // A break written outside any run, which is how PHPWord emits a
                // line break that is not itself formatted.
                case 'br':
                case 'cr':
                    if ($child->getAttributeNS(self::W_NS, 'type') === '') {
                        $inlines[] = Inline::break();
                    }

                    break;

                case 'tab':
                    $inlines[] = Inline::text("\t");

                    break;

                case 'drawing':
                case 'pict':
                    $image = $this->readImage($child);
                    if ($image !== null) {
                        $inlines[] = $image;
                    }

                    break;

                // Anything else is a run-level property change, which Word writes
                // around a field and which carries no content.
                default:
                    break;
            }
        }

        return $this->merge($inlines);
    }

    /**
     * @param array{bold: bool, italic: bool, strike: bool} $inherited
     * @return list<Inline>
     */
    private function readHyperlink(DOMElement $hyperlink, array $inherited = []): array
    {
        $id = $hyperlink->getAttributeNS(self::R_NS, 'id');
        $url = $this->relationships[$id] ?? '';

        $title = $hyperlink->getAttributeNS(self::W_NS, 'tooltip');
        $title = $title === '' ? null : $title;

        // A link with no resolvable destination is not a link, as on the way in.
        if ($url === '') {
            return $this->readChildren($hyperlink, $inherited);
        }

        $label = [];
        foreach ($hyperlink->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'r') {
                $label = array_merge($label, $this->readRun($child, $inherited));
            }
        }

        return [Inline::link($url, $title, $this->merge($label))];
    }

    /**
     * @param array{bold: bool, italic: bool, strike: bool} $inherited
     * @return list<Inline>
     */
    private function readChildren(DOMElement $parent, array $inherited = []): array
    {
        $inlines = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'r') {
                $inlines = array_merge($inlines, $this->readRun($child, $inherited));
            }
        }

        return $this->merge($inlines);
    }

    /**
     * Emphasis is what the run adds to the style it sits in, not what the run
     * carries: Word writes the paragraph style's own weight onto the runs too, and
     * reading that as `**` makes every bold heading come back as `# **Heading**`.
     *
     * @param array{bold: bool, italic: bool, strike: bool} $inherited
     * @return list<Inline>
     */
    private function readRun(DOMElement $run, array $inherited = []): array
    {
        $bold = false;
        $italic = false;
        $strike = false;
        $code = false;

        $properties = $this->xpath->query('./w:rPr', $run)?->item(0);
        if ($properties instanceof DOMElement) {
            $bold = $this->isOn($properties, 'w:b') && !($inherited['bold'] ?? false);
            $italic = $this->isOn($properties, 'w:i') && !($inherited['italic'] ?? false);
            $strike = $this->isOn($properties, 'w:strike') && !($inherited['strike'] ?? false);
            $code = $this->isMonospace($properties);
        }

        $inlines = [];

        foreach ($run->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            if ($child->localName === 't') {
                $inlines[] = Inline::text($child->textContent, $bold, $italic, $strike, $code);

                continue;
            }

            // `w:br` is a line break the author asked for; a break with a type
            // is a column or page break, which is layout rather than content.
            if ($child->localName === 'br' && $child->getAttributeNS(self::W_NS, 'type') === '') {
                $inlines[] = Inline::break();

                continue;
            }

            if ($child->localName === 'tab') {
                $inlines[] = Inline::text("\t", $bold, $italic, $strike, $code);
            }
        }

        $image = $this->readImage($run);
        if ($image !== null) {
            $inlines[] = $image;
        }

        return $inlines;
    }

    private function isOn(DOMElement $properties, string $name): bool
    {
        $node = $this->xpath->query('./' . $name, $properties)?->item(0);

        if (!$node instanceof DOMElement) {
            return false;
        }

        // A toggle property carries an explicit `w:val`; "0", "false" and "off"
        // all mean off, and anything else means on.
        $value = strtolower($node->getAttributeNS(self::W_NS, 'val'));

        return !in_array($value, ['0', 'false', 'off'], true);
    }

    private function isMonospace(DOMElement $properties): bool
    {
        $fonts = $this->xpath->query('./w:rFonts', $properties)?->item(0);

        if (!$fonts instanceof DOMElement) {
            return false;
        }

        foreach (['ascii', 'hAnsi', 'cs'] as $attribute) {
            $name = mb_strtolower($fonts->getAttributeNS(self::W_NS, $attribute));

            if ($name !== '' && in_array($name, $this->monospace, true)) {
                return true;
            }
        }

        return false;
    }

    private function readImage(DOMElement $run): ?Inline
    {
        // Two shapes describe an image in OOXML: DrawingML, which is what Word
        // writes today, and VML, the older form PHPWord emits for a plain inline
        // picture and that this library's own documents are full of.
        $blip = $this->xpath->query('.//a:blip', $run)?->item(0);
        $id = $blip instanceof DOMElement
            ? $blip->getAttributeNS(self::R_NS, 'embed')
            : '';

        $alt = '';

        if ($id !== '') {
            // The description is the alt text, and also the fallback a reader with
            // no image support sees.
            $properties = $this->xpath->query('.//wp:docPr', $run)?->item(0);
            if ($properties instanceof DOMElement) {
                $alt = $properties->getAttribute('descr');
            }
        } else {
            $imagedata = $this->xpath->query('.//v:imagedata', $run)?->item(0);

            if (!$imagedata instanceof DOMElement) {
                return null;
            }

            $id = $imagedata->getAttributeNS(self::R_NS, 'id');
            $alt = $imagedata->getAttributeNS(self::O_NS, 'title');
        }

        return Inline::image($alt, $this->mediaPath($this->relationships[$id] ?? ''));
    }

    /**
     * Where an image is referenced from in the Markdown, per
     * {@see Options::$mediaDirectory}.
     */
    private function mediaPath(string $target): string
    {
        if ($target === '') {
            return '';
        }

        $relative = (string) preg_replace('#^\./#', '', $target);

        if ($this->options->mediaDirectory === null) {
            return $relative;
        }

        return rtrim($this->options->mediaDirectory, '/') . '/' . basename($relative);
    }

    /**
     * Merge neighbouring runs that are formatted identically.
     *
     * Word splits a sentence into a run per formatting change, and often into
     * more than that, so plain prose would otherwise come back as fragments.
     *
     * @param list<Inline> $inlines
     * @return list<Inline>
     */
    private function merge(array $inlines): array
    {
        $merged = [];

        foreach ($inlines as $inline) {
            $last = $merged === [] ? null : $merged[count($merged) - 1];

            if (
                $last !== null
                && $last->kind === Inline::TEXT
                && $inline->kind === Inline::TEXT
                && $last->sameFormatting($inline)
            ) {
                $merged[count($merged) - 1] = $last->withText($last->text . $inline->text);

                continue;
            }

            $merged[] = $inline;
        }

        return $merged;
    }
}
