<?php

declare(strict_types=1);

use League\CommonMark\Node\Node;
use MarkdownOffice\CommonMark\Directive\DirectiveType;
use MarkdownOffice\CommonMark\Directive\Node\DirectiveBlock;
use MarkdownOffice\CommonMark\Directive\Node\DirectiveInline;
use MarkdownWord\Configuration;
use MarkdownWord\Exception\NothingToConvert;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Text\TextExtractor;

/*
 * The Generic Directives RFC: parsed, and rendered by a renderer that has never
 * heard of it.
 *
 * `markdown-office/commonmark-generic-directives` teaches league/commonmark three
 * shapes — `:name[…]`, `::name[…]` and `:::name` … `:::` — and stops there. The
 * grammar is in; the rendering is not.
 *
 * The tests below pin what a Word document gets today, and the pinning is the
 * point: a directive that reaches the renderer loses its name and its attributes,
 * and a leaf loses its label too, and nothing anywhere says so. What is asserted
 * is that loss, not an endorsement of it, and each test that asserts it says so
 * where it is read.
 *
 * How the renderer treats them was established by rendering and reading the result
 * rather than by reading the walk: `DocumentRenderer::renderNode()` and
 * `InlineRenderer::renderNode()` both end in "no branch claimed this node, so
 * descend into whatever it holds", and the assertions below are the rendered text
 * of each shape.
 */

/** Every node of a parse tree, so a shape can be asked for rather than counted. */
function directiveNodes(Node $node): array
{
    $nodes = [$node];

    foreach ($node->children() as $child) {
        foreach (directiveNodes($child) as $descendant) {
            $nodes[] = $descendant;
        }
    }

    return $nodes;
}

/**
 * @return list<DirectiveBlock|DirectiveInline>
 */
function directivesIn(string $markdown, ?CommonMarkParser $parser = null): array
{
    $document = ($parser ?? CommonMarkParser::directives())->parse($markdown);

    return array_values(array_filter(
        directiveNodes($document),
        static fn (Node $node): bool => $node instanceof DirectiveBlock || $node instanceof DirectiveInline,
    ));
}

/**
 * `renderText()` with the parser this file is about; the shared helper takes a
 * configuration and leaves the dialect to the default.
 */
function renderDirectives(string $markdown): string
{
    $converter = new MarkdownToWord(null, new Configuration(), CommonMarkParser::directives());

    return TextExtractor::fromPhpWord(
        $converter->toPhpWord($markdown),
        TextExtractor::LINE_BREAK,
        $converter->pendingHyperlinks(),
    );
}

it('parses all three shapes of the directive syntax', function () {
    $markdown = <<<'MD'
        Ship it :badge[PRODUCTION]{type=success} today.

        ::image[caption]{src="chart.png"}

        :::callout{type=info}
        Inside the container.
        :::
        MD;

    $nodes = directivesIn($markdown);

    // One node per shape, told apart by the node's own type rather than by the
    // colons that introduced them, which is what the RFC asks a consumer to read.
    expect($nodes)->toHaveCount(3);

    [$inline, $leaf, $container] = $nodes;

    expect($inline)->toBeInstanceOf(DirectiveInline::class)
        ->and($inline->getName())->toBe('badge')
        ->and($inline->getLabel())->toBe('PRODUCTION')
        ->and($inline->getType())->toBe(DirectiveType::Text);

    expect($leaf)->toBeInstanceOf(DirectiveBlock::class)
        ->and($leaf->getName())->toBe('image')
        ->and($leaf->getLabel())->toBe('caption')
        ->and($leaf->getType())->toBe(DirectiveType::Leaf);

    expect($container)->toBeInstanceOf(DirectiveBlock::class)
        ->and($container->getName())->toBe('callout')
        ->and($container->getType())->toBe(DirectiveType::Container)
        ->and($container->isContainer())->toBeTrue();
});

it('reads a directive\'s attributes off the node', function () {
    $nodes = directivesIn(':::callout{type=info #lead .terse title="a } b"}' . "\nBody.\n" . ":::\n");

    expect($nodes[0]->getAttributes()->toArray())
        ->toHaveKey('type', 'info')
        ->toHaveKey('id', 'lead')
        ->toHaveKey('class', 'terse')
        ->toHaveKey('title', 'a } b');
});

it('is not in the default parser, or in any of the other factories', function () {
    $markdown = "Ship it :badge[PRODUCTION] today.\n";

    // The difference is the whole reason this is a factory rather than a default:
    // without the extension `:badge[PRODUCTION]` is literal text inside a
    // sentence, and with it the same bytes are a node.
    expect(directivesIn($markdown, new CommonMarkParser()))->toBe([])
        ->and(directivesIn($markdown, CommonMarkParser::commonMarkOnly()))->toBe([])
        ->and(directivesIn($markdown, CommonMarkParser::extended()))->toBe([])
        ->and(directivesIn($markdown, CommonMarkParser::withAllExtensions()))->toBe([])
        ->and(directivesIn($markdown, CommonMarkParser::directives()))->toHaveCount(1);

    // And the default renders the author's sentence rather than a word of it.
    expect(renderText($markdown))->toContain(':badge[PRODUCTION]');
});

it('draws a container\'s body and drops the container — temporarily', function () {
    $markdown = <<<'MD'
        Before.

        :::callout{type=info}
        Inside the container.
        :::

        After.
        MD;

    // What is missing is the thing a directive *is*: its name, its type, and the
    // boundary it drew. The document a reader gets is the one the same bytes
    // produce with the container written out, which is the loss stated as itself.
    expect(renderDirectives($markdown))->toBe("Before.\nInside the container.\nAfter.")
        ->and(renderDirectives("Before.\n\nInside the container.\n\nAfter.\n"))->toBe("Before.\nInside the container.\nAfter.");
});

it('draws an inline directive as its label — temporarily', function () {
    // The label is drawn and the attributes are not, so two directives differing
    // only in `{type=…}` come out identical — which is the loss a caller cannot
    // see until they read the source back.
    expect(renderDirectives("Ship it :badge[PRODUCTION]{type=success} today.\n"))
        ->toBe('Ship it PRODUCTION today.')
        ->and(renderDirectives("Ship it :badge[DRAFT]{type=info} today.\n"))
        ->toBe('Ship it DRAFT today.');
});

it('drops a leaf directive entirely, label and all — temporarily', function () {
    // A leaf has no body, so there is nothing left to draw. It is not shown as
    // text either, and the paragraph it stood between does not survive as an
    // empty one.
    expect(renderDirectives("Before.\n\n::image[caption]{src=\"chart.png\"}\n\nAfter.\n"))
        ->toBe("Before.\nAfter.")
        ->and(renderDirectives("Before.\n\nAfter.\n"))->toBe("Before.\nAfter.");
});

it('leaves a container\'s Markdown as Markdown', function () {
    // The container is transparent, so everything inside it goes through the
    // renderer exactly as if the fences were not there. This is the one part of
    // the behaviour that is certainly not temporary.
    expect(renderDirectives(":::callout\n# Heading\n\n- one\n- two\n:::\n"))
        ->toBe(renderDirectives("# Heading\n\n- one\n- two\n"));
});

it('is permissive: any well-formed name parses', function () {
    // The RFC describes no fixed vocabulary and the extension is given no
    // registry, so a name nobody has heard of is still a directive. Strict mode —
    // an unregistered name raising `UnknownDirective` rather than being ignored —
    // is a decision about which default this library wants, and it is not made
    // here; see `CommonMarkParser::directives()`.
    expect(directivesIn(":anything[at all]{k=v}\n"))->toHaveCount(1)
        ->and(directivesIn("::whatever\n"))->toHaveCount(1);
});

it('reports a document of nothing but leaf directives as having nothing in it', function () {
    // The one input a dropped directive can turn into an error. The renderer draws
    // nothing, so the document reads as empty and `NothingToConvert` is raised for
    // a document that plainly had Markdown in it.
    //
    // Asserted because it is a question and not an answer — whether a directive
    // counts as content is the same decision as how a directive is drawn, and both
    // belong to whoever writes the renderer. Pinned now so that whoever decides
    // sees the cost rather than discovering it.
    $converter = new MarkdownToWord(null, new Configuration(), CommonMarkParser::directives());

    expect($converter->toPhpWord("::image{src=\"chart.png\"}\n")->getSections()[0]->getElements())->toBe([]);

    expect(fn () => $converter->convert())->toThrow(NothingToConvert::class);
});
