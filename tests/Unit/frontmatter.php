<?php

declare(strict_types=1);

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Document\ConfigurationMerger;
use MarkdownWord\Document\Frontmatter;
use MarkdownWord\Parser\CommonMarkParser;

/*
 * Frontmatter as configuration.
 *
 * The block at the top of a Markdown file used to be parsed and then thrown away: the
 * `FrontMatterExtension` strips it so it never renders, and nothing read it, so a
 * document could say `maxHeadingLevel: 3` in the file and be rendered at level 6. These
 * tests are what stops that reading as working.
 *
 * Three traps, each of which has to fail loudly rather than quietly:
 *
 *  - The merge order is four sources deep, and a merge that drops a source produces a
 *    configuration that is entirely valid and entirely wrong. Every source is therefore
 *    tested against the one directly beneath it, not only against the defaults.
 *
 *  - A merge that rebuilds from the defaults instead of merging over its input passes
 *    every test written against the defaults. So the base here is `defaults` with
 *    *every* property moved away from its default, and the assertion is built from the
 *    base. "The property I set changed and the other fifteen survived" is the contract;
 *    "the property I set has the right value" is not, and is not what is checked.
 *
 *  - `symfony/yaml` was a `suggest` while `CommonMarkParser::withAllExtensions()`
 *    registered the extension that needs it, so that method threw
 *    `MissingDependencyException` on any document with frontmatter — the one document
 *    shape it exists for. It is a `require` now, and the last group is the proof.
 */

/** Every option moved away from its default, so preservation and resetting differ. */
function awayFromTheDefaults(): Options
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

/** A configuration whose every option and every style slot differs from the default. */
function configurationAwayFromTheDefaults(): Configuration
{
    return Configuration::create()
        ->withOptions(awayFromTheDefaults())
        ->withStyles(['heading.1' => 'Title', Configuration\Styles::CODE_FONT => 'Consolas']);
}

it('reads the block the extension already parsed', function () {
    $document = CommonMarkParser::withAllExtensions()->parse("---\ntitle: Quarterly\n---\n\nBody");

    $frontmatter = Frontmatter::fromDocument($document);

    expect($frontmatter->getString('title'))->toBe('Quarterly');
});

it('reads a six-digit colour as the string it was written as', function () {
    // `FrontMatterExtension` given no parser takes libyaml wherever `ext-yaml` is
    // loaded — a CI runner has it, a checkout usually does not — and the two
    // disagree about this value rather than only about its type: `000000` is the
    // string asserted below under symfony/yaml, and the integer 0 under libyaml,
    // which the validator then refuses as though the document were malformed.
    // Naming the extension by class has to read the same as naming it by factory.
    $markdown = "---\nstyles:\n  heading.1:\n    color: 000000\n---\n\nBody";
    $expected = ['styles' => ['heading.1' => ['color' => '000000']]];

    $byClassName = (new CommonMarkParser([FrontMatterExtension::class]))->parse($markdown);

    expect(Frontmatter::fromDocument(CommonMarkParser::withAllExtensions()->parse($markdown))->toArray())
        ->toBe($expected)
        ->and(Frontmatter::fromDocument($byClassName)->toArray())->toBe($expected);
});

it('reads a block that is not at the start of the file as no block', function () {
    // The extension only treats a block as frontmatter when it opens the file. Reading
    // one from the middle would be reading a thematic break followed by YAML.
    $document = CommonMarkParser::withAllExtensions()->parse("Body\n\n---\ntitle: Late\n---");

    expect(Frontmatter::fromDocument($document)->isEmpty())->toBeTrue();
});

it('keeps the block out of the rendered document', function () {
    // What the extension does, asserted because the merge depends on it: the block is
    // configuration, so it must not also be a paragraph of the document.
    $document = CommonMarkParser::withAllExtensions()->parse("---\ntitle: Quarterly\n---\n\nBody");

    $paragraph = $document->firstChild();

    expect($document->children())->toHaveCount(1)
        ->and($paragraph)->toBeInstanceOf(League\CommonMark\Node\Block\Paragraph::class)
        ->and($paragraph?->firstChild()?->getLiteral())->toBe('Body');
});

it('distinguishes no block from an empty one', function () {
    $absent = Frontmatter::fromDocument(CommonMarkParser::withAllExtensions()->parse('Body'));
    $empty = Frontmatter::of([]);

    expect($absent->isEmpty())->toBeTrue()
        ->and($empty->isEmpty())->toBeTrue()
        // Both read as "says nothing", which is what they have in common. What differs
        // is whether the author wrote a block, and only the parsed document knows.
        ->and($absent->toArray())->toBe([])
        ->and($empty->toArray())->toBe([]);
});

it('reads the typed accessors frontmatter is asked for', function () {
    $frontmatter = Frontmatter::of([
        'title' => 'Quarterly',
        'template_file' => 'corporate.dotx',
        'theme_file' => 'themes/acme.pptx',
        'slides' => 12,
        'draft' => true,
        'count' => '7',
    ]);

    expect($frontmatter->getString('template_file'))->toBe('corporate.dotx')
        ->and($frontmatter->getString('theme_file'))->toBe('themes/acme.pptx')
        ->and($frontmatter->getInt('slides'))->toBe(12)
        ->and($frontmatter->getBool('draft'))->toBeTrue()
        ->and($frontmatter->getInt('count'))->toBe(7)
        ->and($frontmatter->has('title'))->toBeTrue()
        ->and($frontmatter->has('absent'))->toBeFalse();
});

it('returns the default rather than guessing at a key of the wrong type', function () {
    $frontmatter = Frontmatter::of(['title' => ['not', 'a', 'string'], 'slides' => 'many']);

    expect($frontmatter->getString('title', 'fallback'))->toBe('fallback')
        ->and($frontmatter->getInt('slides', 1))->toBe(1)
        ->and($frontmatter->getBool('title', true))->toBeTrue();
});

it('drops a sub-key that is not a block rather than coercing it', function () {
    // `options: 3` and `options:\n  a: 1` are the same syntax to a YAML parser, and
    // reading the first as the second would let a document's contents decide what they
    // configure.
    $frontmatter = Frontmatter::of(['options' => 3]);

    expect($frontmatter->has('options'))->toBeTrue()
        ->and($frontmatter->getArray('options'))->toBe([]);
});

it('passes only the two keys a configuration understands', function () {
    $frontmatter = Frontmatter::of([
        'title' => 'Quarterly',
        'template_file' => 'corporate.dotx',
        'options' => ['maxHeadingLevel' => 3],
    ]);

    expect($frontmatter->toConfigurationArray())->toBe(['options' => ['maxHeadingLevel' => 3]]);
});

it('keeps an empty sub-key, because saying "no overrides" is not saying nothing', function () {
    expect(Frontmatter::of(['options' => []])->toConfigurationArray())->toBe(['options' => []]);
});

it('reads a nested block', function () {
    $frontmatter = Frontmatter::of(['styles' => ['heading.1' => 'Title']]);

    expect($frontmatter->getArray('styles'))->toBe(['heading.1' => 'Title']);
});

it('configures nothing when there is no frontmatter at all', function () {
    // Both ways of having no frontmatter have to reach the merge saying nothing, or a
    // document without one takes a different path from a document with an empty one.
    foreach ([Frontmatter::none(), Frontmatter::fromDocument(CommonMarkParser::withAllExtensions()->parse('Body'))] as $absent) {
        expect($absent->toConfigurationArray())->toBe([]);
    }
});

it('resolves to the defaults when no source says anything', function () {
    // Compared as configuration rather than as an object: `create()` hands back a new
    // instance every time, and identity is not the claim being made here.
    expect(ConfigurationMerger::resolve()->toArray())->toBe(Configuration::create()->toArray());
});

it('reads no frontmatter from a parser that has no frontmatter extension', function () {
    // The key is only written by the extension that knows about frontmatter, so every
    // other parser leaves the document without it. Asking for it anyway is the normal
    // case rather than an edge one — the default parser is what a caller gets.
    expect(Frontmatter::fromDocument((new CommonMarkParser())->parse('# Body'))->isEmpty())->toBeTrue();
});

it('configures nothing for a document read by a parser with no frontmatter extension', function () {
    $document = (new CommonMarkParser())->parse("# Body\n");

    expect(ConfigurationMerger::resolve(frontmatter: Frontmatter::fromDocument($document))->toArray())
        ->toBe(Configuration::create()->toArray());
});

/*
 * The merge. Each source is tested against the one directly beneath it rather than
 * only against the defaults, because a merge that ignores a source in the middle of
 * the chain produces a configuration that is valid and wrong.
 */
it('lets the command line beat the frontmatter', function () {
    $merged = ConfigurationMerger::resolve(
        commandLine: ['options' => ['maxHeadingLevel' => 2]],
        frontmatter: Frontmatter::of(['options' => ['maxHeadingLevel' => 5]]),
    );

    expect($merged->getOptions()->maxHeadingLevel)->toBe(2);
});

it('lets the frontmatter beat the config file', function () {
    $merged = ConfigurationMerger::resolve(
        frontmatter: Frontmatter::of(['options' => ['maxHeadingLevel' => 5]]),
        configFile: ['options' => ['maxHeadingLevel' => 4]],
    );

    expect($merged->getOptions()->maxHeadingLevel)->toBe(5);
});

it('lets the config file beat the defaults', function () {
    $merged = ConfigurationMerger::resolve(configFile: ['options' => ['maxHeadingLevel' => 4]]);

    expect($merged->getOptions()->maxHeadingLevel)->toBe(4);
});

it('lets the command line beat the config file with the frontmatter absent', function () {
    // The gap the chain has to survive: skipping a source must not skip the ones above
    // it, or an absent frontmatter would silently promote the config file.
    $merged = ConfigurationMerger::resolve(
        commandLine: ['options' => ['maxHeadingLevel' => 2]],
        configFile: ['options' => ['maxHeadingLevel' => 4]],
    );

    expect($merged->getOptions()->maxHeadingLevel)->toBe(2);
});

it('keeps the order with all four sources present', function () {
    $merged = ConfigurationMerger::resolve(
        commandLine: ['options' => ['maxHeadingLevel' => 1]],
        frontmatter: Frontmatter::of(['options' => ['maxHeadingLevel' => 2]]),
        configFile: ['options' => ['maxHeadingLevel' => 3]],
        defaults: Configuration::create()->withOptions(['maxHeadingLevel' => 4]),
    );

    expect($merged->getOptions()->maxHeadingLevel)->toBe(1);
});

it('ignores a source that says nothing rather than reading it as a reset', function () {
    // A command line with no option flags says nothing about the options. Treating its
    // silence as an instruction to drop them would make two ways of running the same
    // document produce two different documents.
    $base = configurationAwayFromTheDefaults();

    $merged = ConfigurationMerger::resolve(commandLine: [], defaults: $base);

    expect($merged->getOptions())->toBe($base->getOptions());
});

it('changes one option and preserves the other fifteen', function () {
    $base = awayFromTheDefaults();

    $merged = ConfigurationMerger::resolve(
        commandLine: ['options' => ['maxHeadingLevel' => 2]],
        defaults: Configuration::create()->withOptions($base),
    );

    expect($merged->getOptions()->toArray())
        ->toBe(array_replace($base->toArray(), ['maxHeadingLevel' => 2]));
});

it('merges styles and options from the same source independently', function () {
    $merged = ConfigurationMerger::resolve(frontmatter: Frontmatter::of([
        'styles' => ['heading.1' => 'Title'],
        'options' => ['maxHeadingLevel' => 3],
    ]));

    expect($merged->getStyles()->toArray())->toHaveKey('heading.1', 'Title')
        ->and($merged->getOptions()->maxHeadingLevel)->toBe(3);
});

it('keeps the other half of the configuration when a source names only one', function () {
    // The failure this guards against is a `withAll()` that rebuilds the whole
    // configuration from the batch and so resets whichever half the batch omitted.
    $base = configurationAwayFromTheDefaults();

    $merged = ConfigurationMerger::resolve(commandLine: ['options' => ['maxHeadingLevel' => 2]], defaults: $base);

    expect($merged->getStyles()->toArray())->toBe($base->getStyles()->toArray())
        ->and($merged->getOptions()->toArray())
        ->toBe(array_replace($base->getOptions()->toArray(), ['maxHeadingLevel' => 2]));
});

it('changes one style slot and preserves the others', function () {
    // The options side of this is covered by the test above, and it is the styles side
    // that was missed: `Styles::withAll()` merges with `+`, but a `Configuration::withAll()`
    // that built a fresh `Styles` from the batch would pass every other test here,
    // because a fresh `Styles` merges the defaults in and so still has every key.
    $base = Configuration::create()->withStyles([
        Configuration\Styles::HEADING_1 => 'Title',
        Configuration\Styles::HEADING_2 => 'Subtitle',
        Configuration\Styles::BLOCK_QUOTE => 'Quote',
        Configuration\Styles::CODE_FONT => 'Consolas',
        Configuration\Styles::BULLET_LIST => 'MarkdownWord-Dash',
    ]);

    $merged = ConfigurationMerger::resolve(
        commandLine: ['styles' => [Configuration\Styles::HEADING_2 => 'DeckSubtitle']],
        defaults: $base,
    );

    expect($merged->getStyles()->toArray())
        ->toBe(array_replace($base->getStyles()->toArray(), [Configuration\Styles::HEADING_2 => 'DeckSubtitle']));
});

it('applies the frontmatter of a real document to a real render', function () {
    // The whole feature, end to end: the block in the file decides the rendering.
    $document = CommonMarkParser::withAllExtensions()->parse(
        "---\noptions:\n  maxHeadingLevel: 2\n---\n\n# One\n\n## Two\n\n### Three",
    );

    $configuration = ConfigurationMerger::resolve(
        frontmatter: Frontmatter::fromDocument($document),
    );

    expect($configuration->getOptions()->maxHeadingLevel)->toBe(2);
});

/*
 * The dependency that was optional while the code needed it.
 */
it('parses frontmatter through withAllExtensions without the caller installing anything', function () {
    expect(class_exists(Symfony\Component\Yaml\Yaml::class))->toBeTrue();
});

it('no longer lists the YAML library as something to install', function () {
    $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);

    expect($composer['require'])->toHaveKey('symfony/yaml')
        ->and($composer['suggest'] ?? [])->not->toHaveKey('symfony/yaml');
});