<?php

declare(strict_types=1);

namespace MarkdownWord\Render\Directive;

use MarkdownOffice\CommonMark\Directive\Node\DirectiveBlock;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Render\RenderContext;
use MarkdownWord\Render\StyleResolver;
use PhpOffice\PhpWord\Element\AbstractContainer;

/**
 * `::caption[Figure 1]{}` — a caption under a picture.
 *
 * **The text is the author's own.** There is no numbering: PHPWord has no `SEQ`
 * field, which is the field Word numbers a figure with, so a caption that
 * numbered itself would be a number written out at conversion time — wrong the
 * moment a figure is inserted above it. The number is written in the caption,
 * where a reader can see it and an author can change it.
 *
 * The caption is a paragraph in the document's own look, with its text in
 * italics, which is what marks it as a caption rather than as another
 * paragraph of prose.
 */
final class Caption
{
    public function __construct(private readonly StyleResolver $styles)
    {
    }

    public function __invoke(DirectiveBlock $node, AbstractContainer $target, RenderContext $context): void
    {
        $text = $node->getLabel() ?? '';

        if ($text === '') {
            $target->addTextRun($this->styles->paragraphStyleFor(Styles::PARAGRAPH))->addText((string) $node);

            return;
        }

        $target->addTextRun($this->styles->paragraphStyleFor(Styles::PARAGRAPH))
            ->addText($text, ['italic' => true]);
    }
}
