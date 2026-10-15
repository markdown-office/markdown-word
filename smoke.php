#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Does this library work on this version of PHP?
 *
 * The test suite needs Pest, and Pest 5 needs PHP 8.4, so on the older versions
 * the library supports there would otherwise be nothing running: a syntax error,
 * a missing extension or a broken autoloader would not be noticed until somebody
 * tried to install it. This uses no test framework, so it runs anywhere the
 * library claims to run, and it checks both directions.
 *
 * ```sh
 * php smoke.php
 * ```
 */

require __DIR__ . '/vendor/autoload.php';

// Not through the autoloader: the minimum-version job installs with `--no-dev`,
// which leaves `autoload-dev` out, so the class the filter lives in is not
// autoloadable here. It is a plain file, so requiring it is enough.
require_once __DIR__ . '/tests/Support/Upstream.php';

use MarkdownWord\MarkdownToWord;
use MarkdownWord\Tests\Support\Upstream;
use MarkdownWord\WordToMarkdown;

$checks = 0;
$failures = 0;

// One dependency emits a deprecation for every list item it writes, and the run
// would print thousands of somebody else's lines; see the class for why.
Upstream::install();

$work = __DIR__ . '/tmp/smoke';
@mkdir($work, 0o777, true);

function check(string $what, callable $test): void
{
    global $checks, $failures;

    $checks++;

    try {
        $result = $test();

        if ($result === true) {
            return;
        }

        $failures++;
        echo 'FAIL  ' . $what . ': ' . (is_string($result) ? $result : 'returned false') . "\n";
    } catch (Throwable $e) {
        $failures++;
        echo 'FAIL  ' . $what . ': ' . $e::class . ' — ' . $e->getMessage() . "\n";
    }
}

/**
 * Whitespace layout is a rendering concern rather than a content one, so runs of
 * spaces collapse and blank lines go. What is left is the text and the structure
 * of the line it is on.
 */
function normalise(string $markdown): string
{
    $lines = array_map(
        static fn (string $line): string => trim((string) preg_replace('/[ \t]+/', ' ', $line)),
        explode("\n", $markdown),
    );

    return implode("\n", array_filter($lines, static fn (string $line): bool => $line !== ''));
}

function part(string $docx, string $name): ?string
{
    $zip = new ZipArchive();

    if ($zip->open($docx) !== true) {
        return null;
    }

    $contents = $zip->getFromName($name);
    $zip->close();

    return $contents === false ? null : $contents;
}

echo 'PHP ' . PHP_VERSION . "\n\n";

check('the extensions the library needs are loaded', static function (): bool|string {
    $missing = array_filter(
        ['zip', 'dom', 'mbstring', 'gd'],
        static fn (string $extension): bool => !extension_loaded($extension),
    );

    return $missing === [] ? true : 'missing: ' . implode(', ', $missing);
});

$sample = <<<'MD'
    # Heading

    A paragraph with **bold**, *italic*, `code` and a [link](https://example.test).

    - one
      - nested
    - two

    1. first
    2. second

    > a quote

    ```php
    $x = 1 < 2 && 3 > 2;
    $y = sqrt($x);
    ```

    | A | B |
    | --- | --- |
    | 1 | 2 |
    MD;

$docx = $work . '/sample.docx';

check('Markdown converts to a Word document', static function () use ($sample, $docx): bool|string {
    Upstream::quietly(
        static fn () => (new MarkdownToWord($sample))->save($docx),
    );

    return is_file($docx) && filesize($docx) > 0 ? true : 'nothing was written';
});

check('the document is a zip archive', static function () use ($docx): bool|string {
    return part($docx, 'word/document.xml') !== null ? true : 'the file is not a zip archive';
});

check('the document is well-formed XML', static function () use ($docx): bool|string {
    $document = part($docx, 'word/document.xml');

    if ($document === null) {
        return 'no document body';
    }

    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $valid = $dom->loadXML($document, LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $valid ? true : 'word/document.xml does not parse';
});

check('the table spans the text column', static function () use ($docx): bool|string {
    $document = part($docx, 'word/document.xml') ?? '';

    return str_contains($document, 'w:tblW w:w="5000" w:type="pct"')
        ? true
        : 'the table is not the full width of the column';
});

check('the hyperlink placeholder did not survive into the document', static function () use ($docx): bool|string {
    return str_contains(part($docx, 'word/document.xml') ?? '', 'MDWL')
        ? 'a placeholder leaked into the output'
        : true;
});

$markdown = '';

check('the document converts back to Markdown', static function () use ($docx, &$markdown): bool|string {
    $markdown = (new WordToMarkdown($docx))->convert();

    return str_contains($markdown, '# Heading') ? true : 'the heading did not survive';
});

foreach ([
    'a heading' => '# Heading',
    'bold text' => '**bold**',
    'inline code' => '`code`',
    'a link' => '[link](https://example.test)',
    'a bullet list' => '- one',
    'a nested list item' => '  - nested',
    'an ordered list' => '1. first',
    'a quote' => '> a quote',
    'a code block' => '```',
    'the code itself' => '$y = sqrt($x);',
    'a table' => '| 1 | 2 |',
] as $what => $needle) {
    // Skipped rather than failed when the conversion above produced nothing: every
    // check in this loop looks for its own construct in the same empty string, so
    // reporting them one by one would report one defect once per construct.
    if ($markdown === '') {
        echo 'SKIP  the round trip keeps ' . $what . ": nothing was read back\n";
        $checks++;

        continue;
    }

    check('the round trip keeps ' . $what, static function () use ($markdown, $needle): bool|string {
        return str_contains($markdown, $needle)
            ? true
            : 'no "' . $needle . '" in the Markdown read back';
    });
}

check('a round trip keeps the text', static function () use ($markdown, $work): bool|string {
    $again = $work . '/again.docx';

    Upstream::quietly(
        static fn () => (new MarkdownToWord($markdown))->save($again),
    );

    $readBack = Upstream::quietly(
        static fn (): string => (new WordToMarkdown($again))->convert(),
    );

    $before = normalise($markdown);
    $after = normalise($readBack);

    return $before === $after
        ? true
        : sprintf("the text changed\n    before: %s\n    after:  %s", $before, $after);
});

check('a document in memory converts back too', static function () use ($sample): bool|string {
    $bytes = Upstream::quietly(
        static fn (): string => (new MarkdownToWord())->toDocx($sample),
    );

    $readBack = (new WordToMarkdown($bytes))->convert();

    return str_contains($readBack, '# Heading') ? true : 'the heading did not survive';
});

check('something that is not a document is reported, not swallowed', static function (): bool|string {
    try {
        (new WordToMarkdown('not a zip file'))->convert();
    } catch (RuntimeException) {
        return true;
    }

    return 'no exception was thrown';
});

echo "\n{$checks} checks, {$failures} failure(s)\n";

Upstream::restore();

removeTree($work);

exit($failures === 0 ? 0 : 1);

/**
 * Only this run's own directory goes: `tmp` is shared with the test suite and with
 * the other checks at the root of the repository.
 */
function removeTree(string $directory): void
{
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . '/' . $entry;

        is_dir($path) ? removeTree($path) : @unlink($path);
    }

    @rmdir($directory);
}
