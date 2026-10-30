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

    // The two documents as well, and for the sharper reason: a false claim about
    // what reaches the document is one a user reads and acts on, and the format
    // claims are only checkable by rendering.
    foreach (['README.md', 'CHANGELOG.md'] as $document) {
        if (str_contains(spelledOut((string) file_get_contents($root . '/' . $document)), $claim)) {
            $found[] = $document;
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
    'a document with its run properties out of order is one Word refuses to open' => 'run properties are out of that order is one Word refuses to open',
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
    'a value list can be read off the upstream class at run time' => 'Read off the class rather than copied out',
    'an inline style definition replaces the built-in look' => 'it replaces, it does not merge with',
    'a theme file could exfiltrate through document metadata' => 'carried would be copied into `docProps/core.xml`',
    'a path a document wrote was checked the same way an image path is' => 'the same question an image path asks',
    'a theme the caller never overrode silently replaced an earlier one' => 'so the second theme is applied',
    'a template with no content control is refused' => 'refused rather than filled at the end',
    'an unquoted colour is an integer the loader has to turn back into a string' => '000000 arrives as `0`',
    // True of the .docx writer and false of the .odt one, which writes the name
    // as the `text:list-style` its lists refer to. Stated once as the .docx
    // writer's behaviour it was read as a claim about both formats.
    'the numbering style name is dropped on its way into the document' => 'the name does not reach the',
    // The same error in the wording the source used, one commit after the two
    // documents above were corrected for it: an .odt carries the name in both
    // `content.xml` and `styles.xml`, so "reaches PHPWord but not the document"
    // described the .docx case as the only one.
    'the numbering style name reaches PHPWord and stops there' => 'The name reaches PHPWord but not the document',
    // `written()` was contrasted against a method that has never been on the
    // class — `git log -S 'function explicit'` over `Styles.php` is empty — so
    // the reference sent a reader looking for an API to compare against.
    'the slots that were written are readable apart from the state' => '{@see self::explicit()}',
    // `move()` is on `Staging`; `DocxWriter` has no such method and no parent
    // that has one. The claim that the template's move copies the writer's held.
    'the template move is a copy of the writer move' => '{@see \MarkdownWord\Writer\DocxWriter::move()}',
    // A `.dotx` is a package a Word document can be rendered into, and the claims
    // below each said otherwise in a way a reader would act on: the README showed a
    // document naming one and the CLI called the flag `.docx` only. A `.docx` template
    // renders into a `.docx`, so "a template is a .docx" read as excluding the other.
    'a template is a .docx' => 'A template is a `.docx`.',
    // `WordPackage::mainPartOf()`'s docblock named "the command line checking that a
    // region is in a template" as its caller. No such call existed — not in the command
    // line, which was removed, and not under `src/`, which never had one — so the
    // comment described a caller that existed nowhere, and the method has none in this
    // repository at all. Read against the rule that a method with no caller outside
    // `tests/` is dead, that is an invitation to delete working public API.
    //
    // Guarded narrowly, on the whole phrase rather than on "the command line":
    // `ConfigurationMerger`, `Loss`, `Input` and others use that phrase correctly, for
    // the override layer as a concept rather than for a program.
    'mainPartOf names a caller that exists nowhere' => 'checking that a region is in a template',
    'the template flag takes a .docx only' => '`--template` is `.docx` only',
    // `template_file` was read into the block and used by nothing at all, so this
    // described a key that no renderer consulted as one somebody did.
    'template_file is read by whoever renders' => 'used by whoever renders',
    // `asDocument()` rewrote the main part to `template.main+xml`, which is what
    // makes a template — the opposite of what its name said, and the opposite of
    // what its one caller wanted. It built a `.docx` that Word opens by spawning a
    // new document, and a `.dotx` that it opens as an ordinary file.
    'the docx-to-template conversion is named for a document' => 'function asDocument(',
    // Four docblocks on this branch described something other than what the code does,
    // and three of them were read as a specification and would have been wrong about it.

    // `CommonMarkParser`'s constructor docblock still said `null` selects GFM after the
    // default flavour became `directives`, so a caller reading it would set an explicit
    // list and believe they had changed nothing.
    'a null parser extension list still means gfm' => '`null` selects the default, GFM',
    // `FurnitureWriter` took a `Furniture $template` nothing ever passed, which made the
    // README's "frontmatter wins over a .dotx per key" look implemented. It was not: a
    // template's header is its own part, and nothing merges into it.
    'a template header is merged with the document\'s own' => 'What a template already has in this slot',
    // `Furniture::NULLABLE` said it kept "the same list" as `Options` for "the same
    // reason", and it does neither — it is empty where `Options` has two names, which is
    // a different decision and one a reader would have assumed was the same one.
    // A narrower form of the same mistake: `Options` points at `Reverse\Options` and that
    // pair really does keep one list between them, so the phrase is only a claim when
    // the other class does not have the property at all — which is what happened when
    // `Furniture` copied the wording onto a class with an empty list.
    'the two NULLABLE lists are the same list' => 'keeps the same list for the same reason, and that list is',
    // A `readlink()` on a target that usually does not exist. The `@` silenced it in
    // production but a test runner's handler is called for suppressed diagnostics too,
    // so every `.odt` and `.rtf` conversion test failed on it.
    'a symlink target is read before it is known to be there' => '@readlink($path)',
    // A heading style is named `Heading1`, which is a style *called* Heading1 and not
    // the built-in `heading 1`. Nothing visible is wrong with it; a table of contents
    // is the one thing that notices, and says so in German rather than in English.
    'a heading style is the built-in heading style' => 'so `Heading1` reaches a reader',
    // The outline level was written as the cure for a table of contents that stayed
    // empty once the name was fixed, and LibreOffice reads the level rather than the
    // name, so it was not a guess. Word does not collect by it at all: with the names
    // right and no levels, Word builds the contents with page numbers.
    'a TOC needs the outline level, not only the style name' => 'still collects nothing',
    // `Survey::isVectorOnly()` said it was "not a `self::FEATURES` key", and `Survey`
    // has no `FEATURES` constant — the list is `Format::FEATURES`, and it is private
    // there. A `{@see}` to a symbol that does not exist is a contract a reader cannot
    // follow and nothing checks, which is why the phrase is pinned rather than only
    // corrected. `Footnote`'s docblock made the same kind of claim about PHPWord, and
    // reading only the check it named is what let a footnote in a running head raise.
    'a `{@see}` can name a constant the class does not have' => '{@see self::FEATURES}',
    // The test read as a description of an implementation that no longer exists. A
    // vector is paired with its picture by the picture's position among the
    // document's pictures — PHPWord names media parts as it writes them, so the
    // bytes say nothing about which element became the part — and the attachment
    // the test built still carried the `fallback` key that byte-matching wanted.
    'a vector is attached by matching bytes' => 'The vector is attached by matching bytes in the archive',
    // A claim on the Command line page that nothing in this repository can check,
    // because it is about another repository's releases: `mdoffice` has no tag and no
    // release, so nothing of it ships as a PHAR or as a self-contained executable.
    'a command line with no release still ships as one' => 'ships as a PHAR and as a self-contained',
    // PHP's `rename()` crosses a filesystem: it copies the file, gives the copy the
    // source's owner and mode, and unlinks the source. A move onto another device
    // therefore succeeds and leaves a document with the staged file's permissions,
    // which is what a runner whose `/tmp` is a tmpfs does to every conversion.
    'a rename cannot cross a filesystem' => '`rename()` cannot cross',
    'a rename that fails across filesystems' => 'a `rename()` that fails because the two paths are on different filesystems',
    'two paths on two filesystems cannot be renamed between' => 'Two paths on different filesystems cannot be renamed between',
    // A link is followed whether its target is there or not: `is_link()` is true for
    // a dangling one, and `readlink()` names the target all the same.
    'a link is only followed when its target is there' => 'needs its target to exist',
    // Two claims about where a theme is looked for, both of which this change made
    // false. A theme's name is resolved beside the document now, so a bare name lands
    // on the document's own directory rather than on the working directory's, and a
    // relative path is written from beside the document as well — which is what a
    // `template_file:` and a relative image path have always done here. The template's
    // own docblock said the theme differed from it in exactly that, and it no longer
    // does.
    'a bare name lands on the working directory itself' => 'lands on the working directory itself',
    'the theme differs from the template in where a relative name is written from' => 'The difference from a theme is where a relative name is written from',
    // Six claims about what a theme *is*, all of which this change made false: a theme
    // is its `theme.yml`, and the `styles.yml` beside it, so a theme may be named by the
    // file rather than by the directory around it. Two messages said a theme is a
    // directory, the page said the same in a longer sentence, and three docblocks said
    // it in their own words.
    'a theme is a directory and nothing else' => 'A theme is a directory holding that file',
    'a theme is a directory and nothing else, on this machine' => 'A theme is a directory on this machine, not something to fetch',
    'a theme is a directory and nothing else, on the page' => 'A theme is a directory holding a `theme.yml` and, usually, a `styles.yml`',
    'a theme is a directory and nothing else, in the loader' => 'Reads a theme out of a directory.',
    'a theme is a directory and nothing else, in the theme' => 'A theme as a directory holds it',
    'a theme is a directory and nothing else, in the failure' => 'A theme directory does not describe a theme',
    // PHPWord measures an image in pixels, and its RTF and ODF writers read the
    // number back as pixels. Only its Word writer labels the number `pt`, so a
    // picture of 96 pixels came to be laid out 96 points wide — a third too large.
    // Four places said the library's own convention was points, and each of them
    // was read as the reason a picture was the size it was.
    'the library measures a picture\'s pixels as points' => 'PHPWord measures an image\'s pixels as points',
    'the library reads a picture\'s pixels as points' => 'PHPWord reads an image\'s pixels as points',
    'the library sizes a picture in points' => 'PHPWord sizes a picture in points',
    'the library sizes pictures in points' => 'PHPWord sizes pictures in points',
    // The photograph in the image example was 1600 pixels wide and the page said
    // 1600 points, which is what made the cap look like it was not being applied.
    'the photograph is 1600pt wide' => '1600pt wide',
    // The cap is a pixel count, and the docblock that says so began by calling it
    // points — a leftover from the change that moved the cap out of points.
    'the picture cap is measured in points' => 'Points, and a bare number.',
    // The docblock of the feature list said a feature nobody asks about is "reported
    // for every document", which is the opposite of what happens: a feature the survey
    // is never asked about is never reported at all.
    'a feature nobody asks about is reported for every document' => 'a feature nobody asks about is reported for every',
    // The finder interface claimed to be asked for Markdown, which this half never
    // does, and claimed that every file it answers is staged into a file of its own.
    // A theme's YAML is parsed from the bytes it was given; only a picture and a
    // template are staged.
    'a finder is asked for Markdown as well as for YAML and a picture' => 'What the library wants for YAML, for Markdown and for a picture',
    'every file a finder answers is staged into a file of its own' => 'the library stages them into a file of their own',
    // The theme's directory is not canonical for a theme read from a source that
    // holds the bytes and no path: the theme's name stands in for it.
    'a theme read from a finder has a canonical directory' => 'The canonical directory this was read from',
    // A theme named in code answers for the document, and the frontmatter's `theme:`
    // is not read. The docblock said the opposite, and the other half of this suite
    // points at this one for the rule.
    'a document may name a theme of its own over one named in code' => 'unless a document names one itself',
    // A picture comes from the caller's source before it comes from `imageBasePath`,
    // and the docblock left the source out of it.
    'a picture is resolved against the base path before the source' => 'a picture is resolved against `imageBasePath` or the working directory, and that is what this library',
    // A finder is the default for the themes and for nothing else: a picture falls
    // back to `imageBasePath` and a template to the document's own directory.
    'a finder is the default for every file a document names' => '`Files\\DiskFinder` is the default',
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
