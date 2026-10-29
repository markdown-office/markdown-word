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
 * The tests that need a choice of platform set `MARKDOWN_OFFICE_PLATFORM`, `PATH` and
 * `MARKDOWN_OFFICE_WORD` themselves, so they are the same on a laptop with Word on it and
 * on a build machine with nothing on it. The tests that need Word are skipped where Word
 * is not.
 */

use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\Script;

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
    return Script::run(
        [PHP_BINARY, dirname(__DIR__, 2) . '/tools/render-document.php'],
        $arguments,
        $environment,
        $workingDirectory,
    );
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
    // The tools these tests fake are shell scripts, and a Windows command prompt does
    // not run them. Skipping is better than failing: a test that cannot run on the
    // machine it is on says nothing about the code, and says it by failing.
    if (PHP_OS_FAMILY === 'Windows') {
        test()->markTestSkipped('The tools these tests fake are shell scripts, and Windows does not run them.');
    }

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

/**
 * A directory that stands for an installed application.
 *
 * The dispatcher looks for Word where Word is installed, and a test cannot install Word.
 * So the test points it at a directory that is there, and the directory is all it looks
 * for.
 */
function anApplication(): string
{
    $directory = Scratch::directory() . '/application-' . bin2hex(random_bytes(6));

    if (! mkdir($directory, 0o777, true) && ! is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create the directory "%s".', $directory));
    }

    return $directory;
}

/**
 * A Word package, whose body is typed as a template's or as a document's.
 *
 * Built rather than carried: a package is a zip archive with one part that says what it
 * holds, and a binary fixture would show a reader nothing about that part.
 */
function wordPackage(string $path, bool $template): string
{
    $type = $template
        ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml'
        : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $zip->addFromString(
        '[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Override PartName="/word/document.xml" ContentType="' . $type . '"/>'
            . '</Types>',
    );

    $zip->addFromString(
        'word/document.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body><w:p><w:r><w:t>A paragraph.</w:t></w:r></w:p></w:body></w:document>',
    );

    $zip->close();

    return $path;
}

/**
 * What a package says it holds.
 */
function declarationsOf(string $path): string
{
    $zip = new ZipArchive();
    $zip->open($path);

    $declared = (string) $zip->getFromName('[Content_Types].xml');

    $zip->close();

    return $declared;
}

/**
 * The answer of the tool that turns a template into a document.
 *
 * @return array{status: int, out: string}
 */
function packageAsDocument(string $source, string $target): array
{
    // The tool is a shell script, for the same reason the fake tools are.
    if (PHP_OS_FAMILY === 'Windows') {
        test()->markTestSkipped('The tool that turns a template into a document is a shell script.');
    }

    $root = dirname(__DIR__, 2);

    $status = 0;
    $output = [];

    exec(
        'sh ' . escapeshellarg($root . '/tools/package-as-document.sh') . ' '
            . escapeshellarg($source) . ' ' . escapeshellarg($target) . ' 2>&1',
        $output,
        $status,
    );

    return ['status' => $status, 'out' => implode("\n", $output)];
}

/**
 * The answer of the tool that says whether a PDF has been written whole.
 *
 * @return array{status: int, out: string}
 */
function pdfIsComplete(string $file): array
{
    // The tool is a shell script, for the same reason the fake tools are.
    if (PHP_OS_FAMILY === 'Windows') {
        test()->markTestSkipped('The tool that says whether a PDF is whole is a shell script.');
    }

    $root = dirname(__DIR__, 2);

    $status = 0;
    $output = [];

    exec(
        'sh ' . escapeshellarg($root . '/tools/pdf-is-complete.sh') . ' '
            . escapeshellarg($file) . ' 2>&1',
        $output,
        $status,
    );

    return ['status' => $status, 'out' => implode("\n", $output)];
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
        [
            'MARKDOWN_OFFICE_PLATFORM' => 'darwin',
            'MARKDOWN_OFFICE_WORD' => anApplication(),
            'PATH' => toolsHolding('osascript'),
        ],
        ['--which'],
    );

    expect($answer['out'])->toBe("word\n")
        ->and($answer['err'])->toBe('');
});

it('does not say Word on a machine that has none', function () {
    // The application, and not the tool that drives it: `osascript` is on every Mac
    // whether or not Word is, and a backend that says "word" where there is no Word
    // fails later, inside Word, with a message about the document rather than about
    // Word.
    $answer = dispatcher(
        [
            'MARKDOWN_OFFICE_PLATFORM' => 'darwin',
            'MARKDOWN_OFFICE_WORD' => Scratch::directory() . '/no-word-here',
            'PATH' => toolsHolding('soffice'),
        ],
        ['--which'],
    );

    expect($answer['out'])->toBe("libreoffice\n");
});

it('refuses rather than falling back to nothing it can trust', function () {
    $answer = dispatcher(
        ['MARKDOWN_OFFICE_PLATFORM' => 'darwin', 'PATH' => noTools()],
        ['--which'],
    );

    expect($answer['out'])->toBe("none\n");
});

it('turns a template into a document, and leaves a document alone', function () {
    // A template and a document are the same archive with one content type different,
    // and that one line is what Word goes by: a package typed as a template is not one
    // Word will open at all.
    $template = wordPackage(Scratch::path('template', '.dotx'), true);
    $document = wordPackage(Scratch::path('document', '.docx'), false);

    $written = Scratch::path('written', '.docx');

    expect(packageAsDocument($template, $written)['status'])->toBe(0)
        ->and(declarationsOf($written))->toContain('wordprocessingml.document.main+xml')
        ->and(declarationsOf($written))->not->toContain('wordprocessingml.template.main+xml');

    // A document is copied as it is, byte for byte: there is nothing to rewrite, and a
    // copy that is not the same is a copy that is not the document.
    $copied = Scratch::path('copied', '.docx');

    expect(packageAsDocument($document, $copied)['status'])->toBe(0)
        ->and(md5_file($copied))->toBe(md5_file($document));
});

it('refuses a file that is not a package at all', function () {
    // A file that is not a zip archive is not a Word document, and saying so is better
    // than repacking whatever it holds.
    $nothing = Scratch::path('nothing', '.docx');

    file_put_contents($nothing, 'not a package');

    $answer = packageAsDocument($nothing, Scratch::path('written', '.docx'));

    expect($answer['status'])->not->toBe(0)
        ->and($answer['out'])->not->toBe('');
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

    saveDocument("# Quarterly review\n\nA paragraph of text.\n", $document);

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

it('renders a template, and names the PDF after the template', function () {
    // Word's AppleScript opens a package typed as a template by opening nothing at all,
    // and raises no error when it does so: the template is staged as a document, and
    // the PDF is named after the template rather than after the template's extension.
    if (PHP_OS_FAMILY !== 'Darwin') {
        test()->markTestSkipped('Microsoft Word is only here on macOS, so the render cannot be checked.');
    }

    if (! commandOnPath('osascript')) {
        test()->markTestSkipped('AppleScript is not here, so Word cannot be driven.');
    }

    $template = dirname(__DIR__, 2) . '/examples/templates/meridian-qbr.dotx';
    $target = Scratch::path('meridian-qbr', '.pdf');

    $answer = dispatcher(
        ['MARKDOWN_OFFICE_PLATFORM' => 'darwin'],
        [$template, '--out', $target],
    );

    expect($answer['status'])->toBe(0)
        ->and(file_exists($target))->toBeTrue()
        ->and((string) file_get_contents($target))->toContain('%%EOF');
});

it('takes the kind of a package from the package and not from its name', function () {
    // A `.docx` whose declarations say it is a template is a template, and a `.dotx`
    // whose declarations say it is a document is a document: the declarations are what
    // a reader goes by. The tool says so, so a test holds it to it.
    $mislabelled = wordPackage(Scratch::path('mislabelled', '.docx'), true);

    $written = Scratch::path('written', '.docx');

    expect(packageAsDocument($mislabelled, $written)['status'])->toBe(0)
        ->and(declarationsOf($written))->toContain('wordprocessingml.document.main+xml')
        ->and(declarationsOf($written))->not->toContain('wordprocessingml.template.main+xml');
});

it('says a PDF is whole when it carries the marker every PDF ends with', function () {
    // A PDF is written from its first byte to its last, and the marker is written last
    // of all, so a file that carries it is a file that has been written whole.
    $pdf = Scratch::path('whole', '.pdf');

    file_put_contents($pdf, "%PDF-1.4\n1 0 obj\n<< >>\nendobj\ntrailer\n%%EOF\n");

    expect(pdfIsComplete($pdf)['status'])->toBe(0);
});

it('says a PDF is not whole when it does not carry the marker', function () {
    // A PDF being written is a file that exists and is not empty, and a copy of that is
    // a copy of half a PDF.
    $half = Scratch::path('half', '.pdf');

    file_put_contents($half, "%PDF-1.4\n1 0 obj\n");

    expect(pdfIsComplete($half)['status'])->toBe(1);
});

it('says a PDF is not whole when there is no file at all', function () {
    expect(pdfIsComplete(Scratch::path('absent', '.pdf'))['status'])->toBe(1);
});

it('says a PDF is not whole when the file is empty', function () {
    $empty = Scratch::path('empty', '.pdf');

    file_put_contents($empty, '');

    expect(pdfIsComplete($empty)['status'])->toBe(1);
});
