<?php

declare(strict_types=1);

use MarkdownWord\MarkdownToWord;
use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;
use PhpOffice\PhpWord\Settings;

/*
 * Content that has to be escaped before it can be written into OOXML.
 *
 * PHPWord leaves output escaping off, which is correct for content that is
 * already escaped and wrong for everything else. Without escaping, a document
 * containing a lone `<` or `&` is not valid XML and Word refuses to open it — a
 * failure with no visible cause that would be very hard to trace back to a
 * conversion.
 *
 * Writing a document reaches the known upstream deprecation described in
 * tests/Support/Upstream, so the filter spans the whole test.
 */
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * Content that has to survive being escaped, and that is not valid XML if it
 * does not.
 */
dataset('dangerous content', [
    'lone ampersand' => ['AT&T'],
    'less than' => ['a < b'],
    'greater than' => ['a > b'],
    'entities' => ['&amp;&lt;&gt;&#65;'],
    'markup characters around text' => ['a < b && c > d'],
]);

it('produces valid xml from markup characters', function (string $markdown) {
    expect(isValidDocumentXml(escapeToFile($markdown)))->toBeTrue();
})->with('dangerous content');

it('keeps the text through escaping', function (string $markdown) {
    $file = escapeToFile($markdown);

    $dom = new DOMDocument();
    $dom->loadXML(TemplateFactory::xmlOf($file), LIBXML_NOCDATA);

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $text = '';
    foreach ($xpath->query('//w:t') ?: [] as $node) {
        $text .= $node->textContent;
    }

    // The document carries the characters themselves; the entities in the source
    // have already been resolved by the Markdown parser.
    expect($text)->toContain(trim(html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
})->with('dangerous content');

it('does not let control characters corrupt the document', function () {
    expect(isValidDocumentXml(escapeToFile("before\x00after\x07 and\x0B more")))->toBeTrue();
});

it('escapes markup characters inside table cells and code', function () {
    $file = escapeToFile("| a < b | c & d |\n| --- | --- |\n| 1 | 2 |\n\n```\nif (a < b && c > d) {}\n```");

    expect(isValidDocumentXml($file))->toBeTrue();
    expect(TemplateFactory::xmlOf($file))->toContain('if (a &lt; b &amp;&amp; c &gt; d) {}');
});

it('enables escaping only while writing', function () {
    $before = Settings::isOutputEscapingEnabled();

    escapeToFile('AT&T');

    // The global setting belongs to the host application and must be restored.
    expect(Settings::isOutputEscapingEnabled())->toBe($before);
});

it('escapes template values', function () {
    $output = escapeTemplate(TemplateFactory::report(), values: ['reference' => 'a < b & c']);

    expect(isValidDocumentXml($output))->toBeTrue();
    expect(TemplateFactory::xmlOf($output))->toContain('a &lt; b &amp; c');
});

it('escapes template markdown', function () {
    $output = escapeTemplate(TemplateFactory::placeholder(), markdown: ['body' => 'AT&T and a < b']);

    expect(isValidDocumentXml($output))->toBeTrue();
    expect(TemplateFactory::xmlOf($output))->toContain('AT&amp;T');
});

it('escapes lists inside a template', function () {
    $output = escapeTemplate(TemplateFactory::placeholder(), markdown: ['body' => "- a < b\n- c & d"]);

    expect(isValidDocumentXml($output))->toBeTrue();
});

/**
 * Render Markdown into a document and return the path to it.
 */
function escapeToFile(string $markdown): string
{
    $file = Scratch::path('escape');
    saveDocument($markdown, $file);

    return $file;
}

/**
 * Fill in a template and return the path to the result.
 *
 * @param array<string, string> $values
 * @param array<string, string> $markdown Regions to insert, keyed by name.
 */
function escapeTemplate(string $template, array $values = [], array $markdown = []): string
{
    $output = Scratch::path('escape-template');

    $processor = new MarkdownTemplate($template, values: $values);

    // The Markdown goes in once, wherever the template's content control is, so the
    // last one named here is the one that lands. Kept as a loop because the values
    // are what these tests are about and the shape of the helper should not change
    // with them.
    foreach ($markdown as $control => $content) {
        $processor->insert($content, (string) $control);
    }

    saveTemplateDocument($processor, $output);

    return $output;
}

function isValidDocumentXml(string $docx): bool
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $valid = $dom->loadXML(TemplateFactory::xmlOf($docx), LIBXML_NOCDATA);
    libxml_clear_errors();

    return $valid;
}
