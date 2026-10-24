<?php

declare(strict_types=1);

/*
 * The command line moved to the documentation site, and this is what keeps it there.
 *
 * The reference — the commands, the options, their defaults — is a chapter of the
 * site now, and the page that says how to run the tool is
 * https://markdown-office.github.io/command-line/basic/. A README that keeps a copy
 * of it keeps a copy that drifts: the two are edited apart, and the one nobody
 * remembers is the one a reader follows.
 *
 * So the rule is about content and not about a sentence. The README names no
 * command of the tool, no option of it and no part of it, runs none of them in
 * an example, and the section that used to hold the reference points at the page
 * that owns it. What the library documents about itself — its classes, its
 * options, its behaviour — is none of that, and the guard does not touch it.
 */

/**
 * The page that owns the command line.
 */
const COMMAND_LINE_PAGE = 'https://markdown-office.github.io/command-line/basic/';

/**
 * The options of the tool, which the documentation site owns.
 *
 * `--strict` is not among them, and the reason is this repository's own: the
 * README's line about `composer validate --strict` is Composer's option and not
 * the tool's, and a guard that forbade the name would forbid that line. The
 * slides half's `--strict` is guarded in the presentation repository, where no
 * such collision exists.
 *
 * The short forms are not among them either. They are single letters, and a
 * letter is a word in the prose a README is made of: `-o` would forbid a
 * sentence with a dash in it, which is a sentence about anything at all. The
 * long form of each short one is here, so a copy of the reference that gives
 * the long form is still caught.
 *
 * @var list<string>
 */
const COMMAND_LINE_OPTIONS = [
    '--config',
    '--help',
    '--image-base',
    '--images',
    '--line-ending',
    '--media',
    '--no-fence',
    '--no-header',
    '--no-images',
    '--output',
    '--plain',
    '--require-control',
    '--setext',
    '--svg-fallback',
    '--table-width',
    '--template',
    '--theme',
    '--to',
    '--version',
];

/**
 * The commands of the tool.
 *
 * @var list<string>
 */
const COMMAND_LINE_COMMANDS = ['to-docx', 'to-markdown', 'to-odt', 'to-pptx', 'to-rtf'];

/**
 * The parts of the tool, which name what a run is for.
 *
 * @var list<string>
 */
const COMMAND_LINE_PARTS = ['slides', 'word'];

/**
 * The examples page, and what it is allowed to hold.
 *
 * The rule above is about the README: the top-level page is where a reader looks
 * for this library, and a copy of the command line there is a copy that drifts.
 *
 * The examples page is a different thing. It names the tool twice, once in a
 * sentence that says where the command line lives and once in a block of two
 * invocations, and both are there to say how two of the example documents are
 * made — the two `build.php` cannot make, because it exercises the library and
 * not the command line.
 *
 * That is a use of the tool and not a copy of its reference, and a rule that
 * forbade it would forbid the page from saying what it is for. What it must not
 * become is a reference of its own, so the check below is the same one the
 * README gets, with the two usages taken out one by one rather than the whole
 * page excused.
 */
const COMMAND_LINE_EXAMPLES = [
    // The sentence that says where the command line lives, and the option it
    // names there.
    'mdoffice word',
    '--image-base',
    // The two invocations, in the block that shows how the two documents are made,
    // and the option one of them names.
    'mdoffice word to-odt',
    'mdoffice word to-docx',
    '--template',
];

/**
 * The README, as it is on disk.
 */
function commandLineReadme(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
}

/**
 * The examples page, as it is on disk.
 */
function commandLineExamples(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/examples/README.md');
}

/**
 * The link definitions of a Markdown document: the `[name]: url` lines at the
 * bottom of the page.
 *
 * @return array<string, string>
 */
function commandLineLinkDefinitions(string $markdown): array
{
    $definitions = [];

    foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
        if (preg_match('/^\[([^\]]+)\]:\s*(\S+)\s*$/', trim($line), $match) === 1) {
            $definitions[strtolower($match[1])] = $match[2];
        }
    }

    return $definitions;
}

/**
 * The link targets a text points at, with the reference links resolved.
 *
 * A reference link names its target at the bottom of the page, so a reader who
 * follows one never sees the address. The guard has to resolve them, or it would
 * only ever see the inline links and would pass on a page that points nowhere.
 *
 * @param array<string, string> $definitions
 *
 * @return list<string>
 */
function commandLineLinkTargets(string $text, array $definitions): array
{
    $targets = [];

    preg_match_all('/\]\(\s*<?([^\s)>]+)>?[^)]*\)/', $text, $inline);

    foreach ($inline[1] as $target) {
        $targets[] = $target;
    }

    preg_match_all('/\[([^\]]*)\]\[([^\]]*)\]/', $text, $references, PREG_SET_ORDER);

    foreach ($references as [, $text, $label]) {
        $name = strtolower($label !== '' ? $label : $text);

        if (isset($definitions[$name])) {
            $targets[] = $definitions[$name];
        }
    }

    return array_values(array_unique($targets));
}

/**
 * The section of a Markdown document that talks about the command line.
 *
 * It is where the reference used to be, so it is where the pointer to the page
 * that owns it has to be.
 */
function commandLineSection(string $markdown): ?string
{
    $lines = preg_split('/\R/', $markdown) ?: [];
    $start = null;

    foreach ($lines as $number => $line) {
        if ($start === null) {
            if (preg_match('/^##\s+.*command line/i', $line) === 1) {
                $start = $number;
            }

            continue;
        }

        if (preg_match('/^##\s/', $line) === 1) {
            return implode("\n", array_slice($lines, $start, $number - $start));
        }
    }

    return $start === null ? null : implode("\n", array_slice($lines, $start));
}

/**
 * The code blocks of a Markdown document, as the lines they hold.
 *
 * The closing fence has to be at least as long as the one that opened the block,
 * or a block that holds a shorter fence ends at the wrong place.
 *
 * @return list<list<string>>
 */
function commandLineCodeBlocks(string $markdown): array
{
    $lines = preg_split('/\R/', $markdown) ?: [];
    $blocks = [];
    $block = null;
    $fence = '';

    foreach ($lines as $line) {
        if ($block === null) {
            if (preg_match('/^\s*(`{3,})/', $line, $match) === 1) {
                $block = [];
                $fence = $match[1];
            }

            continue;
        }

        if (preg_match('/^\s*`{' . strlen($fence) . ',}\s*$/', $line) === 1) {
            $blocks[] = $block;
            $block = null;

            continue;
        }

        $block[] = $line;
    }

    return $blocks;
}

/**
 * What a document still holds of the command line reference.
 *
 * Each finding says what it found and where, because a guard that says only
 * "something moved" is one nobody can act on.
 *
 * The allowance is a list of the usages a document is allowed to have. A line
 * that holds one of them is not a finding, and a line that holds any other use
 * of the tool still is — so a document that grows a reference is caught even
 * where a few usages are allowed.
 *
 * @param list<string> $allowed The usages this document may have.
 *
 * @return list<string>
 */
function commandLineHeldBy(string $markdown, array $allowed = []): array
{
    $held = [];

    $permitted = function (string $line) use ($allowed): bool {
        foreach ($allowed as $usage) {
            if (stripos($line, $usage) !== false) {
                return true;
            }
        }

        return false;
    };

    foreach (preg_split('/\R/', $markdown) ?: [] as $number => $line) {
        if ($permitted($line)) {
            continue;
        }

        foreach (COMMAND_LINE_COMMANDS as $command) {
            if (preg_match('/(?<![\w-])' . preg_quote($command, '/') . '(?![\w-])/i', $line) === 1) {
                $held[] = sprintf('line %d names the command %s', $number + 1, $command);
            }
        }

        foreach (COMMAND_LINE_OPTIONS as $option) {
            if (preg_match('/(?<![\w-])' . preg_quote($option, '/') . '(?![\w-])/i', $line) === 1) {
                $held[] = sprintf('line %d names the option %s', $number + 1, $option);
            }
        }

        foreach (COMMAND_LINE_PARTS as $part) {
            if (preg_match('/\bmdoffice\s+' . preg_quote($part, '/') . '\b/i', $line) === 1) {
                $held[] = sprintf('line %d names the part %s', $number + 1, $part);
            }
        }
    }

    foreach (commandLineCodeBlocks($markdown) as $block) {
        foreach ($block as $line) {
            if ($permitted($line)) {
                continue;
            }

            if (preg_match('/\bmdoffice\b/', $line) === 1) {
                $held[] = 'an example runs the command line: ' . trim($line);
            }
        }
    }

    return $held;
}

it('links to the page that owns the command line', function () {
    $readme = commandLineReadme();

    expect(commandLineLinkTargets($readme, commandLineLinkDefinitions($readme)))
        ->toContain(COMMAND_LINE_PAGE);
});

it('keeps the command line reference on the page that owns it', function () {
    $readme = commandLineReadme();
    $section = commandLineSection($readme);

    // No copy of the reference is left here. A command, an option, a part, or an
    // example that runs the tool is the reference, in pieces: a half of it is a
    // half that is wrong the moment the page it came from changes.
    expect(commandLineHeldBy($readme))->toBe([]);

    // And the reference is on the documentation site, with the section that used
    // to hold it saying so rather than leaving a reader to look for what moved.
    expect($section)->not->toBeNull();
    expect(commandLineLinkTargets($section ?? '', commandLineLinkDefinitions($readme)))
        ->toContain(COMMAND_LINE_PAGE);
});

it('keeps the command line reference off the examples page too', function () {
    $examples = commandLineExamples();

    // The same rule as the README gets, with the usages the page is allowed to
    // have taken out one by one. A page that grew a reference of its own is
    // caught here even though two usages are permitted, because what is permitted
    // is what is named in the allowance and nothing else.
    expect(commandLineHeldBy($examples, COMMAND_LINE_EXAMPLES))->toBe([]);

    // The two usages the page has are the ones the allowance names, and each is
    // there to say how one of the two documents `build.php` cannot make is made.
    // An allowance that names a usage the page no longer has is an allowance
    // that has stopped holding anything down.
    foreach (COMMAND_LINE_EXAMPLES as $usage) {
        expect($examples)->toContain($usage);
    }
});
