<?php

declare(strict_types=1);

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Configuration;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Parser\MarkdownParserInterface;

/*
 * Frontmatter reaching the document, not just the configuration object.
 *
 * The rest of tests/Unit/frontmatter.php checks what the merge decides. This file
 * checks that the decision survives all the way into `word/document.xml`, because
 * "the configuration is right" and "the document was written with it" are different
 * claims and only the second one is the feature.
 *
 * It was not the second one for a while: `ConfigurationMerger` and `Frontmatter`
 * existed, were tested, and were called by nothing at all — a document with
 * frontmatter was rendered exactly as if the block were not there, in silence. The
 * tests below read the archive rather than the objects for that reason.
 */

use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * A parser with frontmatter and without anything else added.
 *
 * `CommonMarkParser::withAllExtensions()` would do, but it also carries
 * `NormalizeHeadingsExtension`, which renumbers the heading levels a document uses so
 * that they are consecutive — so `#` followed by `###` is written as `Heading1` and
 * `Heading2`, and a test about `maxHeadingLevel` cannot tell a capped `###` from a
 * normalised one.
 */
function frontmatterParser(): MarkdownParserInterface
{
    // CommonMark core is always registered by the parser itself; naming it here as well
    // is a duplicate delimiter processor and the constructor refuses it.
    return new CommonMarkParser([FrontMatterExtension::class]);
}

/**
 * `word/document.xml` out of a finished archive.
 *
 * Read through the archive rather than off the PhpWord object: the claim is that the
 * bytes on disk carry the frontmatter, and the object tree is an earlier stage of the
 * same claim.
 */
function xmlOf(string $docx): string
{
    $target = sys_get_temp_dir() . '/frontmatter-' . bin2hex(random_bytes(6)) . '.docx';
    file_put_contents($target, $docx);

    $zip = new ZipArchive();
    $zip->open($target);
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    unlink($target);

    return $xml;
}

/** `word/document.xml` for a document rendered from this Markdown. */
function documentXml(string $markdown, ?Configuration $config = null, ?Configuration $overrides = null): string
{
    $converter = new MarkdownToWord(
        null,
        $config ?? Configuration::create(),
        frontmatterParser(),
        $overrides,
    );

    return xmlOf($converter->toDocx($markdown));
}

/** The paragraph styles actually written, in the order they appear. */
function paragraphStyles(string $xml): array
{
    preg_match_all('/w:pStyle w:val="([^"]+)"/', $xml, $matches);

    return array_values(array_unique($matches[1]));
}

it('writes the style the frontmatter asked for', function () {
    $xml = documentXml("---\nstyles:\n  heading.1:\n    size: 28\n    color: 1B3A5C\n---\n\n# Heading one\n");

    // Word sizes runs in half-points, so 28pt is `w:sz 56`. A size that arrives as
    // anything else is a size that did not arrive.
    expect($xml)->toContain('w:sz w:val="56"')
        ->and($xml)->toContain('1B3A5C');
});

it('honours the heading level the frontmatter capped', function () {
    $markdown = "# One\n\n### Three\n";

    // maxHeadingLevel: 2 means an H3 is written as a paragraph, which means no
    // `Heading3` style is ever referenced.
    expect(paragraphStyles(documentXml($markdown)))->toContain('Heading3')
        ->and(paragraphStyles(documentXml("---\noptions:\n  maxHeadingLevel: 2\n---\n\n" . $markdown)))
        ->not->toContain('Heading3');
});

it('leaves the same Markdown alone when the frontmatter says nothing about it', function () {
    $body = "# One\n\n### Three\n";

    expect(paragraphStyles(documentXml("---\ntitle: Only a title\n---\n\n" . $body)))
        ->toBe(paragraphStyles(documentXml($body)));
});

it('keeps the frontmatter out of the document it configures', function () {
    $xml = documentXml("---\noptions:\n  maxHeadingLevel: 2\n---\n\n# One\n");

    // The block is configuration, so it must not also arrive as a paragraph.
    expect($xml)->not->toContain('maxHeadingLevel')
        ->and(paragraphStyles($xml))->toBe(['Heading1']);
});

it('lets the constructor lose to the frontmatter', function () {
    $markdown = "# One\n\n### Three\n";

    $withConstructor = documentXml($markdown, Configuration::create()->withOptions(['maxHeadingLevel' => 6]));
    $withFrontmatter = documentXml("---\noptions:\n  maxHeadingLevel: 2\n---\n\n" . $markdown, Configuration::create()->withOptions(['maxHeadingLevel' => 6]));

    expect(paragraphStyles($withConstructor))->toContain('Heading3')
        ->and(paragraphStyles($withFrontmatter))->not->toContain('Heading3');
});

it('lets the overrides lose to nothing, and win over the frontmatter', function () {
    $markdown = "---\noptions:\n  maxHeadingLevel: 2\n---\n\n# One\n\n### Three\n";

    $frontmatterOnly = documentXml($markdown);
    $overridden = documentXml($markdown, null, Configuration::create()->withOptions(['maxHeadingLevel' => 6]));

    expect(paragraphStyles($frontmatterOnly))->not->toContain('Heading3')
        // The highest source, and it is above the frontmatter in the same order the
        // README's table gives.
        ->and(paragraphStyles($overridden))->toContain('Heading3');
});

it("gives each document its own frontmatter when one converter renders two", function () {
    // The frontmatter belongs to a document, not to the converter, so a converter handed
    // two documents with different blocks has to render each with its own.
    $converter = new MarkdownToWord(null, Configuration::create(), frontmatterParser());

    $first = "---\noptions:\n  maxHeadingLevel: 2\n---\n\n# One\n\n### Three\n";
    $second = "# One\n\n### Three\n";

    $render = static fn (string $markdown): array => paragraphStyles(xmlOf($converter->toDocx($markdown)));

    expect($render($first))->not->toContain('Heading3')
        ->and($render($second))->toContain('Heading3');
});

it('reports the configuration a document would use before rendering it', function () {
    $converter = new MarkdownToWord(null, Configuration::create(), frontmatterParser());
    $document = frontmatterParser()->parse("---\noptions:\n  maxHeadingLevel: 2\n---\n\n# One\n");

    expect($converter->configurationFor($document)->getOptions()->maxHeadingLevel)->toBe(2)
        // And the constructor's own value is what `getConfiguration()` still reports,
        // because that is what it was given.
        ->and($converter->getConfiguration()->getOptions()->maxHeadingLevel)->toBe(6);
});

it('works through the template path too', function () {
    // The container path is a second route into the same renderer, and it is the one
    // the template uses. Reading the frontmatter in only one of them would make a
    // template render differently from a plain document for the same Markdown.
    $template = Scratch::remember(TemplateFactory::report());

    $markdown = "---\nstyles:\n  heading.1:\n    size: 30\n---\n\n# Heading\n";

    $target = Scratch::path('frontmatter-template.docx');
    (new MarkdownTemplate($template, Configuration::create(), [], frontmatterParser()))
        ->insert('summary', $markdown)
        ->save($target);

    expect(xmlOf((string) file_get_contents($target)))->toContain('w:sz w:val="60"');
});