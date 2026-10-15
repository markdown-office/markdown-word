<?php

declare(strict_types=1);

use League\CommonMark\Node\Node;
use MarkdownOffice\CommonMark\Directive\DirectiveType;
use MarkdownOffice\CommonMark\Directive\Node\DirectiveBlock;
use MarkdownOffice\CommonMark\Directive\Node\DirectiveInline;
use MarkdownWord\Configuration;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
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

it('is the default parser, and can still be asked for or refused', function () {
    $markdown = "Ship it :badge[PRODUCTION] today.\n";

    // The default changed when directives were switched on, so this is the test
    // that fails if it is switched back; the other two are what would fail if it
    // could not be turned off.
    expect(directivesIn($markdown))->toHaveCount(1)
        ->and(directivesIn($markdown, new CommonMarkParser()))->toHaveCount(1)
        ->and(directivesIn($markdown, CommonMarkParser::directives()))->toHaveCount(1)
        ->and(directivesIn($markdown, CommonMarkParser::commonMarkOnly()))->toBe([])
        ->and(directivesIn($markdown, CommonMarkParser::extended()))->toBe([])
        ->and(directivesIn($markdown, CommonMarkParser::withAllExtensions()))->toBe([]);

    // And either parser renders an unclaimed name as the characters it was given,
    // so the sentence is the one the author wrote.
    expect(renderText($markdown))->toBe('Ship it :badge[PRODUCTION] today.');
});

it('draws a container\'s body and drops the fence', function () {
    // Transparent, because the body is the author's own Markdown and drawing the
    // fence as well would put `:callout` in the document as a line of its own.
    $markdown = <<<'MD'
        Before.

        :::callout{type=info}
        Inside the container.
        :::

        After.
        MD;

    expect(renderDirectives($markdown))->toBe("Before.\nInside the container.\nAfter.");
});

it('draws an inline directive nobody claims as the source form', function () {
    // A label alone would drop the syntax around it, so two directives differing
    // only in `{type=…}` would come out as two sentences indistinguishable in a
    // document that never used a directive. `directive-rendering.php` asserts
    // this behaviour and why the default-flavour change rests on it.
    expect(renderDirectives("Ship it :badge[PRODUCTION]{type=success} today.\n"))
        ->toBe('Ship it :badge[PRODUCTION]{type="success"} today.')
        ->and(renderDirectives("Ship it :badge[DRAFT]{type=info} today.\n"))
        ->toBe('Ship it :badge[DRAFT]{type="info"} today.');
});

it('draws a leaf nobody claims as a paragraph, label and all', function () {
    // A leaf has no body to descend into, so before there was anything to draw it
    // disappeared and took the paragraph it stood between with it.
    expect(renderDirectives("Before.\n\n::image[caption]{src=\"chart.png\"}\n\nAfter.\n"))
        ->toBe("Before.\n::image[caption]{src=\"chart.png\"}\nAfter.");
});

it('leaves a container\'s Markdown as Markdown', function () {
    expect(renderDirectives(":::callout\n# Heading\n\n- one\n- two\n:::\n"))
        ->toBe(renderDirectives("# Heading\n\n- one\n- two\n"));
});

it('is permissive: any well-formed name parses', function () {
    // The RFC describes no fixed vocabulary and the extension is given no
    // registry, so a name nobody has heard of is still a directive. Strict mode —
    // an unregistered name raising `UnknownDirective` — is deliberately not
    // reachable, because a name a document has not met yet is prose until
    // something draws it otherwise.
    expect(directivesIn(":anything[at all]{k=v}\n"))->toHaveCount(1)
        ->and(directivesIn("::whatever\n"))->toHaveCount(1);
});

it('counts a document of nothing but directives as having something in it', function () {
    // The one input a dropped directive used to turn into an empty document. A
    // leaf drawn as its source form is content, so a document made only of
    // leaves converts rather than coming back empty.
    //
    // The source goes to the constructor rather than being left null: `convert()`
    // raises `NothingToConvert` for a converter that has no input at all, which is
    // a different thing and would let this pass without saying anything about how
    // a leaf is drawn. The old version of this test made exactly that mistake.
    $markdown = "::image{src=\"chart.png\"}\n";
    $document = Scratch::path('leaf-only');

    (new MarkdownToWord($markdown, new Configuration(), CommonMarkParser::directives()))->save($document);

    expect(TemplateFactory::xmlOf($document))->toContain('::image')
        ->and((new MarkdownToWord($markdown, new Configuration(), CommonMarkParser::directives()))->convert())
        ->toStartWith('PK');
});
