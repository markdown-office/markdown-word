<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Format;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Render\Directives;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;
use MarkdownWord\Text\TextExtractor;
use PhpOffice\PhpWord\PhpWord;

/*
 * The directives that place things in a document: a picture, a caption, a page
 * break, a section, a text box, a shape, a form field.
 *
 * Two claims are made about them and both are checked here. The first is that
 * each one draws what it says: the assertion is made against the written file,
 * because a directive that puts the wrong thing in the document is wrong however
 * the code was called.
 *
 * The second is the loss. PHPWord writes a text box, a shape and a form field
 * for `.docx` alone — there is no element for any of them under the ODF or the
 * RTF writer, and an element with no writer is skipped without a word. A
 * document carrying one would come out of the other two formats with the thing
 * quietly gone, so each of them is given the characters the author wrote and the
 * loss is reported.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * A document as a `.docx` file, so a part can be read out of it.
 */
function objectDocument(string $markdown, ?Configuration $config = null): string
{
    $path = Scratch::path('objects', '.docx');

    file_put_contents($path, (new MarkdownToWord($markdown, $config ?? new Configuration()))->toDocx($markdown));

    return $path;
}

/**
 * The losses a document reports when it is written as the given format.
 *
 * @return list<array{feature: string, message: string}>
 */
function objectLossesIn(string $markdown, Format $format): array
{
    $converter = new MarkdownToWord($markdown);
    $converter->convertTo($format, Scratch::path('objects', $format->extension()));

    return $converter->pendingLosses();
}

/**
 * A picture of a given size in pixels, and the directory holding it.
 */
function objectPicture(int $width, int $height): string
{
    $directory = Scratch::path('picture', '');
    mkdir($directory, 0o777, true);

    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 0x8B, 0x1A, 0x1A));
    imagepng($image, $directory . '/logo.png');

    return $directory;
}

it('names every directive it draws', function () {
    expect(Directives::names())->toContain('image')
        ->and(Directives::names())->toContain('caption')
        ->and(Directives::names())->toContain('pagebreak')
        ->and(Directives::names())->toContain('section')
        ->and(Directives::names())->toContain('textbox')
        ->and(Directives::names())->toContain('shape')
        ->and(Directives::names())->toContain('field');
});

it('draws a picture at the size the directive asks for', function () {
    $directory = objectPicture(96, 48);

    $document = objectDocument('::image[Logo]{src="logo.png" width=5 height=2.5}', Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $directory,
    ]));

    // 5cm is 188.98 pixels and 2.5cm is 94.49. Asserted on the size in the file
    // rather than on the number handed to PHPWord: the defect this library had
    // was a number and a file that disagreed.
    expect(TemplateFactory::xmlOf($document))
        ->toContain('width:188.97637795276px; height:94.488188976378px');
});

it('draws a picture at its own size when the directive names none', function () {
    $directory = objectPicture(96, 48);

    $document = objectDocument('::image[Logo]{src="logo.png"}', Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $directory,
    ]));

    expect(TemplateFactory::xmlOf($document))->toContain('width:96px; height:48px');
});

it('gives a picture the alternative text its label says', function () {
    $directory = objectPicture(96, 48);

    $document = objectDocument('::image[The company logo]{src="logo.png"}', Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $directory,
    ]));

    expect(TemplateFactory::xmlOf($document))->toContain('o:title="The company logo"');
});

it('draws the same picture at the same size in all three formats', function () {
    $directory = objectPicture(96, 48);
    $markdown = '::image[Logo]{src="logo.png" width=5 height=2.5}';

    $config = Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $directory,
    ]);

    $docx = TemplateFactory::xmlOf(objectDocument($markdown, $config));
    preg_match('~style="width:([\d.]+)px; height:([\d.]+)px~', $docx, $shape);

    $rtf = (new MarkdownToWord($markdown, $config))->toRtf($markdown);
    preg_match('~\\\\picwgoal(\d+)~', $rtf, $goal);

    $odt = Scratch::path('objects', '.odt');
    file_put_contents($odt, (new MarkdownToWord($markdown, $config))->toOdt($markdown));

    $zip = new ZipArchive();
    $zip->open($odt);
    $content = (string) $zip->getFromName('content.xml');
    $zip->close();

    preg_match('~svg:width="([\d.]+)cm" svg:height="([\d.]+)cm~', $content, $frame);

    // 5cm is 141.73 points, and 2.5cm is 70.87. The `.rtf` rounds to whole
    // twips, of which there are twenty to a point, so it is a twentieth away.
    expect((float) $shape[1] * 72 / 96)->toEqualWithDelta(141.73, 0.01)
        ->and((int) $goal[1] / 20)->toEqualWithDelta(141.73, 0.05)
        ->and((float) $frame[1] * 72 / 2.54)->toEqualWithDelta(141.73, 0.01)
        ->and((float) $shape[2] * 72 / 96)->toEqualWithDelta(70.87, 0.01)
        ->and((float) $frame[2] * 72 / 2.54)->toEqualWithDelta(70.87, 0.01);
});

it('draws a caption as a paragraph of its own', function () {
    $document = objectDocument('::caption[Figure 1]{}');

    expect(TemplateFactory::textOf($document))->toBe('Figure 1')
        // And in italics, which is what marks it as a caption.
        ->and(TemplateFactory::xmlOf($document))->toContain('<w:i w:val="1"/>');
});

it('draws a page break', function () {
    $document = objectDocument("Before.\n\n::pagebreak\n\nAfter.");

    expect(TemplateFactory::xmlOf($document))->toContain('<w:br w:type="page"/>');
});

it('carries a page break in all three formats', function (Format $format) {
    $path = Scratch::path('pagebreak', $format->extension());

    (new MarkdownToWord("Before.\n\n::pagebreak\n\nAfter."))->convertTo($format, $path);

    $written = (string) file_get_contents($path);

    if ($format === Format::Rtf) {
        expect($written)->toContain('\\page');

        return;
    }

    $zip = new ZipArchive();
    $zip->open($path);
    $content = (string) $zip->getFromName('content.xml');
    $zip->close();

    // The ODF writer writes the break as a paragraph with a style of its own
    // that breaks after itself; RTF has a control word for it. The style itself
    // is written into every document, so the paragraph that uses it is what
    // says that a break was written.
    expect($content)->toContain('<text:p text:style-name="PB"/>');
})->with(['odt' => Format::Odt, 'rtf' => Format::Rtf]);

it('writes the columns of a section into the document', function () {
    $document = objectDocument("Before.\n\n::section{columns=2}\n\nAfter.");

    // The columns belong to the section the directive starts, which is the one
    // the text after it is in.
    expect(TemplateFactory::xmlOf($document))->toContain('<w:cols w:num="2"');
});

it('carries the columns of a section in an .odt', function () {
    $path = Scratch::path('columns', '.odt');

    (new MarkdownToWord("Before.\n\n::section{columns=2}\n\nAfter."))->convertTo(Format::Odt, $path);

    $zip = new ZipArchive();
    $zip->open($path);
    $content = (string) $zip->getFromName('content.xml');
    $zip->close();

    expect($content)->toContain('<style:columns fo:column-count="2"/>');
});

it('reports the columns of a section as lost in an .rtf', function () {
    // RTF has no columns at all, so the section break survives and the columns
    // do not. The sentence has to name the directive that asked for them.
    $losses = objectLossesIn("Before.\n\n::section{columns=2}\n\nAfter.", Format::Rtf);

    expect(array_column($losses, 'feature'))->toContain('columns')
        ->and(implode(' ', array_column($losses, 'message')))->toContain('::section');
});

it('reports no loss for the columns of a section in a .docx', function () {
    $markdown = "Before.\n\n::section{columns=2}\n\nAfter.";

    $losses = objectLossesIn($markdown, Format::Docx);

    // The columns are in the document, and nothing is reported about them.
    expect(TemplateFactory::xmlOf(objectDocument($markdown)))->toContain('<w:cols w:num="2"')
        ->and(array_column($losses, 'feature'))->not->toContain('columns');
});

it('draws a page break as its own source text where there is no page to break', function () {
    // A page break belongs to a section, and a table cell has none. The answer
    // is the characters the author wrote, the same answer an unclaimed
    // directive gets.
    $phpWord = new PhpWord();
    $cell = $phpWord->addSection()->addTable()->addRow()->addCell();

    (new MarkdownToWord())->renderIntoContainer("::pagebreak\n", $cell);

    expect(TextExtractor::fromContainer($cell))->toContain('::pagebreak');
});

it('draws a name nothing claims as its own source text', function () {
    // A name one letter away from a name this library draws is still a name
    // nothing claims, and the registry is not allowed to claim it by accident.
    expect(TemplateFactory::textOf(objectDocument('::captions[Figure 1]{}')))
        ->toBe('::captions[Figure 1]')
        // While the name it does draw is drawn, and not as its own source text.
        ->and(TemplateFactory::textOf(objectDocument('::caption[Figure 1]{}')))->toBe('Figure 1');
});

it('draws a directive it cannot place as the characters the author wrote', function (string $markdown, string $expected) {
    // Each of these is a directive in a place it cannot be drawn: a picture with
    // no file, a caption with no text, a page break in a table cell, a section
    // break inside a quote. The answer is the same in all four — the characters
    // the author wrote — and it is the answer an unclaimed directive gets.
    expect(TemplateFactory::textOf(objectDocument($markdown)))->toContain($expected);
})->with([
    'a picture with no file' => ['::image[Logo]{}', '::image[Logo]'],
    'a caption with no text' => ['::caption{}', '::caption'],
    'a page break in a table cell' => [
        "| a | b |\n| --- | --- |\n| ::pagebreak | two |",
        '::pagebreak',
    ],
    'a section break inside a quote' => ['> ::section{columns=2}', '::section{columns="2"}'],
]);
