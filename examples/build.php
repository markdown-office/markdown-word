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

/**
 * A package that is not what its name says is a mistake in *this* file, so it stops
 * the build rather than producing a set of examples around it.
 */
final class BadPackage extends RuntimeException
{
}

const DOCX_EXTENSION = '.docx';
const CONTENT_TYPES_PART = '[Content_Types].xml';
const STYLES_PART = 'word/styles.xml';

// The consultancy's two colours, named because a house style is a palette rather
// than a pile of hex literals, and a palette with one value in it eight times is
// not a palette.
const MRD_NAVY = '1B3A5C';
const MRD_SLATE = '55606B';

/**
 * The paragraph properties a `<w:pBdr>` has to come before, in OOXML's sequence.
 *
 * `w:pBdr` sits in a fixed place inside `w:pPr` and Word refuses a part that is out
 * of it: after `pStyle`, `keepNext`, `keepLines` and the framing properties, before
 * `shd`, `tabs` and everything that indents or spaces the paragraph.
 *
 * Declared here rather than beside {@see borderOffset()} because this file runs
 * top to bottom — the build calls into it long before its functions are reached.
 */
const AFTER_PBDR = ['shd', 'tabs', 'suppressAutoHyphens', 'kinsoku', 'wordWrap', 'spacing', 'ind', 'contextualSpacing', 'jc', 'textAlignment', 'outlineLvl', 'rPr'];

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

$path = $out . '/07-no-decoration' . DOCX_EXTENSION;
(new MarkdownToWord(
    (string) file_get_contents($root . '/markdown/01-kitchen-sink.md'),
    Configuration::create()->withoutDecoration(),
))->save($path);
$built[] = ['07-no-decoration', 'The kitchen sink with every default switched off.', $path];

// A house style, defined in code

$path = $out . '/08-house-style' . DOCX_EXTENSION;
(new MarkdownToWord(
    (string) file_get_contents($root . '/markdown/01-kitchen-sink.md'),
    houseStyle(),
))->save($path);
$built[] = ['08-house-style', 'The kitchen sink in a custom house style.', $path];

// The same source, with the extras a README tends to use

$path = $out . '/09-extended-parser' . DOCX_EXTENSION;
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

$templatePath = $out . '/template-invoice' . DOCX_EXTENSION;
$outputPath = $out . '/10-template' . DOCX_EXTENSION;

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
$path = $out . '/11-frontmatter' . DOCX_EXTENSION;
(new MarkdownToWord(
    (string) file_get_contents($root . '/markdown/11-frontmatter.md'),
    Configuration::create(),
    $frontmatterParser,
))->save($path);
$built[] = ['11-frontmatter', 'Frontmatter configuring the conversion.', $path];

// 12 — the same Markdown with no block, for the pair.
$path = $out . '/12-frontmatter-none' . DOCX_EXTENSION;
(new MarkdownToWord(
    (string) file_get_contents($root . '/markdown/12-frontmatter-none.md'),
    Configuration::create(),
    $frontmatterParser,
))->save($path);
$built[] = ['12-frontmatter-none', 'The same Markdown with no frontmatter.', $path];

// 13 — the block is outranked by the configuration passed in code.
$path = $out . '/13-frontmatter-override' . DOCX_EXTENSION;
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

// A Word *template* — a `.dotx`
//
// Built rather than checked in, for the same reason the invoice template above is: a
// `.dotx` is a `.docx` with one line of `[Content_Types].xml` changed, and a binary
// fixture does not show that line.
//
// Built as a `.docx` first and converted afterwards, because PHPWord's writer has one
// output and the conversion is the whole of the difference. Getting that order
// backwards leaves the two files each carrying the other's type: a `.docx` that Word
// opens by spawning a new document, and a `.dotx` it opens as an ordinary file.
//
// Two documents come out of them, from the same Markdown, and they are worth opening
// side by side. `21-dotx-report.docx` is rendered through the `.dotx` and
// `21-dotx-report.docx-template.docx` through the `.docx`; the words, the formatting
// and the styles are the same in both, because which kind of template it is makes no
// difference to the result. What it does change is what the result *is* — both are
// documents, because a filled-in template that kept its own type would open by
// creating a new document from itself rather than opening.

$docxTemplatePath = $out . '/template-report' . DOCX_EXTENSION;
$dotxPath = $out . '/template-report.dotx';

buildReportTemplate($docxTemplatePath);
asTemplate($docxTemplatePath, $dotxPath);

$DOCUMENT_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';

assertPackageType($docxTemplatePath, $DOCUMENT_TYPE);
assertPackageType($dotxPath, 'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml');

$reportMarkdown = (string) file_get_contents($root . '/markdown/21-dotx-report.md');

$reportValues = [
    'client' => 'Contoso Industrial',
    'period' => 'Q2 2026',
    'reference' => 'MER-QBR-2026-02',
    'prepared' => '14 April 2026',
];

$reportRows = [
    ['label' => 'Contract value', 'value' => '€412,000', 'note' => 'three-year term'],
    ['label' => 'Renewal date', 'value' => '31 March 2029', 'note' => 'auto-renew off'],
    ['label' => 'Named contacts', 'value' => '4', 'note' => '2 technical, 2 commercial'],
];

foreach ([
    '21-dotx-report' => $dotxPath,
    '21-dotx-report.docx-template' => $docxTemplatePath,
] as $name => $template) {
    $path = $out . '/' . $name . '.docx';

    (new MarkdownTemplate($template, reportStyle(), $reportValues))
        ->repeat('facts', $reportRows)
        ->insert('report', $reportMarkdown)
        ->save($path);

    // The claim the pair exists to show, checked rather than written in a README:
    // whichever kind of template it was rendered into, what comes out is a document.
    assertPackageType($path, $DOCUMENT_TYPE);

    $built[] = [$name, 'Rendered into ' . basename($template) . '.', $path];
}

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
    $path = $out . '/19-vector' . DOCX_EXTENSION;
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


$roundTripped = (new WordToMarkdown($out . '/01-kitchen-sink' . DOCX_EXTENSION))->convert();
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
 * The report template's own styles, pointed at by name.
 *
 * A slot given a style *name* is used verbatim and nothing of the built-in look is
 * layered over it, which is what makes the body inherit this template rather than
 * bring its own. The template defines `MrdHeading1`…`MrdHeading6`, `MrdQuote` and
 * `MrdCode`; see {@see buildReportTemplate()} and `markdownReportStyles()`.
 *
 * @return array<string, string>
 */
function markdownReportStyles(): array
{
    return [
        Styles::HEADING_1 => 'MrdHeading1',
        Styles::HEADING_2 => 'MrdHeading2',
        Styles::HEADING_3 => 'MrdHeading3',
        Styles::PARAGRAPH => 'MrdBody',
        Styles::BLOCK_QUOTE => 'MrdQuote',
        Styles::CODE_FONT => 'MrdCode',
        Styles::CODE_BLOCK => 'MrdCodeBlock',
        Styles::LIST_PARAGRAPH => 'MrdBody',
    ];
}

/**
 * The configuration the report is rendered with.
 *
 * The style slots are names rather than properties, so the Markdown takes the
 * template's own definitions; the remaining slots — code block shading, table
 * borders — are the direct formatting this library writes onto the text, because
 * those are properties of the paragraph rather than names the template would have
 * to define.
 */
function reportStyle(): Configuration
{
    return Configuration::create()
        ->withStyles(markdownReportStyles())
        ->withOptions([
            'codeBlockShading' => true,
            'tableBorders' => true,
            'tableHeaderBold' => true,
        ]);
}

/**
 * A configuration that looks like a printed report rather than a Word default.
 */
function houseStyle(): Configuration
{
    return Configuration::create()
        ->withStyles([
            Styles::HEADING_1 => ['size' => 20, 'bold' => true, 'color' => MRD_NAVY, 'space' => ['before' => 0, 'after' => 240]],
            Styles::HEADING_2 => ['size' => 15, 'bold' => true, 'color' => MRD_NAVY, 'space' => ['before' => 280, 'after' => 120]],
            Styles::HEADING_3 => ['size' => 12, 'bold' => true, 'color' => '2E5F86', 'space' => ['before' => 200, 'after' => 80]],
            Styles::BLOCK_QUOTE => ['italic' => true, 'color' => MRD_SLATE, 'indentation' => ['left' => 567, 'right' => 567]],
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
 * A quarterly review template for a consultancy, written the way a real one would be:
 * its own named styles, its own spacing, and placeholder regions for the parts the
 * caller fills in.
 *
 * The styles are the point. `houseStyle()` below points the Markdown slots at them by
 * name, so the body inherits this template's design rather than imposing one on it —
 * which is what "render into a template" is supposed to mean and is the thing an
 * example is worth having for.
 *
 * @param string $path Written as a `.docx`; {@see asDocument()} makes the `.dotx`.
 */
function buildReportTemplate(string $path): void
{
    $phpWord = new PhpWord();

    // A serif for headings and the company's navy, the way a real report template
    // carries its house style rather than Word's defaults.
    //
    // `addFontStyle()` and `addParagraphStyle()` each write half a paragraph style:
    // the first carries the run properties, the second the paragraph ones, and a
    // style defined with only one of them resolves to nothing much. The third
    // argument is an *array* of paragraph properties — handing over a built
    // `Paragraph` is accepted and quietly applies none of them, which is how this
    // looked like plain body text for a while.
    $phpWord->addFontStyle('MrdTitle', [
        'name' => 'Georgia', 'size' => 22, 'bold' => true, 'color' => MRD_NAVY,
    ], ['space' => ['before' => 0, 'after' => 60]]);

    $phpWord->addFontStyle('MrdFirm', [
        'name' => 'Georgia', 'size' => 11, 'color' => '7A8B99',
    ], ['space' => ['before' => 0, 'after' => 320]]);

    $phpWord->addFontStyle('MrdMeta', [
        'size' => 9, 'color' => MRD_SLATE,
    ], ['space' => ['before' => 0, 'after' => 40]]);

    // The three lines of one fact in the repeating region. The rules between them are
    // added by `ruleStyles()` below, because PHPWord will not write a border into a
    // style however it is spelled; the indentation and spacing it will.
    $indent = ['indentation' => ['left' => 284]];

    $phpWord->addFontStyle('MrdFactLabel', [
        'bold' => true, 'size' => 10, 'color' => MRD_NAVY,
    ], [...$indent, 'space' => ['before' => 0, 'after' => 0]]);

    $phpWord->addFontStyle('MrdFactValue', [
        'size' => 10, 'color' => '2A3540',
    ], [...$indent, 'space' => ['before' => 0, 'after' => 0]]);

    $phpWord->addFontStyle('MrdFactNote', [
        'size' => 9, 'italic' => true, 'color' => '7A8B99',
    ], [...$indent, 'space' => ['before' => 0, 'after' => 160]]);

    // The body. These are what the Markdown slots are pointed at, so this is the
    // part that decides what the report reads like — the point of rendering into a
    // template rather than into a new document.
    $phpWord->addFontStyle('MrdBody', [
        'size' => 10.5, 'color' => '2A3540',
    ], [
        'line' => 276, 'lineRule' => 'auto',
        'space' => ['before' => 0, 'after' => 160],
    ]);

    $phpWord->addFontStyle('MrdHeading1', [
        'name' => 'Georgia', 'size' => 16, 'bold' => true, 'color' => MRD_NAVY,
    ], ['space' => ['before' => 320, 'after' => 140], 'keepNext' => true]);

    $phpWord->addFontStyle('MrdHeading2', [
        'name' => 'Georgia', 'size' => 13, 'bold' => true, 'color' => MRD_NAVY,
    ], ['space' => ['before' => 260, 'after' => 110], 'keepNext' => true]);

    $phpWord->addFontStyle('MrdHeading3', [
        'name' => 'Georgia', 'size' => 11, 'bold' => true, 'color' => '2E5F86',
    ], ['space' => ['before' => 200, 'after' => 90], 'keepNext' => true]);

    $phpWord->addFontStyle('MrdQuote', [
        'italic' => true, 'color' => MRD_SLATE,
    ], [
        'indentation' => ['left' => 454, 'right' => 454],
        'space' => ['before' => 160, 'after' => 180],
    ]);

    $phpWord->addFontStyle('MrdCode', [
        'name' => 'Consolas', 'size' => 9, 'color' => '1F3864',
    ]);

    $phpWord->addFontStyle('MrdCodeBlock', [
        'size' => 9,
    ], [
        'shading' => ['fill' => 'F4F6F8'],
        'indentation' => ['left' => 227],
        'space' => ['before' => 120, 'after' => 140],
    ]);

    $section = $phpWord->addSection();

    // The masthead: the firm's own lines, and the client's details filled in through
    // single-line macros rather than Markdown.
    $section->addText('MERIDIAN ADVISORY', null, 'MrdFirm');
    $section->addText('Quarterly Business Review', null, 'MrdTitle');
    $section->addText('${client}   ·   ${period}   ·   ${reference}', null, 'MrdMeta');
    $section->addText('Prepared ${prepared}', null, 'MrdMeta');

    // A repeating region: the renderer clones it once per row and adds the `#1`,
    // `#2`, ... index to the macros inside, so the template holds the plain names.
    $section->addText('${facts}');
    $section->addText('${label}', null, 'MrdFactLabel');
    $section->addText('${value}', null, 'MrdFactValue');
    $section->addText('${note}', null, 'MrdFactNote');
    $section->addText('${/facts}');

    // The Markdown region, and the whole of the report body.
    $section->addText('${report}');
    $section->addText('${slot}');
    $section->addText('${/report}');

    // A closing line, so the template is visibly a document rather than a frame: the
    // Markdown lands above it and the signature block stays where it was put.
    $section->addText('Meridian Advisory · Registered in Scotland · Confidential', null, 'MrdMeta');

    IOFactory::createWriter($phpWord, 'Word2007')->save($path);

    $hairline = ['size' => 4, 'color' => 'C9D4DE'];
    $accent = ['size' => 12, 'color' => MRD_NAVY];

    ruleStyles($path, [
        'MrdQuote' => ['left' => $accent],
        'MrdFactLabel' => ['top' => $hairline, 'bottom' => $hairline],
        'MrdFactNote' => ['bottom' => $hairline],
    ]);
}

/**
 * Where the border goes, as the offset into the paragraph's properties.
 *
 * The *earliest* of the elements that are actually there, not the first that happens
 * to be in {@see AFTER_PBDR}: PHPWord writes `ind` before `spacing`, so a list-order
 * lookup puts the border between the two and Word rejects the part. Null when none is
 * present, which puts the border last and is correct for a paragraph with nothing.
 */
function borderOffset(string $properties): ?int
{
    $at = null;

    foreach (AFTER_PBDR as $element) {
        $found = strpos($properties, '<w:' . $element);

        if ($found === false) {
            continue;
        }

        $at = $at === null ? $found : min($at, $found);
    }

    return $at;
}

/**
 * Put a rule on some of the template's paragraph styles.
 *
 * Neither PHPWord nor this library can express one: PHPWord's style writer ignores
 * every border key, in every spelling, and {@see \MarkdownWord\Configuration\Styles}
 * has no border among the paragraph properties it understands — `thematicBreak: border`
 * is the rule under a `---` and is a different thing entirely. A template authored in
 * Word has them, because Word writes them, so they are written here rather than
 * quietly left out of an example that is meant to look designed.
 *
 * @param array<string, array<string, array{size: int, color: string}>> $styles Style id to
 *        the sides to rule, each as the `w:sz`/`w:color` a `<w:pBdr>` child takes. `sz`
 *        is in eighths of a point, so 4 is half a point.
 */
function ruleStyles(string $path, array $styles): void
{
    $zip = new ZipArchive();
    $zip->open($path);

    try {
        $declared = (string) $zip->getFromName(STYLES_PART);

        foreach ($styles as $id => $sides) {
            $borders = '';

            foreach ($sides as $side => $rule) {
                $borders .= sprintf(
                    '<w:%s w:val="single" w:sz="%d" w:space="4" w:color="%s"/>',
                    $side,
                    $rule['size'],
                    $rule['color'],
                );
            }

            $declared = preg_replace_callback(
                '~(<w:style w:type="paragraph"[^>]*w:styleId="' . preg_quote($id, '~') . '">.*?<w:pPr(?:\s[^>]*)?>)(.*?)(</w:pPr>)~s',
                static function (array $style) use ($borders): string {
                    $at = borderOffset($style[2]);
                    $inner = $style[2];

                    return $style[1]
                        . ($at === null ? $inner : substr($inner, 0, $at))
                        . '<w:pBdr>' . $borders . '</w:pBdr>'
                        . ($at === null ? '' : substr($inner, $at))
                        . $style[3];
                },
                $declared,
                1,
            );
        }

        $zip->deleteName(STYLES_PART);
        $zip->addFromString(STYLES_PART, (string) $declared);
    } finally {
        $zip->close();
    }
}

/**
 * Rewrite a `.docx` package as a `.dotx`, which is what makes it a Word template.
 *
 * One line of `[Content_Types].xml` is the entire difference: the body part is
 * declared as a template's rather than a document's, and Word opens a package typed
 * that way by creating a *new* document from it.
 *
 * The parts that follow it are left exactly as they are, which is the point — the two
 * packages differ only in the line above, so anything a renderer does differently for
 * one has to be about that line.
 */
function asTemplate(string $source, string $target): void
{
    $document = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';
    $template = 'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml';

    copy($source, $target);

    $zip = new ZipArchive();
    $zip->open($target);

    try {
        $declared = (string) $zip->getFromName(CONTENT_TYPES_PART);
        $rewritten = (string) preg_replace('~' . preg_quote($document, '~') . '~', $template, $declared);

        // The name of a package decides nothing; the content type decides everything,
        // and a `.dotx` that is really a document opens as an ordinary file while a
        // `.docx` that is really a template spawns a new document on double-click.
        // Neither is worth leaving for a reader to discover in Word.
        if (!str_contains($rewritten, $template) || str_contains($rewritten, $document)) {
            throw new BadPackage(sprintf(
                'Converting "%s" left a package that is neither a document nor a template; '
                . 'a .dotx and a .docx differ only in the main part\'s content type.',
                $source,
            ));
        }

        $zip->deleteName(CONTENT_TYPES_PART);
        $zip->addFromString(CONTENT_TYPES_PART, $rewritten);
    } finally {
        $zip->close();
    }

    assertPackageType($target, $template);
}

/**
 * Check that a package's body carries the content type its name promises.
 *
 * @param string $expected One of the two `wordprocessingml.{document,template}.main+xml` types.
 */
function assertPackageType(string $path, string $expected): void
{
    $zip = new ZipArchive();
    $zip->open($path);

    try {
        $declared = (string) $zip->getFromName(CONTENT_TYPES_PART);
    } finally {
        $zip->close();
    }

    if (!str_contains($declared, $expected)) {
        throw new BadPackage(sprintf(
            '"%s" is not the kind of package its name says: its body is declared as %s, not %s.',
            $path,
            preg_match('~wordprocessingml\.\w+\.main\+xml~', $declared, $found) ? $found[0] : 'nothing',
            $expected,
        ));
    }
}

/**
 * A template with the placeholder regions the renderer expects. The paragraph
 * styles are defined here the way a real template would define them, so the
 * example shows a template driving the output rather than the other way round.
 */
function buildInvoiceTemplate(string $path): void
{
    $phpWord = new PhpWord();

    $phpWord->addFontStyle('InvoiceTitle', ['bold' => true, 'size' => 24, 'color' => MRD_NAVY], new Paragraph());
    $phpWord->addFontStyle('InvoiceMeta', ['size' => 10, 'color' => MRD_SLATE], new Paragraph());
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
