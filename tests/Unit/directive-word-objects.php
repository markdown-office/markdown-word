<?php

declare(strict_types=1);

use MarkdownWord\Exception\InvalidInput;
use MarkdownWord\Format;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;
use MarkdownWord\Text\TextExtractor;

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
    $document = wordObjectDocument('::textbox[Hello there]{width=6 height=3}');

    expect(TemplateFactory::xmlOf($document))
        ->toContain('<v:textbox>')
        ->and(TemplateFactory::textOf($document))->toContain('Hello there');
});

it('draws a text box floating, with a border round it', function () {
    $document = wordObjectDocument('::textbox[Hello there]{width=6 height=3}');
    $xml = TemplateFactory::xmlOf($document);

    // A box that sat in the flow would be a paragraph with a border round it.
    expect($xml)->toContain('position:absolute')
        // And PHPWord draws no border unless it is given the colour of one.
        ->and($xml)->toContain('<v:stroke weight="1pt" color="#000000"/>');
});

it('places a text box where the directive says', function () {
    $document = wordObjectDocument('::textbox[Hello there]{width=6 height=3 left=2 top=1}');
    $xml = TemplateFactory::xmlOf($document);

    // Two centimetres from the left margin and one from the top of the paragraph,
    // in points, because that is the unit a VML shape is measured in.
    expect($xml)->toContain('margin-left:56.692913385827pt')
        ->and($xml)->toContain('margin-top:28.346456692913pt');
});

it('draws a shape', function () {
    $document = wordObjectDocument('::shape{type=rect width=6 height=3 fill=FF0000}');

    expect(TemplateFactory::xmlOf($document))
        ->toContain('<v:rect')
        ->and(TemplateFactory::xmlOf($document))->toContain('fillcolor="#FF0000"');
});

it('draws a form field as the field Word reads', function () {
    $document = wordObjectDocument('::field{name="Customer"}');

    expect(TemplateFactory::xmlOf($document))
        ->toContain('FORMTEXT')
        ->and(TemplateFactory::xmlOf($document))->toContain('<w:name w:val="Customer"/>');
});

it('draws a dropdown with the entries it offers', function () {
    $document = wordObjectDocument('::field{name="City" type=dropdown entries="Vienna,Berlin"}');

    expect(TemplateFactory::xmlOf($document))
        ->toContain('FORMDROPDOWN')
        ->and(TemplateFactory::xmlOf($document))->toContain('<w:listEntry w:val="Vienna"/>')
        ->and(TemplateFactory::xmlOf($document))->toContain('<w:listEntry w:val="Berlin"/>');
});

it('refuses a value on a dropdown, and keeps it on a text field', function () {
    // Word records a dropdown's choice as the position of the chosen entry in the
    // list, not as its text: a `w:default` of `ST_DecimalNumber` would hold a word
    // where a number belongs.
    $dropdown = '::field{name="City" type=dropdown entries="Vienna,Berlin" value="Berlin"}';
    $text = '::field{name="Customer" value="Vienna"}';

    expect(fn () => (new MarkdownToWord($dropdown))->toDocx($dropdown))
        ->toThrow(InvalidInput::class, 'The "value" attribute of a dropdown field')
        ->and(TemplateFactory::xmlOf(wordObjectDocument($text)))->toContain('<w:default w:val="Vienna"/>');
});

it('draws a dropdown that carries a value as its source text in the formats that cannot carry a field', function (Format $format) {
    // Only a `.docx` builds the field, so the other two formats never reach the
    // check: they cannot carry a field at all and give the author their own
    // characters back, as they do every other directive.
    $markdown = '::field{name="City" type=dropdown entries="Vienna,Berlin" value="Berlin"}';

    $losses = wordObjectLossesIn($markdown, $format);

    expect(array_column($losses, 'feature'))->toContain('form-field')
        ->and(implode(' ', array_column($losses, 'message')))->toContain('::field');
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);

it('protects a document with a dropdown, which is what makes it work', function () {
    $path = Scratch::path('dropdown', '.docx');

    (new MarkdownToWord('::field{name="City" type=dropdown entries="Vienna"}'))->save($path);

    $settings = TemplateFactory::xmlOf($path, 'word/settings.xml');

    expect($settings)->toContain('w:documentProtection')
        ->and($settings)->toContain('w:edit="forms"');
});

it('reports a text box as lost in the formats that cannot carry one', function (Format $format) {
    $losses = wordObjectLossesIn('::textbox[Hello there]{width=6 height=3}', $format);

    expect(array_column($losses, 'feature'))->toContain('text-box')
        ->and(implode(' ', array_column($losses, 'message')))->toContain('::textbox');
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);

it('reports a shape as lost in the formats that cannot carry one', function (Format $format) {
    $losses = wordObjectLossesIn('::shape{type=rect width=6 height=3}', $format);

    expect(array_column($losses, 'feature'))->toContain('shape')
        ->and(implode(' ', array_column($losses, 'message')))->toContain('::shape');
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);

it('reports a form field as lost in the formats that cannot carry one', function (Format $format) {
    $losses = wordObjectLossesIn('::field{name="Customer"}', $format);

    expect(array_column($losses, 'feature'))->toContain('form-field')
        ->and(implode(' ', array_column($losses, 'message')))->toContain('::field');
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);

it('reports no loss for a text box, a shape or a form field in a .docx', function () {
    $markdown = "::textbox[Hello]{width=6 height=3}\n\n::shape{type=rect width=6 height=3}\n\n::field{name=\"Customer\"}";

    $document = wordObjectDocument($markdown);
    $losses = wordObjectLossesIn($markdown, Format::Docx);

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
    $document = wordObjectDocument('::textbox[Hello there]{width=6 height=3}');

    expect(TemplateFactory::xmlOf($document))->not->toContain('::textbox')
        ->and(TemplateFactory::xmlOf($document))->toContain('<v:textbox>');
});

it('draws the outline of a shape in the colour it is given', function () {
    $document = wordObjectDocument('::shape{type=rect width=6 height=3 line=000000}');

    expect(TemplateFactory::xmlOf($document))->toContain('<v:stroke')
        ->and(TemplateFactory::xmlOf($document))->toContain('color="#000000"');
});

it('draws a checkbox that is ticked, and a field with a value in it', function () {
    $checkbox = wordObjectDocument('::field{name="Agreed" type=checkbox checked=true}');
    $text = wordObjectDocument('::field{name="Customer" value="Vienna"}');

    expect(TemplateFactory::xmlOf($checkbox))->toContain('FORMCHECKBOX')
        ->and(TemplateFactory::xmlOf($checkbox))->toContain('<w:checked w:val="1"/>')
        ->and(TemplateFactory::xmlOf($text))->toContain('<w:default w:val="Vienna"/>');
});

it('draws a directive it cannot place as the characters the author wrote', function (string $markdown, string $expected) {
    // A box with no size is not a box, a shape of a type Word cannot draw is not
    // a shape, and a field of a kind Word does not have is not a field. The
    // answer is the same in all three — the characters the author wrote.
    expect(TemplateFactory::textOf(wordObjectDocument($markdown)))->toContain($expected);
})->with([
    'a text box with no size' => ['::textbox[Hello]{}', '::textbox[Hello]'],
    'a shape of a type Word cannot draw' => ['::shape{type=blob}', '::shape{type="blob"}'],
    'a field of a kind Word does not have' => ['::field{type=slider}', '::field{type="slider"}'],
]);

it('reads a document with a form field back, and the text the field holds with it', function () {
    $markdown = '::field{name="Customer" value="Vienna"}';

    $converter = new MarkdownToWord($markdown);
    $text = TextExtractor::fromPhpWord($converter->toPhpWord($markdown));

    // The text of a form field is the text it holds: the default the author gave
    // it. The name is the form's identifier — the thing a script fills the field
    // in by — and it is not the document's wording.
    expect($text)->toBe('Vienna')
        ->and($text)->not->toContain('Customer');
});

it('reads a form field with nothing in it, and a checkbox, as nothing at all', function () {
    $empty = '::field{name="Customer"}';
    $tick = '::field{name="Agreed" type=checkbox checked=true}';

    $converter = new MarkdownToWord($empty);

    // A field with no default holds no text, and a checkbox is a box: a reader of
    // the extracted text finds neither the box nor the name it answers to.
    expect(TextExtractor::fromPhpWord($converter->toPhpWord($empty)))->toBe('')
        ->and(TextExtractor::fromPhpWord($converter->toPhpWord($tick)))->toBe('');
});
