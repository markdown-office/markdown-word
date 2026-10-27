<?php

declare(strict_types=1);

namespace MarkdownWord\Render\Directive;

use MarkdownOffice\CommonMark\Directive\Node\DirectiveBlock;
use MarkdownWord\Render\ImageResolver;
use PhpOffice\PhpWord\Element\AbstractContainer;

/**
 * `::image{src=… width=… height=… align=…}` — a picture of a stated size.
 *
 * The file is resolved the way a Markdown image's URL is: against
 * `imageBasePath`, and a file that is not there or a URL that is remote falls
 * back to the alt text. The label is that alt text, which is what a picture in
 * the document is described by.
 *
 * **The size is in centimetres**, the unit `imageMaxWidth` is in, so a document
 * that names a size and a document that leaves it to the option are in the same
 * unit. A size that is asked for is the size the picture gets: the cap is what
 * happens to a picture that says nothing about its own size, and shrinking a
 * picture to a limit the author did not know about is the kind of quiet change
 * this library does not make.
 *
 * A picture with no file is not a picture, and a picture with no size is a
 * picture of its own size — which is the answer `imageMaxWidth` gives it.
 */
final class Image
{
    public function __construct(private readonly ImageResolver $images)
    {
    }

    public function __invoke(DirectiveBlock $node, AbstractContainer $target): void
    {
        $attributes = $node->getAttributes();
        $source = $attributes->getString('src') ?? '';

        if ($source === '') {
            $target->addTextRun()->addText((string) $node);

            return;
        }

        $this->images->drawFile(
            $source,
            $target,
            $node->getLabel(),
            $attributes->getFloat('width'),
            $attributes->getFloat('height'),
            $attributes->getString('align') ?? 'left',
        );
    }
}
