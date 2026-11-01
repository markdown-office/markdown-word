<?php

declare(strict_types=1);

use MarkdownWord\Converter;
use MarkdownWord\Input;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\WordToMarkdown;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\Upstream;

/*
 * The shape of the public interface.
 *
 * The two directions are the same operation, so the classes that do them should
 * look alike, and both should be described by one `Converter`. They did not at
 * first: `save()` took `(content, path)` in one and `(path, path)` in the other —
 * the same name and the same arity, meaning opposite things, so a caller who
 * learned one got the other wrong silently.
 *
 * These tests are what holds them together. Nothing else notices a method being
 * renamed on one side only, and an interface nothing is written against is
 * decoration.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * @return array<string, class-string<Converter>>
 */
function bothDirections(): array
{
    return [
        MarkdownToWord::class => MarkdownToWord::class,
        WordToMarkdown::class => WordToMarkdown::class,
    ];
}

it('describes both directions with one interface', function () {
    foreach (bothDirections() as $class) {
        expect($class)->toImplement(Converter::class);
    }
});

it('converts either direction through the interface alone', function () {
    // The whole point: a caller holding a Converter cannot tell the two apart,
    // and does not have to.
    $markdown = Scratch::path('iface', '.md');
    file_put_contents($markdown, "# Subject\n\nBody.\n");
    $document = Scratch::path('iface', '.docx');
    saveDocument("# Subject\n\nBody.\n", $document);

    $toWord = new MarkdownToWord($markdown);
    $toMarkdown = new WordToMarkdown($document);

    expect($toWord)->toBeInstanceOf(Converter::class);
    expect($toMarkdown)->toBeInstanceOf(Converter::class);

    /** @var list<Converter> $converters */
    $converters = [$toWord, $toMarkdown];

    foreach ($converters as $index => $converter) {
        $bytes = $converter->convert();
        $target = Scratch::path('iface-out-' . $index);
        $converter->save($target);

        expect($bytes)->toBeString();
        expect(is_file($target))->toBeTrue();
        // Whatever it produced is the same whether returned or written. A document
        // is compared by its parts and not by its bytes: a zip carries the time it
        // was written and `docProps/core.xml` carries the time the document was
        // made, so two correct conversions of the same input differ most of the
        // time.
        $written = (string) file_get_contents($target);

        if (Input::looksLikeDocument($written)) {
            expect(documentParts($written))->toBe(documentParts($bytes));
        } else {
            expect($written)->toBe($bytes);
        }
    }
});

it('sends both directions to the same place through the interface', function () {
    // A round trip written once, against the interface, with no reference to
    // either class.
    $toWord = new MarkdownToWord("# Through the interface\n");
    $toMarkdown = new WordToMarkdown($toWord->convert());

    expect($toMarkdown->convert())->toContain('# Through the interface');
});

/**
 * @return list<string>
 */
function publicMethods(string $class): array
{
    return array_values(array_filter(
        get_class_methods($class),
        static fn (string $method): bool => $method !== '__construct',
    ));
}

it('gives both classes the same operations', function () {
    $markdown = publicMethods(MarkdownToWord::class);
    $document = publicMethods(WordToMarkdown::class);

    // The two the pair is built on, present on both, and nowhere else named
    // differently.
    foreach (['convert', 'save'] as $method) {
        expect($markdown)->toContain($method);
        expect($document)->toContain($method);
    }

    // Each has one string-in, string-out method, named after the direction it
    // produces rather than after the verb. That is deliberately not on the
    // interface: it is the one thing the two do differently.
    expect($markdown)->toContain('toDocx');
    expect($document)->toContain('toMarkdown');
    expect(get_class_methods(Converter::class))->toBe(['convert', 'save']);
});

it('takes the subject to convert as the first argument to both', function () {
    foreach ([MarkdownToWord::class, WordToMarkdown::class] as $class) {
        $parameters = (new ReflectionMethod($class, '__construct'))->getParameters();

        expect($parameters)->not->toBeEmpty();
        expect($parameters[0]->getName())->toBe('source');
        expect((string) $parameters[0]->getType())->toBe('?string');
    }
});

it('gives save the same signature in both directions', function () {
    // One target, and nothing else: the subject went in the constructor, so
    // there is no second argument to get the argument order wrong.
    foreach ([MarkdownToWord::class, WordToMarkdown::class] as $class) {
        $parameters = (new ReflectionMethod($class, 'save'))->getParameters();

        expect($parameters)->toHaveCount(1);
        expect($parameters[0]->getName())->toBe('target');
        expect((string) $parameters[0]->getType())->toBe('string');
    }
});

it('gives convert the same signature in both directions', function () {
    foreach ([MarkdownToWord::class, WordToMarkdown::class] as $class) {
        $parameters = (new ReflectionMethod($class, 'convert'))->getParameters();

        expect($parameters)->toHaveCount(1);
        expect($parameters[0]->getName())->toBe('target');
        expect($parameters[0]->isOptional())->toBeTrue();
    }
});

it('tells the caller when there is nothing to convert', function () {
    // Both, and in the same words, so neither is a worse experience than the
    // other.
    foreach ([MarkdownToWord::class, WordToMarkdown::class] as $class) {
        $error = null;

        try {
            (new $class())->convert();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }

        expect($error)->toContain('There is no');
    }
});

it('takes either a file or the content in both directions', function () {
    $markdown = Scratch::path('shape', '.md');
    file_put_contents($markdown, "# Subject\n");

    $document = Scratch::path('shape', '.docx');
    saveDocument("# Subject\n", $document);

    // A path, and the bytes themselves, are both accepted — the same rule in
    // both directions.
    expect((new MarkdownToWord($markdown))->convert())->toStartWith('PK');
    expect((new MarkdownToWord('# Subject'))->convert())->toStartWith('PK');
    expect((new WordToMarkdown($document))->convert())->toContain('# Subject');
    expect((new WordToMarkdown((new MarkdownToWord($markdown))->convert()))->convert())
        ->toContain('# Subject');
});

it('reports a string that is neither a document nor the name of one', function () {
    // Any text is Markdown, so a string is taken as the content. A document has
    // to be a zip, so a string that is not one is a mistake worth naming.
    expect((new MarkdownToWord('just some text'))->convert())->toStartWith('PK');

    expect(fn () => (new WordToMarkdown('just some text'))->convert())
        ->toThrow(RuntimeException::class, 'neither a Word document nor the name of one');
});
