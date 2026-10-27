<?php

declare(strict_types=1);

use MarkdownWord\Format;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

/*
 * The three things only a Word document can carry: a text box, a shape, a form
 * field.
 *
 * PHPWord writes a text box and a shape as VML and a form field as Word's own
 * `FORMTEXT` / `FORMCHECKBOX` / `FORMDROPDOWN` field. Neither the ODF writer nor
 * the RTF writer has an element for any of them, and an element with no writer
 * is skipped without a word: a document carrying one would come out of the other
 * two formats with the thing quietly gone.
 *
 * So each of them is given the characters the author wrote in a format that
 * cannot carry it, and the loss is reported through `Format::drops()`.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * A document as a `.docx` file, so a part can be read out of it.
 */
function wordObjectDocument(string $markdown): string
{
    $path = Scratch::path('word-objects', '.docx');

    file_put_contents($path, (new MarkdownToWord($markdown))->toDocx($markdown));

    return $path;
}

/**
 * The losses a document reports when it is written as the given format.
 *
 * @return list<array{feature: string, message: string}>
 */
function wordObjectLossesIn(string $markdown, Format $format): array
{
    $converter = new MarkdownToWord($markdown);
    $converter->convertTo($format, Scratch::path('word-objects', $format->extension()));

    return $converter->pendingLosses();
}

it('draws a text box with its text in it', function () {
    $document = objectDocument('::textbox[Hello there]{width=6 height=3}');

    expect(TemplateFactory::xmlOf($document))
        ->toContain('<v:textbox>')
        ->and(TemplateFactory::textOf($document))->toContain('Hello there');
});

it('draws a shape', function () {
    $document = objectDocument('::shape{type=rect width=6 height=3 fill=FF0000}');

    expect(TemplateFactory::xmlOf($document))
        ->toContain('<v:rect')
        ->and(TemplateFactory::xmlOf($document))->toContain('fillcolor="FF0000"');
});

it('draws a form field as the field Word reads', function () {
    $document = objectDocument('::field{name="Customer"}');

    expect(TemplateFactory::xmlOf($document))
        ->toContain('FORMTEXT')
        ->and(TemplateFactory::xmlOf($document))->toContain('<w:name w:val="Customer"/>');
});

it('draws a dropdown with the entries it offers', function () {
    $document = objectDocument('::field{name="City" type=dropdown entries="Vienna,Berlin"}');

    expect(TemplateFactory::xmlOf($document))
        ->toContain('FORMDROPDOWN')
        ->and(TemplateFactory::xmlOf($document))->toContain('<w:listEntry w:val="Vienna"/>')
        ->and(TemplateFactory::xmlOf($document))->toContain('<w:listEntry w:val="Berlin"/>');
});

it('protects a document with a dropdown, which is what makes it work', function () {
    $path = Scratch::path('dropdown', '.docx');

    (new MarkdownToWord('::field{name="City" type=dropdown entries="Vienna"}'))->save($path);

    $settings = TemplateFactory::xmlOf($path, 'word/settings.xml');

    expect($settings)->toContain('w:documentProtection')
        ->and($settings)->toContain('w:edit="forms"');
});

it('reports a text box as lost in the formats that cannot carry one', function (Format $format) {
    $losses = objectLossesIn('::textbox[Hello there]{width=6 height=3}', $format);

    expect(array_column($losses, 'feature'))->toContain('text-box')
        ->and(implode(' ', array_column($losses, 'message')))->toContain('::textbox');
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);

it('reports a shape as lost in the formats that cannot carry one', function (Format $format) {
    $losses = objectLossesIn('::shape{type=rect width=6 height=3}', $format);

    expect(array_column($losses, 'feature'))->toContain('shape')
        ->and(implode(' ', array_column($losses, 'message')))->toContain('::shape');
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);

it('reports a form field as lost in the formats that cannot carry one', function (Format $format) {
    $losses = objectLossesIn('::field{name="Customer"}', $format);

    expect(array_column($losses, 'feature'))->toContain('form-field')
        ->and(implode(' ', array_column($losses, 'message')))->toContain('::field');
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);

it('reports no loss for a text box, a shape or a form field in a .docx', function () {
    $markdown = "::textbox[Hello]{width=6 height=3}\n\n::shape{type=rect width=6 height=3}\n\n::field{name=\"Customer\"}";

    $document = objectDocument($markdown);
    $losses = objectLossesIn($markdown, Format::Docx);

    // The three of them are in the document, and nothing is reported about them.
    expect(TemplateFactory::xmlOf($document))->toContain('<v:textbox>')
        ->and(TemplateFactory::xmlOf($document))->toContain('<v:rect')
        ->and(TemplateFactory::xmlOf($document))->toContain('FORMTEXT')
        ->and(array_column($losses, 'feature'))
        ->not->toContain('text-box')
        ->not->toContain('shape')
        ->not->toContain('form-field');
});

it('gives a format that cannot carry a directive the characters the author wrote', function (Format $format) {
    $path = Scratch::path('source-form', $format->extension());

    $converter = new MarkdownToWord('::textbox[Hello there]{width=6 height=3}');
    $converter->convertTo($format, $path);

    $written = (string) file_get_contents($path);

    if ($format === Format::Rtf) {
        expect($written)->toContain('::textbox');
    } else {
        $zip = new ZipArchive();
        $zip->open($path);
        $content = (string) $zip->getFromName('content.xml');
        $zip->close();

        expect($content)->toContain('::textbox');
    }

    // And the loss is reported, which is what tells the two apart from a name
    // this library does not draw at all.
    expect(array_column($converter->pendingLosses(), 'feature'))->toContain('text-box');
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);

it('draws a text box in a .docx rather than the characters the author wrote', function () {
    // The other half of the test above: the source form is what a writer that
    // cannot carry the directive is given, and it is not what a `.docx` gets.
    $document = objectDocument('::textbox[Hello there]{width=6 height=3}');

    expect(TemplateFactory::xmlOf($document))->not->toContain('::textbox')
        ->and(TemplateFactory::xmlOf($document))->toContain('<v:textbox>');
});

it('draws a name nothing claims as its own source text', function () {
    // A name one letter away from a name this library draws is still a name
    // nothing claims, and the registry is not allowed to claim it by accident.
    expect(TemplateFactory::textOf(objectDocument('::captions[Figure 1]{}')))
        ->toBe('::captions[Figure 1]')
        // While the name it does draw is drawn, and not as its own source text.
        ->and(TemplateFactory::textOf(objectDocument('::caption[Figure 1]{}')))->toBe('Figure 1');
});
