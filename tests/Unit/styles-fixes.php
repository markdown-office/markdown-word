<?php

declare(strict_types=1);

use MarkdownWord\Configuration\Styles;
use MarkdownWord\Render\ParagraphStyle;

/*
 * The heading slot.
 *
 * Heading levels reach a style slot through a name, and the name is composed
 * from the level number: `heading.3`. Three call sites compose it by hand rather
 * than asking `Styles` for it — `DocumentRenderer::renderHeading()`,
 * `StyleRegistrar::register()` and `Configuration::withBuiltInHeadingStyles()` —
 * which means the mapping from a level to a slot name is written down four
 * times, and the method that exists to hold it (`Styles::heading()`) is on the
 * side looking in: nothing in `src/` calls it. `StyleResolver::headingStyle()`
 * did call it, and had no caller of its own either, so it is gone and the mapping
 * is now read from nowhere.
 *
 * The call sites are named by method rather than by line number on purpose. A
 * line number in a comment is a claim about the very files this one exists to
 * stop drifting from, and the version of this comment that carried three of them
 * had all three wrong — which is the drift arriving through the guard.
 *
 * The duplication cannot be removed from here: `DocumentRenderer` picks the slot
 * name and hands it to `emitParagraph()`, which then resolves the style and
 * pushes its character half into the runs. Routing it through `Styles::heading()`
 * instead would lose the slot, and with it the ability to recognise the slot
 * again inside `emitParagraph()` — which resolves it twice, once for the
 * paragraph half and once for the character half.
 *
 * What these tests do is make the method the one place the answer is written
 * down, so the three call sites have something to converge on. They pin the two
 * things that would otherwise drift apart quietly:
 *
 *  - the constants, which the renderer and the registrar both use to look a slot
 *    up, and
 *  - the string `heading()` composes from the level number.
 *
 * If either side is renamed without the other, these fail — which is the failure
 * that would otherwise show up as a heading silently rendering unstyled.
 */

it('resolves every heading level to the slot its constant names', function (int $level) {
    $styles = new Styles();
    $constant = 'HEADING_' . $level;

    // The level resolves through the constant the rest of the library uses, and
    // through the name `heading()` composes from the number. A default slot is a
    // definition rather than a bare styleId — that is what the Look & Feel is —
    // so the style id named inside it is what the two sides agree on.
    expect(ParagraphStyle::styleNameOf($styles->get(Styles::{$constant})))->toBe(['styleName' => 'Heading' . $level])
        ->and($styles->heading($level))->toBe($styles->get(Styles::{$constant}));
})->with([1, 2, 3, 4, 5, 6]);

it('clamps a level outside one to six into the range it has slots for', function () {
    $styles = new Styles();

    expect($styles->heading(0))->toBe($styles->get(Styles::HEADING_1))
        ->and($styles->heading(-3))->toBe($styles->get(Styles::HEADING_1))
        ->and($styles->heading(7))->toBe($styles->get(Styles::HEADING_6))
        ->and($styles->heading(99))->toBe($styles->get(Styles::HEADING_6));
});

it('falls back to the paragraph style for a level that was left unstyled', function () {
    // A heading explicitly unstyled should look like body text rather than like a
    // heading with Word's default spacing and size, which is a different thing.
    //
    // The fallback is for an explicit `null` only. The constructor merges the
    // defaults in, so a level nobody mentioned always has a slot: reading "not
    // configured" as "not mentioned" would resolve every heading to the paragraph
    // style under the default configuration, which is to say to no style at all.
    $styles = new Styles([
        Styles::HEADING_1 => 'Title',
        Styles::HEADING_3 => null,
        Styles::PARAGRAPH => 'Body',
    ]);

    expect($styles->heading(3))->toBe('Body')
        // The levels that were given a value keep it, mentioned or not.
        ->and($styles->heading(1))->toBe('Title')
        ->and($styles->heading(2))->toBe($styles->get(Styles::HEADING_2))
        ->and($styles->heading(6))->toBe($styles->get(Styles::HEADING_6));
});

it('reports no style at all when neither the level nor the paragraph has one', function () {
    $styles = new Styles([Styles::HEADING_4 => null, Styles::PARAGRAPH => null]);

    expect($styles->heading(4))->toBeNull()
        ->and($styles->heading(1))->toBe($styles->get(Styles::HEADING_1));
});

it('finds a heading level that was overridden after the defaults', function () {
    $styles = (new Styles())->with(Styles::HEADING_2, 'Corp Section');

    expect($styles->heading(2))->toBe('Corp Section')
        ->and($styles->heading(1))->toBe($styles->get(Styles::HEADING_1))
        ->and($styles->heading(3))->toBe($styles->get(Styles::HEADING_3));
});

it('reads an inline heading style as readily as a style id', function () {
    // A slot is either an id or an inline definition, and `heading()` must not
    // quietly collapse the second into the first — the renderer reads the size
    // and weight back out of it.
    $styles = new Styles([Styles::HEADING_2 => ['size' => 20, 'bold' => true]]);

    expect($styles->heading(2))->toBe(['size' => 20, 'bold' => true]);
});

it('keeps the slots a heading lookup is built from', function () {
    // `heading()` is a `get()` against a composed name, so the composed name and
    // the constants describing it have to agree. If a constant is renamed and the
    // composition is not, the renderer asks for a slot nothing points at any more,
    // and headings come out with Word's default styling rather than an error.
    $slots = (new Styles())->toArray();

    foreach ([1, 2, 3, 4, 5, 6] as $level) {
        $constant = 'HEADING_' . $level;

        expect(Styles::{$constant})->toBe('heading.' . $level)
            ->and($slots)->toHaveKey(Styles::{$constant});
    }
});

it('keeps the other slots when one heading level is changed', function () {
    $styles = (new Styles())->with(Styles::HEADING_1, 'Title');
    $defaults = (new Styles())->toArray();

    // Set every other property away from its default first would be the usual
    // advice; here the default *is* the thing under test, so each untouched slot
    // is compared with a fresh instance rather than with a literal.
    expect($styles->get(Styles::HEADING_2))->toBe($defaults[Styles::HEADING_2])
        ->and($styles->get(Styles::CODE_FONT))->toBe(['name' => 'Consolas', 'size' => 9, 'color' => 'A31515'])
        ->and($styles->get(Styles::BLOCK_QUOTE))->toBe($defaults[Styles::BLOCK_QUOTE]);
});

it('merges withAll over the current slots', function () {
    $styles = (new Styles())->withAll([
        Styles::HEADING_1 => 'Title',
        Styles::CODE_FONT => ['name' => 'Fira Code'],
    ]);

    expect($styles->get(Styles::HEADING_1))->toBe('Title')
        ->and($styles->get(Styles::CODE_FONT))->toBe(['name' => 'Fira Code'])
        ->and($styles->get(Styles::HEADING_6))->toBe((new Styles())->get(Styles::HEADING_6));
});

it('leaves the receiver of a slot change alone', function () {
    $styles = new Styles();
    $before = $styles->toArray();

    $styles->with(Styles::HEADING_1, 'Title');
    $styles->withAll([Styles::HEADING_2 => 'Section']);

    expect($styles->toArray())->toBe($before)
        ->and($styles->get(Styles::HEADING_1))->toBe($before[Styles::HEADING_1]);
});

it('round trips through the array form', function () {
    $styles = new Styles([Styles::HEADING_1 => 'Title', Styles::CODE_FONT => ['name' => 'Fira Code']]);

    expect(new Styles($styles->toArray())->toArray())->toBe($styles->toArray());
});
