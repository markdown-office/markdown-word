<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Render\LinkPlaceholder;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Writer\HyperlinkPass;

/*
 * The two paths a link label can take through the writer.
 *
 * A label with no emphasis in it is written by PHPWord as a `w:hyperlink`
 * straight away. A label with emphasis in it cannot be — PHPWord's Link element
 * only takes one plain string — so the label becomes a placeholder run, the runs
 * are recorded, and HyperlinkPass swaps a real `w:hyperlink` back in while it
 * rewrites the archive. That second path is the one that used to lose formatting:
 * the emphasis flags never reached the XML because two of them were looked up
 * under the wrong key, and the whole font — the link colour, the underline, and
 * anything the surrounding block forces, such as a bold table header — was thrown
 * away before the walk even started.
 *
 * Every assertion here is about the XML in the finished document, because that
 * is the only place either fault is visible: the element tree looks right
 * throughout.
 */

function linkFixesXml(string $markdown, ?Configuration $config = null): string
{
    $path = Scratch::path('link-fixes');

    saveDocument($markdown, $path, $config);

    return TemplateFactory::xmlOf($path);
}

/** A configuration that sends every link down the deferred path, whatever its label looks like. */
function linkFixesDeferred(?Configuration $config = null): Configuration
{
    return ($config ?? new Configuration())->withOptions(['deferredHyperlinks' => true]);
}

/**
 * The `w:hyperlink` element whose runs spell out $label.
 *
 * Reading the element back out rather than slicing the document text is what
 * keeps an assertion about "the label is italic" from being satisfied by an
 * italic somewhere else in the document.
 */
function linkFixesHyperlink(string $xml, string $label): DOMElement
{
    return linkFixesHyperlinkIn(linkFixesXPath($xml), $label);
}

/** The `w:hyperlink` element for a label, serialised on its own. */
function linkFixesElement(string $xml, string $label): string
{
    $hyperlink = linkFixesHyperlink($xml, $label);

    return (string) $hyperlink->ownerDocument?->saveXML($hyperlink);
}

function linkFixesHyperlinkIn(DOMXPath $xpath, string $label): DOMElement
{
    foreach ($xpath->query('//w:hyperlink') ?: [] as $hyperlink) {
        if (!$hyperlink instanceof DOMElement) {
            continue;
        }

        if (linkFixesTextOf($xpath, $hyperlink) === $label) {
            return $hyperlink;
        }
    }

    throw new RuntimeException(sprintf('No w:hyperlink in the document has the label "%s".', $label));
}

/**
 * @return list<array{text: string, properties: list<string>}>
 */
function linkFixesRuns(string $xml, string $label): array
{
    $xpath = linkFixesXPath($xml);

    return linkFixesRunsIn($xpath, linkFixesHyperlinkIn($xpath, $label));
}

/**
 * @return list<array{text: string, properties: list<string>}>
 */
function linkFixesRunsIn(DOMXPath $xpath, DOMElement $hyperlink): array
{
    $runs = [];

    foreach ($xpath->query('.//w:r', $hyperlink) ?: [] as $run) {
        if (!$run instanceof DOMElement) {
            continue;
        }

        $runs[] = [
            'text' => linkFixesTextOf($xpath, $run),
            'properties' => linkFixesNormalise($xpath, $run),
        ];
    }

    return $runs;
}

function linkFixesTextOf(DOMXPath $xpath, DOMElement $element): string
{
    $text = '';

    foreach ($xpath->query('.//w:t', $element) ?: [] as $node) {
        $text .= $node->textContent;
    }

    return $text;
}

function linkFixesXPath(string $xml): DOMXPath
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadXML($xml, LIBXML_NOCDATA);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    return $xpath;
}

/**
 * @return list<string>
 */
function linkFixesProperties(string $xml, string $label): array
{
    $properties = [];

    foreach (linkFixesRuns($xml, $label) as $run) {
        $properties = [...$properties, ...$run['properties']];
    }

    $properties = array_values(array_unique($properties));

    sort($properties);

    return $properties;
}

/**
 * A run's `w:rPr` children, as a sorted list of comparable tokens.
 *
 * The tokens are normalised rather than compared as XML because the two paths
 * legitimately order the same properties differently: PHPWord writes what its
 * Font carries, HyperlinkPass writes what the style array holds. A toggle is
 * also written both as `<w:i/>` and as `<w:i w:val="1"/>` for the same meaning,
 * so the value is read and dropped when it merely turns the property on.
 *
 * @return list<string>
 */
function linkFixesNormalise(DOMXPath $xpath, DOMElement $run): array
{
    $properties = [];

    foreach ($xpath->query('./w:rPr/*', $run) ?: [] as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }

        $name = $node->localName;
        $value = $node->getAttributeNS(
            'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
            'val',
        );

        // The complex-script twin of a property always follows its own, and a
        // toggle that is explicitly off carries no formatting at all.
        if (str_ends_with($name, 'Cs') || in_array(strtolower($value), ['0', 'false', 'off'], true)) {
            continue;
        }

        // `w:val="1"` and no value at all both mean "on", and the two paths
        // disagree about which they write, so an on-toggle is just its name.
        $properties[] = in_array(strtolower($value), ['', '1', 'true', 'on'], true)
            ? $name
            : $name . '=' . $value;
    }

    sort($properties);

    return $properties;
}

// Defect 1: the emphasis in a label

it('keeps the italic of a link label', function () {
    $xml = linkFixesXml("[*italic*](https://example.com)\n");

    expect(linkFixesProperties($xml, 'italic'))->toContain('i');
    expect(linkFixesElement($xml, 'italic'))->toContain('<w:i/>');
});

it('keeps the strikethrough of a link label', function () {
    $xml = linkFixesXml("[~~struck~~](https://example.com)\n");

    expect(linkFixesProperties($xml, 'struck'))->toContain('strike');
    expect(linkFixesElement($xml, 'struck'))->toContain('<w:strike/>');
});

it('keeps every emphasis in a link label at once', function () {
    $xml = linkFixesXml("[**bold** *and* ~~struck~~](https://example.com)\n");

    $runs = linkFixesRuns($xml, 'bold and struck');

    expect($runs)->toHaveCount(5);
    expect($runs[0])->toBe(['text' => 'bold', 'properties' => ['b', 'color=0563C1', 'u=single']]);
    expect($runs[2])->toBe(['text' => 'and', 'properties' => ['color=0563C1', 'i', 'u=single']]);
    expect($runs[4])->toBe(['text' => 'struck', 'properties' => ['color=0563C1', 'strike', 'u=single']]);
});

it('keeps emphasis nested inside a link label', function () {
    $xml = linkFixesXml("[***both***](https://example.com)\n");

    expect(linkFixesProperties($xml, 'both'))->toEqual(['b', 'color=0563C1', 'i', 'u=single']);
});

// Defect 2: the font the label is written in

it('writes a deferred link label in the font the immediate path would use', function () {
    $markdown = "[plain](https://example.com)\n";

    $immediate = linkFixesXml($markdown);
    $deferred = linkFixesXml($markdown, linkFixesDeferred());

    // Neither path is allowed to be the one that decides what a link looks like.
    expect(linkFixesRuns($deferred, 'plain'))->toBe(linkFixesRuns($immediate, 'plain'));
    expect(linkFixesProperties($deferred, 'plain'))->toEqual(['color=0563C1', 'u=single']);
});

it('keeps the emphasis in a deferred link label as well as the link font', function () {
    $xml = linkFixesXml("[*italic*](https://example.com)\n", linkFixesDeferred());

    expect(linkFixesProperties($xml, 'italic'))->toEqual(['color=0563C1', 'i', 'u=single']);
});

it('keeps the font forced by a table header on a deferred link label', function () {
    $plain = "| [plain](https://example.com)\n| --- |\n| x |\n";
    $emphasised = "| [*plain*](https://example.com)\n| --- |\n| x |\n";

    $immediate = linkFixesXml($plain);
    $deferred = linkFixesXml($plain, linkFixesDeferred());

    // The header forces bold onto every run of the cell, so the immediate path
    // reaches the run with it and the deferred path has to as well.
    $expected = linkFixesProperties($immediate, 'plain');
    expect($expected)->toEqual(['b', 'color=0563C1', 'u=single']);

    expect(linkFixesProperties($deferred, 'plain'))->toBe($expected);

    // Emphasis adds its own flag and takes nothing else away: the header's bold
    // and the link's own colour and underline survive an italic layered on top.
    $withEmphasis = linkFixesProperties(linkFixesXml($emphasised, linkFixesDeferred()), 'plain');

    expect($withEmphasis)->toContain(...$expected);
    expect(array_values(array_diff($withEmphasis, $expected)))->toBe(['i']);
});

it('writes a deferred link label in the configured link font', function () {
    $config = linkFixesDeferred((new Configuration())->withStyles([
        Styles::LINK_FONT => ['color' => 'B22222', 'underline' => 'single'],
    ]));

    $xml = linkFixesXml("[*italic*](https://example.com)\n", $config);

    expect(linkFixesProperties($xml, 'italic'))->toEqual(['color=B22222', 'i', 'u=single']);
});

it('writes a deferred link label in a link font named by a style name', function () {
    // A slot configured as a string names a style in the target document, and
    // the deferred path has to fall back to the built-in array rather than lose
    // the link entirely.
    $config = (new Configuration())->withStyles([Styles::LINK_FONT => null]);

    $xml = linkFixesXml("[*italic*](https://example.com)\n", linkFixesDeferred($config));

    expect(linkFixesProperties($xml, 'italic'))->toEqual(['i']);
});

/*
 * The recorded payload.
 *
 * The faults above are in the writing, but both start in what the collector
 * records, so the recording is pinned here too: it is the part of the round trip
 * a reader can inspect without unzipping anything.
 */

it('records the emphasis of a link label', function () {
    $converter = new MarkdownToWord();
    $converter->toPhpWord("[**bold** *and* ~~struck~~](https://example.com)\n");

    $payloads = $converter->pendingHyperlinks();

    expect($payloads)->toHaveCount(1);
    expect(array_column($payloads[0]['runs'], 'style'))->toBe([
        ['color' => '0563C1', 'underline' => 'single', 'bold' => true],
        ['color' => '0563C1', 'underline' => 'single'],
        ['color' => '0563C1', 'underline' => 'single', 'italic' => true],
        ['color' => '0563C1', 'underline' => 'single'],
        ['color' => '0563C1', 'underline' => 'single', 'strikethrough' => true],
    ]);
});

it('records the link font and the font forced by the surrounding block', function () {
    $markdown = "| [*italic*](https://example.com) |\n| --- |\n| x |\n";

    $converter = new MarkdownToWord();
    $converter->toPhpWord($markdown);

    $payloads = $converter->pendingHyperlinks();

    expect($payloads)->toHaveCount(1);
    expect($payloads[0]['runs'])->toBe([
        ['text' => 'italic', 'style' => ['bold' => true, 'color' => '0563C1', 'underline' => 'single', 'italic' => true]],
    ]);
});

/*
 * The relationship the link is given.
 *
 * A link in a part of the package is a relationship in that part's own relationship
 * part, and the id of that relationship has to be one the part has not used. The
 * count is taken from the relationships themselves, because an id is an attribute
 * and an attribute may be written in more than one spelling.
 */

it('gives the link an id the part has not used', function () {
    $placeholder = LinkPlaceholder::forIndex(0);

    $path = TemplateFactory::rawPackage('quoted-relationships', [
        'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<w:body><w:p><w:r><w:t>' . $placeholder . '</w:t></w:r></w:p></w:body>'
            . '</w:document>',
        'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . "<Relationship Id='rId1' Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>"
            . '</Relationships>',
    ]);

    (new HyperlinkPass([[
        'placeholder' => $placeholder,
        'url' => 'https://example.test',
        'title' => null,
        'runs' => [['text' => 'the link', 'style' => []]],
    ]]))->applyTo($path);

    // `Id='rId1'` is the same relationship as `Id="rId1"`, so the id the link is
    // given is the second one and not the first one a second time: two
    // relationships with the same id in one part make the link behind them
    // ambiguous.
    expect(TemplateFactory::xmlOf($path, 'word/_rels/document.xml.rels'))
        ->toContain('Id="rId2"')
        ->and(TemplateFactory::xmlOf($path))->toContain('r:id="rId2"');
});
