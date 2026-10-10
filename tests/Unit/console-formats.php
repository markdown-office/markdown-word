<?php

declare(strict_types=1);

use MarkdownWord\Console\Application;
use MarkdownWord\Format;
use MarkdownWord\Tests\Support\Scratch;

/*
 * `mdword to-odt` and `mdword to-rtf`: the same conversion in two more formats.
 *
 * The default has to stay `.docx`, and it is checked as a default rather than as a
 * value: a run with no `--to` writes a `.docx` whatever else the code can do. The
 * loss report is checked here as well as in the library, because on the command
 * line it is a line on standard error and a caller piping the result out never
 * sees the document to notice it in.
 */

/**
 * A Markdown file in the scratch space, and the path the command line will derive
 * an output name from if it is not told one.
 *
 * `inputFile()` gives every file a random suffix so two tests cannot collide, so
 * the derived name is worked out here rather than written down.
 */
function formatInput(string $name, string $markdown): array
{
    $input = inputFile($name, $markdown);

    return [$input, pathinfo($input, PATHINFO_DIRNAME) . '/' . pathinfo($input, PATHINFO_FILENAME)];
}

/**
 * The command word for a format.
 *
 * Named rather than derived from the value, because `--to docx` on `to-odt` is a
 * contradiction and refuses — which is the other half of what these tests check.
 */
function formatCommand(Format $format): string
{
    return 'to-' . $format->value;
}

it('writes a .docx when nothing says otherwise', function () {
    [$input, $derived] = formatInput('default.md', "# Notes\n");

    $run = runCli(['to-docx', $input]);

    expect($run['code'])->toBe(Application::SUCCESS)
        ->and($run['err'])->toContain($derived . '.docx')
        ->and(is_file($derived . '.docx'))->toBeTrue();
});

it('writes the format the command names, and nothing else', function (Format $format, string $command) {
    [$input, $derived] = formatInput('named.md', "# Notes\n");

    $run = runCli([$command, $input]);

    expect($run['code'])->toBe(Application::SUCCESS)
        ->and($run['err'])->toContain($derived . $format->extension())
        ->and(is_file($derived . $format->extension()))->toBeTrue()
        // The other two are absent, which is what "and nothing else" means: a run
        // that wrote all three would be right about the one asked for.
        ->and(is_file($derived . '.docx'))->toBe($format === Format::Docx)
        ->and(is_file($derived . '.odt'))->toBe($format === Format::Odt)
        ->and(is_file($derived . '.rtf'))->toBe($format === Format::Rtf);
})->with([
    'docx' => [Format::Docx, 'to-docx'],
    'odt' => [Format::Odt, 'to-odt'],
    'rtf' => [Format::Rtf, 'to-rtf'],
]);

it('refuses a --to naming a format the command does not write', function () {
    [$input] = formatInput('mismatch.md', "# Notes\n");

    $run = runCli(['to-docx', $input, '--to', 'odt']);

    expect($run['code'])->toBe(Application::FAILURE)
        ->and($run['err'])->toContain('--to odt does not match what this reads');
});

it('reaches the same three formats through --to', function (Format $format) {
    [$input, $derived] = formatInput('todo.md', "# Notes\n");

    $run = runCli([$input, '--to', $format->value]);

    expect($run['code'])->toBe(Application::SUCCESS)
        ->and(is_file($derived . $format->extension()))->toBeTrue();
})->with(Format::cases());

it('refuses a --to that contradicts the command it was given to', function () {
    [$input] = formatInput('contradiction.md', "# Notes\n");

    $run = runCli(['to-docx', $input, '--to', 'rtf']);

    expect($run['code'])->toBe(Application::FAILURE)
        ->and($run['err'])->toContain('--to rtf does not match what this reads');
});

it('refuses a template for a format that has none', function () {
    $template = Scratch::path('template', '.docx');
    saveDocument("# A document\n", $template);

    [$input] = formatInput('templated.md', "# Notes\n");
    $run = runCli(['to-odt', $input, '-t', $template]);

    // PHPWord's template processor reads a `.docx` package and nothing else, so
    // this is refused rather than half attempted.
    expect($run['code'])->toBe(Application::FAILURE)
        ->and($run['err'])->toContain('A template cannot be filled in for odt');
});

it('reports what the format dropped, on standard error', function () {
    // A heading named after a style rather than styled directly: the default slots
    // are direct formatting, which every writer carries, and `named-styles` is
    // the loss a template author is told about rather than one everybody is.
    [$input] = formatInput('lossy.md', <<<'MD'
        ---
        styles:
          heading.1: Title
        ---

        # Notes

        - one
        - two
        MD);

    $run = runCli(['to-rtf', $input, '-o', Scratch::path('lossy.rtf')]);

    expect($run['code'])->toBe(Application::SUCCESS)
        ->and($run['err'])->toContain('rtf cannot carry lists')
        ->and($run['err'])->toContain('named-styles');
});

it('says nothing about named styles for a document written with the built-in look', function () {
    [$input] = formatInput('styled.md', "# Notes\n\n> quoted\n");

    $run = runCli(['to-rtf', $input, '-o', Scratch::path('styled.rtf')]);

    // Every slot is direct formatting, so there is no style name for either writer
    // to fail to resolve and nothing for the reader to be warned about.
    expect($run['code'])->toBe(Application::SUCCESS)
        ->and($run['err'])->not->toContain('named-styles');
});

it('reports nothing for a .docx, which drops nothing', function () {
    [$input] = formatInput('complete.md', "# Notes\n\n- one\n- two\n\n![alt](none.png)\n");

    $run = runCli(['to-docx', $input, '-o', Scratch::path('complete.docx')]);

    expect($run['code'])->toBe(Application::SUCCESS)
        ->and($run['err'])->not->toContain('cannot carry');
});

it('still reports a conversion on standard error whatever the format', function () {
    [$input] = formatInput('reported.md', "# Notes\n");

    foreach (Format::cases() as $format) {
        $run = runCli([
            formatCommand($format),
            $input,
            '-o',
            Scratch::path('reported', $format->extension()),
        ]);

        expect($run['code'])->toBe(Application::SUCCESS)
            ->and($run['err'])->toContain($input . ' →');
    }
});

it('keeps the result off standard output when it is a file', function () {
    [$input] = formatInput('quiet.md', "# Notes\n");

    foreach (Format::cases() as $format) {
        $run = runCli([
            formatCommand($format),
            $input,
            '-o',
            Scratch::path('quiet', $format->extension()),
        ]);

        expect($run['out'])->toBe('');
    }
});

it('still writes to standard output when the output is "-"', function (Format $format) {
    [$input] = formatInput('piped.md', "# Notes\n");

    $run = runCli([formatCommand($format), $input, '-o', '-']);

    expect($run['code'])->toBe(Application::SUCCESS)
        ->and(strlen($run['out']))->toBeGreaterThan(0)
        ->and(str_starts_with($run['out'], $format === Format::Rtf ? '{\rtf1' : 'PK'))->toBeTrue();
})->with(Format::cases());

it('refuses a format it does not know', function () {
    $run = runCli(['--to', 'pdf', '-']);

    expect($run['code'])->toBe(Application::FAILURE)
        ->and($run['err'])->toContain('Unknown format "pdf"')
        ->and($run['err'])->toContain('Use docx, odt, rtf or markdown.');
});

it('lists the three output commands in the help', function () {
    $run = runCli(['help']);

    expect($run['code'])->toBe(Application::SUCCESS)
        ->and($run['out'])->toContain('to-docx')
        ->and($run['out'])->toContain('to-odt')
        ->and($run['out'])->toContain('to-rtf')
        // The sentence that tells a reader which of them is the default.
        ->and($run['out'])->toContain('is the default of all three');
});

it('answers --help for each of the three, naming the format it writes', function () {
    foreach (['to-docx', 'to-odt', 'to-rtf'] as $command) {
        $run = runCli([$command, '--help']);

        expect($run['code'])->toBe(Application::SUCCESS)
            ->and($run['out'])->toContain('mdword ' . $command . ' —');
    }
});