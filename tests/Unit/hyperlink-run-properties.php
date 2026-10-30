<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;

/*
 * The order of a link's run properties.
 *
 * `w:rPr` is a sequence in ECMA-376's `wml.xsd` — `EG_RPrBase` — and not a set,
 * so a reader walks the children in the order they are written and stops at the
 * first one it did not expect. A document whose run properties are out of that
 * order is one Word refuses to open.
 *
 * A link's properties are written by hand, by {@see \MarkdownWord\Writer\HyperlinkPass},
 * because PHPWord's `Link` element holds one plain string and cannot carry a
 * label with emphasis in it. So the order is this library's to get right, and the
 * schema's order is `rFonts`, `b`, `i`, `strike`, `color`, `sz`, `u`.
 */

/**
 * The children of the first link run's `w:rPr`, in the order they are written.
 *
 * @return list<string>
 */
function hyperlinkRunPropertyNames(string $markdown, ?Configuration $config = null): array
{
    $path = Scratch::path('hyperlink-run-properties');

    saveDocument($markdown, $path, $config);

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadXML(TemplateFactory::xmlOf($path), LIBXML_NOCDATA);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $hyperlink = $xpath->query('//w:hyperlink')?->item(0);

    if (!$hyperlink instanceof DOMElement) {
        throw new RuntimeException('The document has no hyperlink.');
    }

    $names = [];

    foreach ($xpath->query('./w:r[1]/w:rPr/*', $hyperlink) ?: [] as $child) {
        if ($child instanceof DOMElement) {
            $names[] = $child->localName;
        }
    }

    return $names;
}

/**
 * A link font carrying every property a run can hold, so the order is the whole
 * of what the document says about the run.
 */
function hyperlinkRunPropertiesConfig(): Configuration
{
    return (new Configuration())
        ->withOptions(['deferredHyperlinks' => true])
        ->withStyles([
            Styles::LINK_FONT => [
                'name' => 'Consolas',
                'size' => 11,
                'color' => 'B22222',
                'bold' => true,
                'italic' => true,
                'strikethrough' => true,
                'underline' => 'single',
            ],
        ]);
}

it('writes the run properties of a link in the order the schema names them', function () {
    $properties = hyperlinkRunPropertyNames(
        '[label](https://example.com)',
        hyperlinkRunPropertiesConfig(),
    );

    expect($properties)->toBe(['rFonts', 'b', 'i', 'strike', 'color', 'sz', 'u']);
});

it('writes the run properties of a link with emphasis in the order the schema names them', function () {
    // The path a label with emphasis takes on its own: the label cannot be one
    // plain string, so the runs are recorded and written afterwards.
    $properties = hyperlinkRunPropertyNames('[*label*](https://example.com)');

    expect($properties)->toBe(['i', 'color', 'u']);
});

it('writes the typeface of a link before the properties that follow it', function () {
    // `w:rFonts` is the second element the schema names and the last one a
    // reader that walks the sequence meets, so a run that carries a typeface
    // says so before anything else.
    $properties = hyperlinkRunPropertyNames('[label](https://example.com)', hyperlinkRunPropertiesConfig());

    expect($properties[0])->toBe('rFonts');
});

it('writes the underline of a link after the size of its run', function () {
    // `w:u` is the twenty-seventh element of the sequence and the last of the
    // ones a link carries, so it is written last.
    $properties = hyperlinkRunPropertyNames('[label](https://example.com)', hyperlinkRunPropertiesConfig());

    expect($properties[array_key_last($properties)])->toBe('u');
});
