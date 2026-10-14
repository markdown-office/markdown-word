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
use MarkdownWord\Template\ContentControl;
use MarkdownWord\Xml;
use MarkdownWord\Template\MarkdownTemplate;
use PhpOffice\PhpWord\Element\Section;
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

const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

// The two types a Word package's body can be declared as, which is the whole of what
// a `.docx` and a `.dotx` differ by.
const DOCUMENT_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';
const TEMPLATE_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml';

/** The house logo, put in the template's header. */
const LOGO = __DIR__ . '/markdown/assets/example-logo-meridian.png';

/**
 * A paragraph that stands for the content control until `controlIn()` has put one there.
 *
 * PHPWord cannot write a content control, and what it writes in its place is escaped
 * into a `w:t` — so the control is added to the part afterwards, by editing the
 * paragraph this marker is in.
 *
 * Not the `@@…@@` marker the fields use, or `fieldsIn()` would read it as a field
 * named `body-control` and consume it first.
 */
const CONTROL_PLACEHOLDER = '%%body-control%%';

$root = __DIR__;
$out = $root . '/out';
$docs = $out . '/documents';
$previews = $out . '/previews';
$assets = $root . '/markdown/assets';
$templates = $root . '/templates';

// Everything in `out/` is produced by this script and nothing else, and it is
// gitignored besides, so it starts empty. A build that only writes what it still names
// leaves behind every name it has stopped naming, which is how the directory came to
// hold a template written in the `${…}` dialect that has since been removed, under a
// number the numbering has since moved past.
emptyDirectory($out);

@mkdir($docs, 0o777, true);
@mkdir($previews, 0o777, true);
@mkdir($assets, 0o777, true);
@mkdir($templates, 0o777, true);

// Where a template is built before it is typed, and where the `.docx` twin is written
// for the one comparison that needs it. Both are intermediates: a reader who opened
// them would see something that looks like an example and is not one.
$scratch = sys_get_temp_dir() . '/mdword-examples-' . bin2hex(random_bytes(6));
@mkdir($scratch, 0o777, true);

register_shutdown_function(static function () use ($scratch): void {
    emptyDirectory($scratch);
});

// The three Word templates the examples render into, all in `templates/` and all named
// as the `.dotx` they are.
//
// They are committed rather than built, so that a fresh clone can open them without
// running Composer, PHP or having LibreOffice installed. They are also *generated*, so
// that nobody has to edit a zip by hand: `--write-templates` rebuilds all three, and a
// plain build never touches them.
//
// Regenerating is a separate step because it is not repeatable: `docProps/core.xml`
// carries the time a document was written, so two runs a minute apart differ there and
// nowhere else, and a build that rewrote a committed binary on every run would leave
// the working tree permanently dirty for a difference nobody can see.
$templatePaths = [
    'invoice' => $templates . '/invoice.dotx',
    'meridian-qbr' => $templates . '/meridian-qbr.dotx',
    'plain-letterhead' => $templates . '/plain-letterhead.dotx',
];

if (in_array('--write-templates', $argv ?? [], true)) {
    $build = [
        'invoice' => 'buildInvoiceTemplate',
        'meridian-qbr' => 'buildReportTemplate',
        'plain-letterhead' => 'buildPlainTemplate',
    ];

    foreach ($build as $name => $builder) {
        $asDocument = $scratch . '/' . $name . DOCX_EXTENSION;

        $builder($asDocument);
        asTemplate($asDocument, $templatePaths[$name]);

        printf("Wrote %s\n", $templatePaths[$name]);
    }

    exit(0);
}

$missing = array_keys(array_filter($templatePaths, static fn (string $t): bool => !is_file($t)));

if ($missing !== []) {
    fprintf(
        STDERR,
        "Missing template(s): %s\nRun `php examples/build.php --write-templates` to build them.\n",
        implode(', ', $missing),
    );

    exit(1);
}

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
    $markdown = markdownOf($root, $name);

    // Images in the examples are relative to the Markdown file.
    $config = Configuration::create()->withOptions([
        'images' => Options::IMAGE_EMBED,
        'imageBasePath' => $root . '/markdown',
    ]);

    $path = $docs . '/' . $name . DOCX_EXTENSION;
    (new MarkdownToWord($markdown, $config))->save($path);

    $built[] = [$name, $description, $path];
}

// The same source, without any decoration
//
// Shows what the configuration controls, side by side with the default.

$path = $docs . '/07-no-decoration' . DOCX_EXTENSION;
(new MarkdownToWord(
    (string) file_get_contents($root . '/markdown/01-kitchen-sink.md'),
    Configuration::create()->withoutDecoration(),
))->save($path);
$built[] = ['07-no-decoration', 'The kitchen sink with every default switched off.', $path];

// A house style, defined in code

$path = $docs . '/08-house-style' . DOCX_EXTENSION;
(new MarkdownToWord(
    (string) file_get_contents($root . '/markdown/01-kitchen-sink.md'),
    houseStyle(),
))->save($path);
$built[] = ['08-house-style', 'The kitchen sink in a custom house style.', $path];

// The same source, with the extras a README tends to use

$path = $docs . '/09-extended-parser' . DOCX_EXTENSION;
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

$outputPath = $docs . '/10-template' . DOCX_EXTENSION;

(new MarkdownTemplate($templatePaths['invoice'], houseStyle()))
    ->insert(
        "---\nfields:\n  number: INV-2024-0042\n  customer: Northwind Ltd\n---\n\n"
        . "| Description | Amount |\n| --- | ---: |\n"
        . "| Licence renewal | 1,200.00 |\n| Priority support | 300.00 |\n| On-site training | 900.00 |\n\n"
        . "## Terms\n\nPayment is due within **14 days**.\n\n- Bank transfer\n- Card\n\n"
        . "> Late payment attracts interest at 4% per year.\n",
    )
    ->save($outputPath);

$built[] = ['10-template', 'Markdown rendered into a Word template.', $outputPath];

/**
 * A source file's Markdown, read from where the examples keep their input.
 *
 * One reader rather than three `file_get_contents()` calls: the path is the thing that
 * has to agree between them, and a fourth example added somewhere else would otherwise
 * be the one that does not.
 */
function markdownOf(string $root, string $name): string
{
    return (string) file_get_contents($root . '/markdown/' . $name . '.md');
}

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
// Directives are on by default, so it is `DEFAULT_FLAVOUR` that is added to rather
// than `gfm`: naming `gfm` here would build a parser *without* them, and an example
// page whose whole subject is the directive syntax would come out as literal text.
$frontmatterParser = new CommonMarkParser([
    ...CommonMarkParser::FLAVOURS[CommonMarkParser::DEFAULT_FLAVOUR],
    FrontMatterExtension::class,
]);

// 11 — the block is read, and it configures the render.
$path = $docs . '/11-frontmatter' . DOCX_EXTENSION;
(new MarkdownToWord(
    (string) file_get_contents($root . '/markdown/11-frontmatter.md'),
    Configuration::create(),
    $frontmatterParser,
))->save($path);
$built[] = ['11-frontmatter', 'Frontmatter configuring the conversion.', $path];

// 12 — the same Markdown with no block, for the pair.
$path = $docs . '/12-frontmatter-none' . DOCX_EXTENSION;
(new MarkdownToWord(
    (string) file_get_contents($root . '/markdown/12-frontmatter-none.md'),
    Configuration::create(),
    $frontmatterParser,
))->save($path);
$built[] = ['12-frontmatter-none', 'The same Markdown with no frontmatter.', $path];

// 13 — the block is outranked by the configuration passed in code.
$path = $docs . '/13-frontmatter-override' . DOCX_EXTENSION;
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
// The template is `templates/meridian-qbr.dotx`, and it is committed: this is the one
// Word file here a reader is meant to open rather than a build product, and a fresh
// clone that could only get it by running Composer and PHP had no reason to carry it.
// `buildReportTemplate()` is what wrote it and `asTemplate()` is what turns its `.docx`
// form into this one, so `php examples/build.php --write-template` regenerates it —
// which is not the same as reproducing it byte for byte, because `docProps/core.xml`
// carries the time it was written and two runs a minute apart differ there and nowhere
// else.
//
// Its `.docx` twin is built here rather than committed, because it exists to be the
// other half of a comparison rather than to be opened: two nearly identical binaries
// in the repository is one too many.
//
// Two documents come out of them, from the same Markdown, and they are worth opening
// side by side. `22-corporate-report.docx` is rendered through the `.dotx` and
// `22-corporate-report.docx-template.docx` through the `.docx`; the words, the formatting
// and the styles are the same in both, because which kind of template it is makes no
// difference to the result. What it does change is what the result *is* — both are
// documents, because a filled-in template that kept its own type would open by
// creating a new document from itself rather than opening.

$dotxPath = $templatePaths['meridian-qbr'];
$docxTemplatePath = $scratch . '/meridian-qbr' . DOCX_EXTENSION;

asDocument($dotxPath, $docxTemplatePath);

assertPackageType($dotxPath, TEMPLATE_TYPE);
assertPackageType($docxTemplatePath, DOCUMENT_TYPE);

// The corporate example: a report rendered into a letterhead that carries its own
// logo, fonts and sizes.
//
// Its values are in the Markdown file's own `---` block, under `fields:`. That is the
// whole point of the shape — the document says what its own values are, and the
// template says what those values look like. A PHP array here would be a second place
// for the same fact, and changing one would not change the other.
$corporateMarkdown = (string) file_get_contents($root . '/markdown/22-corporate-report.md');

foreach ([
    '22-corporate-report' => $dotxPath,
    '22-corporate-report.docx-template' => $docxTemplatePath,
] as $name => $template) {
    $path = $docs . '/' . $name . DOCX_EXTENSION;

    (new MarkdownTemplate($template, reportStyle()))
        ->insert($corporateMarkdown)
        ->save($path);

    // The claim the pair exists to show, checked rather than written in a README:
    // whichever kind of template it was rendered into, what comes out is a document.
    assertPackageType($path, DOCUMENT_TYPE);

    $built[] = [$name, 'Rendered into ' . basename($template) . '.', $path];
}

// The plain template: a logo and nothing else, which is what most templates actually
// are — and, more to the point, what a letterhead somebody made in Word to be filled in
// by a person looks like, because nobody making one inserts a content control. The
// content is appended, which is the whole of the fallback; everything else about the
// appearance comes from the library's built-in look rather than from the file.
//
// The pair with `22-corporate-report` is deliberate: that one names where the content
// goes, this one does not, and both render the same Markdown.
$plainPath = $docs . '/23-plain-letterhead' . DOCX_EXTENSION;

(new MarkdownTemplate($templatePaths['plain-letterhead']))
    ->insert($corporateMarkdown)
    ->save($plainPath);

$built[] = ['23-plain-letterhead', 'The same report into a template that names no place for it: a logo and nothing else.', $plainPath];

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
    $path = $docs . '/15-images-' . $mode . DOCX_EXTENSION;
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

// Headers and footers

// `imageBasePath` for the same reason as `15-images`: a relative `assets/…` is
// resolved against this directory rather than against wherever the build ran, and
// without it the logo comes out as its own alt text where the picture should be.
$path = $docs . '/' . '24-furniture' . DOCX_EXTENSION;
(new MarkdownToWord(
    (string) file_get_contents($root . '/markdown/24-furniture.md'),
    Configuration::create()->withOptions(['imageBasePath' => $root . '/markdown']),
    $frontmatterParser,
))->save($path);

$built[] = ['24-furniture', 'A running head and foot described in the frontmatter.', $path];

// Directives

foreach ([
    '25-toc' => 'A table of contents field and the footnotes that go with it.',
    '26-directives' => 'What each shape of the directive syntax becomes, drawn and not.',
] as $name => $description) {
    $path = $docs . '/' . $name . DOCX_EXTENSION;
    (new MarkdownToWord(
        markdownOf($root, $name),
        Configuration::create(),
        $frontmatterParser,
    ))->save($path);

    $built[] = [$name, $description, $path];
}

// Tables

foreach ([
    '16-styled-tables' => 'Table style slots from the frontmatter.',
    '17-borderless-tables' => 'The table options, with no style slot.',
] as $name => $description) {
    $path = $docs . '/' . $name . DOCX_EXTENSION;
    (new MarkdownToWord(
        markdownOf($root, $name),
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
    $path = $docs . '/19-vector' . DOCX_EXTENSION;
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


$roundTripped = (new WordToMarkdown($docs . '/01-kitchen-sink' . DOCX_EXTENSION))->convert();
file_put_contents($docs . '/18-round-trip.md', $roundTripped);

$built[] = [
    '18-round-trip',
    'The kitchen sink read back out of 01-kitchen-sink.docx.',
    $docs . '/18-round-trip.md',
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
    $path = $docs . '/20-formats' . $format->extension();
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

echo "\nBuilt, into out/documents/:\n\n";

foreach ($built as [$name, $description, $path]) {
    printf("  %-34s %s\n", basename($path), $description);
}

echo "\n";

renderPreviews($built, $previews);

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

    addLogo($section);

    // The masthead. `@@…@@` is a marker this file replaces with a real `MERGEFIELD`
    // afterwards — PHPWord has no API for a field, and a template written by hand or in
    // Word has one.
    $section->addText('MERIDIAN ADVISORY', null, 'MrdFirm');
    $section->addText('Quarterly Business Review', null, 'MrdTitle');
    // One marker per paragraph, so what separates them is text in the template and
    // not padding folded into a field — a field's value carries the field's run
    // properties, and a run of spaces at the start of one is not a separator.
    $section->addText('Prepared for @@client@@', null, 'MrdMeta');
    $section->addText('Period @@period@@   ·   Reference @@reference@@', null, 'MrdMeta');
    $section->addText('Issued @@prepared@@', null, 'MrdMeta');

    $section->addText('@@contract_value@@', null, 'MrdFactLabel');
    $section->addText('@@renewal_date@@', null, 'MrdFactValue');

    // Where the Markdown goes, and the closing line after it — which is why this is a
    // content control and not "the end of the document": content appended below the
    // signature block looks like a finished report and is not one.
    // Where the Markdown goes. Written into the part after the writer has run, because
    // a `w:sdt` is a child of `w:body` and PHPWord has no API for one — given to
    // `addText()` it would be escaped into a `w:t` and be the literal characters
    // `<w:sdt>` on the page rather than a control.
    $section->addText(CONTROL_PLACEHOLDER);

    $section->addText('Meridian Advisory · Registered in Scotland · Confidential', null, 'MrdMeta');

    IOFactory::createWriter($phpWord, 'Word2007')->save($path);

    fieldsIn($path, [
        'client' => '',
        'period' => '',
        'reference' => '',
        'prepared' => '',
        'contract_value' => '',
        'renewal_date' => 'Contract runs to ',
    ]);

    controlIn($path, ContentControl::BODY);

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
 * The house logo in a section's header, so it is on every page.
 *
 * A header image and not a page background, on purpose: Word's `w:background` is shown
 * on screen and left out of the printed page by default, which is the wrong trade for a
 * document that gets printed and filed.
 *
 * The size is in points rather than centimetres because PHPWord does not read a unit
 * it does not know — given `'4.6cm'` it stores 0, and a logo sized by nothing arrives
 * at the width of its own pixels. It also reads an image's pixels as points, which is
 * the same correction the SVG pass makes for a vector.
 */
function addLogo(Section $section): void
{
    if (!is_file(LOGO)) {
        return;
    }

    [$pixelsWide, $pixelsHigh] = getimagesize(LOGO) ?: [0, 0];

    if ($pixelsWide <= 0 || $pixelsHigh <= 0) {
        return;
    }

    // 4.6 cm across — a letterhead logo rather than a banner.
    $pointsWide = 4.6 * 72 / 2.54;

    $section->addHeader()->addImage(LOGO, [
        'width' => $pointsWide,
        'height' => $pointsWide * $pixelsHigh / $pixelsWide,
    ]);
}

/**
 * A plain Word template: the logo, a body control, and nothing else.
 *
 * Deliberately not a letterhead. Everything that would make this a *corporate*
 * template — the house fonts, the sizes, the navy, the rules — is what the library's
 * built-in look already provides, so leaving them out is the demonstration: a template
 * is not what makes a document look designed, and a file that carries only a logo and
 * a place for the content is the one most templates actually are.
 */
function buildPlainTemplate(string $path): void
{
    $phpWord = new PhpWord();
    $section = $phpWord->addSection();

    addLogo($section);

    // No content control, and that is the point: this is what a letterhead somebody
    // made in Word to be filled in by a person looks like, because nobody making one
    // inserts a control. The two template examples are a pair for exactly that reason —
    // `22-corporate-report` is rendered through one that names where the content goes,
    // `23-plain-letterhead` through one that does not.
    IOFactory::createWriter($phpWord, 'Word2007')->save($path);
}

/**
 * Replace `@@name@@` with a real Word field, in the parts of a template that can hold one.
 *
 * PHPWord has no API for a field, and a template comes from Word — where a person
 * presses Ctrl+F9 and gets one — so the field is written into the part directly. The
 * alternative would be a marker syntax of this file's own, which is the thing the
 * library stopped using.
 *
 * The simple shape is written, `w:fldSimple`, because it is one element and reads the
 * same. Word's own five-run form is handled by the library when it fills one, which is
 * what a template authored in Word will carry.
 *
 * @param array<string, string> $before Text that goes immediately before each field, so a
 *        marker inside a sentence keeps the sentence.
 */
function fieldsIn(string $path, array $before = []): void
{
    foreach (partsWithFields($path) as $part) {
        rewritePart($path, $part, static function (DOMDocument $dom) use ($before): void {
            foreach (fieldRuns($dom) as $run) {
                replaceMarkers($dom, $run, $before);
            }
        });
    }
}

/**
 * The parts of a template that can hold a field: the body, and every header and
 * footer there is.
 *
 * The header and footer parts are numbered from one, and `hasPart()` says whether
 * one is there rather than assuming the numbering is contiguous.
 *
 * @return list<string>
 */
function partsWithFields(string $path): array
{
    $parts = ['word/document.xml'];

    foreach (['header', 'footer'] as $kind) {
        for ($index = 1; $index <= 99; $index++) {
            $part = sprintf('word/%s%d.xml', $kind, $index);

            if (hasPart($path, $part)) {
                $parts[] = $part;
            }
        }
    }

    return $parts;
}

/**
 * Every run in a part that carries a marker.
 *
 * @return list<DOMElement>
 */
function fieldRuns(DOMDocument $dom): array
{
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', W_NS);

    $runs = [];

    foreach ($xpath->query('//w:r[w:t]') ?: [] as $run) {
        $text = $run->getElementsByTagNameNS(W_NS, 't')->item(0);

        if ($text instanceof DOMElement && str_contains((string) $text->textContent, '@@')) {
            $runs[] = $run;
        }
    }

    return $runs;
}

/**
 * Split one run into the fields and text its markers stand for.
 *
 * The pieces alternate text, name, text, name, and they are built in that order and
 * then inserted before the run together: inserting each in turn before an anchor that
 * moves with it reverses them, which is how "Prepared for @@client@@" came out as
 * "Contoso IndustrialPrepared for".
 *
 * @param array<string, string> $before
 */
function replaceMarkers(DOMDocument $dom, DOMElement $run, array $before): void
{
    $parent = $run->parentNode;

    if (!$parent instanceof DOMElement) {
        return;
    }

    $text = $run->getElementsByTagNameNS(W_NS, 't')->item(0);
    $pieces = explode('@@', $text instanceof DOMElement ? (string) $text->textContent : '');
    $nodes = [];

    foreach ($pieces as $index => $piece) {
        if ($piece === '') {
            continue;
        }

        if ($index % 2 === 0) {
            $nodes[] = textRun($dom, $piece);

            continue;
        }

        // Only the first field in a run takes the text that goes before it; the rest
        // are preceded by a separator the template already wrote.
        $nodes[] = mergeField($dom, $piece, $index === 1 ? ($before[$piece] ?? '') : '');
    }

    foreach ($nodes as $node) {
        $parent->insertBefore($node, $run);
    }

    $parent->removeChild($run);
}

/**
 * Replace the placeholder paragraph with a real content control.
 *
 * The whole paragraph goes, not just the marker: the control is a child of `w:body`
 * and the marker sits inside a `w:t` inside a run inside a paragraph, so what
 * replaces the paragraph is the control in its place.
 */
function controlIn(string $path, string $name): void
{
    rewritePart($path, 'word/document.xml', static function (DOMDocument $dom) use ($name): void {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', W_NS);

        foreach ($xpath->query('//w:p[w:r/w:t]') ?: [] as $paragraph) {
            $text = $paragraph->getElementsByTagNameNS(W_NS, 't')->item(0);

            if (!$text instanceof DOMElement || trim((string) $text->textContent) !== CONTROL_PLACEHOLDER) {
                continue;
            }

            $control = Xml::parse(
                sprintf(
                    '<root xmlns:w="%s" xmlns:xml="http://www.w3.org/XML/1998/namespace">%s</root>',
                    W_NS,
                    ContentControl::markup($name, 'The report goes here.'),
                ),
            )?->documentElement?->firstChild;

            if (!$control instanceof DOMNode) {
                throw new BadPackage('The content control could not be built.');
            }

            $paragraph->parentNode?->replaceChild($dom->importNode($control, true), $paragraph);

            return;
        }

        throw new BadPackage(sprintf('The template has no "%s" marker to replace with a control.', $name));
    });
}

/** A plain text run, where the fragment carries text rather than a field. */
function textRun(DOMDocument $dom, string $text): DOMElement
{
    $run = $dom->createElementNS(W_NS, 'w:r');
    $node = $dom->createElementNS(W_NS, 'w:t');
    $node->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
    $node->appendChild($dom->createTextNode($text));
    $run->appendChild($node);

    return $run;
}

/** A `w:fldSimple` naming a `MERGEFIELD`, in the shape Word writes. */
function mergeField(DOMDocument $dom, string $name, string $before = ''): DOMElement
{
    $field = $dom->createElementNS(W_NS, 'w:fldSimple');
    $field->setAttributeNS(W_NS, 'w:instr', ' MERGEFIELD ' . $name . ' \\* MERGEFORMAT ');

    $run = $dom->createElementNS(W_NS, 'w:r');
    $text = $dom->createElementNS(W_NS, 'w:t');
    $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
    $text->appendChild($dom->createTextNode($before . '«' . $name . '»'));
    $run->appendChild($text);
    $field->appendChild($run);

    return $field;
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
 * Rewrite a `.dotx` package as a `.docx`, so the two can be rendered from and compared.
 *
 * One line of `[Content_Types].xml` is the entire difference: the body part is
 * declared as a document's rather than a template's. Everything else is left exactly as
 * it is, which is the point — the two packages differ only in that line, so anything a
 * renderer does differently for one has to be about it.
 *
 * @see self::asTemplate() for the same rewrite the other way, which is how
 *      `templates/meridian-qbr.dotx` is produced in the first place.
 */
function asDocument(string $source, string $target): void
{
    [$document, $template] = [DOCUMENT_TYPE, TEMPLATE_TYPE];

    copy($source, $target);

    $zip = new ZipArchive();
    $zip->open($target);

    try {
        $declared = (string) $zip->getFromName(CONTENT_TYPES_PART);
        $rewritten = (string) preg_replace('~' . preg_quote($template, '~') . '~', $document, $declared);

        // The name of a package decides nothing; the content type decides everything,
        // and a `.dotx` that is really a document opens as an ordinary file while a
        // `.docx` that is really a template spawns a new document on double-click.
        // Neither is worth leaving for a reader to discover in Word.
        if (!str_contains($rewritten, $document) || str_contains($rewritten, $template)) {
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

    assertPackageType($target, $document);
}

/**
 * Rewrite a `.docx` package as a `.dotx`, which is what makes it a Word template.
 *
 * Only used by `--write-template`, which is how the committed
 * `templates/meridian-qbr.dotx` is produced. Kept as its own function rather than as a
 * flag on {@see self::asDocument()} because the two run in opposite directions and
 * neither is the general case.
 */
function asTemplate(string $source, string $target): void
{
    [$document, $template] = [DOCUMENT_TYPE, TEMPLATE_TYPE];

    copy($source, $target);

    $zip = new ZipArchive();
    $zip->open($target);

    try {
        $declared = (string) $zip->getFromName(CONTENT_TYPES_PART);
        $rewritten = (string) preg_replace('~' . preg_quote($document, '~') . '~', $template, $declared);

        if (!str_contains($rewritten, $template) || str_contains($rewritten, $document)) {
            throw new BadPackage(sprintf('Converting "%s" left a package that is not a template.', $source));
        }

        $zip->deleteName(CONTENT_TYPES_PART);
        $zip->addFromString(CONTENT_TYPES_PART, $rewritten);
    } finally {
        $zip->close();
    }

    assertPackageType($target, $template);
}

/**
 * Whether an archive holds a part by that name.
 *
 * @see self::assertPackageType() for why the name and the type are checked separately.
 */
function hasPart(string $path, string $part): bool
{
    $zip = new ZipArchive();
    $opened = $zip->open($path) === true;
    $found = $opened && $zip->locateName($part) !== false;
    $zip->close();

    return $found;
}

/**
 * Edit one part of a package in place, through a callback that has the whole document.
 *
 * The whole part rather than a fragment, because a field is a child of a paragraph and
 * a `w:fldSimple` is a child of `w:p`: replacing one run inside a paragraph is how you
 * end up with unbalanced XML, and there is no way to add a sibling to a run without
 * holding its parent.
 *
 * @param callable(DOMDocument):void $edit
 */
function rewritePart(string $path, string $part, callable $edit): void
{
    $zip = new ZipArchive();
    $zip->open($path);

    try {
        $xml = $zip->getFromName($part);

        if (!is_string($xml)) {
            return;
        }

        $dom = Xml::parse($xml);

        if ($dom === null) {
            throw new BadPackage(sprintf('%s in "%s" is not valid XML.', $part, $path));
        }

        $edit($dom);

        $zip->deleteName($part);
        $zip->addFromString($part, (string) $dom->saveXML());
    } finally {
        $zip->close();
    }
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

    $phpWord->addFontStyle('InvoiceTitle', ['bold' => true, 'size' => 24, 'color' => MRD_NAVY]);
    $phpWord->addFontStyle('InvoiceMeta', ['size' => 10, 'color' => MRD_SLATE]);

    $section = $phpWord->addSection();

    // Note the third argument: PHPWord's second parameter of addText() is a
    // *character* style, and a paragraph style has to go in the third. Getting
    // this wrong writes a `w:rStyle` reference to a paragraph style, which Word
    // silently ignores.
    $section->addText('INVOICE', null, 'InvoiceTitle');
    $section->addText('No. @@number@@   ·   Issued for @@customer@@', null, 'InvoiceMeta');
    $section->addText(CONTROL_PLACEHOLDER);

    IOFactory::createWriter($phpWord, 'Word2007')->save($path);

    // The values are `MERGEFIELD`s — what a person inserts with Ctrl+F9 — and the
    // content goes into a content control, which is what the Developer tab inserts.
    // Neither is written by PHPWord, so both go into the part afterwards.
    fieldsIn($path);
    controlIn($path, ContentControl::BODY);
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
function renderPreviews(array $built, string $previews): void
{
    $soffice = locateLibreOffice();

    if ($soffice === null) {
        echo "LibreOffice was not found, so no preview images were produced.\n";

        return;
    }

    // Every format the examples write, so the comparison page can be looked at as well
    // as read. The round trip is Markdown and has no first page to look at.
    $renderable = ['docx', 'odt', 'rtf'];

    // One directory for the run, emptied between documents rather than one per document.
    //
    // LibreOffice names its output after the file it was given, so the three formats
    // behind `20-formats` each write `20-formats.png` into whatever directory they were
    // given: in one shared directory each overwrites the last, and the one left standing
    // would stand for all three — the opposite of what the comparison page is for. A
    // directory per document solves that and leaks one directory into the system temp
    // folder on every run of the script, forever, which is what a scratch directory is
    // for.
    $scratch = sys_get_temp_dir() . '/mdword-preview-' . bin2hex(random_bytes(6));

    if (!is_dir($scratch) && !mkdir($scratch, 0o777, true) && !is_dir($scratch)) {
        echo "A temporary directory could not be made, so no preview images were produced.\n";

        return;
    }

    $rendered = 0;

    try {
        foreach ($built as [, , $path]) {
            $extension = pathinfo($path, PATHINFO_EXTENSION);

            if (!in_array($extension, $renderable, true)) {
                continue;
            }

            emptyDirectory($scratch);

            exec(sprintf(
                '%s --headless --convert-to png --outdir %s %s 2>/dev/null',
                escapeshellcmd($soffice),
                escapeshellarg($scratch),
                escapeshellarg($path),
            ), $output, $status);

            if ($status === 0 && movePreview($scratch, $previews, $path, $extension)) {
                $rendered++;
            }

            $output = [];
        }
    } finally {
        emptyDirectory($scratch);
    }

    printf("Rendered %d preview image(s) into out/previews/.\n", $rendered);
}

/**
 * Put a document's preview image in the previews directory, under a name of its own.
 *
 * The format goes into the name wherever it is not the default, so the `.docx` is
 * `20-formats.png` and its two siblings are `20-formats.odt.png` and
 * `20-formats.rtf.png`.
 */
function movePreview(string $scratch, string $previews, string $path, string $extension): bool
{
    $stem = (string) preg_replace('/\.[^.]+$/', '', $path);
    $produced = $scratch . '/' . basename($stem) . '.png';

    if (!is_file($produced)) {
        return false;
    }

    $name = $extension === 'docx'
        ? basename($stem) . '.png'
        : basename($stem) . '.' . $extension . '.png';

    return rename($produced, $previews . '/' . $name);
}

/**
 * Delete a directory and everything in it.
 *
 * `out/` is where the build puts its output and nowhere else, so emptying it first is
 * what keeps a file whose example has been renamed or removed from outliving it.
 *
 * Word's owner file is deleted with everything else rather than treated as a reason to
 * stop. It is written beside a document while it is open and removed when it closes,
 * which in practice means it is usually left behind: a build that refused on finding one
 * would never run again, and the eight of them that were sitting in `out/` were all
 * from documents closed hours earlier.
 */
function emptyDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $entries = array_values(array_diff(scandir($path) ?: [], ['.', '..']));

    foreach ($entries as $entry) {
        $child = $path . '/' . $entry;

        if (is_dir($child) && !is_link($child)) {
            emptyDirectory($child);

            continue;
        }

        @unlink($child);
    }

    @rmdir($path);
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
