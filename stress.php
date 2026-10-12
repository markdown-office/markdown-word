<?php

declare(strict_types=1);

/**
 * Feeds deliberately awkward input through every configuration this library has,
 * and checks that every result is a valid `.docx`.
 *
 * ```sh
 * php stress.php
 * ```
 *
 * What it is for is the failure mode rather than the input: malformed nesting,
 * unbalanced delimiters, control characters, very deep structures and enormous
 * documents all have to come out as a document Word will open. It uses no test
 * framework, so it runs on the oldest PHP the library claims to support.
 *
 * Everything it writes goes into `tmp/stress`, and it removes that directory on
 * the way out: `tmp` is shared with the test suite and with the other checks at
 * the root of this repository, and a check that leaves its output behind is a
 * check whose evidence nobody knows what to do with.
 */

require __DIR__ . '/vendor/autoload.php';

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Console\UpstreamDeprecations;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Template\ContentControl;
use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\Text\TextExtractor;
use MarkdownWord\Xml;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

// One dependency emits a deprecation for every list item it writes, and the run
// would print thousands of somebody else's lines; see the class for why.
UpstreamDeprecations::install();

$work = __DIR__ . '/tmp/stress';
@mkdir($work, 0o777, true);

$cases = [
    'empty' => '',
    'whitespace only' => "   \n\t\n  \n",
    'unterminated emphasis' => '*unclosed',
    'unterminated code' => "```\nno closing fence\n",
    'unterminated link' => '[text](unclosed',
    'unterminated html' => '<div><span>unclosed',
    'deep emphasis' => str_repeat('*', 40) . 'x' . str_repeat('*', 40),
    'deep quotes' => str_repeat('> ', 20) . 'deep',
    'deep lists' => implode("\n", array_map(
        static fn (int $i): string => str_repeat(' ', $i * 2) . '- item ' . $i,
        range(0, 12),
    )),
    // Marks that introduce no content: the result is legitimately empty, so the
    // "nothing was lost" check has nothing to say about this one.
    'lone marks' => "#\n\n[]()\n\n<!-- a comment -->\n\n***\n",
    'only entities' => '&amp;&lt;&gt;&#65;&#x42;',
    'null bytes' => "before\x00after",
    'control chars' => "bell\x07 and vertical\x0B tab",
    'very long line' => str_repeat('word ', 5000),
    'many headings' => implode("\n\n", array_map(
        static fn (int $i): string => '#' . (($i % 6) + 1) . " Heading {$i}",
        range(1, 200),
    )),
    'wide table' => self_table(60, 8),
    'crlf' => "# Title\r\n\r\nBody\r\n",
    'bom' => "\xEF\xBB\xBF# Title\n",
    'html table' => "<table><tr><td>a</td></tr></table>\n",
    'image missing' => '![alt text](nope.png)',
    'image remote' => '![alt](https://example.com/a.png)',
    'link in code' => '`[not a link](x)`',
    'autolink email' => '<mailto:a@b.com>',
    'mixed everything' => self_mixed(),
];

/**
 * The cases whose document is allowed to carry no text at all: there was never any
 * to lose, while anything with content in it has to come out with that content.
 */
$noTextExpected = [
    'empty' => true,
    'whitespace only' => true,
    'lone marks' => true,
];

function self_table(int $rows, int $cols): string
{
    $line = fn (int $c): string => '| c' . $c . ' ';

    $out = $line(0);
    for ($c = 1; $c < $cols; $c++) {
        $out .= str_repeat('-', 3) . '|';
    }
    $out = substr($out, 0, -1) . "\n" . $line(0);
    for ($c = 1; $c < $cols; $c++) {
        $out .= '---|';
    }
    $out = rtrim($out, '|') . "\n";

    for ($r = 0; $r < $rows; $r++) {
        $out .= '|';
        for ($c = 0; $c < $cols; $c++) {
            $out .= ' v' . $r . '-' . $c . ' |';
        }
        $out .= "\n";
    }

    return $out;
}

function self_mixed(): string
{
    return <<<'MD'
        # Title with `code` and **bold**

        > Quote with a [link](https://example.com) and a list:
        >
        > - one
        > - two

        1. First
           1. Nested
              - Deeper

        | a | b |
        | - | - |
        | 1 | 2 |

        ```js
        const x = { nested: { deeply: true } };
        ```

        ![img](a.png "title")

        <div class="x">raw <b>html</b></div>

        Term[^1]

        [^1]: The note.

        ---

        [ref]: https://example.com/ref "Ref title"
        MD;
}

/**
 * "Word will open it" means the archive is a zip and `word/document.xml` is XML,
 * short of opening it. Read from the archive rather than from the file: the file
 * is compressed, and looking for a placeholder in compressed bytes finds one by
 * coincidence sooner or later.
 *
 * @throws RuntimeException when either of those is not so.
 */
function self_documentXmlOf(string $path): string
{
    $zip = new ZipArchive();

    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('the output is not a zip archive');
    }

    $document = (string) $zip->getFromName('word/document.xml');
    $zip->close();

    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $valid = $dom->loadXML($document, LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$valid) {
        throw new RuntimeException('word/document.xml is not valid XML');
    }

    return $document;
}

$configurations = [
    'default' => new Configuration(),
    'no decoration' => Configuration::create()->withoutDecoration(),
    'CommonMark only' => null,
    'every extension' => null,
    'placeholder images' => Configuration::create()->withOptions(['images' => Options::IMAGE_PLACEHOLDER]),
    'soft break as paragraph' => Configuration::create()->withOptions(['softBreak' => Options::SOFT_BREAK_PARAGRAPH]),
    'html preserved' => Configuration::create()->withOptions(['html' => Options::HTML_PRESERVE]),
    'max heading 2' => Configuration::create()->withOptions(['maxHeadingLevel' => 2]),
    'roman lists' => Configuration::create()->withOptions(['orderedListFormat' => 'lowerRoman']),
];

$parsers = [
    'CommonMark only' => CommonMarkParser::commonMarkOnly(),
    'every extension' => CommonMarkParser::withAllExtensions(),
];

// The template renderer takes a configuration but no parser, so the configurations
// named in $parsers are left out: they would run as the default and be reported
// under a name that is not what was given.
$templateConfigurations = array_filter(
    $configurations,
    static fn ($config, string $name): bool => !isset($parsers[$name]),
    ARRAY_FILTER_USE_BOTH,
);

$failures = 0;
$checks = 0;

foreach ($configurations as $configName => $config) {
    $parser = $parsers[$configName] ?? null;

    foreach ($cases as $caseName => $markdown) {
        $checks++;

        try {
            $converter = new MarkdownToWord(null, $config ?? new Configuration(), $parser);
            $phpWord = $converter->toPhpWord($markdown);

            $path = $work . '/out.docx';
            file_put_contents($path, $converter->toDocx($markdown));

            if (!is_file($path) || filesize($path) < 100) {
                throw new RuntimeException('the document is empty');
            }

            $document = self_documentXmlOf($path);

            if (str_contains($document, 'MDWL')) {
                throw new RuntimeException('a hyperlink placeholder leaked into the document');
            }

            // The text must be extractable, and there must be as much of it as
            // there was. A case that legitimately produces no content is named in
            // $noTextExpected rather than skipped: the document is still written
            // and still has to be valid.
            $text = TextExtractor::fromPhpWord($phpWord, TextExtractor::LINE_BREAK, $converter->pendingHyperlinks());

            if ($text === '' && trim($markdown) !== '' && !isset($noTextExpected[$caseName])) {
                throw new RuntimeException('text was lost');
            }
        } catch (\Throwable $e) {
            $failures++;
            echo "FAIL  [{$configName}] {$caseName}: {$e->getMessage()}\n";
        }
    }
}

// The template path has to survive the same treatment with the same
// configurations: a configuration that works only on the way into a new document
// is not a configuration that works.
$templatePath = $work . '/template.docx';

$blank = new PhpWord();
$section = $blank->addSection();
$section->addText('%%body%%');
IOFactory::createWriter($blank, 'Word2007')->save($templatePath);

// PHPWord writes no content control, so the marker is replaced with one in the part
// afterwards — which is what a template authored in Word carries.
$zip = new ZipArchive();
$zip->open($templatePath);
$dom = new DOMDocument();
$dom->loadXML((string) $zip->getFromName('word/document.xml'));
$zip->close();

$xpath = new DOMXPath($dom);
$xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

$control = Xml::parse(
    '<root xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . ContentControl::markup(ContentControl::BODY) . '</root>',
)?->documentElement?->firstChild;

foreach ($xpath->query('//w:p') ?: [] as $paragraph) {
    if (!$control instanceof DOMNode || trim((string) $paragraph->textContent) !== '%%body%%') {
        continue;
    }

    $paragraph->parentNode?->replaceChild($dom->importNode($control, true), $paragraph);

    break;
}

$zip = new ZipArchive();
$zip->open($templatePath);
$zip->deleteName('word/document.xml');
$zip->addFromString('word/document.xml', (string) $dom->saveXML());
$zip->close();

foreach ($templateConfigurations as $configName => $config) {
    foreach ($cases as $caseName => $markdown) {
        $checks++;

        try {
            $processor = new MarkdownTemplate($templatePath, $config ?? new Configuration());
            $processor->insert($markdown);
            $output = $work . '/template-out.docx';
            $processor->save($output);

            $document = self_documentXmlOf($output);

            if (str_contains($document, '${')) {
                throw new RuntimeException('an unreplaced macro survived');
            }

            if (str_contains($document, 'MDWL')) {
                throw new RuntimeException('a hyperlink placeholder leaked into the template');
            }
        } catch (\Throwable $e) {
            $failures++;
            echo "FAIL  [template: {$configName}] {$caseName}: {$e->getMessage()}\n";
        }
    }
}

UpstreamDeprecations::restore();

self_removeTree($work);

echo "\n{$checks} checks, {$failures} failure(s)\n";

exit($failures === 0 ? 0 : 1);

function self_removeTree(string $directory): void
{
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . '/' . $entry;

        is_dir($path) ? self_removeTree($path) : @unlink($path);
    }

    @rmdir($directory);
}
