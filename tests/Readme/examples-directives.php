<?php

declare(strict_types=1);

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Configuration;
use MarkdownWord\Format;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

/*
 * The two examples about the directives, and what they claim.
 *
 * `28-inline-styles` is a page about a word written in a style, and `29-objects`
 * is a page about the seven names that place a picture, a caption, a page break,
 * a section, a box, a shape and a field in a document. Both are examples rather
 * than documentation, so the claims they make are claims about a *document* —
 * and a document is not established by reading its Markdown.
 *
 * So each test here converts the example and reads the result: the colours a
 * named style carries, the size a picture is written at, and what each of the
 * three formats does with a name it cannot carry. A test that read the Markdown
 * would pass whatever the library did with it.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

const EXAMPLES_DIRECTORY = __DIR__ . '/../../examples/markdown';

/**
 * The Markdown of an example, read from where the examples keep their input.
 */
function exampleMarkdown(string $name): string
{
    return (string) file_get_contents(EXAMPLES_DIRECTORY . '/' . $name . '.md');
}

/**
 * A parser that reads the front matter and the directives at once, which is
 * what a document naming a theme and writing a word in one of its styles needs.
 */
function exampleDirectiveParser(): CommonMarkParser
{
    return new CommonMarkParser([
        ...CommonMarkParser::FLAVOURS[CommonMarkParser::DEFAULT_FLAVOUR],
        FrontMatterExtension::class,
    ]);
}

/**
 * The example as a document of the given format, and the converter that made it.
 *
 * @return array{0: MarkdownToWord, 1: string}
 */
function exampleDocument(string $name, Format $format): array
{
    $converter = new MarkdownToWord(
        exampleMarkdown($name),
        Configuration::create()->withOptions([
            'imageBasePath' => realpath(EXAMPLES_DIRECTORY),
        ]),
        exampleDirectiveParser(),
    );

    $path = Scratch::path('example-' . $name, $format->extension());
    $converter->convertTo($format, $path);

    return [$converter, $path];
}

/**
 * Everything a reader of the document is shown: the body for a zipped format,
 * the whole file for an RTF, which is not one.
 */
function exampleBody(string $path): string
{
    if (str_ends_with($path, '.rtf')) {
        return (string) file_get_contents($path);
    }

    if (str_ends_with($path, '.odt')) {
        $zip = new ZipArchive();
        $zip->open($path);
        $content = (string) $zip->getFromName('content.xml');
        $zip->close();

        return $content;
    }

    return TemplateFactory::xmlOf($path);
}

/**
 * The runs of the document, with the properties each one carries.
 *
 * @return list<array{text: string, colour: ?string, bold: bool, italic: bool}>
 */
function exampleRuns(string $path): array
{
    $zip = new ZipArchive();
    $zip->open($path);
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();

    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $runs = [];

    foreach ($xpath->query('//w:r') ?: [] as $run) {
        $text = '';

        foreach ($run->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 't') as $node) {
            $text .= $node->textContent;
        }

        if ($text === '') {
            continue;
        }

        $properties = $run->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'rPr');
        $property = $properties->item(0);

        $colour = null;
        $bold = false;
        $italic = false;

        if ($property instanceof DOMElement) {
            $colourNode = $property->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'color')->item(0);
            $colour = $colourNode instanceof DOMElement ? $colourNode->getAttribute('w:val') : null;

            $boldNode = $property->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'b')->item(0);
            $bold = $boldNode instanceof DOMElement && $boldNode->getAttribute('w:val') !== '0';

            $italicNode = $property->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'i')->item(0);
            $italic = $italicNode instanceof DOMElement && $italicNode->getAttribute('w:val') !== '0';
        }

        $runs[] = ['text' => $text, 'colour' => $colour, 'bold' => $bold, 'italic' => $italic];
    }

    return $runs;
}

/**
 * The run holding a word, or null when there is none.
 *
 * @return array{text: string, colour: ?string, bold: bool, italic: bool}|null
 */
function exampleRunWith(string $path, string $word): ?array
{
    foreach (exampleRuns($path) as $run) {
        if (str_contains($run['text'], $word)) {
            return $run;
        }
    }

    return null;
}

// The inline styles

it('writes a word in the named style the theme brings', function () {
    [, $path] = exampleDocument('28-inline-styles', Format::Docx);

    $brand = exampleRunWith($path, 'Orrery');
    $muted = exampleRunWith($path, 'Cast by hand');

    // The theme's own two styles: `brand` is blue and bold, `muted` is grey and
    // a size smaller than the body's.
    expect($brand)->not->toBeNull()
        ->and($brand['colour'])->toBe('0B5CAB')
        ->and($brand['bold'])->toBeTrue()
        ->and($muted)->not->toBeNull()
        ->and($muted['colour'])->toBe('6B7280');
});

it('writes the raw form in the colour it is given and in no other style', function () {
    [, $path] = exampleDocument('28-inline-styles', Format::Docx);

    $runs = exampleRuns($path);
    $raw = null;

    foreach ($runs as $run) {
        if ($run['colour'] === '8B0000' && !$run['bold']) {
            $raw = $run;
        }
    }

    // There is no named style behind the reserved name, so there is no weight
    // to inherit: the word is in the colour and in nothing else.
    expect($raw)->not->toBeNull();
});

it('overrides one property of a named style and leaves the rest standing', function () {
    [, $path] = exampleDocument('28-inline-styles', Format::Docx);

    $runs = exampleRuns($path);
    $overridden = null;

    foreach ($runs as $run) {
        if ($run['colour'] === '8B0000' && $run['bold']) {
            $overridden = $run;
        }
    }

    // The attribute wins for the colour it names; the theme's weight stands,
    // because the attribute said nothing about it.
    expect($overridden)->not->toBeNull();
});

it('writes a name it cannot draw as the characters the author typed', function () {
    [, $path] = exampleDocument('28-inline-styles', Format::Docx);

    $text = TemplateFactory::textOf($path);

    // The unknown name and the label that holds Markdown both come back as
    // their own source form, in the middle of the sentences that hold them.
    expect($text)->toContain(':ember[Orrery]')
        ->and($text)->toContain(':brand[**Orrery**]');
});

// The objects

it('writes the picture at the size the document asked for, in every format', function (Format $format) {
    [, $path] = exampleDocument('29-objects', $format);
    $body = exampleBody($path);

    // 6.35 cm by 3.81 cm is 240 by 144 pixels at 96 dpi, and every format says
    // the same size in its own unit.
    expect($body)->toContain(match ($format) {
        Format::Docx => 'width:240px; height:144px',
        Format::Odt => 'svg:width="6.35cm" svg:height="3.81cm"',
        Format::Rtf => '\picwgoal3600\pichgoal2160',
    });
})->with(Format::cases());

it('carries the caption and the page break in every format', function (Format $format) {
    [, $path] = exampleDocument('29-objects', $format);
    $body = exampleBody($path);

    expect($body)->toContain('Figure 1: The house rule')
        ->and($body)->toContain(match ($format) {
            Format::Docx => '<w:br w:type="page"/>',
            Format::Odt => 'fo:break-after="page"',
            Format::Rtf => '\page',
        });
})->with(Format::cases());

it('carries the columns of a section in two of the three formats', function (Format $format) {
    [, $path] = exampleDocument('29-objects', $format);
    $body = exampleBody($path);

    // RTF has no columns at all, so the break survives and the columns do not.
    expect($body)->toContain(match ($format) {
        Format::Docx => '<w:cols w:num="2"',
        Format::Odt => 'fo:column-count="2"',
        Format::Rtf => '\sect',
    });

    // The columns themselves: in the `.docx` and the `.odt`, and in neither of
    // them for the `.rtf`.
    $columns = str_contains($body, 'fo:column-count') || str_contains($body, 'w:cols w:num="2"');

    expect($columns)->toBe($format !== Format::Rtf);
})->with(Format::cases());

it('draws the three Word-only names in a .docx', function () {
    [, $path] = exampleDocument('29-objects', Format::Docx);
    $body = exampleBody($path);

    expect($body)->toContain('<v:textbox>')
        ->and($body)->toContain('<v:rect')
        ->and($body)->toContain('FORMTEXT')
        ->and($body)->toContain('FORMDROPDOWN')
        // And not the characters the author wrote, which is the other half of it.
        ->and($body)->not->toContain('::textbox')
        ->and($body)->not->toContain('::shape');
});

it('gives a format that cannot carry a name the characters the author wrote', function (Format $format) {
    [, $path] = exampleDocument('29-objects', $format);
    $body = exampleBody($path);

    // The braces are escaped in an RTF, so the names are looked for without them.
    expect($body)->toContain('::textbox[')
        ->and($body)->toContain('::shape')
        ->and($body)->toContain('::field');
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);

it('reports what each of the two formats could not carry', function (Format $format) {
    [$converter] = exampleDocument('29-objects', $format);

    $features = array_column($converter->pendingLosses(), 'feature');

    expect($features)->toContain('text-box')
        ->and($features)->toContain('shape')
        ->and($features)->toContain('form-field')
        // The columns are the one RTF has no room for.
        ->and(in_array('columns', $features, true))->toBe($format === Format::Rtf);
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);
