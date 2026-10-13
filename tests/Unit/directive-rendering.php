<?php

declare(strict_types=1);

use MarkdownOffice\CommonMark\Directive\Node\DirectiveBlock;
use MarkdownOffice\CommonMark\Directive\Node\DirectiveInline;
use MarkdownWord\Render\Directives;

/*
 * What a directive becomes in a Word document.
 *
 * The grammar half is asserted in `directive-grammar.php`. This is the other
 * half, and it rests on one decision: **a directive this library does not draw
 * is rendered as its own source form**.
 *
 * The decision was forced by directives becoming the default flavour. With
 * them off by default, `Status :green[go]` was a sentence and the parser never
 * saw it. With them on, the same characters parse as a directive — so a renderer
 * that drops it, or that draws only its label, is no longer leaving prose alone.
 * It is rewriting it. Drawing the source form is what makes enabling the syntax
 * by default a change that costs a document nothing: a name nothing claims comes
 * back out as the characters the author typed.
 *
 * That is also the reason the two "unclaimed" tests below assert a whole
 * sentence rather than a token. They are the regression test for a change that
 * would otherwise be invisible: if a future renderer starts claiming a name by
 * accident, or starts treating an unknown one as an error, these fail.
 */

it('draws an inline directive nobody claims as the characters that were typed', function () {
    // Before this behaviour existed the same input came out as `Status go` — the
    // label with the syntax around it removed and no indication that anything
    // had been taken out of the sentence.
    expect(renderText('Status :green[go] today.'))->toBe('Status :green[go] today.');
});

it('draws an inline directive nobody claims with its attributes intact', function () {
    // The attributes are where the loss shows: a renderer that drew the node
    // without them would produce two identical sentences from two different
    // directives, and reading the source back would be the only way to tell.
    //
    // The value comes back quoted where the author wrote it bare. That is the
    // directive's own canonical form rather than a change made here: the quoted
    // and bare spellings parse to the same attributes, so what has to survive is
    // that `{type=success}` and `{type=warning}` still read as different.
    expect(renderText('Ship it :badge[PRODUCTION]{type=success} today.'))
        ->toBe('Ship it :badge[PRODUCTION]{type="success"} today.')
        ->and(renderText('Ship it :badge[PRODUCTION]{type=warning} today.'))
        ->toBe('Ship it :badge[PRODUCTION]{type="warning"} today.');
});

it('draws a leaf nobody claims as a paragraph of its own', function () {
    // A leaf has no body, so there is nothing left to descend into and the old
    // fallthrough dropped it entirely — label included — leaving a hole where
    // the paragraph had been.
    expect(renderText('Before.' . "\n\n" . '::image[caption]{src="chart.png"}' . "\n\n" . 'After.'))
        ->toBe("Before.\n::image[caption]{src=\"chart.png\"}\nAfter.");
});

it('leaves a container nobody claims transparent', function () {
    // The one case where drawing the source form would be wrong. A container
    // holds Markdown, and that Markdown is the author's content; drawing the
    // fence as well would put `:note` in the document as a line of its own.
    expect(renderText(":::note\nInside the container.\n:::\n"))->toBe('Inside the container.');
});

it('leaves a colon that is not a directive alone', function () {
    // The cases the RFC declines to make directives. They must survive the
    // extension being on by default, because they are not a directive at all and
    // a parser that turned them into one would be changing prose for no reason.
    expect(renderText('Note: prose'))->toBe('Note: prose')
        ->and(renderText('The meeting is at 10:30.'))->toBe('The meeting is at 10:30.')
        ->and(renderText('status:error is not a directive'))->toBe('status:error is not a directive');
});

it('draws the directives a handler claims and leaves the rest of them alone', function () {
    $registry = (new Directives())->withInline('badge', function (
        DirectiveInline $node,
        PhpOffice\PhpWord\Element\AbstractContainer $target,
        MarkdownWord\Render\InlineStyle $style,
    ): void {
        $target->addText('[' . $node->getLabel() . ']');
    });

    expect($registry->drawsInline(inlineNodeOf(':badge[X]')))
        ->toBeTrue()
        ->and($registry->drawsInline(inlineNodeOf(':other[X]')))
        ->toBeFalse();
});

it('matches a directive name without regard to case or spacing', function () {
    // The RFC allows `:: name` with a space, and a document cannot be asked to
    // care about case: `::TOC` and `::toc` are the same directive to a reader.
    $registry = new Directives();

    expect($registry->drawsBlock(blockNodeOf('::TOC')))->toBeFalse();

    $drawing = static function (DirectiveBlock $node, $target, $context): void {
    };

    $registry = $registry->withBlock('toc', $drawing);

    expect($registry->drawsBlock(blockNodeOf('::TOC')))->toBeTrue()
        ->and($registry->drawsBlock(blockNodeOf(':: toc')))->toBeTrue()
        ->and($registry->drawsBlock(blockNodeOf('::ToC')))->toBeTrue()
        ->and($registry->drawsBlock(blockNodeOf('::tocx')))->toBeFalse();
});

it('claims nothing for a container written without a name', function () {
    // `::: {.note}` is a div carrying attributes, not a directive, and there is
    // no name to look up. Handing it to a handler keyed on null would be a
    // handler that fires for every bare container in every document.
    expect((new Directives())->drawsBlock(blockNodeOf("::: {.note}\nx\n:::\n")))->toBeFalse();
});

/** The one parsed node of a given shape, so a test can ask about a name rather than count. */
function inlineNodeOf(string $markdown): DirectiveInline
{
    $parser = MarkdownWord\Parser\CommonMarkParser::directives();

    foreach ($parser->parse($markdown)->children() as $paragraph) {
        foreach ($paragraph->children() as $child) {
            if ($child instanceof DirectiveInline) {
                return $child;
            }
        }
    }

    throw new RuntimeException('No inline directive in ' . $markdown);
}

function blockNodeOf(string $markdown): DirectiveBlock
{
    $parser = MarkdownWord\Parser\CommonMarkParser::directives();

    foreach ($parser->parse($markdown)->children() as $child) {
        if ($child instanceof DirectiveBlock) {
            return $child;
        }
    }

    throw new RuntimeException('No block directive in ' . $markdown);
}