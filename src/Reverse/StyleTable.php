<?php

declare(strict_types=1);

namespace MarkdownWord\Reverse;

use DOMElement;

/**
 * Resolves a paragraph's style name to the properties that style implies.
 *
 * A Word paragraph often says nothing about its indentation, because the
 * indentation lives in the style it references. Block quotes are the case that
 * matters: the outermost level inherits a half-inch indent from the style, so
 * without resolving it the nesting depth cannot be recovered at all.
 *
 * The character half is resolved for the same reason. A bold heading is bold
 * because its style says so, and a run that repeats the style's own weight is not
 * emphasis the author typed — reading it as `**` turns every heading of a document
 * written by this library into `# **Heading**` on the way back.
 *
 * Properties are inherited through `w:basedOn`, and a paragraph's own direct
 * formatting wins over both, which is the precedence Word applies.
 */
final class StyleTable
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * Style id => the `w:style` element defining it.
     *
     * @var array<string, \DOMElement>
     */
    private array $styles = [];

    /** @var array<string, array{indent: int, alignment: string, bold: bool, italic: bool, strike: bool}> */
    private array $resolved = [];

    /**
     * The styles whose resolution is in progress, as a set.
     *
     * A property rather than an argument, because an argument is copied into
     * every frame: that copy is what made a deep `basedOn` chain cost memory
     * quadratic in its own depth. Shared, it is a plain cycle guard.
     *
     * @var array<string, true>
     */
    private array $resolving = [];

    /**
     * @param \DOMDocument|null $styles        `word/styles.xml`.
     * @param int               $maxStyleDepth How many styles in a `basedOn`
     *        chain contribute their own properties. Past the cap a chain resolves
     *        to the safe default rather than being followed, so a document that
     *        nests styles absurdly deeply still converts instead of exhausting
     *        memory. Word itself nests a handful deep, so the default is a wide
     *        margin.
     */
    public function __construct(?\DOMDocument $styles, private readonly int $maxStyleDepth = 32)
    {
        if ($styles === null) {
            return;
        }

        $xpath = new \DOMXPath($styles);
        $xpath->registerNamespace('w', self::W_NS);

        foreach ($xpath->query('//w:style') ?: [] as $style) {
            /** @var \DOMElement $style */
            $id = $style->getAttributeNS(self::W_NS, 'styleId');

            if ($id !== '') {
                $this->styles[$id] = $style;
            }
        }
    }

    /**
     * The left indentation, in twips, a style contributes.
     */
    public function indentOf(string $id): int
    {
        return $this->resolve($id)['indent'];
    }

    /**
     * The paragraph alignment a style contributes, or an empty string.
     */
    public function alignmentOf(string $id): string
    {
        return $this->resolve($id)['alignment'];
    }

    /**
     * The weight, slant and strike-through a style contributes to every run in it.
     *
     * @return array{bold: bool, italic: bool, strike: bool}
     */
    public function emphasisOf(string $id): array
    {
        $resolved = $this->resolve($id);

        return [
            'bold' => $resolved['bold'],
            'italic' => $resolved['italic'],
            'strike' => $resolved['strike'],
        ];
    }

    /** What a style contributes when it contributes nothing at all. */
    private const NOTHING = [
        'indent' => 0,
        'alignment' => '',
        'bold' => false,
        'italic' => false,
        'strike' => false,
    ];

    /**
     * The properties a style contributes, its own taking precedence over the ones
     * it inherits.
     *
     * @param int $depth How many styles of the chain have already contributed.
     * @return array{indent: int, alignment: string, bold: bool, italic: bool, strike: bool}
     */
    private function resolve(string $id, int $depth = 0): array
    {
        if (isset($this->resolved[$id])) {
            return $this->resolved[$id];
        }

        // Three ways of stopping: a style that refers to itself, directly or
        // through a chain, would otherwise recurse forever; a chain longer than
        // the cap is legal but is not something Word writes, and following it all
        // the way is what turns a small styles part into unbounded work; and an id
        // that is not in the part is a dangling reference, which is what a
        // hand-edited document contains. All three answer the same way: no
        // indentation and no alignment, which is what a style that says neither
        // contributes.
        if (
            isset($this->resolving[$id])
            || $depth >= $this->maxStyleDepth
            || !isset($this->styles[$id])
        ) {
            return self::NOTHING;
        }

        $this->resolving[$id] = true;

        try {
            $style = $this->styles[$id];
            $xpath = new \DOMXPath($style->ownerDocument ?? new \DOMDocument());
            $xpath->registerNamespace('w', self::W_NS);

            $parent = $style->getElementsByTagNameNS(self::W_NS, 'basedOn')->item(0);
            $parentId = $parent instanceof \DOMElement
                ? $parent->getAttributeNS(self::W_NS, 'val')
                : '';

            // A style that names itself as its own parent keeps its own properties
            // and takes nothing from the chain. `Writer\Word2007\Part\Styles` writes
            // a Paragraph's own style name as the `w:basedOn` of the style it is
            // building, so that is what documents this library wrote contain.
            $inherited = $parentId === '' || $parentId === $id
                ? self::NOTHING
                : $this->resolve($parentId, $depth + 1);

            $own = $this->ownProperties($xpath, $style);

            return $this->resolved[$id] = [
                'indent' => $own['indent'] ?? $inherited['indent'],
                'alignment' => $own['alignment'] ?? $inherited['alignment'],
                'bold' => $own['bold'] ?? $inherited['bold'],
                'italic' => $own['italic'] ?? $inherited['italic'],
                'strike' => $own['strike'] ?? $inherited['strike'],
            ];
        } finally {
            // The guard is about the path being walked, not about the style, so a
            // style is released once its own resolution is done. Leaving it
            // marked would make every later style that inherits from it look like
            // a cycle.
            unset($this->resolving[$id]);
        }
    }

    /**
     * @return array{indent?: int, alignment?: string, bold?: bool, italic?: bool, strike?: bool}
     */
    private function ownProperties(\DOMXPath $xpath, \DOMElement $style): array
    {
        $properties = [];

        $indentation = $xpath->query('./w:pPr/w:ind', $style)?->item(0);
        if ($indentation instanceof \DOMElement) {
            $left = $indentation->getAttributeNS(self::W_NS, 'left');
            $properties['indent'] = $left === '' ? 0 : (int) $left;
        }

        $alignment = $xpath->query('./w:pPr/w:jc', $style)?->item(0);
        if ($alignment instanceof \DOMElement) {
            $properties['alignment'] = $alignment->getAttributeNS(self::W_NS, 'val');
        }

        // A toggle written as off is a decision, so it is recorded as one. One that
        // is absent leaves the key out, and the inherited value stands.
        foreach (['bold' => 'w:b', 'italic' => 'w:i', 'strike' => 'w:strike'] as $property => $name) {
            $toggle = $xpath->query('./w:rPr/' . $name, $style)?->item(0);

            if ($toggle instanceof DOMElement) {
                $properties[$property] = !in_array(
                    strtolower($toggle->getAttributeNS(self::W_NS, 'val')),
                    ['0', 'false', 'off'],
                    true,
                );
            }
        }

        return $properties;
    }
}
