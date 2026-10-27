<?php

declare(strict_types=1);

namespace MarkdownWord\Render\Directive;

use MarkdownOffice\CommonMark\Directive\Node\DirectiveBlock;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Format;
use MarkdownWord\Render\RenderContext;
use MarkdownWord\Render\StyleResolver;
use PhpOffice\PhpWord\Element\AbstractContainer;

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
    /** The feature key of a text box. */
    public const TEXT_BOX = 'text-box';

    /** The feature key of a shape. */
    public const SHAPE = 'shape';

    /** The feature key of a form field. */
    public const FORM_FIELD = 'form-field';

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
     * size this library names is, and the box is drawn where it stands rather
     * than floating over the text: a floating box is positioned against the
     * page, and a position in points is not a thing a document can say in
     * centimetres without inventing a second unit for it.
     */
    public function drawTextBox(DirectiveBlock $node, AbstractContainer $target, RenderContext $context): void
    {
        $this->usage->add(self::TEXT_BOX);

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

        $box = $target->addTextBox([
            'width' => self::POINTS_PER_CENTIMETRE * $width,
            'height' => self::POINTS_PER_CENTIMETRE * $height,
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
        $this->usage->add(self::SHAPE);

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
            $style['fill'] = ['color' => $fill];
        }

        $line = $attributes->getString('line');

        if ($line !== null && $line !== '') {
            $style['outline'] = ['color' => $line];
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
        $this->usage->add(self::FORM_FIELD);

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
                new \PhpOffice\PhpWord\Metadata\Protection('forms'),
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
     * The directive as the author wrote it, which is what a writer that cannot
     * carry it is given instead.
     */
    private function sourceForm(DirectiveBlock $node, AbstractContainer $target): void
    {
        $target->addTextRun($this->styles->paragraphStyleFor(Styles::PARAGRAPH))->addText((string) $node);
    }
}
