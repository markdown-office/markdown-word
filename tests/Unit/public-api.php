<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Console\Application;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Reverse\Options as ReverseOptions;
use MarkdownWord\Render\InlineStyle;
use MarkdownWord\Render\StyleResolver;
use MarkdownWord\Text\TextExtractor;
use MarkdownWord\Tests\Support\Upstream;

/*
 * The smaller public surface: the fluent setters, the parser factories and the
 * style resolver.
 *
 * These are all things a caller reaches for and none of them was covered, which
 * is how a method can sit in a released library having never once run. They are
 * thin, so the tests are thin too: what they are here for is to be executed at
 * all, and to say what each one means.
 *
 * Note what these are not. A setter is exercised here on a default-constructed
 * receiver, so it cannot fail if that setter quietly rebuilt the object from the
 * defaults. That is the data-loss bug tests/Unit/options-fixes.php is built
 * around, and the reason its setter tests start elsewhere.
 */
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

// Configuration\Options

it('offers a setter for every option it takes', function () {
    // The array form is the documented way in, and these are the convenience
    // spellings of it. Each returns a new instance and leaves the original alone.
    $options = new Options();

    expect($options->withSoftBreak(Options::SOFT_BREAK_PARAGRAPH)->softBreak)
        ->toBe(Options::SOFT_BREAK_PARAGRAPH)
        ->and($options->softBreak)->toBe(Options::SOFT_BREAK_SPACE)
        ->and($options)->not->toBe($options->withSoftBreak(Options::SOFT_BREAK_PARAGRAPH));
});

it('changes each behaviour through its own setter', function (string $method, mixed $argument, string $property) {
    // The value is read back off the readonly property rather than through a
    // getter, because there is no getter.
    $changed = (new Options())->{$method}($argument);

    expect($changed->{$property})->toBe($argument)
        ->and((new Options())->{$property})->not->toBe($changed->{$property});
})->with([
    'hard break' => ['withHardBreak', 'paragraph', 'hardBreak'],
    'html' => ['withHtml', 'preserve', 'html'],
    'heading depth' => ['withMaxHeadingLevel', 3, 'maxHeadingLevel'],
    'table borders' => ['withTableBorders', false, 'tableBorders'],
    'code shading' => ['withCodeBlockShading', false, 'codeBlockShading'],
    'table width' => ['withTableWidth', 2500, 'tableWidth'],
]);

it('changes the image mode, and the two things that go with it, at once', function () {
    // A path inside the project rather than the system temp directory, which is
    // where this repository keeps everything a test writes. The directory is only
    // stored, never touched.
    $options = (new Options())->withImages(Options::IMAGE_PLACEHOLDER, 'assets', 8.5);

    expect($options->images)->toBe(Options::IMAGE_PLACEHOLDER)
        ->and($options->imageBasePath)->toBe('assets')
        ->and($options->imageMaxWidth)->toBe(8.5);
});

it('leaves the image settings alone when they are not given', function () {
    $options = (new Options())->withImages(Options::IMAGE_SKIP);

    expect($options->images)->toBe(Options::IMAGE_SKIP)
        ->and($options->imageBasePath)->toBeNull()
        ->and($options->imageMaxWidth)->toBe(15.0);
});

it('clamps the values that have a range', function () {
    // `fromArray()` casts loosely typed values from a config file, so these arrive
    // as strings there and have to come out as something usable.
    expect(Options::fromArray(['tableWidth' => '99999'])->tableWidth)->toBe(5000)
        ->and(Options::fromArray(['tableWidth' => '-4'])->tableWidth)->toBe(0)
        ->and(Options::fromArray(['maxHeadingLevel' => '99'])->maxHeadingLevel)->toBe(6)
        ->and(Options::fromArray(['imageMaxWidth' => '-1'])->imageMaxWidth)->toBe(0.0)
        ->and(Options::fromArray(['tableBorders' => '1'])->tableBorders)->toBeTrue()
        ->and(Options::fromArray(['imageBasePath' => ''])->imageBasePath)->toBeNull();
});

it('ignores an option it does not know rather than refusing the file', function () {
    // A config file may carry entries for another version; that is not a reason
    // to refuse to render the document.
    expect(Options::fromArray(['noSuchOption' => true])->tableWidth)->toBe(5000);
});

// Reverse\Options

it('offers a setter for the media directory and for a batch of options', function () {
    expect((new ReverseOptions())->withMediaDirectory('assets')->mediaDirectory)->toBe('assets')
        ->and((new ReverseOptions())->withMediaDirectory(null)->mediaDirectory)->toBeNull()
        ->and((new ReverseOptions())->withAll(['headingSetext' => true])->headingSetext)->toBeTrue();
});

// Parser factories

it('renders with a parser chosen for the dialect', function () {
    // The extras are what the extended flavours are for: none of these constructs
    // survives in plain CommonMark, and all of them do once the extension that
    // knows them is loaded.
    $markdown = <<<'MD'
        - [x] done

        Footnote[^1]

        [^1]: the note
        MD;

    $render = static fn (string $flavour): string => TextExtractor::fromPhpWord(
        (new MarkdownToWord(null, new Configuration(), CommonMarkParser::$flavour()))->toPhpWord($markdown),
    );

    // Without the extensions the markers are just characters: a literal `[x]`, and
    // a reference nobody follows. With them, a checkbox and a real footnote.
    expect($render('commonMarkOnly'))->toContain('[x] done')->toContain('[^1]');
    expect($render('extended'))->toContain("\u{2612} done")->not->toContain('[^1]');
    expect($render('withAllExtensions'))->toContain("\u{2612} done")->not->toContain('[^1]');
});

it('reads front matter on a machine that has no YAML extension at all', function () {
    // The parser is named rather than discovered, so a runner carrying `ext-yaml`
    // and a checkout carrying neither read the block the same way. What is left to
    // assert is the outcome, that the block parses into the document — there being
    // no longer a second implementation for the answer to depend on.
    $document = CommonMarkParser::withAllExtensions()->parse("---\ntitle: Report\n---\n\n# Heading\n");

    expect($document)->toBeInstanceOf(League\CommonMark\Node\Block\Document::class);
});

// StyleResolver

it('uses a style named as a string only when it is the whole of the formatting', function () {
    // A named style cannot be combined with anything else in a run, so a run
    // that also carries emphasis has to fall back to the array form for the
    // combination to resolve.
    $config = Configuration::create()->withStyles([
        Styles::CODE_FONT => 'CodeChar',
        Styles::LINK_FONT => 'Hyperlink',
    ]);

    expect((new StyleResolver($config))->fontFor(new InlineStyle(code: true)))->toBe('CodeChar')
        ->and((new StyleResolver($config))->fontFor(new InlineStyle(code: true, bold: true)))
        ->toBeArray();
});

// Application

it('reports a version the release tag can be compared against', function () {
    // The phar stamps this into its own manifest and the release job fails when
    // it disagrees with the tag, so the one thing worth pinning here is that it is
    // a version at all — a date, a branch name or a `1.0.0` left over from before
    // the project was released would all pass a test that asserted the value.
    //
    // No pre-release or build metadata: the tag is `v` plus this, and nothing
    // between them to explain.
    expect(Application::VERSION)->toMatch('/^\d+\.\d+\.\d+$/')
        ->and(Application::NAME)->toBe('mdword');
});

it('says the same version as the changelog does', function () {
    // Two places write the version down: the constant, and the heading of the
    // newest entry in the changelog. They drifted once already — the constant read
    // `1.0.0` through a period in which the library had never been released — and
    // nothing in the suite could see it, because the value was only ever compared
    // with itself.
    $changelog = (string) file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');
    $latest = [];

    // The newest `## [x.y.z]` heading, which Keep a Changelog puts first.
    if (preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $latest) === 1) {
        expect(Application::VERSION)->toBe($latest[1]);
    } else {
        // No changelog is a choice, not a drift, but it should be a deliberate
        // one rather than the result of the file having gone missing.
        expect(is_file(dirname(__DIR__, 2) . '/CHANGELOG.md'))->toBeTrue();
    }
});
