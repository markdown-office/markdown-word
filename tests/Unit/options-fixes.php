<?php

declare(strict_types=1);

use MarkdownWord\Configuration\Options;

/*
 * What a fluent options object has to promise.
 *
 * `Options` is an immutable value object reached through a chain:
 *
 *     Configuration::create()->withTableBorders(false)->withMaxHeadingLevel(3)
 *
 * Every link in that chain is handed the result of the last one, so a setter
 * that drops the options it was not given is not a slightly wrong answer — it is
 * silent data loss, and the caller has no way to see it. Each test below is
 * built so that it cannot pass for the wrong reason:
 *
 *  - The base is configured with a value different from the default for every
 *    property. A setter that quietly reverts to the defaults therefore changes
 *    the answer, and a test that only compared against the defaults would not
 *    notice. That is the trap the setter tests in tests/Unit/public-api.php fell
 *    into: calling each setter on a fresh `new Options()` and asserting the one
 *    property it set cannot fail on this bug by construction, whatever the
 *    setter did to the rest.
 *  - The expected value is built from the base, so the assertion is about
 *    preservation — "the thing I set changed, and nothing else did" — which is
 *    the contract a value object actually has.
 *
 * The Casting group is that same concern from the config-file side: a number read
 * out of JSON as the string "2500" has to reach the renderer as an int, because
 * a wrong number changes the document instead of being noticed.
 *
 * The What a null means group is about a `null`, which the two array entry points
 * read differently on purpose, and about the one list they agree on.
 * tests/Unit/reverse-options-fixes.php is its twin.
 */

/**
 * An options object in which every single property differs from its default, so
 * that "the other properties were preserved" and "the other properties were
 * reset" are distinguishable.
 */
function configuredAwayFromTheDefaults(): Options
{
    return Options::fromArray([
        'softBreak' => Options::SOFT_BREAK_PARAGRAPH,
        'hardBreak' => Options::BREAK_REMOVE,
        'html' => Options::HTML_DROP,
        'images' => Options::IMAGE_PLACEHOLDER,
        'imageBasePath' => '/srv/pics',
        'imageMaxWidth' => 8.5,
        'maxHeadingLevel' => 3,
        'orderedListFormat' => 'lowerRoman',
        'orderedListSuffix' => 'space',
        'tableBorders' => false,
        'tableHeaderBold' => false,
        'tableWidth' => 2500,
        'codeBlockShading' => false,
        'linkTarget' => '_self',
        'thematicBreak' => 'text',
        'deferredHyperlinks' => true,
    ]);
}

// The setters

it('changes one option without disturbing the other fifteen', function (string $method, mixed $argument, string $property) {
    $base = configuredAwayFromTheDefaults();
    $changed = $base->{$method}($argument);

    expect($changed)->not->toBe($base)
        ->and($changed->{$property})->toBe($argument)
        // The whole point: every other property came through untouched. Had the
        // setter rebuilt the object from the defaults, the rest of this array
        // would all differ.
        ->and($changed->toArray())
        ->toBe(array_replace($base->toArray(), [$property => $argument]));
})->with([
    'soft break' => ['withSoftBreak', Options::SOFT_BREAK_SPACE, 'softBreak'],
    'hard break' => ['withHardBreak', Options::BREAK_PARAGRAPH, 'hardBreak'],
    'html' => ['withHtml', Options::HTML_PRESERVE, 'html'],
    'heading depth' => ['withMaxHeadingLevel', 5, 'maxHeadingLevel'],
    'table borders' => ['withTableBorders', true, 'tableBorders'],
    'table width' => ['withTableWidth', 4000, 'tableWidth'],
    'code shading' => ['withCodeBlockShading', true, 'codeBlockShading'],
]);

it('changes the image settings without disturbing the other thirteen', function () {
    $base = configuredAwayFromTheDefaults();
    $changed = $base->withImages(Options::IMAGE_SKIP, '/opt/assets', 4.5);

    expect($changed->toArray())->toBe(array_replace($base->toArray(), [
        'images' => Options::IMAGE_SKIP,
        'imageBasePath' => '/opt/assets',
        'imageMaxWidth' => 4.5,
    ]));
});

it('keeps every option set earlier in a chain', function () {
    // The reported shape: three setters in a row, and the first one is the only
    // one whose value the caller can still see in the result.
    $options = (new Options())
        ->withTableBorders(false)
        ->withMaxHeadingLevel(3)
        ->withTableWidth(2500);

    expect($options->tableBorders)->toBeFalse()
        ->and($options->maxHeadingLevel)->toBe(3)
        ->and($options->tableWidth)->toBe(2500);
});

it('keeps a non-default starting point through a whole chain', function () {
    // The same chain, started from a configured object rather than from the
    // defaults — so a chain cannot pass by accidentally reproducing them.
    $options = configuredAwayFromTheDefaults()
        ->withTableBorders(true)
        ->withMaxHeadingLevel(5)
        ->withTableWidth(4000);

    expect($options->toArray())->toBe(array_replace(configuredAwayFromTheDefaults()->toArray(), [
        'tableBorders' => true,
        'maxHeadingLevel' => 5,
        'tableWidth' => 4000,
    ]));
});

it('leaves the receiver of a setter alone', function () {
    $original = configuredAwayFromTheDefaults();
    $before = $original->toArray();

    $original->withSoftBreak(Options::SOFT_BREAK_LINE);
    $original->withTableWidth(1);
    $original->withImages(Options::IMAGE_SKIP, '/nowhere');

    expect($original->toArray())->toBe($before)
        ->and($original->softBreak)->toBe(Options::SOFT_BREAK_PARAGRAPH)
        ->and($original->tableWidth)->toBe(2500)
        ->and($original->imageBasePath)->toBe('/srv/pics');
});

// The array forms

it('merges withAll over the current state', function () {
    $changed = configuredAwayFromTheDefaults()->withAll([
        'tableBorders' => false,
        'tableWidth' => 100,
    ]);

    expect($changed->toArray())->toBe(array_replace(configuredAwayFromTheDefaults()->toArray(), [
        'tableBorders' => false,
        'tableWidth' => 100,
    ]));
});

it('treats a null in withImages as "not mentioned"', function () {
    // The null filter is deliberate: `withImages($mode)` must not wipe a base
    // path and a width that were configured earlier.
    $base = configuredAwayFromTheDefaults();
    $changed = $base->withImages(Options::IMAGE_SKIP);

    expect($changed->images)->toBe(Options::IMAGE_SKIP)
        ->and($changed->imageBasePath)->toBe('/srv/pics')
        ->and($changed->imageMaxWidth)->toBe(8.5)
        ->and($changed->toArray())->toBe(array_replace($base->toArray(), ['images' => Options::IMAGE_SKIP]));
});

it('leaves a null base path null rather than turning it into an empty string', function () {
    $options = (new Options())->withImages(Options::IMAGE_SKIP);

    expect($options->imageBasePath)->toBeNull()
        ->and($options->toArray()['imageBasePath'])->toBeNull();
});

it('round trips through the array form', function () {
    $options = configuredAwayFromTheDefaults();

    expect(Options::fromArray($options->toArray())->toArray())->toBe($options->toArray());
});

// What a null means

/*
 * A `null` in an array is two different things, and the two entry points have to
 * read it differently because they are in different positions.
 *
 * `fromArray()` builds an object that does not exist yet, so there is nothing a
 * null could take away: it means "not configured" and the default stands.
 * `withAll()` is handed an object that already holds values, and every one of
 * them was asked for, so a null there means "not mentioned" and the value stays.
 *
 * Reading it the other way round — a merge resetting to the default — is the bug
 * these tests are about, and it is the more expensive of the two: a null is
 * exactly the shape an override array takes when a key is present with no value
 * for it, which is what `+` and a JSON config with explicit nulls both produce,
 * and the option it silently changes is one the caller never mentioned.
 *
 * The one thing the two entry points do agree on is which properties can hold a
 * null at all, since that is a property of the class rather than of the caller.
 * The invariants at the end of this file pin that list against the constructor's
 * declared types.
 */

/**
 * The options of this class whose declared type accepts a `null`.
 *
 * Read off the constructor rather than written out here, so an option added
 * later is in every test below from the moment it exists.
 *
 * @return list<string>
 */
function nullableForwardOptions(): array
{
    $nullable = [];

    foreach ((new ReflectionClass(Options::class))->getConstructor()->getParameters() as $parameter) {
        if ($parameter->getType()?->allowsNull() === true) {
            $nullable[] = $parameter->getName();
        }
    }

    return $nullable;
}

it('keeps a value a merge was not asked to change when a key arrives null', function (string $property, mixed $configured) {
    // The shape: an object with this one option configured away from its default,
    // merged with an array that names it and gives it no value for it. Nothing
    // about the object changes, so the whole array comes back as it went in —
    // compared in full, so a merge that quietly put the class default back
    // instead fails here for whichever property it was.
    $base = Options::fromArray([$property => $configured]);

    expect($base->withAll([$property => null])->toArray())
        ->toBe($base->toArray(), "withAll(['{$property}' => null])");
})->with([
    'a width' => ['tableWidth', 2500],
    'a depth' => ['maxHeadingLevel', 2],
    'a switch' => ['tableBorders', false],
    'a mode' => ['softBreak', Options::SOFT_BREAK_PARAGRAPH],
    'a size' => ['imageMaxWidth', 8.5],
]);

it('applies the keys a merge was given and keeps the ones that arrived null', function () {
    $base = configuredAwayFromTheDefaults();

    $changed = $base->withAll([
        'tableWidth' => null,
        'maxHeadingLevel' => 2,
    ]);

    expect($changed->toArray())->toBe(array_replace($base->toArray(), ['maxHeadingLevel' => 2]));
});

it('reads a null for a nullable option as the value it is', function (string $property, string $configured) {
    // The other half of the contract, and the reason the merge is keyed on which
    // properties accept a null rather than on "is this null": `imageBasePath` is
    // nullable by design, so `['imageBasePath' => null]` is how a base path is
    // cleared, and a merge that read that as "not mentioned" could not be undone.
    $base = Options::fromArray([$property => $configured]);

    expect($base->withAll([$property => null])->{$property})->toBeNull()
        ->and($base->withAll([$property => null])->toArray()[$property])->toBeNull();
})->with([
    'base path' => ['imageBasePath', '/srv/pics'],
    'list suffix' => ['orderedListSuffix', 'space'],
]);

it('reads an empty string as a value rather than as an absence', function () {
    // `''` is not the case `null` is, and this says which of the two it is. It is
    // a value `cast()` knows how to read — for a nullable option, "no base
    // path" — and it reads the same way in both ways in, so a merge that kept
    // the configured path while `fromArray()` cleared it would make the two
    // disagree about the same array. Passing `''` therefore clears the base
    // path; the way to say "leave it as it is" is to leave the key out, or to
    // pass a null.
    $base = Options::fromArray(['imageBasePath' => '/srv/pics']);

    expect($base->withAll(['imageBasePath' => ''])->imageBasePath)->toBeNull()
        ->and(Options::fromArray(['imageBasePath' => ''])->imageBasePath)->toBeNull()
        ->and($base->withAll(['imageBasePath' => null])->imageBasePath)->toBeNull();
});

// Casting

it('gives every option the type the constructor promises', function () {
    // Fed as the strings a JSON or YAML config file produces, and read back as
    // the exact values the constructor declares. A single `toBe` on the whole
    // array says both things: the numbers came back as numbers, and nothing was
    // left holding the string it arrived as.
    $options = Options::fromArray([
        'softBreak' => Options::SOFT_BREAK_LINE,
        'hardBreak' => Options::BREAK_LINE,
        'html' => Options::HTML_STRIP,
        'images' => Options::IMAGE_EMBED,
        'imageBasePath' => '/srv/pics',
        'imageMaxWidth' => '12.5',
        'svgFallback' => Options::SVG_FALLBACK_NONE,
        'maxHeadingLevel' => '3',
        'orderedListFormat' => 'decimal',
        'orderedListSuffix' => 'tab',
        'tableBorders' => '1',
        'tableHeaderBold' => '0',
        'tableWidth' => '2500',
        'codeBlockShading' => 1,
        'linkTarget' => '_blank',
        'thematicBreak' => 'border',
        'deferredHyperlinks' => '0',
    ]);

    expect($options->toArray())->toBe([
        'softBreak' => Options::SOFT_BREAK_LINE,
        'hardBreak' => Options::BREAK_LINE,
        'html' => Options::HTML_STRIP,
        'images' => Options::IMAGE_EMBED,
        'imageBasePath' => '/srv/pics',
        'imageMaxWidth' => 12.5,
        'svgFallback' => Options::SVG_FALLBACK_NONE,
        'maxHeadingLevel' => 3,
        'orderedListFormat' => 'decimal',
        'orderedListSuffix' => 'tab',
        'tableBorders' => true,
        'tableHeaderBold' => false,
        'tableWidth' => 2500,
        'codeBlockShading' => true,
        'linkTarget' => '_blank',
        'thematicBreak' => 'border',
        'deferredHyperlinks' => false,
    ]);
});

it('holds no string in any property that is not a string', function () {
    // The general form of the test above: a config file that hands every option
    // over as a string must not leave one of the numbers or the switches as a
    // string, because PHPWord would then be handed a string where it wants a
    // number, and the document would come out wrong rather than raise.
    $given = [];

    foreach (array_keys((new Options())->toArray()) as $property) {
        $given[$property] = $property === 'imageMaxWidth' ? '7.5' : '1';
    }

    $options = Options::fromArray($given);

    foreach (['maxHeadingLevel', 'tableWidth', 'imageMaxWidth', 'tableBorders', 'tableHeaderBold', 'codeBlockShading', 'deferredHyperlinks'] as $numericOrSwitch) {
        expect($options->{$numericOrSwitch})
            ->not->toBeString()
            ->and(get_debug_type($options->{$numericOrSwitch}))
            ->toBe(in_array($numericOrSwitch, ['imageMaxWidth'], true) ? 'float' : (in_array($numericOrSwitch, ['maxHeadingLevel', 'tableWidth'], true) ? 'int' : 'bool'));
    }
});

it('clamps the numbers that have a range', function () {
    expect(Options::fromArray(['tableWidth' => '99999'])->tableWidth)->toBe(5000)
        ->and(Options::fromArray(['tableWidth' => '-4'])->tableWidth)->toBe(0)
        ->and(Options::fromArray(['tableWidth' => 0])->tableWidth)->toBe(0)
        ->and(Options::fromArray(['maxHeadingLevel' => '99'])->maxHeadingLevel)->toBe(6)
        ->and(Options::fromArray(['maxHeadingLevel' => '0'])->maxHeadingLevel)->toBe(1)
        ->and(Options::fromArray(['imageMaxWidth' => '-1'])->imageMaxWidth)->toBe(0.0);
});

it('clamps through the setters too, not only through the array form', function () {
    // The setters go through the same cast, so an out-of-range number handed to
    // one of them is bounded rather than written into the document.
    expect((new Options())->withTableWidth(99999)->tableWidth)->toBe(5000)
        ->and((new Options())->withMaxHeadingLevel(0)->maxHeadingLevel)->toBe(1);
});

it('reads the switches from the spellings a config file uses', function () {
    expect(Options::fromArray(['tableBorders' => '1'])->tableBorders)->toBeTrue()
        ->and(Options::fromArray(['tableBorders' => '0'])->tableBorders)->toBeFalse()
        ->and(Options::fromArray(['tableBorders' => 0])->tableBorders)->toBeFalse()
        ->and(Options::fromArray(['tableBorders' => ''])->tableBorders)->toBeFalse()
        ->and(Options::fromArray(['deferredHyperlinks' => 1])->deferredHyperlinks)->toBeTrue();
});

it('reads an empty base path as "no base path"', function () {
    expect(Options::fromArray(['imageBasePath' => ''])->imageBasePath)->toBeNull()
        ->and(Options::fromArray(['imageBasePath' => 'assets'])->imageBasePath)->toBe('assets');
});

it('treats a key spelled out with no value as unconfigured', function () {
    // A JSON config that says `"tableBorders": null`, or a PHP config array
    // merged over another that set the option, hands over a null. For most of
    // them that is a `TypeError` the caller can see; for `tableWidth` it is
    // worse, because the cast turns the null into 0 — the documented "let Word
    // size it" value rather than the 5000 that was configured, and that is a
    // document that comes out wrong with nothing to show for having asked.
    $defaults = (new Options())->toArray();
    $nullable = nullableForwardOptions();

    foreach (array_keys($defaults) as $property) {
        $configured = Options::fromArray([$property => null]);

        // The properties that accept a null hold it, because a null is what they
        // mean; every other property takes the default. Which ones those are is
        // read off the constructor rather than written out here, so the two
        // cannot drift apart.
        $expected = in_array($property, $nullable, true) ? null : $defaults[$property];

        expect($configured->{$property})->toBe($expected, "options['{$property}'] = null");
    }
});

it('ignores a key it does not know', function () {
    expect(Options::fromArray(['noSuchOption' => true, 'tableWidth' => 1000])->toArray())
        ->toBe(array_replace((new Options())->toArray(), ['tableWidth' => 1000]));
});

/*
 * The two invariants the null handling rests on, neither of which a test of
 * behaviour can hold on its own. Both are read off the constructor with
 * reflection, so an option added to the class without updating the class fails
 * one of them by name rather than by producing a document that is subtly wrong.
 */

it('lists exactly the options whose type accepts a null', function () {
    // The list deciding that is hand-written, so a property added without a line
    // in it is handled wrongly the moment a caller passes a null for it. In this
    // direction the mistake is silent for `tableWidth`, which the cast turns into
    // 0, and a `TypeError` for everything else — neither raises from `withAll()`.
    $listed = (new ReflectionClass(Options::class))->getReflectionConstant('NULLABLE');

    expect($listed)->toBeInstanceOf(
        ReflectionClassConstant::class,
        'Options has to say which of its properties accept a null.',
    );

    $declared = $listed->getValue();
    $nullable = nullableForwardOptions();
    sort($declared);
    sort($nullable);

    expect($declared)->toBe($nullable);
});

it('carries every property forward in toArray', function () {
    // `withAll()` merges over `toArray()`, so an option missing from it is
    // rebuilt from the default on the next merge — the same data loss as a setter
    // that forgets its receiver, one array further along.
    $constructor = array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionClass(Options::class))->getConstructor()->getParameters(),
    );

    expect(array_keys((new Options())->toArray()))->toBe($constructor);
});
