<?php

declare(strict_types=1);

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Configuration\Validator;
use MarkdownWord\Exception\UnknownConfigurationKey;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;

/*
 * The keys that name nothing, reported rather than dropped.
 *
 * Four places take a key and discard one they do not recognise: an unknown option, an
 * unknown style slot, an unknown font property and an unknown paragraph property. Every
 * one of them leaves a document that renders cleanly and is configured as though the
 * key had never been written. There is no symptom to notice and nothing to diagnose it
 * from, which is why these tests are about the reporting rather than about the
 * rendering — the rendering was never wrong, it was silently not what was asked for.
 */

use MarkdownWord\Tests\Support\Upstream;

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/** Renders a document and returns the exception message, or null when it succeeded. */
function rejection(string $frontmatter): ?string
{
    $parser = new CommonMarkParser([...CommonMarkParser::FLAVOURS['gfm'], FrontMatterExtension::class]);
    $markdown = "---\n" . $frontmatter . "---\n\n# One\n";

    try {
        (new MarkdownToWord($markdown, Configuration::create(), $parser))->toDocx($markdown);
    } catch (UnknownConfigurationKey $error) {
        return $error->getMessage();
    }

    return null;
}

it('rejects an option that does not exist', function () {
    $message = rejection("options:\n  maxheadinglevel: 2\n");

    expect($message)->toContain('Unknown option "maxheadinglevel"')
        ->and($message)->toContain('Did you mean "maxHeadingLevel"?');
});

it('rejects a style slot that does not exist', function () {
    // The exact mistake the frontmatter examples used to carry: a slot name is
    // camelCase, and the wrong spelling was dropped without a word.
    $message = rejection("styles:\n  blockquote: Quote\n");

    expect($message)->toContain('Unknown style "blockquote"')
        ->and($message)->toContain('Did you mean "blockQuote"?');
});

it('rejects a style property that does not exist', function () {
    $message = rejection("styles:\n  heading.1:\n    colour: red\n");

    expect($message)->toContain('Unknown "colour" property of the "heading.1" style')
        ->and($message)->toContain('Did you mean "color"?');
});

it('rejects a paragraph property that does not exist', function () {
    expect(rejection("styles:\n  heading.1:\n    spce:\n      after: 200\n"))
        ->toContain('Unknown "spce" property');
});

it('names the alternatives, because a key that is merely unknown is not enough', function () {
    expect(rejection("options:\n  zzzz: 1\n"))
        ->toContain('maxHeadingLevel')
        ->toContain('tableBorders')
        ->toContain('codeBlockShading');
});

it('offers no suggestion when there is no near miss', function () {
    // A suggestion that is wrong sends the reader to the wrong place to look, so one
    // that is merely plausible is not offered.
    expect(rejection("options:\n  zzzz: 1\n"))->not->toContain('Did you mean');
});

it('reports every problem at once rather than the first', function () {
    // A file with three typos should take one run to fix, not three.
    $message = rejection("options:\n  maxheadinglevel: 2\nstyles:\n  blockquote: Quote\n");

    expect($message)->toContain('maxheadinglevel')
        ->and($message)->toContain('blockquote')
        ->and(substr_count((string) $message, 'Unknown'))->toBe(2);
});

it('accepts every key that does exist', function () {
    expect(rejection(
        "options:\n  maxHeadingLevel: 2\n  tableBorders: false\n"
        . "styles:\n  heading.1:\n    name: Georgia\n    size: 30\n    color: 8B0000\n"
        . "    space:\n      before: 0\n      after: 480\n"
        . "  codeFont:\n    name: Courier New\n",
    ))->toBeNull();
});

it('accepts a frontmatter carrying keys that are not configuration', function () {
    // The block at the top of a document also holds `title`, `author`, `theme` and
    // `template_file`, none of which is an option. Validating those would fail every
    // deck that has one, so only the two sub-keys are checked.
    expect(rejection("title: Quarterly\nauthor: Fabi\ntheme: corporate-dark\ntemplate_file: x.dotx\n"))
        ->toBeNull();
});

it('leaves the value objects alone', function () {
    // `fromArray()` and `withAll()` keep doing what they have always done, because a
    // config file may carry entries for something else and has been allowed to for a
    // decade. Changing that would break callers; the validator is a separate, opt-in
    // call for anyone who wants the check.
    //
    // The two disagree about what "dropping" means, which is the point. An unknown
    // option is gone from the object. An unknown slot is *kept* by `Styles` and never
    // rendered, so `toArray()` shows a key that no document will ever use. Either way
    // nothing warns.
    expect(Options::fromArray(['maxheadinglevel' => 2])->maxHeadingLevel)->toBe(6)
        ->and((new Styles(['blockquote' => 'Quote']))->toArray())->toHaveKey('blockquote');
});

it('renders nothing for a slot that names nothing', function () {
    // The consequence of the line above, and the reason the key needs checking: the
    // setting the author wrote is in the configuration and absent from the document,
    // with nothing in either to say so.
    $markdown = "# One\n";
    $docx = (new MarkdownToWord($markdown, Configuration::create()->withStyles(['blockquote' => 'Quote'])))->toDocx($markdown);

    $target = sys_get_temp_dir() . '/validator-slot.docx';
    file_put_contents($target, $docx);

    $zip = new ZipArchive();
    $zip->open($target);
    $styles = (string) $zip->getFromName('word/styles.xml');
    $document = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    unlink($target);

    expect($styles)->not->toContain('blockquote')
        ->and($document)->not->toContain('blockquote');
});

it('can be asked about a configuration built in code', function () {
    expect(Validator::problems(['options' => ['maxHeadingLevel' => 3]]))->toBe([])
        ->and(Validator::problems(['options' => ['nope' => 1]]))->toHaveCount(1);

    Validator::assertValid(['styles' => ['heading.1' => ['size' => 20]]]);
});

it('skips a section that is not a mapping rather than reading its keys', function () {
    // `Frontmatter::getArray()` already turns `options: 3` into an empty array, so this
    // only arises from a hand-written array. The guard is here because walking a
    // string's keys would produce one problem per character.
    expect(Validator::problems(['options' => 'nonsense']))->toBe([])
        ->and(Validator::problems(['styles' => 3, 'other' => ['x' => 1]]))->toBe([]);
});

it('checks a style slot against the styles, not against the options', function () {
    // `thematicBreak` is both an option and a style slot, and a loop that asked each
    // section which of the two it was sent every recognised key to the same value
    // check whichever section it was written in. So a style naming a Word style was
    // refused, and the refusal called itself an option.
    expect(Validator::problems(['styles' => ['thematicBreak' => 'Rule']]))->toBe([])
        ->and(Validator::problems(['styles' => ['thematicBreak' => ['color' => 'FF0000']]]))->toBe([])
        ->and(Validator::problems(['options' => ['thematicBreak' => 'nonsense']]))
        ->toContain('The "thematicBreak" option is "nonsense". It is one of: border, text.');
});

it('raises for a configuration built in code as well', function () {
    Validator::assertValid(['options' => ['nope' => 1]]);
})->throws(UnknownConfigurationKey::class, 'Unknown option "nope"');

it('keeps the two key lists in step with what the registrar reads', function () {
    // A key added to `Styles` and not to `StyleRegistrar` would be accepted here and
    // then silently dropped there, which is the whole problem this file is about.
    $registrar = (new ReflectionClass(MarkdownWord\Render\StyleRegistrar::class))->getFileName();
    $source = (string) file_get_contents((string) $registrar);

    expect($source)->toContain('Styles::FONT_KEYS')
        ->and($source)->toContain('Styles::PARAGRAPH_KEYS');
});