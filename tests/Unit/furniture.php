<?php

declare(strict_types=1);

use MarkdownWord\Configuration\Furniture;
use MarkdownWord\Document\Frontmatter;

/*
 * What a header or footer configuration has to promise.
 *
 * `Furniture` is read twice over the life of a conversion: once as the whole block out
 * of a frontmatter, and once more as an override merged over whatever a template
 * supplied. The second reading is the one with a contract behind it — `header:` beneath
 * a document whose template carries `align: right` has to change the body and leave the
 * alignment alone — and it is the easier one to get wrong invisibly, because a merge
 * that rebuilt the object from the given array alone would return exactly what was
 * written and lose a slot the author never mentioned.
 *
 * So the assertions compare whole arrays rather than the one property under test. A
 * `withAll()` that reset a slot to its default fails there for whichever slot it reset,
 * and one that passed by reproducing the defaults cannot.
 *
 * `isEmpty()` is the other one that has to be exactly right, because it decides whether
 * a Word header part is written at all. `false` and `''` are values — a page number
 * switched off, a body with only a border under it — and both tests below are the only
 * ones that can tell `!== null` from `empty()`.
 */
it('has nothing in it until something is configured', function () {
    expect((new Furniture())->isEmpty())->toBeTrue()
        ->and(Furniture::fromArray([])->isEmpty())->toBeTrue()
        // A key written with nothing under it is not configured, which is what the
        // default says too, so it lands in the same place.
        ->and(Furniture::fromArray(['align' => null, 'page' => null])->isEmpty())->toBeTrue()
        // And a key this class does not know is dropped rather than refused, so a
        // template written for another version still reads.
        ->and(Furniture::fromArray(['noSuchKey' => 'x'])->isEmpty())->toBeTrue();
});

it('carries every property through the array form', function (string $property, mixed $value) {
    $defaults = Furniture::fromArray([])->toArray();
    $furniture = Furniture::fromArray([$property => $value]);

    expect($furniture->{$property})->toBe($value)
        ->and($furniture->toArray())->toBe(array_replace($defaults, [$property => $value]))
        ->and(Furniture::fromArray($furniture->toArray())->toArray())->toBe($furniture->toArray());
})->with([
    'a body' => ['body', "Acme Ltd\n42 Example Street"],
    'an alignment' => ['align', 'right'],
    'a border' => ['border', 'both'],
    'a date format' => ['dateFormat', 'd MMMM yyyy'],
    'a chapter switch' => ['chapter', true],
    'a page switch' => ['page', true],
    'a total switch' => ['total', true],
    'a filename switch' => ['filename', true],
    'a date' => ['date', true],
        'a different first page' => ['firstPage', true],
        'a separate even page' => ['even', true],
]);

it('keeps what a merge was not asked to change', function () {
    // The reported shape: a template header carrying an alignment and two page
    // numbers, and a document that overrides one slot of it. Reading the override as
    // the whole block loses two page numbers and an alignment, and says nothing about
    // having done it — so the comparison is of the whole array.
    $template = Furniture::fromArray(['align' => 'right', 'page' => true, 'total' => true]);
    $overridden = $template->withAll(['body' => 'Acme Ltd']);

    expect($overridden->toArray())->toBe([
        'body' => 'Acme Ltd',
        'align' => 'right',
        'border' => null,
        'dateFormat' => null,
        'chapter' => null,
        'page' => true,
        'total' => true,
        'filename' => null,
        'date' => null,
        'firstPage' => null,
        'even' => null,
    ]);
});

it('applies the keys a merge was given', function () {
    $template = Furniture::fromArray(['align' => 'right', 'page' => true, 'body' => 'Acme Ltd']);
    $overridden = $template->withAll(['align' => 'center', 'page' => false]);

    expect($overridden->toArray())->toBe(array_replace($template->toArray(), [
        'align' => 'center',
        'page' => false,
    ]));
});

it('reads a key that arrives with no value as not mentioned', function (string $property, mixed $value) {
    // A null is the shape an override array takes when a key is present with nothing
    // for it, which is what `+` and a JSON config with explicit nulls both produce.
    // Reading it as "reset to the default" would change a slot the caller never named.
    $base = Furniture::fromArray([$property => $value]);

    expect($base->withAll([$property => null])->toArray())->toBe($base->toArray());
})->with([
    'a body' => ['body', 'Acme Ltd'],
    'an alignment' => ['align', 'center'],
    'a border' => ['border', 'bottom'],
    'a switch' => ['page', true],
    'a switch turned off' => ['chapter', false],
]);

it('keeps a switch a document switches off', function () {
    // The other half of the merge: a template turns page numbers on and the document
    // turns them off. `false` is what it wrote, so it is applied — the case a merge
    // that filtered falsy values out would silently reverse.
    $template = Furniture::fromArray(['page' => true, 'total' => true]);
    $overridden = $template->withAll(['page' => false]);

    expect($overridden->page)->toBeFalse()
        ->and($overridden->toArray())->toBe(array_replace($template->toArray(), ['page' => false]));
});

it('is not empty as soon as one property has a value', function (string $property, mixed $value) {
    expect(Furniture::fromArray([$property => $value])->isEmpty())->toBeFalse();
})->with([
    'a body' => ['body', 'Acme Ltd'],
    'an alignment' => ['align', 'right'],
    'a border' => ['border', 'both'],
    'a date format' => ['dateFormat', 'd MMMM yyyy'],
    'a switch on' => ['page', true],
    'a switch off' => ['chapter', false],
    'a date' => ['date', true],
        'a different first page' => ['firstPage', true],
        'a separate even page' => ['even', true],
    'a body with nothing in it' => ['body', ''],
]);

it('reads a switch the way YAML and people spell one', function () {
    // `(bool) "no"` is `true`, so a plain cast writes a page number into a header that
    // asked for none. These are the spellings `validate()` accepts as well, which is the
    // half that has to agree with it.
    expect(Furniture::fromArray(['page' => 'no'])->page)->toBeFalse()
        ->and(Furniture::fromArray(['chapter' => 'false'])->chapter)->toBeFalse()
        ->and(Furniture::fromArray(['total' => 'off'])->total)->toBeFalse()
        ->and(Furniture::fromArray(['page' => 'yes'])->page)->toBeTrue()
        ->and(Furniture::fromArray(['filename' => 'on'])->filename)->toBeTrue()
        ->and(Furniture::fromArray(['page' => 1])->page)->toBeTrue();
});

it('says what a block got wrong', function () {
    $problems = Furniture::validate([
        'align' => 'diagonally',
        'body' => 3,
        'page' => 'maybe',
    ]);

    expect($problems)->toHaveCount(3)
        ->and($problems[0])->toContain('"align"')
        ->toContain('"diagonally"')
        ->toContain('It is one of: center, left, right.')
        ->and($problems[1])->toContain('"body"')
        ->toContain('"3"')
        ->toContain('Markdown')
        ->and($problems[2])->toContain('"page"')
        ->toContain('It is true or false.');
});

it('names a key that is not one of its own', function () {
    // Reported rather than dropped, because the block is written by a hand or by a
    // language model and a mis-indented key looks exactly like one that was never meant
    // to be there. A near miss is suggested, since that is the mistake anyone makes once
    // and cannot see from the error alone.
    // Written out rather than read from the class, so a property added to `Furniture`
    // has to be added here too. This is that test: a new key with a stale list would
    // report a key the reader has legitimately written as one that does not exist.
    $keys = 'align, body, border, chapter, date, dateFormat, even, filename, firstPage, page, total.';

    expect(Furniture::validate(['totl' => true]))
        ->toBe([\sprintf('In "header or footer": Unknown "totl". Did you mean "total"? Known keys: %s', $keys)])
        // Far enough from every key to be something else, so none is suggested: a wrong
        // suggestion sends the reader to the wrong place to look.
        ->and(Furniture::validate(['alignement' => 'center']))
        ->toBe([\sprintf('In "header or footer": Unknown "alignement". Known keys: %s', $keys)]);
});

it('says where a wrong value was written', function () {
    // The block is named because a reader with two of them — a head and a foot —
    // otherwise has to work out which one a wrong value is in.
    $lines = ['align' => 7];

    expect(Furniture::validate(
        ['align' => 'nope'],
        static fn (string $path): ?int => $lines[$path] ?? null,
        'footer',
    ))->toBe(['Line 7: In "footer": the "align" key is "nope". It is one of: center, left, right.']);
});

it('accepts a block it can use', function () {
    expect(Furniture::validate([
        'body' => "Acme Ltd\n42 Example Street",
        'align' => 'center',
        'border' => 'bottom',
        'dateFormat' => 'd MMMM yyyy',
        'chapter' => true,
        'page' => true,
        'total' => true,
        'filename' => true,
        'date' => true,
    ]))->toBe([])
        // A null is not configured rather than wrong, and `dateFormat` on its own is a
        // partial override of a date a template already asked for — not a mistake.
        ->and(Furniture::validate(['align' => null]))->toBe([])
        ->and(Furniture::validate(['dateFormat' => 'd MMMM']))->toBe([]);
});

it('reads the header and the footer a document describes', function () {
    $frontmatter = Frontmatter::of([
        'title' => 'Report',
        'header' => ['body' => 'Acme Ltd', 'align' => 'right'],
        'footer' => ['page' => true],
    ]);

    expect($frontmatter->header()->body)->toBe('Acme Ltd')
        ->and($frontmatter->header()->align)->toBe('right')
        ->and($frontmatter->header()->page)->toBeNull()
        ->and($frontmatter->footer()->page)->toBeTrue()
        ->and($frontmatter->footer()->body)->toBeNull()
        ->and($frontmatter->header()->isEmpty())->toBeFalse()
        ->and($frontmatter->footer()->isEmpty())->toBeFalse();
});

it('has no header or footer for a document that names neither', function () {
    // Empty rather than null, so a caller can ask `isEmpty()` and write no header part
    // at all instead of having to ask the block twice.
    $frontmatter = Frontmatter::of(['title' => 'Report']);

    expect($frontmatter->header()->isEmpty())->toBeTrue()
        ->and($frontmatter->footer()->isEmpty())->toBeTrue()
        ->and(Frontmatter::none()->header()->isEmpty())->toBeTrue()
        ->and(Frontmatter::none()->footer()->isEmpty())->toBeTrue();
});

it('reads a header that is not a block as nothing at all', function () {
    // `getArray()` drops it whole rather than coercing: YAML makes `header: 3` and
    // `header:\n  align: right` the same syntax, and taking the first as the second
    // would mean a document's contents deciding what they configure.
    expect(Frontmatter::of(['header' => 3])->header()->isEmpty())->toBeTrue()
        ->and(Frontmatter::of(['footer' => 'yes please'])->footer()->isEmpty())->toBeTrue();
});