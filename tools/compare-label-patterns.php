<?php

declare(strict_types=1);

/**
 * Compares the label patterns of this half with the slides half's.
 *
 * A label is plain text, so a label holding a complete Markdown construct cannot have
 * it honoured, and each half draws such a label as its own source text. What counts as
 * a construct is CommonMark's own question: a delimiter run that opens an emphasis may
 * not be followed by whitespace, and one that closes it may not be preceded by
 * whitespace.
 *
 * The rules live in a list of patterns in each half, and two lists are two answers to
 * one question. So this reads both of them out of their own sources and runs the same
 * corpus of labels through them, and the corpus says what CommonMark makes of each
 * label.
 *
 *     php tools/compare-label-patterns.php [path/to/markdown-presentation]
 *
 * The argument is the slides half's checkout; it defaults to the one beside this
 * repository.
 */

$root = dirname(__DIR__);

$corpus = $root . '/tests/fixtures/labels.txt';

if (!is_file($corpus)) {
    fwrite(STDERR, sprintf("No corpus at \"%s\".\n", $corpus));
    exit(2);
}

$deck = $argv[1] ?? dirname($root) . '/markdown-presentation';

$halves = [
    'word' => $root . '/src/Render/Directive/TextStyle.php',
    'slides' => $deck . '/src/Render/CommonMark/StyleReader.php',
];

$patterns = [];

foreach ($halves as $half => $file) {
    if (!is_file($file)) {
        fwrite(STDERR, sprintf("No source at \"%s\".\n", $file));
        exit(2);
    }

    $patterns[$half] = patternsFrom($file);
}

/**
 * The patterns a half keeps its labels away from, read out of its own source.
 *
 * @return list<string>
 */
function patternsFrom(string $file): array
{
    $source = (string) file_get_contents($file);

    if (preg_match('/const\s+MARKDOWN\s*=\s*\[(.*?)\]\s*;/s', $source, $match) !== 1) {
        fwrite(STDERR, sprintf("No MARKDOWN list in \"%s\".\n", $file));
        exit(2);
    }

    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $match[1], $found);

    return array_map(
        static fn (string $raw): string => str_replace(["\\\\", "\\'"], ['\\', "'"], $raw),
        $found[1],
    );
}

/**
 * Whether a label holds a construct the patterns name.
 *
 * @param list<string> $patterns
 */
function holds(array $patterns, string $label): bool
{
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $label) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * The corpus, as the marker and the label.
 *
 * @return list<array{0: string, 1: string}>
 */
function corpusFrom(string $file): array
{
    $labels = [];

    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$marker, $label] = array_pad(explode(' ', $line, 2), 2, '');

        $labels[] = [$label, $marker];
    }

    return $labels;
}

/**
 * What a marker says the halves must make of a label.
 *
 * @return list<string>|null The two verdicts, or null where the marker asks for the
 *                           halves to agree with each other rather than with CommonMark.
 */
function expected(string $marker): ?array
{
    return match ($marker) {
        'markdown' => ['markdown', 'markdown'],
        'text' => ['text', 'text'],
        'markdown-only' => ['text', 'text'],
        'text-only' => ['markdown', 'markdown'],
        default => null,
    };
}

$labels = corpusFrom($corpus);
$disagreements = [];
$againstTheCorpus = [];
$gaps = [];
$count = 0;

foreach ($labels as [$label, $marker]) {
    $count++;

    $word = holds($patterns['word'], $label) ? 'markdown' : 'text';
    $slides = holds($patterns['slides'], $label) ? 'markdown' : 'text';

    if ($word !== $slides) {
        $disagreements[] = sprintf('%s: word says %s, slides says %s', $label, $word, $slides);
    }

    $want = expected($marker);

    if ($want === null) {
        $gaps[] = sprintf('%s: %s', $label, $marker);

        continue;
    }

    if ($word !== $want[0]) {
        $againstTheCorpus[] = sprintf(
            '%s: the corpus says %s, the word half says %s',
            $label,
            $marker,
            $word,
        );
    }
}

printf("Corpus: %d labels.\n", $count);
printf("Patterns: word %d, slides %d.\n", count($patterns['word']), count($patterns['slides']));

if ($gaps !== []) {
    printf("\n%d labels are where the halves differ from CommonMark:\n", count($gaps));

    foreach ($gaps as $gap) {
        printf("  %s\n", $gap);
    }
}

if ($disagreements !== []) {
    printf("\nThe halves disagree on %d labels:\n", count($disagreements));

    foreach ($disagreements as $disagreement) {
        printf("  %s\n", $disagreement);
    }
}

if ($againstTheCorpus !== []) {
    printf("\nThe halves disagree with the corpus on %d labels:\n", count($againstTheCorpus));

    foreach ($againstTheCorpus as $disagreement) {
        printf("  %s\n", $disagreement);
    }
}

if ($disagreements === [] && $againstTheCorpus === []) {
    printf("\nThe halves agree with each other and with the corpus on every label.\n");
}

exit($disagreements === [] && $againstTheCorpus === [] ? 0 : 1);
