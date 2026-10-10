<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Exception\InvalidInput;
use MarkdownWord\Exception\TemplateNotFound;

/*
 * What is left of the code that was removed for having no caller.
 *
 * Five public methods existed whose only caller in the whole repository was a
 * test written alongside them, so the coverage went up and the library did not
 * grow: `StyleResolver::font()` and `StyleResolver::headingStyle()`,
 * `Reverse\Inline::formatted()`, `Reverse\StyleTable::has()` and
 * `Reverse\Escaping::insideSpan()`. Two of them carried a docblock saying what
 * they were for, and both docblocks were wrong — which is the part that cannot be
 * left to rot, because a wrong docblock is read as a contract by whoever comes
 * next.
 *
 * So what is here is not "the method is gone" — a test that asserts an absence
 * only fails when somebody puts the method back, and says nothing about whether
 * the library still works. It is the other half: the claims that were false are
 * asserted absent from the sources, and the behaviour that outlived the deletion
 * is asserted still to work. When the next method with a dead caller and a
 * confident docblock turns up, both kinds of test apply to it as well.
 */

/**
 * The files holding a phrase, so a claim that has been corrected cannot come
 * back unnoticed.
 *
 * @return list<string>
 */
function sourcesWithClaim(string $claim): array
{
    $found = [];
    $root = dirname(__DIR__, 2);

    // `tests/` and `tools/` as well as `src/`: the policy applies the same rules
    // to all three, and the dividers it banned were mostly in the two that were
    // outside the first pass's scope.
    foreach (['src', 'tests', 'tools'] as $area) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/' . $area, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || !$file->isFile()) {
                continue;
            }

            // This file names every phrase it looks for, so it always matches
            // itself. Scanning for the claims is the exception that proves the
            // rule, not a counterexample to it.
            if ($file->getFilename() === basename(__FILE__)) {
                continue;
            }

            $source = spelledOut((string) file_get_contents($file->getPathname()));

            if (str_contains($source, $claim)) {
                $found[] = $area . '/' . $file->getFilename();
            }
        }
    }

    // `.gitattributes` as well: it justified shipping the lock file with a claim
    // about Composer that was false, and nothing else in the repository reads it.
    $attributes = $root . '/.gitattributes';

    if (str_contains(spelledOut((string) file_get_contents($attributes)), $claim)) {
        $found[] = basename($attributes);
    }

    sort($found);

    return $found;
}

/**
 * A source with its comment markers and line breaks flattened away.
 *
 * A phrase to ban is written here as the claim reads, not as it happens to be
 * wrapped: "inherits only the spacing" is one clause of a sentence that a
 * `//` comment puts on two lines, and matching the raw bytes for it finds
 * nothing — so the guard passes against the very words it was added to forbid.
 * Taking the markers out first and the wrapping out second makes a case
 * survive an editor that rewraps the comment around it.
 */
function spelledOut(string $source): string
{
    $lines = [];

    foreach (preg_split('/\R/', $source) ?: [] as $line) {
        $lines[] = trim((string) preg_replace('~^\s*(?://+|\*+|\#+)\s?~', '', $line));
    }

    return (string) preg_replace('/\s+/', ' ', implode(' ', $lines));
}

it('does not claim again what the code does not do', function (string $claim) {
    // Each of these was a comment asserting something the code does not do. Add
    // the phrase here when you correct one, so it cannot come back.
    expect(sourcesWithClaim($claim))->toBe([]);
})->with([
    'code block shading goes through a paragraph style, not a Font' => 'used for code block shading',
    'the round trip loses what Word does not record' => 'the round trip is exact',
    'a link wrapping a bare image is marked by a flag nothing read' => 'imageLabel',
    'the version is written down in only one place' => 'The one place the version is written down',
    'shipping the lock file makes an install reproducible' => 'a reproducible install is worth',
    'one list of style keys covers every slot' => 'The keys a style slot\'s array form understands',
    'a default heading is body text in an .odt or an .rtf' => 'headings are body text',
    'the built-in look is a no-op because it matches the default' => 'currently re-applies what is already the default',
    'a built-in style id carries an outline level by itself' => 'only the built-in ids carry an outline level',
    "the RTF font table is filled from the section's elements" => 'only walks section-level',
    'a named style keeps its spacing in an .odt' => 'inherits only the spacing',
    'a style name makes a heading a heading' => 'the reason a heading is still a heading',
    'a style name is what makes a .docx heading a Heading 1' => 'heading a `Heading 1` rather than',
    'an .odt root leaves the style and fo prefixes undeclared' => 'but not `style` and `fo`',
    'which YAML implementation reads the block is the machine\'s choice' => 'which one is in play depends on the',
]);

it('has no decorative dividers in it', function () {
    // A rule of dashes above a run of methods says what the method names below it
    // already say, in seventy characters, on every read of the file. The policy bans
    // them; this is what makes that a rule rather than a note.
    //
    // `examples/` is scanned for the same reason `tests/` and `tools/` are: the
    // eleven banners in `build.php` sat outside every directory this test looked
    // at, so they were there the whole time the rule was said to hold.
    $dividers = [];

    foreach (['src', 'tests', 'tools', 'examples'] as $area) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/' . $area, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || !$file->isFile()) {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                if (preg_match('#^\s*//\s*[-=*_]{4,}#', $line) === 1) {
                    $dividers[] = $area . '/' . $file->getFilename() . ':' . ($number + 1) . ' ' . trim($line);
                }
            }
        }
    }

    expect($dividers)->toBe([]);
});

it('imports nothing from the global namespace in a test file', function () {
    // One such line costs the run its coverage report. PCOV discards everything it
    // collected, and PHPUnit then prints no table and exits non-zero with every
    // test passing — which is a red build in CI and nothing at all locally, unless
    // the report is read rather than the exit code.
    //
    // Only the testsuite directories, and that is the whole of the exemption:
    // `src/` and the namespaced files under `tests/Support` are unaffected, and
    // need their imports. A test file has no namespace, so the import resolves to
    // the class the unqualified name already meant.
    $root = dirname(__DIR__, 2);
    $suites = simplexml_load_file($root . '/phpunit.xml.dist');
    $imports = [];

    foreach ($suites->testsuites->testsuite as $suite) {
        foreach ($suite->directory as $directory) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                foreach (file($file->getPathname()) as $number => $line) {
                    if (preg_match('#^use\s+[A-Za-z_][A-Za-z0-9_]*\s*;#', $line) === 1) {
                        $imports[] = $file->getFilename() . ':' . ($number + 1) . ' ' . trim($line);
                    }
                }
            }
        }
    }

    expect($imports)->toBe([]);
});

it('gives a code block its shading as a paragraph property', function () {
    // `StyleResolver::font()` claimed to build the Font that a code block's
    // shading was applied through. It is not: the background is a property of
    // the paragraph, and the type is a property of the run inside it.
    $elements = renderElements("```\nx = 1\n```\n");
    $style = paragraphStyleOf($elements[0]);
    $font = renderRuns($elements[0])[0]['font'];

    expect($style['shading']['fill'] ?? null)->toBe('F2F2F2')
        ->and($font['name'] ?? null)->toBe('Consolas');
});

it('splits a code block style between the paragraph and the runs in it', function () {
    // The same slot read as both halves: a Word paragraph has no character
    // formatting of its own, so a style that kept `name` on the paragraph would
    // have had no effect at all and the run would come out in the default face.
    $config = Configuration::create()->withStyles([
        Styles::CODE_BLOCK => [
            'name' => 'Fira Code',
            'size' => 11,
            'shading' => ['fill' => 'EEEEEE'],
        ],
    ]);

    $elements = renderElements("```\nx = 1\n```\n", $config);
    $style = paragraphStyleOf($elements[0]);
    $font = renderRuns($elements[0])[0]['font'];

    expect($style['shading']['fill'] ?? null)->toBe('EEEEEE')
        ->and($style)->not->toHaveKey('name')
        ->and($font['name'] ?? null)->toBe('Fira Code')
        ->and($font['size'] ?? null)->toBe(11);
});

it('uses the shading a code block style configures when the default is off', function () {
    // The same split from the other end: with the built-in background switched
    // off, the one the caller configured is the one that is there.
    $config = Configuration::create()
        ->withOptions(['codeBlockShading' => false])
        ->withStyles([Styles::CODE_BLOCK => ['shading' => ['fill' => 'EEEEEE']]]);

    $style = paragraphStyleOf(renderElements("```\nx\n```\n", $config)[0]);

    expect($style['shading']['fill'] ?? null)->toBe('EEEEEE');
});

it('clamps a heading level to the six there are styles for', function () {
    // The clamp lives in `Styles::heading()`, which is where a level becomes a
    // slot name; `StyleResolver::headingStyle()` was only a reader of it. There
    // is no seventh heading style, so a level past the sixth wants the deepest
    // rather than nothing.
    $styles = new Styles();

    expect($styles->heading(9))->toBe($styles->get(Styles::HEADING_6))
        ->and($styles->heading(0))->toBe($styles->get(Styles::HEADING_1));
});

it('reports a template that is not there as the caller\'s mistake', function () {
    // A missing template is a file the caller named and can name differently, so
    // it belongs with the other "that is not what the conversion needs" failures
    // and not beside the ones the machine is responsible for.
    expect(new TemplateNotFound('no such template'))
        ->toBeInstanceOf(InvalidInput::class);
});
