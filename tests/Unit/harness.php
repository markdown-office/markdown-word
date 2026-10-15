<?php

declare(strict_types=1);

use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

/*
 * The test harness itself.
 *
 * Nothing here is about the library; it is about the things about the harness
 * that fail silently rather than loudly, so a regression shows up as a test
 * rather than as a slowly filling disk or a vanished file.
 */

/*
 * The suite-wide clean-up has to be registered through `pest()`.
 *
 * In Pest 5 a bare `afterEach()` in tests/Pest.php binds to that file rather than
 * to the suite. Nothing complains: the tests all pass, the temporary files are
 * all written, and they are simply never removed. Reading the source is the only
 * way to notice, so the rule is pinned here.
 *
 * Only the negative half is asserted. A test that greps its own bootstrap for the
 * literal text `pest()->afterEach(` fails the moment anybody reformats the line
 * or writes it as `pest()->afterEach (` — a suite broken by whitespace, over
 * something that still works. What matters is that a hook not reached through
 * `pest()` is the defect, and it has several spellings, so all of them are
 * excluded.
 */
it('registers the scratch clean-up through the suite rather than to the bootstrap file', function () {
    $bootstrap = (string) file_get_contents(dirname(__DIR__) . '/Pest.php');

    // Comments are dropped first, since the bootstrap explains this very rule in
    // prose and naming the call there must not count as calling it.
    $code = '';
    foreach (token_get_all($bootstrap) as $token) {
        if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    // `afterEach(`, `afterEach (`, `Pest\afterEach()` and a namespaced
    // `\Pest\afterEach(`: everything that would bind the hook to the file rather
    // than to the suite. A call reached through `->` is left alone, which is the
    // form the rule requires.
    expect($code)->not->toMatch('/(?<![>\\\\\\w])afterEach\s*\(/');
});

it('empties the directory the suite owns', function () {
    $document = Scratch::path('harness');
    $media = Scratch::directory() . '/assets';
    mkdir($media);
    file_put_contents($media . '/logo.png', 'not really a png');

    Scratch::cleanUp();

    expect(is_file($document))->toBeFalse();
    // A media directory and everything in it: a test cannot know every file it
    // caused to be written, so the whole directory goes rather than what was
    // remembered.
    expect(is_dir($media))->toBeFalse();
    expect(glob(Scratch::directory() . '/*'))->toBe([]);
});

it('leaves the output of a check running beside it alone', function () {
    // `tmp` is shared: the checks at the root of the repository write their own
    // directories into it, and `composer check` runs them in this tree. A suite
    // that emptied `tmp` would take their output with it, which is a real way to
    // lose the evidence of a passing check.
    $beside = dirname(Scratch::directory()) . '/harness-marker-' . bin2hex(random_bytes(6));

    file_put_contents($beside, 'written by a check that is not the test suite');

    try {
        Scratch::cleanUp();

        expect(is_file($beside))->toBeTrue();
    } finally {
        @unlink($beside);
    }
});

/*
 * The canonical way to ask "is this document openable".
 *
 * A `.docx` Word will not open is the failure several tests exist for, and the
 * question was being answered in several files, which had drifted apart — one of
 * them lost the previous `libxml_use_internal_errors` setting on the way past.
 * `TemplateFactory::xmlIsValid()` is the answer, and this is that it works both
 * ways.
 *
 * It is not yet the only one: `raw-html.php`, `escaping.php` and `docx-output.php`
 * each still parse the document XML themselves. Consolidating them is a change to
 * those files rather than to this one.
 */
it('tells a document with a body that parses from one that does not', function () {
    $document = Scratch::path('openable');
    saveDocument('# One', $document);

    expect(TemplateFactory::xmlIsValid($document))->toBeTrue();

    $zip = new ZipArchive();
    $zip->open($document);
    $zip->deleteName('word/document.xml');
    $zip->addFromString('word/document.xml', '<w:document><this is not xml');
    $zip->close();

    expect(TemplateFactory::xmlIsValid($document))->toBeFalse();
});

/*
 * One defect in one dependency, filtered by one filter.
 *
 * The filter is the one in tests/Support/Upstream and nowhere else, because there
 * is nothing here for a second copy to drift from. What has to hold is that it is
 * really in place — a helper that silently did not install would leave every test
 * passing and every document-writing test marked deprecated — and that it swallows
 * the one known defect and nothing else.
 */
it('filters the known upstream deprecation and nothing else', function () {
    Upstream::install();

    try {
        // Whatever sits on the stack above the runner's own handler is the filter,
        // and `set_error_handler()` hands it back. It has to be the closure
        // `Upstream` declared: anything else on top means something installed a
        // second handler over it, which is what the copy this replaced used to do.
        $onTop = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        expect($onTop)->toBeInstanceOf(Closure::class)
            ->and((new ReflectionFunction($onTop))->getFileName())
            ->toBe((new ReflectionClass(Upstream::class))->getFileName());

        // And it answers for the one known defect, and for nothing else: the
        // message from that file, the message from anywhere else, and the same
        // message at a severity that is not a deprecation.
        $style = dirname(__DIR__, 2) . '/vendor/phpoffice/phpword/src/PhpWord/Style.php';
        $known = 'Using null as an array offset';

        expect($onTop(E_DEPRECATED, $known, $style))->toBeTrue()
            ->and($onTop(E_DEPRECATED, $known, __FILE__))->toBeFalse()
            ->and($onTop(E_DEPRECATED, 'A defect nobody has heard of', $style))->toBeFalse()
            ->and($onTop(E_WARNING, $known, $style))->toBeFalse();
    } finally {
        Upstream::restore();
    }
});
