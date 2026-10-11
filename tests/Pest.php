<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Console\Application;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\Text\TextExtractor;
use MarkdownWord\Tests\Support\MarkdownText;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\Upstream;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\Paragraph;

/**
 * Test bootstrap and the helpers the renderer tests share.
 *
 * The helpers are functions rather than a base test case: Pest builds each test
 * as a closure, so a shared *capability* belongs here and a shared *assertion* in
 * the test that makes it.
 */

/**
 * Render Markdown into a `PhpWord` document.
 */
function convertTo(string $markdown, ?Configuration $config = null, ?PhpWord $phpWord = null): PhpWord
{
    return (new MarkdownToWord(null, $config ?? new Configuration()))->toPhpWord($markdown, $phpWord);
}

/**
 * @return list<AbstractElement>
 */
function renderElements(string $markdown, ?Configuration $config = null): array
{
    return array_values(renderSection($markdown, $config)->getElements());
}

function renderSection(string $markdown, ?Configuration $config = null): AbstractContainer
{
    $sections = convertTo($markdown, $config)->getSections();

    expect($sections)->not->toBeEmpty('The document should contain at least one section.');

    return $sections[0];
}

function renderText(string $markdown, ?Configuration $config = null): string
{
    $converter = new MarkdownToWord(null, $config ?? new Configuration());

    return TextExtractor::fromPhpWord(
        $converter->toPhpWord($markdown),
        TextExtractor::LINE_BREAK,
        $converter->pendingHyperlinks(),
    );
}

function elementTextOf(AbstractElement $element): string
{
    return $element instanceof AbstractContainer
        ? TextExtractor::fromContainer($element)
        : '';
}

function styleNameOf(AbstractElement $element): ?string
{
    $style = $element->getParagraphStyle();

    if (is_string($style)) {
        return $style;
    }

    return $style instanceof Paragraph ? $style->getStyleName() : null;
}

/**
 * The paragraph style of an element, normalised to the shape the renderer was
 * configured with: a style name, an inline array, or null.
 *
 * PHPWord materialises a `Paragraph` object when an inline style array is given,
 * so tests read better once it is turned back into an array.
 *
 * @return array<string, mixed>|string|null
 */
function paragraphStyleOf(AbstractElement $element): array|string|null
{
    $style = $element->getParagraphStyle();

    if (!$style instanceof Paragraph) {
        return $style;
    }

    // Only the values that were actually set are of interest, so a default
    // constructed Paragraph (no options given) reads as "no style".
    $set = [];

    foreach ([
        'styleName' => 'getStyleName',
        'alignment' => 'getAlignment',
        'indentation' => 'getIndentation',
        'shading' => 'getShading',
        'keepNext' => 'getKeepNext',
        'pageBreakBefore' => 'getPageBreakBefore',
        'borderBottomStyle' => 'getBorderBottomStyle',
        'borderBottomSize' => 'getBorderBottomSize',
        'borderBottomColor' => 'getBorderBottomColor',
    ] as $key => $getter) {
        if (!method_exists($style, $getter)) {
            continue;
        }

        $value = simplifyStyleValue($style->{$getter}());

        if ($value !== null && $value !== false && $value !== [] && $value !== '') {
            $set[$key] = $value;
        }
    }

    // PHPWord exposes spacing through accessors of its own, and a space before
    // of zero is meaningful, so it is read outside the table above.
    $spacing = array_filter([
        'before' => $style->getSpaceBefore(),
        'after' => $style->getSpaceAfter(),
    ], static fn (mixed $value): bool => $value !== null);

    if ($spacing !== []) {
        $set['space'] = $spacing;
    }

    return $set === [] ? null : $set;
}

/**
 * Flatten the value objects PHPWord returns into plain arrays, so assertions read
 * like the configuration that produced them.
 */
function simplifyStyleValue(mixed $value): mixed
{
    if ($value instanceof PhpOffice\PhpWord\Style\Indentation) {
        return array_filter([
            'left' => (int) $value->getLeft(),
            'right' => (int) $value->getRight(),
            'firstLine' => (int) $value->getFirstLine(),
            'hanging' => (int) $value->getHanging(),
        ], static fn (mixed $v): bool => $v !== null);
    }

    if ($value instanceof PhpOffice\PhpWord\Style\Spacing) {
        return array_filter([
            'before' => $value->getBefore(),
            'after' => $value->getAfter(),
            'line' => $value->getLine(),
        ], static fn (mixed $v): bool => $v !== null);
    }

    if ($value instanceof PhpOffice\PhpWord\Style\Shading) {
        return array_filter([
            'fill' => $value->getFill(),
            'color' => $value->getColor(),
        ], static fn (mixed $v): bool => $v !== null);
    }

    if ($value instanceof PhpOffice\PhpWord\Style\Border) {
        return array_filter([
            'style' => $value->getBorderStyle(),
            'size' => $value->getBorderSize(),
            'color' => $value->getBorderColor(),
        ], static fn (mixed $v): bool => $v !== null && $v !== 0);
    }

    return $value;
}

/**
 * The runs of an element, in order.
 *
 * PHPWord materialises the inline style array into a `Font` object, so it is
 * turned back into the array the renderer was configured with.
 *
 * @return list<array{text: string, font: array<string, mixed>|string|null}>
 */
function renderRuns(AbstractElement $element): array
{
    if (!$element instanceof AbstractContainer) {
        return [];
    }

    $runs = [];

    foreach ($element->getElements() as $child) {
        if (!$child instanceof Text) {
            continue;
        }

        $font = simplifyFont($child->getFontStyle());

        // An unstyled run still carries PHPWord's defaults, so an empty result
        // reads as "no formatting".
        $runs[] = [
            'text' => $child->getText(),
            'font' => $font === [] ? null : $font,
        ];
    }

    return $runs;
}

/**
 * @return array<string, mixed>|string|null
 */
function simplifyFont(mixed $font): array|string|null
{
    if (!$font instanceof Font) {
        return $font;
    }

    $set = [];

    foreach ([
        'bold' => 'isBold',
        'italic' => 'isItalic',
        'strike' => 'isStrikethrough',
        'name' => 'getName',
        'size' => 'getSize',
        'color' => 'getColor',
    ] as $key => $getter) {
        $value = $font->{$getter}();

        if ($value !== null && $value !== false && $value !== '') {
            $set[$key] = $value;
        }
    }

    // "none" is PHPWord's default for underline, so it carries no meaning.
    $underline = $font->getUnderline();

    if ($underline !== 'none' && $underline !== '' && $underline !== null) {
        $set['underline'] = $underline;
    }

    return $set;
}

function renderRunText(AbstractElement $element): string
{
    return implode('', array_column(renderRuns($element), 'text'));
}

/**
 * The visible text of a document, in the shape a text comparison needs; the
 * rules are {@see MarkdownText::normalise()}'s.
 */
function normaliseDocumentText(string $text): string
{
    return MarkdownText::normalise($text);
}

// The specification corpora, as named datasets: a closure, so the examples are
// parsed only when a corpus suite runs and each suite reads as one line.

require_once __DIR__ . '/Datasets/spec-examples.php';

dataset('commonMarkExamples', fn (): array => specExamples(__DIR__ . '/fixtures/spec/commonmark-spec.txt', 'commonmark'));

dataset('gfmExamples', fn (): array => specExamples(__DIR__ . '/fixtures/spec/gfm-spec.txt', 'gfm'));

/**
 * How many levels of list nesting a numbering definition covers.
 *
 * A nested list points at a level of the same definition, so a definition
 * missing one makes Word fall back to a different list — which is how a sub-list
 * of bullets comes out numbered. `NumberingRegistry` writes this many levels,
 * and `it('writes a level for every level Word supports')` in
 * tests/Unit/style-definition.php reads that number back off the renderer, so a
 * change to either fails as one test whose name says what changed.
 */
const NUMBERING_LEVELS = 9;

/**
 * Assert that both specification corpora are really there.
 *
 * A silently empty corpus — a parser that stopped recognising the example
 * markers, a fixture that failed to copy — would leave the corpus suites passing
 * for the wrong reason, so their size is asserted. Both corpus suites need it.
 */
function expectSpecificationCorpora(): void
{
    expect(specExamples(__DIR__ . '/fixtures/spec/commonmark-spec.txt', 'commonmark'))->toHaveCount(654);
    expect(specExamples(__DIR__ . '/fixtures/spec/gfm-spec.txt', 'gfm'))->toHaveCount(646);
}

/**
 * With the one known upstream deprecation silenced; tests/Support/Upstream is
 * where the defect and the reason only that one is swallowed are described.
 */
function withoutUpstreamDeprecations(callable $work): mixed
{
    return Upstream::quietly($work);
}

/**
 * Render Markdown to the bytes of a `.docx` file.
 */
function toDocx(string $markdown, ?Configuration $config = null): string
{
    return withoutUpstreamDeprecations(
        static fn (): string => (new MarkdownToWord(null, $config ?? new Configuration()))->toDocx($markdown),
    );
}

// `tmp/pest` is emptied after every test, failure or not, so a run leaves
// nothing behind. The hook goes through `pest()`: in Pest 5 a bare `afterEach()`
// written here binds to this file, which is not a test file, so it would never
// fire — nothing fails, every test passes, and the files simply stop being
// cleaned up.

pest()->afterEach(function (): void {
    Scratch::cleanUp();
});

/**
 * A file in the scratch directory with the given contents, and its path.
 *
 * The name may carry its own extension — the command line derives an output name
 * from it, and a few tests are about the name rather than only the bytes — and a
 * random suffix is added so that no two tests collide.
 */
function inputFile(string $name, string $contents, ?string $extension = null): string
{
    $given = pathinfo($name, PATHINFO_EXTENSION);

    $path = Scratch::path(
        pathinfo($name, PATHINFO_FILENAME),
        $extension ?? ($given === '' ? '.md' : '.' . $given),
    );

    file_put_contents($path, $contents);

    return $path;
}

/**
 * Skip a test that cannot say anything about a file's permissions.
 *
 * Running as root, every file is readable however its mode is set and every
 * directory is writable, so a test about an unreadable file or an uncreatable one
 * has nothing left to check. It is reported as skipped rather than passed: in a
 * root container a green tick is a check that never ran, and a build full of them
 * says nothing.
 */
function skipWithoutPermissions(string $what): void
{
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        PHPUnit\Framework\Assert::markTestSkipped($what . ' — running as root, which can do it anyway');
    }
}

/**
 * Write a `.docx` file. The wrapper is only there for the upstream deprecation
 * described in tests/Support/Upstream.
 */
function saveDocument(string $markdown, string $path, ?Configuration $config = null): void
{
    withoutUpstreamDeprecations(static function () use ($markdown, $path, $config): void {
        (new MarkdownToWord($markdown, $config ?? new Configuration()))->save($path);
    });
}

/**
 * Write a `PhpWord` document out with PHPWord's own writer.
 */
function writePhpWordDocument(PhpWord $phpWord, string $path): void
{
    withoutUpstreamDeprecations(static function () use ($phpWord, $path): void {
        PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save($path);
    });
}

/**
 * Write a filled-in template out.
 */
function saveTemplateDocument(MarkdownTemplate $template, string $path): void
{
    withoutUpstreamDeprecations(static function () use ($template, $path): void {
        $template->save($path);
    });
}

/**
 * Run the command line in process, and hand back what it did.
 *
 * @param list<string> $argv
 * @return array{code: int, out: string, err: string}
 */
function runCli(array $argv, string $stdin = ''): array
{
    $out = fopen('php://memory', 'r+b');
    $err = fopen('php://memory', 'r+b');
    $in = fopen('php://memory', 'r+b');

    fwrite($in, $stdin);
    rewind($in);

    $code = (new Application($out, $err, $in))->run($argv);

    rewind($out);
    rewind($err);

    $result = [
        'code' => $code,
        'out' => (string) stream_get_contents($out),
        'err' => (string) stream_get_contents($err),
    ];

    fclose($out);
    fclose($err);
    fclose($in);

    return $result;
}

/**
 * Every part of a `.docx`, keyed by name, with what a clock changes taken out.
 *
 * Two things vary between two correct conversions of the same input, and neither
 * is a difference in the document: the zip's per-entry timestamps — two seconds of
 * resolution, no sub-second part, no time zone — which live in the container and
 * not in the parts, and `docProps/core.xml`, which records when the document was
 * created and last modified. So a check that compares two documents compares these,
 * and a check that compares the archive's bytes fails about three times in four on
 * any machine slow enough to cross a two-second boundary between the two writes.
 *
 * @return array<string, string>
 */
function documentParts(string $docx): array
{
    $path = Scratch::path('parts', '.docx');
    file_put_contents($path, $docx);

    $zip = new ZipArchive();

    if ($zip->open($path) !== true) {
        throw new RuntimeException('The document is not a readable archive.');
    }

    try {
        $parts = [];

        // Every entry is wanted, the empty directories and all, so that a
        // difference in what is present is a difference here too.
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false || !is_string($stat['name'] ?? null)) {
                continue;
            }

            $content = (string) $zip->getFromIndex($index);

            $parts[$stat['name']] = $stat['name'] === 'docProps/core.xml'
                ? blankedCreationDates($content)
                : $content;
        }
    } finally {
        $zip->close();
    }

    ksort($parts);

    return $parts;
}

/**
 * A document's core properties with the two dates blanked: `dcterms:created` and
 * `dcterms:modified` are the only parts of a `.docx` that say when it was made,
 * and leaving them in makes the comparison a test of the clock.
 */
function blankedCreationDates(string $coreProperties): string
{
    return (string) preg_replace(
        ['#<dcterms:(created|modified)[^>]*>.*?</dcterms:\1>#s', '#<dcterms:(created|modified)[^>]*/>#'],
        '<dcterms:$1>whenever</dcterms:$1>',
        $coreProperties,
    );
}


