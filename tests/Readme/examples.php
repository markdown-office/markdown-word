<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Console\Application;
use MarkdownWord\Converter;
use MarkdownWord\Document\ConfigurationMerger;
use MarkdownWord\Document\Frontmatter;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Render\LinkPlaceholder;
use MarkdownWord\Reverse\Block;
use MarkdownWord\Reverse\Options as ReverseOptions;
use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\Text\TextExtractor;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;
use MarkdownWord\WordToMarkdown;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style\Paragraph;
use PhpOffice\PhpWord\TemplateProcessor;

/*
 * Every example in README.md, run.
 *
 * The examples were a standalone script and are these tests, which is where they
 * belonged: they were 26 checks on a hand-rolled runner, outside the suite, so
 * `pest` did not run them and the coverage gate did not see them. The list is
 * still meant to be read against the README rather than trusted — seven checks
 * for nine examples, and a claim on the page that all of them ran, is what this
 * file exists to prevent — so a new example on that page is a new test here.
 *
 * Each test builds the document it needs rather than sharing one with the next,
 * because Pest may run them in any order and in parallel.
 */

// Writing a document reaches the one known upstream deprecation described in
// tests/Support/Upstream, so the filter spans the whole file rather than each
// test, as it does in the console tests.
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * The report template the README's template example builds, with its own styles.
 *
 * Written out rather than taken from `TemplateFactory` because the point of that
 * example is the corporate style names and the `${rows}` region; a factory
 * template would check that templates work rather than that this page's does.
 */
function readmeReportTemplate(): string
{
    $phpWord = new PhpWord();
    $phpWord->addFontStyle('ReportTitle', ['bold' => true, 'size' => 20], new Paragraph());
    $phpWord->addFontStyle('BodyText', ['size' => 11], new Paragraph());

    $section = $phpWord->addSection();
    $section->addText('Report for ${customer}');
    $section->addText('${body}');
    $section->addText('${slot}');
    $section->addText('${/body}');
    $section->addText('${rows}');
    $section->addText('${item}: ${price}');
    $section->addText('${/rows}');

    $path = Scratch::path('report-template');
    IOFactory::createWriter($phpWord, 'Word2007')->save($path);

    return $path;
}

// The ways in

it('quick start: Markdown to Word', function () {
    $markdown = inputFile('README.md', "# Title\n\nBody.\n");
    $document = Scratch::path('quick');

    (new MarkdownToWord($markdown))->save($document);

    expect(is_file($document))->toBeTrue();
});

it('quick start: Word to Markdown', function () {
    $markdown = inputFile('README.md', "# Title\n\nBody.\n");
    $document = Scratch::path('quick');
    $readBack = Scratch::path('quick', '.md');

    (new MarkdownToWord($markdown))->save($document);
    (new WordToMarkdown($document))->save($readBack);

    expect((string) file_get_contents($readBack))->toContain('# Title');
});

it('convert returns the result, save writes it', function () {
    $markdown = inputFile('notes.md', "# Notes\n\nSome **bold** text.\n");
    $document = Scratch::path('four-ways');
    $readBack = Scratch::path('four-ways-back', '.md');

    $bytes = withoutUpstreamDeprecations(
        static fn (): string => (new MarkdownToWord($markdown))->convert(),
    );

    // Past a two-second boundary on purpose, so the comparison below is made
    // across a clock tick rather than by luck.
    usleep(1100000);

    (new MarkdownToWord($markdown))->save($document);
    $back = withoutUpstreamDeprecations(
        static fn (): string => (new WordToMarkdown($document))->convert(),
    );

    (new WordToMarkdown($document))->save($readBack);

    expect($bytes)->toStartWith('PK');
    expect(documentParts($bytes))->toBe(documentParts((string) file_get_contents($document)));
    expect($back)->toContain('**bold**');
    expect((string) file_get_contents($readBack))->toBe($back);
});

it('a string that names a file is read from it', function () {
    $markdown = inputFile('path-or-content.md', "# Either way\n");
    $fromPath = Scratch::path('from-path');
    $fromText = Scratch::path('from-text');

    (new MarkdownToWord($markdown))->save($fromPath);
    (new MarkdownToWord((string) file_get_contents($markdown)))->save($fromText);

    expect(TemplateFactory::xmlOf($fromPath))
        ->toBe(TemplateFactory::xmlOf($fromText));
});

it('both directions through the interface', function () {
    $markdown = inputFile('interface.md', "# Through the interface\n\nBody.\n");
    $document = Scratch::path('interface');
    $readBack = Scratch::path('interface', '.md');

    (new MarkdownToWord($markdown))->save($document);

    $convert = static function (Converter $converter, string $target): void {
        $converter->save($target);
    };

    $convert(new MarkdownToWord($markdown), Scratch::path('interface-a'));
    $convert(new WordToMarkdown($document), $readBack);

    expect(is_file($document))->toBeTrue();
    expect((string) file_get_contents($readBack))->toContain('# Through the interface');
});

it('a round trip is two of them', function () {
    $document = toDocx("# Round trip\n\nA paragraph.\n");

    expect((new WordToMarkdown($document))->convert())
        ->toBe("# Round trip\n\nA paragraph.");
});

// Installation

it('installation: the package, the bin entry and the version', function () {
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    // `composer require` puts the CLI in `vendor/bin` because of the `bin` entry,
    // so a page naming `vendor/bin/mdword` and a `bin` naming something else is a
    // page that is wrong.
    expect($composer['name'])->toBe('markdown-office/markdown-word')
        ->and($composer['bin'])->toBe(['bin/mdword'])
        ->and(is_file(dirname(__DIR__, 2) . '/bin/mdword'))->toBeTrue();

    $version = runCli(['--version']);

    expect($version['code'])->toBe(0)
        ->and(trim($version['out']))->toBe(Application::NAME . ' ' . Application::VERSION);
});

it('installation: the package the page asks for is this package', function () {
    // Neither of the two commands the page prints can be run here: `composer
    // require` needs the package published, and the release download needs a tag.
    // What can be checked is that the name on the page is the name in
    // `composer.json` — a rename that missed the README is the drift that matters,
    // and it is invisible until somebody follows the page and gets a 404.
    $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');

    expect($readme)->toMatch('/composer require ([\w.-]+\/[\w.-]+)/');

    preg_match('/composer require ([\w.-]+\/[\w.-]+)/', $readme, $match);

    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($match[1])->toBe($composer['name']);
});

// The command line

it('the command line works the direction out for itself', function () {
    $markdown = inputFile('cli.md', "# From the command line\n");
    $document = Scratch::path('cli');
    $readBack = Scratch::path('cli-1', '.md');
    $shortTarget = Scratch::path('cli-3');

    (new MarkdownToWord($markdown))->save($document);

    $fromMarkdown = runCli(['to-docx', $markdown, '-o', Scratch::path('cli-1')]);
    $fromDocument = runCli(['to-markdown', $document, '-o', $readBack]);
    $detected = runCli([$markdown, '-o', Scratch::path('cli-2')]);
    $piped = runCli(['--to', 'docx', '-', '-o', '-'], "# Piped in\n");

    expect($fromMarkdown['code'])->toBe(0)
        ->and($fromDocument['code'])->toBe(0)
        ->and($detected['code'])->toBe(0)
        ->and($piped['code'])->toBe(0)
        ->and($piped['out'])->toStartWith('PK')
        ->and((string) file_get_contents($readBack))->toContain('# From the command line');

    // `--to` takes a short name for either direction as well as the long one.
    $short = runCli(['--to', 'word', $markdown, '-o', $shortTarget]);
    $shorter = runCli(['--to', 'md', $shortTarget, '-o', '-']);

    expect($short['code'])->toBe(0)
        ->and($shorter['out'])->toContain('# From the command line');
});

// Word to Markdown

it('Word to Markdown: convert, save and toMarkdown', function () {
    $document = Scratch::path('in-hand');
    saveDocument("# Bytes in hand\n", $document);
    $bytes = (string) file_get_contents($document);

    $converted = (new WordToMarkdown($document))->convert();
    $readBack = Scratch::path('in-hand', '.md');

    (new WordToMarkdown($document))->save($readBack);
    $fromBytes = (new WordToMarkdown())->toMarkdown($bytes);

    expect($converted)->toContain('# Bytes in hand')
        ->and($fromBytes)->toContain('# Bytes in hand')
        ->and((string) file_get_contents($readBack))->toBe($converted);
});

it('the reader takes its options from an array', function () {
    // The example on the page names a directory beside the Markdown; here the
    // scratch directory is absolute and the rest is as written.
    $assets = Scratch::directory() . '/assets';
    $image = Scratch::image('red-square.png');

    $config = Configuration::create()->withOptions([
        'images' => Options::IMAGE_EMBED,
        'imageBasePath' => dirname($image),
    ]);

    $document = Scratch::path('with-image');
    (new MarkdownToWord('![A red square](red-square.png)', $config))->save($document);

    $options = ReverseOptions::fromArray(['mediaDirectory' => $assets]);
    $markdown = (new WordToMarkdown($document, $options))->convert();

    expect($options->mediaDirectory)->toBe($assets)
        ->and(preg_match('/!\[[^\]]*\]\(([^)]+)\)/', $markdown, $match))->toBe(1)
        ->and(is_file($assets . '/' . basename($match[1])))->toBeTrue();
});

it('what the round trip does not preserve', function () {
    $back = static function (string $markdown, ?Configuration $config = null): string {
        $document = withoutUpstreamDeprecations(
            static fn (): string => (new MarkdownToWord($markdown, $config ?? new Configuration()))->convert(),
        );

        return (new WordToMarkdown($document))->convert();
    };

    // A table's header row comes back bold — the reader cannot tell the
    // renderer's `tableHeaderBold` from the author's `**` — and its delimiter row
    // is rewritten. The alignment is *not* part of the loss, which is the half
    // that a check written from the prose alone would get wrong.
    $table = $back("| A | B |\n| --- | --- |\n| 1 | 2 |\n");

    expect($table)->toContain('| **A** | **B** |')
        ->and($table)->toContain('| :-- | :-- |')
        ->and($back("| A | B | C |\n| :--- | ---: | :---: |\n| 1 | 2 | 3 |\n"))
        ->toContain('| :-- | --: | :-: |');

    // A fenced code block comes back without its language.
    $fenced = $back("```php\n\$x = 1;\n\$y = 2;\n```\n");

    expect($fenced)->toContain("```\n\$x = 1;\n\$y = 2;\n```")
        ->and($fenced)->not->toContain('php');

    // A fenced code block of one line is not read as a block at all: two or more
    // monospaced paragraphs are, which is what `fenceCodeBlocks` says.
    expect(trim($back("```php\n\$x = 1;\n```\n")))->toBe('`$x = 1;`');

    // A quote configured as plain indentation rather than as a style is read as a
    // plain paragraph: `>` is a paragraph style, and there is none to recognise.
    // And with the default style it is a quote, so the loss is the configuration
    // and not the reader.
    $undecorated = Configuration::create()->withStyles([Styles::BLOCK_QUOTE => null]);

    expect(trim($back("> quoted\n", $undecorated)))->toBe('quoted')
        ->and(trim($back("> quoted\n")))->toBe('> quoted');

    // The last line carries no newline, whichever direction and line ending it is
    // read with — the one case the reader appends one.
    expect($back("# Title\n"))->toBe('# Title')
        ->and($back("# Title\n\nBody.\n"))->toBe("# Title\n\nBody.");

    $document = Scratch::path('trailing');
    saveDocument("# Title\n", $document);

    expect((new WordToMarkdown($document))->convert())->toBe('# Title')
        ->and((new WordToMarkdown($document, ReverseOptions::fromArray(['lineEnding' => "\r\n"])))->convert())
        ->toBe("# Title\r\n");
});

// Templates

it('template: a template that defines its own styles', function () {
    $template_path = readmeReportTemplate();
    $summary = inputFile('summary.md', "# Highlights\n\n- one\n- two\n");

    $config = Configuration::create()->withStyles([
        Styles::HEADING_1 => 'ReportTitle',
        Styles::PARAGRAPH => 'BodyText',
        Styles::BLOCK_QUOTE => 'PullQuote',
    ]);

    $output = Scratch::path('northwind');
    $markdown = (string) file_get_contents($summary);

    (new MarkdownTemplate($template_path, $config, ['customer' => 'Northwind Ltd']))
        ->insert('body', $markdown)
        ->repeat('rows', [
            ['item' => 'Licence', 'price' => '1,200 EUR'],
            ['item' => 'Support', 'price' => '300 EUR'],
        ])
        ->save($output);

    $xml = TemplateFactory::xmlOf($output);
    $numbering = TemplateFactory::xmlOf($output, 'word/numbering.xml');

    expect($xml)->not->toContain('${')
        ->and($xml)->toContain('Northwind Ltd')
        ->and($xml)->toContain('ReportTitle')
        ->and($xml)->toContain('1,200 EUR')
        ->and($xml)->toContain('300 EUR')
        ->and($numbering)->toContain('w:numFmt w:val="bullet"');
});

it('renderIntoContainer renders into a container you name', function () {
    // The same render as `toDocx()`, into a container rather than into a document.
    // Nothing is written here, which is the point: the caller is writing the
    // document out, so the destination is the authority on its styles.
    $phpWord = new PhpWord();
    $converter = new MarkdownToWord(null, new Configuration());

    $header = $phpWord->addSection()->addHeader();
    $converter->renderIntoContainer("# A running head\n", $header, $phpWord);

    $output = Scratch::path('container');
    writePhpWordDocument($phpWord, $output);

    expect(TemplateFactory::xmlOf($output, 'word/header1.xml'))->toContain('A running head');
});

it('the template hands back PHPWord\'s own processor', function () {
    expect((new MarkdownTemplate(readmeReportTemplate()))->processor())
        ->toBeInstanceOf(TemplateProcessor::class);
});

// Configuration

it('configuration from a chain', function () {
    $config = Configuration::create()
        ->withStyles([Styles::CODE_FONT => ['name' => 'Fira Code', 'size' => 10]])
        ->withOptions(['images' => Options::IMAGE_PLACEHOLDER]);

    expect($config->getStyles()->get(Styles::CODE_FONT)['name'])->toBe('Fira Code')
        ->and($config->getOptions()->images)->toBe('placeholder');
});

it('configuration from an array', function () {
    $config = Configuration::fromArray(
        require inputFile('config.php', "<?php return ['styles' => ['heading.1' => 'Title']];"),
    );

    expect($config->getStyles()->get(Styles::HEADING_1))->toBe('Title');
});

it('the built-in heading styles, and the rest of the built-in set', function () {
    $styles = (new Configuration())->withBuiltInHeadingStyles()->getStyles();

    // Not the defaults: the quote and list slots move to the built-in list styles,
    // which is the whole point of asking for this one.
    expect($styles->get(Styles::HEADING_1))->toBe('Heading1')
        ->and($styles->get(Styles::BLOCK_QUOTE))->toBe('Quote')
        ->and($styles->get(Styles::BULLET_LIST))->toBe('ListBullet')
        ->and($styles->get(Styles::ORDERED_LIST))->toBe('ListNumber');
});

it('a configuration gives its array back', function () {
    $config = Configuration::create()->withOptions(['tableBorders' => false]);
    $array = $config->toArray();

    expect($array)->toHaveKeys(['styles', 'options'])
        ->and($array['styles'][Styles::HEADING_1])->toBe(Styles::defaults()[Styles::HEADING_1])
        ->and($array['options']['tableBorders'])->toBeFalse();

    // Round trip: what comes out can go back in.
    expect(Configuration::fromArray($array)->getOptions()->tableBorders)->toBeFalse()
        ->and($config->getStyles()->toArray())->toBe($array['styles'])
        ->and($config->getOptions()->toArray())->toBe($array['options']);
});

it('the reader options survive the array they are written in', function () {
    $reader = ReverseOptions::fromArray(['mediaDirectory' => 'assets', 'headingSetext' => true]);

    expect($reader->toArray())->toBe(ReverseOptions::fromArray($reader->toArray())->toArray())
        ->and($reader->mediaDirectory)->toBe('assets')
        ->and($reader->headingSetext)->toBeTrue();
});

it('the style slots can be read and replaced one at a time', function () {
    $styles = new Styles();

    // `defaults()` is what a new instance starts from, `heading()` resolves a
    // level, and `with()` returns a new instance with one slot replaced.
    expect($styles->toArray())->toBe(Styles::defaults())
        ->and($styles->heading(1))->toBe(Styles::defaults()[Styles::HEADING_1])
        ->and($styles->heading(9))->toBe(Styles::defaults()[Styles::HEADING_6]);

    $changed = $styles->with(Styles::HEADING_1, 'CorpTitle');

    expect($changed->get(Styles::HEADING_1))->toBe('CorpTitle')
        ->and($styles->get(Styles::HEADING_1))->toBe(Styles::defaults()[Styles::HEADING_1]);
});

// Advanced use

it('advanced: compose with PhpWord', function () {
    $phpWord = new PhpWord();
    $converter = new MarkdownToWord(null, new Configuration());

    $section = $phpWord->addSection();
    $section->addTitle('Annual Report', 1);

    $phpWord->getDocInfo()->setTitle('Annual Report');

    // `toDocx()` renders the Markdown into the document it is handed, so the
    // example renders once. An example that also called `renderIntoContainer()`
    // with the same section first would have the chapter in the document twice,
    // and the count is what says so.
    $document = $converter->toDocx("# Chapter one\n\nBody.", $phpWord);
    $readBack = (new WordToMarkdown($document))->convert();

    expect($readBack)->toContain('Annual Report')
        ->and(substr_count($readBack, '# Chapter one'))->toBe(1);
});

it('text extractor', function () {
    $text = TextExtractor::fromPhpWord(
        withoutUpstreamDeprecations(
            static fn (): PhpWord => (new MarkdownToWord())->toPhpWord("# Title\n\nBody with **bold**."),
        ),
    );

    expect($text)->toBe("Title\nBody with bold.");
});

it('the syntax tree is there for callers that want it', function () {
    $converter = new MarkdownToWord();

    $tree = $converter->parse("# Parsed\n\nBody with a [link](https://example.com).");

    expect($tree)->toBeInstanceOf(League\CommonMark\Node\Block\Document::class);

    $converter->toPhpWord("A [link](https://example.com) and a [**bold** one](https://example.test).");

    // Only the links PHPWord cannot express are left pending: a plain one becomes
    // a `Link` element, while a label carrying emphasis has to wait for the
    // writer.
    $pending = $converter->pendingHyperlinks();

    // The shape as well as the URLs. The sample on the page is a shape, and
    // checking only the URLs left nothing holding it up: a sample whose token had
    // the marker on one side of the index rather than both survived a whole pass
    // over the page, because the URL was right and nothing looked at the rest.
    expect(array_column($pending, 'url'))->toBe(['https://example.test'])
        ->and(array_keys($pending[0]))->toBe(['placeholder', 'url', 'title', 'runs'])
        ->and($pending[0]['title'])->toBeNull()
        ->and($pending[0]['runs'])->not->toBe([]);

    // `MARKER . $index . MARKER`, twice over — which is what the sample shows.
    $token = $pending[0]['placeholder'];

    expect($token)->toBe(LinkPlaceholder::forIndex(0))
        ->and($token)->toStartWith(LinkPlaceholder::MARKER)
        ->and($token)->toEndWith(LinkPlaceholder::MARKER)
        ->and(substr_count($token, LinkPlaceholder::MARKER))->toBe(2);
});

it('the reader hands back the block tree as well as the Markdown', function () {
    $document = Scratch::path('blocks');
    saveDocument("# Read back\n\n- one\n- two\n", $document);

    $blocks = (new WordToMarkdown())->read($document);

    expect($blocks)->not->toBe([])
        ->and($blocks[0])->toBeInstanceOf(Block::class)
        ->and((new WordToMarkdown($document))->convert())->toContain('# Read back');
});

it('parser flavours', function () {
    foreach (['commonMarkOnly', 'extended', 'withAllExtensions'] as $flavour) {
        $converter = new MarkdownToWord(null, new Configuration(), CommonMarkParser::{$flavour}());

        expect(withoutUpstreamDeprecations(
            static fn (): PhpWord => $converter->toPhpWord("# Title\n\n- a\n- b\n"),
        ))->toBeInstanceOf(PhpWord::class);
    }
});

it('the default parser: what needs extended() and what does not', function () {
    $back = static function (string $markdown, ?CommonMarkParser $parser = null): string {
        $document = withoutUpstreamDeprecations(
            static fn (): string => (new MarkdownToWord($markdown, new Configuration(), $parser))->convert(),
        );

        return (new WordToMarkdown($document))->convert();
    };

    // A task list, through the default parser, unchanged in both directions — the
    // round trip's missing trailing newline and all, which is a loss of its own
    // and is listed as one on the page.
    $tasks = "- [ ] todo\n- [x] done\n";

    expect($back($tasks))->toBe("- [ ] todo\n- [x] done");

    // What the document actually carries is the ballot box, not the bracket —
    // which is the half of the table row that says what happens in Word.
    expect(TextExtractor::fromPhpWord(withoutUpstreamDeprecations(
        static fn (): PhpWord => (new MarkdownToWord())->toPhpWord($tasks),
    )))->toBe("\u{2610} todo\n\u{2612} done");

    // A footnote is not GFM, so without the extension the marker survives as
    // escaped literal text. With it, the marker is consumed and the note text
    // moves into the document body. A description list is the same story.
    $footnote = "Text[^1]\n\n[^1]: A note.\n";
    $description = "Term\n\n: Definition\n";

    expect($back($footnote))->toContain('\[^1\]')
        ->and($back($footnote, CommonMarkParser::extended()))->not->toContain('[^1]')
        ->and($back($description))->toContain(': Definition')
        ->and(trim($back($description, CommonMarkParser::extended())))->toBe('Definition');
});

// The frontmatter example, run.

it('reads the frontmatter example off the page and merges it as the page says', function () {
    $document = CommonMarkParser::withAllExtensions()->parse(<<<'MARKDOWN'
        ---
        template_file: report.dotx
        options:
          maxHeadingLevel: 3
          tableBorders: false
        styles:
          heading.1: Title
        ---

        # Quarterly
        MARKDOWN);

    $configuration = ConfigurationMerger::resolve(
        commandLine: ['options' => ['maxHeadingLevel' => 2]],
        frontmatter: Frontmatter::fromDocument($document),
        configFile: null,
    );

    // The block names a template and is not a Word option, so it is read as data and
    // does not become a configuration key.
    expect(Frontmatter::fromDocument($document)->getString('template_file'))->toBe('report.dotx')
        ->and($configuration->getOptions()->maxHeadingLevel)->toBe(2)
        // The option the command line did not mention came from the frontmatter, which
        // is the claim the page makes about a source that says nothing about it.
        ->and($configuration->getOptions()->tableBorders)->toBeFalse()
        ->and($configuration->getStyles()->toArray())->toHaveKey('heading.1', 'Title');
});

it('keeps the frontmatter out of the document it configures', function () {
    // The claim is that the block is configuration rather than content, so it must not
    // also arrive as a paragraph.
    $document = CommonMarkParser::withAllExtensions()->parse("---\ntitle: Quarterly\n---\n\nBody");

    expect($document->firstChild())->toBeInstanceOf(League\CommonMark\Node\Block\Paragraph::class)
        ->and($document->firstChild()?->firstChild()?->getLiteral())->toBe('Body');
});

it('orders the four sources the way the table on the page says', function () {
    $fromFile = ['options' => ['maxHeadingLevel' => 4]];

    $frontmatter = Frontmatter::of(['options' => ['maxHeadingLevel' => 3]]);
    $commandLine = ['options' => ['maxHeadingLevel' => 2]];

    $level = static fn (Configuration $c): int => $c->getOptions()->maxHeadingLevel;

    // Each source against the one directly below it, because a merge that ignores a
    // source in the middle of the chain produces a configuration that is valid and
    // entirely wrong.
    expect($level(ConfigurationMerger::resolve(configFile: $fromFile)))->toBe(4)
        ->and($level(ConfigurationMerger::resolve(frontmatter: $frontmatter, configFile: $fromFile)))->toBe(3)
        ->and($level(ConfigurationMerger::resolve(commandLine: $commandLine, frontmatter: $frontmatter)))->toBe(2)
        ->and($level(ConfigurationMerger::resolve(commandLine: $commandLine, frontmatter: $frontmatter, configFile: $fromFile)))->toBe(2);
});
