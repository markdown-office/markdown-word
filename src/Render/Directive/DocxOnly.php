<?php

declare(strict_types=1);

namespace MarkdownWord\Render\Directive;

use MarkdownOffice\CommonMark\Directive\Node\DirectiveBlock;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Format;
use MarkdownWord\Render\RenderContext;
use MarkdownWord\Render\StyleResolver;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Metadata\Protection;

/**
 * The directives only a `.docx` can carry: a text box, a shape, a form field.
 *
 * PHPWord writes a text box and a shape as VML and a form field as Word's own
 * `FORMTEXT` / `FORMCHECKBOX` / `FORMDROPDOWN` field. Neither the ODF writer nor
 * the RTF writer has an element for any of them, and an element with no writer
 * is skipped without a word: a document carrying one comes out of the other two
 * formats with the thing quietly gone.
 *
 * So a writer that cannot carry the directive is given the characters the author
 * wrote — the answer an unclaimed directive gets, and the one a reader can act
 * on — and the loss is reported through {@see \MarkdownWord\Format}, which is
 * told about it by {@see Usage}.
 */
final class DocxOnly
{
    /**
     * The shapes PHPWord's Word writer can draw.
     *
     * The writer writes the VML element named after the type and calls a method
     * named after it, so a type it does not know becomes an element no reader
     * knows. `Element\Shape` refuses one anyway — with an exception, which is
     * not what a document should get for a typo in an attribute.
     */
    private const SHAPES = ['arc', 'curve', 'line', 'polyline', 'rect', 'oval'];

    /** The kinds of form field Word has. */
    private const FIELDS = ['textinput', 'checkbox', 'dropdown'];

    /** A centimetre in points, which is the unit a box and a shape are measured in. */
    private const POINTS_PER_CENTIMETRE = 72 / 2.54;

    /** The colour of the line round a text box. */
    private const BORDER = '000000';

    /**
     * The weight of that line, in points.
     *
     * A whole number, because PHPWord's own setter takes an integer and a
     * fraction is quietly rounded to nothing — a box with a border of no weight
     * is a box with no border.
     */
    private const BORDER_SIZE = 1;

    /**
     * @param StyleResolver $styles The document's styles, for the source form.
     * @param Format|null $format The format the document is written in, or null for
     *        the default. Read as `.docx` when null, because the two entry points
     *        that render without writing never set it.
     * @param Usage $usage What the document asked of a writer that cannot carry it.
     */
    public function __construct(
        private readonly StyleResolver $styles,
        private readonly ?Format $format = null,
        private readonly Usage $usage = new Usage(),
    ) {
    }

    /**
     * `::textbox{width=… height=…}` — a floating box with text in it.
     *
     * The label is the text the box holds. The size is in centimetres, as every
     * size this library names is.
     *
     * **The box floats**, and the text flows around it: a box that sat in the
     * flow would be a paragraph with a border round it, and a paragraph is what
     * a document can already say. `left` and `top` place it — from the left
     * margin and from the top of the paragraph it stands in — and both are
     * centimetres, like every other size here.
     *
     * The box is given a border: PHPWord draws a text box without one unless it
     * is given the colour of one, and a box with nothing round it is a
     * paragraph.
     */
    public function drawTextBox(DirectiveBlock $node, AbstractContainer $target, RenderContext $context): void
    {
        $this->usage->add(Format::TEXT_BOX);

        if (!$this->carries()) {
            $this->sourceForm($node, $target);

            return;
        }

        $attributes = $node->getAttributes();
        $width = $attributes->getFloat('width');
        $height = $attributes->getFloat('height');

        if ($width === null || $height === null) {
            $this->sourceForm($node, $target);

            return;
        }

        $border = $attributes->getString('border') ?? self::BORDER;

        $box = $target->addTextBox([
            'width' => self::POINTS_PER_CENTIMETRE * $width,
            'height' => self::POINTS_PER_CENTIMETRE * $height,
            'left' => self::POINTS_PER_CENTIMETRE * ($attributes->getFloat('left') ?? 0.0),
            'top' => self::POINTS_PER_CENTIMETRE * ($attributes->getFloat('top') ?? 0.0),
            'positioning' => 'absolute',
            'hPos' => 'left',
            'vPos' => 'top',
            'hPosRelTo' => 'margin',
            'vPosRelTo' => 'text',
            'wrap' => 'square',
            'borderSize' => self::BORDER_SIZE,
            'borderColor' => self::colour($border),
        ]);

        $box->addText($node->getLabel() ?? '');
    }

    /**
     * `::shape{type=rect width=… height=… fill=…}` — a shape.
     *
     * The type is one of the shapes PHPWord can draw, and the size is in
     * centimetres as everywhere else. The fill and the outline are the colours
     * the shape is drawn in, six hexadecimal digits without a `#`, the spelling
     * Word uses.
     */
    public function drawShape(DirectiveBlock $node, AbstractContainer $target, RenderContext $context): void
    {
        $this->usage->add(Format::SHAPE);

        if (!$this->carries()) {
            $this->sourceForm($node, $target);

            return;
        }

        $attributes = $node->getAttributes();
        $type = $attributes->getString('type') ?? 'rect';

        if (!in_array($type, self::SHAPES, true)) {
            $this->sourceForm($node, $target);

            return;
        }

        $style = [];

        $width = $attributes->getFloat('width');
        $height = $attributes->getFloat('height');

        if ($width !== null || $height !== null) {
            $style['frame'] = [
                'width' => $width === null ? null : self::POINTS_PER_CENTIMETRE * $width,
                'height' => $height === null ? null : self::POINTS_PER_CENTIMETRE * $height,
            ];
        }

        $fill = $attributes->getString('fill');

        if ($fill !== null && $fill !== '') {
            $style['fill'] = ['color' => self::colour($fill)];
        }

        $line = $attributes->getString('line');

        if ($line !== null && $line !== '') {
            $style['outline'] = ['color' => self::colour($line)];
        }

        $target->addShape($type, $style);
    }

    /**
     * `::field{name=… type=…}` — a form field.
     *
     * `FORMTEXT` is the default; `checkbox` and `dropdown` are the other two.
     * A dropdown carries the entries it offers, separated by commas, and Word
     * only lets a reader choose from one while the document is protected for
     * forms — so a dropdown turns that protection on, and a document with one
     * is a form.
     */
    public function drawField(DirectiveBlock $node, AbstractContainer $target, RenderContext $context): void
    {
        $this->usage->add(Format::FORM_FIELD);

        if (!$this->carries()) {
            $this->sourceForm($node, $target);

            return;
        }

        $attributes = $node->getAttributes();
        $type = $attributes->getString('type') ?? 'textinput';

        if (!in_array($type, self::FIELDS, true)) {
            $this->sourceForm($node, $target);

            return;
        }

        $field = $target->addFormField($type);
        $field->setName($attributes->getString('name') ?? 'field');

        $entries = $attributes->getList('entries');

        if ($type === 'dropdown' && $entries !== []) {
            $field->setEntries($entries);

            // A dropdown is inert without it: Word only lets a reader choose from
            // one while the document is protected for forms, and a field that
            // cannot be answered is a field that is not there.
            $target->getPhpWord()?->getSettings()->setDocumentProtection(
                new Protection('forms'),
            );
        }

        if ($type === 'checkbox') {
            $field->setDefault($attributes->getBool('checked'));
        } else {
            $value = $attributes->getString('value');

            if ($value !== null && $value !== '') {
                $field->setDefault($value);
            }
        }
    }

    /**
     * Whether the format this document is written in can carry what these draw.
     */
    private function carries(): bool
    {
        return ($this->format ?? Format::Docx) === Format::Docx;
    }

    /**
     * A colour as VML writes it.
     *
     * VML wants a leading `#` — `fillcolor="#ff0000"` — and six hexadecimal digits
     * without one are not a colour at all: the shape comes out with no fill and
     * nobody is told. The rest of this library writes colours as six hexadecimal
     * digits, because that is the spelling `w:color` takes, so the `#` is added
     * here rather than asked of the author.
     *
     * A name is left alone, because a name is a colour in VML too.
     */
    private static function colour(string $value): string
    {
        return preg_match('/^[0-9A-Fa-f]{6}$/', $value) === 1 ? '#' . $value : $value;
    }

    /**
     * The directive as the author wrote it, which is what a writer that cannot
     * carry it is given instead.
     */
    private function sourceForm(DirectiveBlock $node, AbstractContainer $target): void
    {
        $target->addTextRun($this->styles->paragraphStyleFor(Styles::PARAGRAPH))->addText((string) $node);
    }
}
