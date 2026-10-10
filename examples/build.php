<?php

declare(strict_types=1);

/**
 * Builds the example documents so they can be opened and inspected.
 *
 *   php examples/build.php
 *
 * Markdown sources live in examples/markdown, the generated .docx files in
 * examples/out. A matching .png of the first page is produced too, if
 * LibreOffice is installed, so the result can be checked without Word.
 */

require __DIR__ . '/../vendor/autoload.php';

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Format;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\WordToMarkdown;
use MarkdownWord\Template\MarkdownTemplate;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\Paragraph;

$root = __DIR__;
$out = $root . '/out';
$assets = $root . '/markdown/assets';

@mkdir($out, 0o777, true);
@mkdir($assets, 0o777, true);

logo($assets . '/logo.png');

$built = [];

// The Markdown sources, rendered with the default configuration

$default = new Configuration();

foreach ([
    '01-kitchen-sink' => 'Every construct on one page.',
    '02-tables' => 'GFM pipe tables and column alignment.',
    '03-lists' => 'Nesting, custom start values, tight and loose lists.',
    '04-code' => 'Fenced, indented and inline code.',
    '05-links-and-images' => 'Hyperlinks with formatting in the label, and images.',
    '06-quotes' => 'Block quotes, including nesting and other block content.',
] as $name => $description) {
    $markdown = (string) file_get_contents($root . '/markdown/' . $name . '.md');

    // Images in the examples are relative to the Markdown file.
    $config = Configuration::create()->withOptions([
        'images' => Options::IMAGE_EMBED,
        'imageBasePath' => $root . '/markdown',
    ]);

    $path = $out . '/' . $name . '.docx';
    (new MarkdownToWord($markdown, $config))->save($path);

    $built[] = [$name, $description, $path];
}

// The same source, without any decoration
//
// Shows what the configuration controls, side by side with the default.

$path = $out . '/07-no-decoration.docx';
(new MarkdownToWord(
    (string) file_get_contents($root . '/markdown/01-kitchen-sink.md'),
    Configuration::create()->withoutDecoration(),
))->save($path);
$built[] = ['07-no-decoration', 'The kitchen sink with every default switched off.', $path];

// A house style, defined in code

$path = $out . '/08-house-style.docx';
(new MarkdownToWord(
    (string) file_get_contents($root . '/markdown/01-kitchen-sink.md'),
    houseStyle(),
))->save($path);
$built[] = ['08-house-style', 'The kitchen sink in a custom house style.', $path];

// The same source, with the extras a README tends to use

$path = $out . '/09-extended-parser.docx';
(new MarkdownToWord(
    "# With the extended parser\n\n"
    . "Footnotes[^1] and description lists are available here.\n\n"
    . "Term\n:   A word being defined\n"
    . ":   Another definition\n\n"
    . "[^1]: The note itself.\n",
    Configuration::create(),
    CommonMarkParser::extended(),
))->save($path);
$built[] = ['09-extended-parser', 'Footnotes and description lists.', $path];

// A Word template

$templatePath = $out . '/template-invoice.docx';
$outputPath = $out . '/10-template.docx';

buildInvoiceTemplate($templatePath);

(new MarkdownTemplate($templatePath, houseStyle(), ['number' => 'INV-2024-0042', 'customer' => 'Northwind Ltd']))
    ->repeat('lines', [
        ['description' => 'Licence renewal', 'amount' => '1,200.00'],
        ['description' => 'Priority support', 'amount' => '300.00'],
        ['description' => 'On-site training', 'amount' => '900.00'],
    ])
    ->insert('terms', "## Terms\n\nPayment is due within **14 days**.\n\n- Bank transfer\n- Card\n\n"
        . "> Late payment attracts interest at 4% per year.\n")
    ->save($outputPath);

$built[] = ['10-template', 'Markdown rendered into a Word template.', $outputPath];

// Frontmatter
//
// Three files, so the block can be seen doing something and seen losing.
//
// The parser matters as much as the block: frontmatter is read by the
// `FrontMatterExtension`, which the default dialect does not carry. Without it a
// leading `---` is a thematic break and the rest of the block is content, so the
// document would come out with the configuration printed at the top of it.

// The GFM flavour plus frontmatter. Naming only `FrontMatterExtension` would *replace*
// the default rather than add to it, and the tables in these examples would come out as
// paragraphs of pipes.
$frontmatterParser = new CommonMarkParser([
    ...CommonMarkParser::FLAVOURS['gfm'],
    FrontMatterExtension::class,
]);

// 11 — the block is read, and it configures the render.
$path = $out . '/11-frontmatter.docx';
(new MarkdownToWord(
    (string) file_get_contents($root . '/markdown/11-frontmatter.md'),
    Configuration::create(),
    $frontmatterParser,
))->save($path);
$built[] = ['11-frontmatter', 'Frontmatter configuring the conversion.', $path];

// 12 — the same Markdown with no block, for the pair.
$path = $out . '/12-frontmatter-none.docx';
(new MarkdownToWord(
    (string) file_get_contents($root . '/markdown/12-frontmatter-none.md'),
    Configuration::create(),
    $frontmatterParser,
))->save($path);
$built[] = ['12-frontmatter-none', 'The same Markdown with no frontmatter.', $path];

// 13 — the block is outranked by the configuration passed in code.
$path = $out . '/13-frontmatter-override.docx';
(new MarkdownToWord(
    (string) file_get_contents($root . '/markdown/13-frontmatter-override.md'),
    // Deliberately the opposite of the block: no borders, no heading cap, a plain
    // grey Arial H1 with no air around it. Where the two disagree, this wins.
    Configuration::create()->withOptions([
        'maxHeadingLevel' => 6,
        'tableBorders' => false,
    ])->withStyles([
        Styles::HEADING_1 => [
            'name' => 'Arial',
            'size' => 12,
            'bold' => false,
            'color' => '808080',
            'space' => ['before' => 0, 'after' => 0],
        ],
    ]),
    $frontmatterParser,
))->save($path);
$built[] = ['13-frontmatter-override', 'Frontmatter outranked by the configuration.', $path];

// 14 is `markdown/14-rejected.md`, which is deliberately not built: converting it is
// the failure it exists to demonstrate.

// Images
//
// One source, three documents. `images` is a single setting and a document cannot
// hold three of it, so the modes are shown side by side rather than in one file.
//
// `imageBasePath` is the other half of the example and it cannot come from the block:
// it is an absolute directory and the source is committed, so it is passed here and
// resolves `assets/logo.png` against `examples/markdown`.

$images = (string) file_get_contents($root . '/markdown/15-images.md');

foreach ([
    'embed' => Options::IMAGE_EMBED,
    'placeholder' => Options::IMAGE_PLACEHOLDER,
    'skip' => Options::IMAGE_SKIP,
] as $mode => $value) {
    $path = $out . '/15-images-' . $mode . '.docx';
    (new MarkdownToWord(
        $images,
        Configuration::create()->withOptions([
            'images' => $value,
            'imageBasePath' => $root . '/markdown',
        ]),
        $frontmatterParser,
    ))->save($path);

    $built[] = ['15-images-' . $mode, 'Images in ' . $mode . ' mode.', $path];
}

// Tables

foreach ([
    '16-styled-tables' => 'Table style slots from the frontmatter.',
    '17-borderless-tables' => 'The table options, with no style slot.',
] as $name => $description) {
    $path = $out . '/' . $name . '.docx';
    (new MarkdownToWord(
        (string) file_get_contents($root . '/markdown/' . $name . '.md'),
        Configuration::create(),
        $frontmatterParser,
    ))->save($path);

    $built[] = [$name, $description, $path];
}

// 19 — a vector, which is a different kind of thing in a Word file from a picture.
//
// Guarded rather than built unconditionally: the conversion needs `ext-imagick`,
// and an example that cannot be built on somebody's machine is worse than one that
// says why it was skipped.
if (\extension_loaded('imagick')) {
    $path = $out . '/19-vector.docx';
    // The same base path the other examples get: without it a relative source path
    // resolves against the working directory and the picture is not found, which
    // falls back to the alt text and produces a document with no picture in it.
    (new MarkdownToWord(
        (string) file_get_contents($root . '/markdown/19-vector.md'),
        Configuration::create()->withOptions([
            'images' => Options::IMAGE_EMBED,
            'imageBasePath' => $root . '/markdown',
        ]),
        $frontmatterParser,
    ))->save($path);
    $built[] = ['19-vector', 'An SVG, embedded as a vector beside its raster.', $path];
} else {
    echo "\nSkipped 19-vector: ext-imagick is not loaded, so an SVG cannot be embedded.\n";
}

// The way back


$roundTripped = (new WordToMarkdown($out . '/01-kitchen-sink.docx'))->convert();
file_put_contents($out . '/18-round-trip.md', $roundTripped);

$built[] = [
    '18-round-trip',
    'The kitchen sink read back out of 01-kitchen-sink.docx.',
    $out . '/18-round-trip.md',
];

// The other two output formats
//
// One source, three documents. The page carries everything the three formats are
// asked about, so the differences between them can be looked at rather than taken
// on trust: the `.rtf` has no list in it, the `.odt` has bullets where the
// numbers should be, and neither has a rule under the `---`.
//
// `.docx` is built here too, so the three are the same conversion and not three
// documents that happen to share a heading.

$formats = (string) file_get_contents($root . '/markdown/20-formats.md');

foreach (Format::cases() as $format) {
    $path = $out . '/20-formats' . $format->extension();
    $converter = new MarkdownToWord($formats, Configuration::create()->withOptions([
        'images' => Options::IMAGE_EMBED,
        'imageBasePath' => $root . '/markdown',
    ]), $frontmatterParser);

    $converter->convertTo($format, $path);

    $built[] = [
        '20-formats' . $format->extension(),
        sprintf('The format comparison page as %s %s.', $format->value === 'rtf' ? 'an' : 'a', $format->value),
        $path,
    ];
}

// Report

echo "\nBuilt:\n\n";

foreach ($built as [$name, $description, $path]) {
    printf("  %-22s %s\n", basename($path), $description);
}

echo "\n";

renderPreviews($built);

/**
 * A configuration that looks like a printed report rather than a Word default.
 */
function houseStyle(): Configuration
{
    return Configuration::create()
        ->withStyles([
            Styles::HEADING_1 => ['size' => 20, 'bold' => true, 'color' => '1B3A5C', 'space' => ['before' => 0, 'after' => 240]],
            Styles::HEADING_2 => ['size' => 15, 'bold' => true, 'color' => '1B3A5C', 'space' => ['before' => 280, 'after' => 120]],
            Styles::HEADING_3 => ['size' => 12, 'bold' => true, 'color' => '2E5F86', 'space' => ['before' => 200, 'after' => 80]],
            Styles::BLOCK_QUOTE => ['italic' => true, 'color' => '55606B', 'indentation' => ['left' => 567, 'right' => 567]],
            Styles::CODE_FONT => ['name' => 'Consolas', 'size' => 9, 'color' => '1F3864'],
            Styles::LINK_FONT => ['color' => '1B5E9B', 'underline' => 'single'],
            Styles::TABLE_CELL => ['size' => 10],
        ])
        ->withOptions([
            'codeBlockShading' => true,
            'tableBorders' => true,
            'orderedListFormat' => 'decimal',
        ]);
}

/**
 * A template with the placeholder regions the renderer expects. The paragraph
 * styles are defined here the way a real template would define them, so the
 * example shows a template driving the output rather than the other way round.
 */
function buildInvoiceTemplate(string $path): void
{
    $phpWord = new PhpWord();

    $phpWord->addFontStyle('InvoiceTitle', ['bold' => true, 'size' => 24, 'color' => '1B3A5C'], new Paragraph());
    $phpWord->addFontStyle('InvoiceMeta', ['size' => 10, 'color' => '55606B'], new Paragraph());
    $phpWord->addFontStyle('InvoiceBody', ['size' => 11], new Paragraph());

    $section = $phpWord->addSection();

    // Note the third argument: PHPWord's second parameter of addText() is a
    // *character* style, and a paragraph style has to go in the third. Getting
    // this wrong writes a `w:rStyle` reference to a paragraph style, which Word
    // silently ignores.
    $section->addText('INVOICE', null, 'InvoiceTitle');
    $section->addText('No. ${number}   ·   Issued for ${customer}', null, 'InvoiceMeta');

    // A repeating region: the renderer clones it once per row and adds the
    // `#1`, `#2`, ... index to the macros inside, so the template itself holds
    // the plain names.
    $section->addText('${lines}');
    $section->addText('${description} — ${amount}');
    $section->addText('${/lines}');

    // A Markdown region: the renderer clones it once per rendered block and
    // replaces the `${slot}` paragraph in each copy.
    $section->addText('${terms}');
    $section->addText('${slot}');
    $section->addText('${/terms}');

    IOFactory::createWriter($phpWord, 'Word2007')->save($path);
}

function logo(string $path): void
{
    if (is_file($path)) {
        return;
    }

    $size = 96;
    $image = imagecreatetruecolor($size, $size);

    $background = imagecolorallocate($image, 0x8B, 0x1A, 0x1A);
    $ink = imagecolorallocate($image, 0xFF, 0xF3, 0xF3);

    imagefilledrectangle($image, 0, 0, $size, $size, $background);
    imagerectangle($image, 4, 4, $size - 5, $size - 5, $ink);
    imagefilledellipse($image, $size / 2, $size / 2, 44, 44, $ink);

    imagepng($image, $path);
}

/**
 * Render the first page of each document so the result can be eyeballed.
 */
function renderPreviews(array $built): void
{
    $soffice = locateLibreOffice();

    if ($soffice === null) {
        echo "LibreOffice was not found, so no preview images were produced.\n";

        return;
    }

    // Every format the examples write, so the comparison page can be looked at as
    // well as read. The round trip is Markdown and has no first page to look at.
    $documents = ['docx', 'odt', 'rtf'];
    $rendered = 0;

    foreach ($built as [, , $path]) {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if (!in_array($extension, $documents, true)) {
            continue;
        }

        // Into a directory of its own, because LibreOffice names its output after
        // the file it was given: three documents sharing a stem would otherwise
        // each overwrite the last one's preview, and the one left standing would
        // stand for all three — the opposite of what the comparison page is for.
        $scratch = sys_get_temp_dir() . '/mdword-preview-' . bin2hex(random_bytes(6));

        if (!is_dir($scratch) && !mkdir($scratch, 0o777, true) && !is_dir($scratch)) {
            continue;
        }

        exec(sprintf(
            '%s --headless --convert-to png --outdir %s %s 2>/dev/null',
            escapeshellcmd($soffice),
            escapeshellarg($scratch),
            escapeshellarg($path),
        ), $output, $status);

        if ($status === 0 && movePreview($scratch, $path, $extension)) {
            $rendered++;
        }

        $output = [];
    }

    printf("Rendered %d preview image(s) next to the documents.\n", $rendered);
}

/**
 * Put a document's preview image beside it, under a name of its own.
 *
 * The format goes into the name wherever it is not the default, so the `.docx` is
 * `20-formats.png` and its two siblings are `20-formats.odt.png` and
 * `20-formats.rtf.png`.
 */
function movePreview(string $scratch, string $path, string $extension): bool
{
    $stem = (string) preg_replace('/\.[^.]+$/', '', $path);
    $produced = $scratch . '/' . basename($stem) . '.png';

    if (!is_file($produced)) {
        return false;
    }

    $wanted = $extension === 'docx' ? $stem . '.png' : $stem . '.' . $extension . '.png';

    return rename($produced, $wanted);
}

function locateLibreOffice(): ?string
{
    $candidates = [
        '/Applications/LibreOffice.app/Contents/MacOS/soffice',
        '/usr/bin/soffice',
        '/usr/bin/libreoffice',
        '/usr/lib/libreoffice/program/soffice',
    ];

    foreach ($candidates as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}
