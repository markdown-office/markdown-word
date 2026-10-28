<?php

declare(strict_types=1);

/*
 * The render tools: the choice between the three backends, and what they say when they
 * cannot keep the promise.
 *
 * A renderer that cannot be trusted is worse than no renderer, because a caller who does
 * not know is told a layout is right when it is not. So the two things these tests hold
 * to are the choice — Word where it can be used, and WordCraft where it cannot — and the
 * saying: a run that falls back to a backend that is not Word says so, and a run that
 * produced no file exits non-zero.
 *
 * The tests that need a choice of platform set `MARKDOWN_OFFICE_PLATFORM` and `PATH`
 * themselves, so they are the same on a laptop with Word on it and on a build machine
 * with nothing on it. The one test that needs Word is skipped where Word is not.
 */

use MarkdownWord\MarkdownToWord;
use MarkdownWord\Tests\Support\Scratch;

/**
 * The dispatcher's answer, with the environment the test chooses.
 *
 * @param array<string, string> $environment
 * @param list<string> $arguments
 *
 * @return array{out: string, err: string, status: int}
 */
function dispatcher(array $environment, array $arguments, ?string $workingDirectory = null): array
{
    $root = dirname(__DIR__, 2);

    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        [PHP_BINARY, $root . '/tools/render-document.php', ...$arguments],
        $descriptors,
        $pipes,
        $workingDirectory,
        array_merge(getenv(), $environment),
    );

    if (! is_resource($process)) {
        throw new RuntimeException('The dispatcher could not be started.');
    }

    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['out' => $out, 'err' => $err, 'status' => proc_close($process)];
}

/**
 * A directory holding the tools a test wants the machine to have, and nothing else.
 *
 * The path is set to this directory and nothing more, so a test cannot pass because the
 * machine it runs on happens to have Word on it — and cannot fail because it does not.
 *
 * @param list<string> $commands
 */
function toolsHolding(string ...$commands): string
{
    return toolsHoldingWith("#!/bin/sh\nexit 0\n", ...$commands);
}

/**
 * The same, with the tools answering in a way the test chooses.
 *
 * @param list<string> $commands
 */
function toolsHoldingWith(string $body, string ...$commands): string
{
    $directory = Scratch::directory() . '/tools-' . bin2hex(random_bytes(6));

    if (! mkdir($directory, 0o777, true) && ! is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create the directory "%s".', $directory));
    }

    foreach ($commands as $command) {
        $path = $directory . '/' . $command;

        file_put_contents($path, $body);
        chmod($path, 0o755);
    }

    return $directory;
}

/**
 * A path no tool is on.
 */
function noTools(): string
{
    return toolsHolding();
}

/**
 * Whether a command is on the path.
 */
function commandOnPath(string $command): bool
{
    $status = 0;
    exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null', $ignored, $status);

    return $status === 0;
}

it('prefers Word on the platforms that can run it', function () {
    expect(dispatcher(['MARKDOWN_OFFICE_PLATFORM' => 'darwin'], ['--platform-backend'])['out'])->toBe("word\n")
        ->and(dispatcher(['MARKDOWN_OFFICE_PLATFORM' => 'windows'], ['--platform-backend'])['out'])->toBe("word\n");
});

it('prefers WordCraft where Word cannot run', function () {
    expect(dispatcher(['MARKDOWN_OFFICE_PLATFORM' => 'linux'], ['--platform-backend'])['out'])->toBe("wordcraft\n")
        ->and(dispatcher(['MARKDOWN_OFFICE_PLATFORM' => 'freebsd'], ['--platform-backend'])['out'])->toBe("wordcraft\n");
});

it('has no preference on a platform it does not know', function () {
    // A platform this does not know has no statement to make about which backend is right
    // for it. That is not the same as a platform whose preferred backend is not installed:
    // the preference is what is missing, not the software.
    expect(dispatcher(['MARKDOWN_OFFICE_PLATFORM' => 'solaris'], ['--platform-backend'])['out'])->toBe("none\n");
});

it('uses the first backend that is installed, in the order of how much it can be trusted', function () {
    // WordCraft is the only tool on this machine, and it is faithful rather than true.
    $answer = dispatcher(
        ['MARKDOWN_OFFICE_PLATFORM' => 'darwin', 'PATH' => toolsHolding('wordcraft-cli')],
        ['--which'],
    );

    expect($answer['out'])->toBe("wordcraft\n");
});

it('says so when the backend it fell back to is not Word', function () {
    // LibreOffice is the last resort and the least trustworthy, and a caller who is not
    // told is told a layout is right when it is not.
    $answer = dispatcher(
        ['MARKDOWN_OFFICE_PLATFORM' => 'darwin', 'PATH' => toolsHolding('soffice')],
        ['--which'],
    );

    expect($answer['out'])->toBe("libreoffice\n")
        ->and($answer['err'])->toContain('not Word');
});

it('says nothing when the backend it used is Word', function () {
    $answer = dispatcher(
        ['MARKDOWN_OFFICE_PLATFORM' => 'darwin', 'PATH' => toolsHolding('osascript')],
        ['--which'],
    );

    expect($answer['out'])->toBe("word\n")
        ->and($answer['err'])->toBe('');
});

it('refuses rather than falling back to nothing it can trust', function () {
    $answer = dispatcher(
        ['MARKDOWN_OFFICE_PLATFORM' => 'darwin', 'PATH' => noTools()],
        ['--which'],
    );

    expect($answer['out'])->toBe("none\n");
});

it('fails rather than reporting a render that produced no file', function () {
    // A backend that says it worked and writes nothing is the failure a caller cannot see
    // from the output directory alone.
    $directory = toolsHolding('wordcraft-cli');
    $document = Scratch::path('render');
    $target = Scratch::path('rendered', '.pdf');

    file_put_contents($document, 'not a document');

    $answer = dispatcher(
        [
            'MARKDOWN_OFFICE_RENDERER' => 'wordcraft',
            'PATH' => $directory,
        ],
        [$document, '--out', $target],
    );

    expect($answer['status'])->not->toBe(0)
        ->and($answer['err'])->not->toBe('')
        ->and(file_exists($target))->toBeFalse();
});

it('runs the backend it was told to rather than the one the platform prefers', function () {
    // The forced backend is the one that runs, and the note is about the one that ran.
    $answer = dispatcher(
        ['MARKDOWN_OFFICE_RENDERER' => 'wordcraft', 'PATH' => toolsHolding('soffice')],
        ['--which'],
    );

    expect($answer['out'])->toBe("wordcraft\n")
        ->and($answer['err'])->toContain('not Word');
});

it('names the file it writes after the document it came from', function () {
    // The name a caller gets is the name of the document they passed, and not a name the
    // tool made for itself: the tool stages a document under a name of its own and writes
    // the PDF under that one, and the caller must not have to know either.
    $document = Scratch::path('quarterly');
    $working = Scratch::directory() . '/render-' . bin2hex(random_bytes(6));

    mkdir($working, 0o777, true);
    file_put_contents($document, 'not a document');

    // A backend that writes what it was told to write, where it was told to write it.
    // `printf` rather than `cp`, because the path holds the tools and nothing else and a
    // program of its own would not be found on it.
    $answer = dispatcher(
        [
            'MARKDOWN_OFFICE_RENDERER' => 'wordcraft',
            'PATH' => toolsHoldingWith("#!/bin/sh\nprintf '%%PDF' > \"\$3\"\n", 'wordcraft-cli'),
        ],
        [$document],
        $working,
    );

    expect($answer['status'])->toBe(0)
        ->and(basename($answer['out']))->toContain('.pdf')
        ->and(glob($working . '/*.pdf'))->not->toBeEmpty();
});

it('tells a caller who asks for nothing how to ask', function () {
    $answer = dispatcher([], []);

    expect($answer['status'])->not->toBe(0)
        ->and($answer['err'])->toContain('Usage');
});

it('renders a document through Microsoft Word', function () {
    // Word is the truth, and the truth is the only thing this test can be about. It is
    // skipped where Word is not, because a machine without Word cannot answer the
    // question and a test that guesses is worse than no test.
    if (PHP_OS_FAMILY !== 'Darwin') {
        test()->markTestSkipped('Microsoft Word is only here on macOS, so the render cannot be checked.');
    }

    if (! commandOnPath('osascript')) {
        test()->markTestSkipped('AppleScript is not here, so Word cannot be driven.');
    }

    $document = Scratch::path('render');
    $target = Scratch::path('rendered', '.pdf');

    (new MarkdownToWord("# Quarterly review\n\nA paragraph of text.\n"))->save($document);

    $answer = dispatcher(
        ['MARKDOWN_OFFICE_PLATFORM' => 'darwin'],
        [$document, '--out', $target],
    );

    expect($answer['status'])->toBe(0)
        ->and(file_exists($target))->toBeTrue();

    // A PDF that exists is a PDF that may still have been being written, so the file has
    // to carry the marker every PDF ends with.
    $pdf = (string) file_get_contents($target);

    expect($pdf)->toStartWith('%PDF')
        ->and($pdf)->toContain('%%EOF');
});
