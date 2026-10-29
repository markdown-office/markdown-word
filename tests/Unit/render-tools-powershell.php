<?php

declare(strict_types=1);

/*
 * The Windows half of the render tool.
 *
 * This is the script that drives Microsoft Word on a machine that has it, and it is the
 * half of the tool that nothing had ever run: the build has no Windows machine, the
 * suite had no test for it, and the two ways it could be broken are both ways a script
 * that drives an application can be broken without anybody noticing — a flag the
 * program's own argument binder eats, and a function that is called before the line
 * that defines it has been reached.
 *
 * So the tests here do not need Word. They need PowerShell, and they need the two
 * things a script can be wrong about before it ever asks an application for anything:
 *
 *   * **The flag.** `--out` is the parameter `out` to PowerShell, and `out` is
 *     ambiguous between `-OutBuffer` and `-OutVariable`, so a script asked for `-out`
 *     refuses to start before it has read an argument. The script is run the way the
 *     dispatcher runs it — `powershell -File` — because a script run from inside
 *     PowerShell reads its arguments differently, and a test that changes the way the
 *     arguments arrive is not a test of the thing that is wrong.
 *
 *   * **The order.** PowerShell runs a script from the top down, and a function is not
 *     defined until the line that defines it has been reached. A function used above
 *     its own definition is a name the script has not met yet.
 *
 * And where the script has to reach Word to be tested at all, Word is stood in for:
 * `tests/Support/powershell/word-mock.ps1` is loaded into a copy of the script, and the
 * copy is the script with one line added. The copy is made from the script's own syntax
 * tree rather than by finding a line that looks like the end of a `param` block, so
 * what is tested is the script and not a guess at where the script begins.
 *
 * The tests are skipped where PowerShell is not, because a machine without PowerShell
 * cannot answer the question and a test that guesses is worse than no test.
 */

use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\Script;

/**
 * The PowerShells on this machine.
 *
 * Both are listed where both are here, because they are not the same program: Windows
 * PowerShell is what `powershell` means on Windows, and `pwsh` is what it means
 * everywhere else.
 *
 * @return list<string>
 */
function powerShells(): array
{
    $found = [];

    foreach (['powershell', 'pwsh'] as $candidate) {
        if (powerShellOnPath($candidate)) {
            $found[] = $candidate;
        }
    }

    return $found;
}

/**
 * The PowerShell the tool is run with.
 *
 * The one the dispatcher runs: `powershell` on Windows, because that is what the word
 * means there, and `pwsh` everywhere else.
 */
function defaultPowerShell(): ?string
{
    $found = powerShells();

    if ($found === []) {
        return null;
    }

    $preferred = PHP_OS_FAMILY === 'Windows' ? 'powershell' : 'pwsh';

    return in_array($preferred, $found, true) ? $preferred : $found[0];
}

/**
 * Whether a command is on the path.
 *
 * The lookup is the running platform's, because the question is about this machine: a
 * Windows command prompt has no `command -v`, and a Unix shell has no `where`.
 */
function powerShellOnPath(string $command): bool
{
    $status = 0;

    $lookup = PHP_OS_FAMILY === 'Windows'
        ? 'where ' . escapeshellarg($command) . ' 2>NUL'
        : 'command -v ' . escapeshellarg($command) . ' 2>/dev/null';

    exec($lookup, $ignored, $status);

    return $status === 0;
}

/**
 * Run a PowerShell script.
 *
 * @param list<string> $arguments
 * @param array<string, string> $environment
 *
 * @return array{out: string, err: string, status: int}
 */
function runPowerShell(string $interpreter, array $arguments, array $environment = []): array
{
    return Script::run([$interpreter, '-NoProfile', '-File'], $arguments, $environment);
}

/**
 * The tool that drives Word on Windows.
 */
function windowsTool(): string
{
    return dirname(__DIR__, 2) . '/tools/render-with-word.ps1';
}

/**
 * The helpers the tests use.
 */
function powerShellSupport(): string
{
    return dirname(__DIR__) . '/Support/powershell';
}

/**
 * A directory of this test's own, with a document and a place for the PDFs.
 *
 * The names inside it are fixed, because the name of the PDF is the name of the
 * document it came from, and a name with a random suffix in it would say nothing about
 * that.
 *
 * @return array{work: string, document: string, out: string}
 */
function windowsToolWorkspace(): array
{
    $work = Scratch::directory() . '/windows-' . bin2hex(random_bytes(6));

    if (! mkdir($work, 0o777, true) && ! is_dir($work)) {
        throw new RuntimeException(sprintf('Unable to create the directory "%s".', $work));
    }

    $document = $work . '/report.docx';

    file_put_contents($document, 'not a document');

    return ['work' => $work, 'document' => $document, 'out' => $work . '/rendered'];
}

/**
 * A copy of the tool with the stand-in for Word loaded into it.
 */
function toolWithMock(): string
{
    $interpreter = defaultPowerShell();

    if ($interpreter === null) {
        test()->markTestSkipped('PowerShell is not here, so the tool cannot be run.');
    }

    $copy = Scratch::path('render-with-word', '.ps1');

    $built = runPowerShell($interpreter, [
        powerShellSupport() . '/copy-with-mock.ps1',
        '-Tool',
        windowsTool(),
        '-Mock',
        powerShellSupport() . '/word-mock.ps1',
        '-Target',
        $copy,
    ]);

    if ($built['status'] !== 0) {
        throw new RuntimeException('The copy could not be made: ' . $built['err']);
    }

    return $copy;
}

/**
 * Run the tool with the stand-in for Word, and say what happened.
 *
 * @param list<string> $arguments
 * @param array<string, string> $environment
 *
 * @return array{out: string, err: string, status: int, log: list<string>}
 */
function runToolWithMock(array $arguments, array $environment = [], string $behaviour = 'writes'): array
{
    $copy = toolWithMock();
    $log = Scratch::path('word-mock', '.log');

    $answer = runPowerShell(
        defaultPowerShell(),
        [$copy, ...$arguments],
        array_merge($environment, [
            'MARKDOWN_OFFICE_WORD_MOCK' => $behaviour,
            'MARKDOWN_OFFICE_WORD_MOCK_LOG' => $log,
        ]),
    );

    $recorded = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];

    return $answer + ['log' => $recorded];
}

it('is a script PowerShell can read', function (string $interpreter) {
    if ($interpreter === '') {
        test()->markTestSkipped('PowerShell is not here, so the script cannot be read.');
    }

    $answer = runPowerShell($interpreter, [
        powerShellSupport() . '/check.ps1',
        '-Script',
        windowsTool(),
        '-Check',
        'syntax',
    ]);

    expect($answer['status'])->toBe(0, $answer['err']);
})->with(fn (): array => array_map(
    fn (string $interpreter): array => [$interpreter],
    powerShells() === [] ? [''] : powerShells(),
));

it('calls a function only after it has defined it', function (string $interpreter) {
    // PowerShell runs a script from the top down, and a function is not defined until
    // the line that defines it has been reached. A function used above its own
    // definition is a name the script has not met yet, and the run fails with a message
    // about the name rather than about the work the function was for.
    if ($interpreter === '') {
        test()->markTestSkipped('PowerShell is not here, so the script cannot be read.');
    }

    $answer = runPowerShell($interpreter, [
        powerShellSupport() . '/check.ps1',
        '-Script',
        windowsTool(),
        '-Check',
        'order',
    ]);

    expect($answer['status'])->toBe(0, $answer['err']);
})->with(fn (): array => array_map(
    fn (string $interpreter): array => [$interpreter],
    powerShells() === [] ? [''] : powerShells(),
));

it('takes the output directory as a flag, and the documents beside it', function () {
    // The flag is read by PowerShell's own binder before the script sees a single
    // argument: `--out` is the parameter `out`, and `out` is ambiguous between
    // `-OutBuffer` and `-OutVariable`. A script asked for `-out` refuses to start, and
    // the run says so in a message about the parameter rather than about the document.
    //
    // So the directory the tool is given has to be the one it writes into, and the run
    // has to end with the file in it.
    $workspace = windowsToolWorkspace();

    $answer = runToolWithMock([$workspace['document'], '--out', $workspace['out']]);

    expect($answer['status'])->toBe(0, $answer['err'])
        ->and(is_file($workspace['out'] . '/report.pdf'))->toBeTrue();
});

it('writes a PDF named after the document it came from', function () {
    $workspace = windowsToolWorkspace();

    $answer = runToolWithMock([$workspace['document'], '--out', $workspace['out']]);

    expect($answer['status'])->toBe(0, $answer['err'])
        ->and($answer['out'])->toContain('report.pdf');

    $pdf = (string) file_get_contents($workspace['out'] . '/report.pdf');

    expect($pdf)->toStartWith('%PDF')->toContain('%%EOF');
});

it('fails rather than reporting a render that produced no file', function () {
    // A backend that says it worked and writes nothing is the failure a caller cannot
    // see from the output directory alone.
    $workspace = windowsToolWorkspace();

    $answer = runToolWithMock(
        [$workspace['document'], '--out', $workspace['out']],
        [],
        'writes-nothing',
    );

    expect($answer['status'])->not->toBe(0)
        ->and($answer['err'])->toContain('no PDF')
        ->and(is_file($workspace['out'] . '/report.pdf'))->toBeFalse();
});

it('fails rather than reporting a PDF that was not finished', function () {
    // A PDF is written lazily, so a file that exists is a file that may still be being
    // written. A copy of half a PDF is not a PDF.
    $workspace = windowsToolWorkspace();

    $answer = runToolWithMock(
        [$workspace['document'], '--out', $workspace['out']],
        [],
        'writes-half-a-pdf',
    );

    expect($answer['status'])->not->toBe(0)
        ->and($answer['err'])->toContain('no finished PDF');
});

it('closes what it opened, and lets Word go', function () {
    // A document window left behind is a window the person using the machine finds open
    // on a document they did not ask for.
    $workspace = windowsToolWorkspace();

    $answer = runToolWithMock([$workspace['document'], '--out', $workspace['out']]);

    expect($answer['status'])->toBe(0, $answer['err'])
        ->and($answer['log'])->toContain('closed ' . $workspace['document'])
        ->and($answer['log'])->toContain('quit');
});

it('closes what it opened when the export failed', function () {
    $workspace = windowsToolWorkspace();

    $answer = runToolWithMock(
        [$workspace['document'], '--out', $workspace['out']],
        [],
        'refuses',
    );

    expect($answer['status'])->not->toBe(0)
        ->and($answer['log'])->toContain('closed ' . $workspace['document'])
        ->and($answer['log'])->toContain('quit');
});

it('says so when there is nothing to render', function () {
    $interpreter = defaultPowerShell();

    if ($interpreter === null) {
        test()->markTestSkipped('PowerShell is not here, so the tool cannot be run.');
    }

    $answer = runPowerShell($interpreter, [windowsTool(), '--out', Scratch::path('rendered', '')]);

    expect($answer['status'])->not->toBe(0)
        ->and($answer['err'])->toContain('Nothing to render');
});

it('says so when there is no output directory', function () {
    $interpreter = defaultPowerShell();

    if ($interpreter === null) {
        test()->markTestSkipped('PowerShell is not here, so the tool cannot be run.');
    }

    $workspace = windowsToolWorkspace();

    $answer = runPowerShell($interpreter, [windowsTool(), $workspace['document']]);

    expect($answer['status'])->not->toBe(0)
        ->and($answer['err'])->toContain('No output directory');
});

it('changes nothing in the tool but the line that loads the stand-in', function () {
    // The copy is what the tests above run, so it has to be the tool and not a
    // paraphrase of it.
    $copy = (string) file_get_contents(toolWithMock());
    $original = (string) file_get_contents(windowsTool());

    $withoutTheLine = (string) preg_replace('/\n\. \'[^\']*word-mock\.ps1\'/', '', $copy);

    expect($withoutTheLine)->toBe($original);
});

it('gives the Windows tool the document and the directory to write into', function () {
    // The dispatcher builds the command line, and the command line is the part nobody
    // reads: a flag the tool's own binder eats, a path with a space in it, and a
    // directory that is not the one the caller asked for are all invisible until the
    // tool is run with the exact arguments the dispatcher gives it.
    //
    // So the dispatcher is run against a stand-in for the tool, which writes down what
    // it was given. The dispatcher asks for the tool beside itself, so the stand-in
    // stands in beside a copy of the dispatcher.
    if (! powerShellOnPath('powershell')) {
        test()->markTestSkipped('Windows PowerShell is not here, so the dispatcher cannot be run the way it runs there.');
    }

    $workspace = windowsToolWorkspace();
    $tools = $workspace['work'] . '/tools';

    if (! mkdir($tools, 0o777, true) && ! is_dir($tools)) {
        throw new RuntimeException(sprintf('Unable to create the directory "%s".', $tools));
    }

    copy(dirname(__DIR__, 2) . '/tools/render-document.php', $tools . '/render-document.php');
    copy(powerShellSupport() . '/word-stub.ps1', $tools . '/render-with-word.ps1');

    $log = $workspace['work'] . '/arguments.txt';
    $target = $workspace['work'] . '/rendered.pdf';

    $answer = Script::run(
        [PHP_BINARY, $tools . '/render-document.php'],
        [$workspace['document'], '--out', $target],
        [
            'MARKDOWN_OFFICE_PLATFORM' => 'windows',
            'MARKDOWN_OFFICE_RENDERER' => 'word',
            'MARKDOWN_OFFICE_WORD_STUB_LOG' => $log,
        ],
    );

    expect($answer['status'])->toBe(0, $answer['err'])
        ->and(is_file($target))->toBeTrue();

    $given = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];

    expect($given)->toContain($workspace['document']);

    // The directory the tool is told to write into is the dispatcher's own scratch
    // directory and not the one the caller asked for: the dispatcher copies the file
    // out of it afterwards, which is why the caller's file is there at all.
    $directories = array_values(array_filter(
        $given,
        fn (string $line): bool => str_starts_with($line, '--out='),
    ));

    expect($directories)->toHaveCount(1)
        ->and(substr($directories[0], 6))->not->toBe($target)
        ->and(substr($directories[0], 6))->toStartWith(sys_get_temp_dir());
});
