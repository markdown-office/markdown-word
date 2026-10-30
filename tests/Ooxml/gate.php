<?php

declare(strict_types=1);

use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;

/*
 * The OOXML gate: the documents this library writes, checked against the schema.
 *
 * A `.docx` is Office Open XML, and a document that breaks the schema is a
 * document that is not what it claims to be. Word opens it anyway, because Word
 * is lenient where the schema is not, and nothing else in the suite could see it.
 *
 * The validator is Microsoft's own — the one the Open XML SDK Productivity Tool
 * uses — and it is run over the examples rather than over documents the test
 * writes itself: the examples are the whole corpus of what this library writes,
 * and a gate over a selection is a gate over the selection.
 *
 * The test skips itself where the .NET SDK is not, and says what is missing. A
 * machine without the SDK can still run the rest of the suite; a machine with it
 * cannot pass this test with a document the schema rejects.
 */

uses()->beforeEach(function (): void {
    $missing = whyTheValidatorCannotRun();

    if ($missing !== '') {
        test()->markTestSkipped($missing);
    }
});

it('writes documents the schema accepts', function () {
    $corpus = theCorpus();
    $answer = runTheValidator($corpus);

    expect($answer['code'])->toBe(0, sprintf(
        "The validator rejected %d of the %d documents the build wrote:%s%s",
        countDocumentsWithErrors($answer['out']),
        count($corpus),
        PHP_EOL,
        $answer['out'],
    ));
});

it('finds an error in a document that has one', function () {
    // The gate is worth nothing if it cannot fail. A validator that answered "no
    // errors" about every document would pass the test above, so this one asks it
    // about a document with an error in it and expects to be told.
    //
    // The error is an element no schema names, and the document is otherwise one
    // this library wrote: it still opens, so what refuses it is the schema and not
    // a broken archive.
    $document = Scratch::path('broken', '.docx');

    file_put_contents($document, toDocx("# Title\n\nA paragraph.\n"));

    TemplateFactory::addRawPart(
        $document,
        'word/document.xml',
        str_replace('<w:body>', '<w:body><w:notAnElement/>', TemplateFactory::xmlOf($document)),
    );

    $answer = runTheValidator([$document]);

    expect($answer['code'])->toBe(1, 'The validator did not refuse the document:' . PHP_EOL . $answer['out'])
        ->and($answer['out'])->toContain('notAnElement');
});

/**
 * The documents the examples build.
 *
 * The build is run here rather than the corpus being read off the disk, because a
 * corpus left over from an earlier commit is a corpus of documents the code under
 * test did not write, and a gate over that measures the past.
 *
 * The previews are skipped: drawing them takes longer than everything else the
 * build does, and they are pictures of the documents rather than the documents.
 *
 * @return list<string>
 */
function theCorpus(): array
{
    static $corpus = null;

    if ($corpus !== null) {
        return $corpus;
    }

    $root = dirname(__DIR__, 2);

    $build = runACommand(sprintf(
        '%s %s --no-previews 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($root . '/examples/build.php'),
    ));

    if ($build['code'] !== 0) {
        throw new RuntimeException('The examples did not build:' . PHP_EOL . $build['out']);
    }

    $documents = glob($root . '/examples/out/documents/*.docx') ?: [];

    sort($documents);

    if ($documents === []) {
        throw new RuntimeException('The build wrote no documents:' . PHP_EOL . $build['out']);
    }

    return $corpus = $documents;
}

/**
 * What the validator says about a set of files.
 *
 * The exit code is the answer: 0 when every file is clean, 1 when one of them is
 * not, and 2 when there was nothing to check. The output says which file and why,
 * which is what a fix has to be pointed at.
 *
 * @param list<string> $files
 *
 * @return array{code: int, out: string}
 */
function runTheValidator(array $files): array
{
    return runACommand(sprintf(
        '%s run --project %s --no-build -- %s 2>&1',
        escapeshellarg((string) commandOnPath('dotnet')),
        escapeshellarg(theBuiltValidator()),
        implode(' ', array_map('escapeshellarg', $files)),
    ));
}

/**
 * The validator, built and ready.
 *
 * The build restores the package it depends on from the network, so it is done
 * once rather than before every document: a gate that took half a minute to start
 * would be a gate nobody runs.
 *
 * @return string The path to the project `dotnet run` is given.
 */
function theBuiltValidator(): string
{
    static $project = null;

    if ($project !== null) {
        return $project;
    }

    $project = dirname(__DIR__, 2) . '/tools/ooxml-validator';

    $build = runACommand(sprintf(
        '%s build %s --nologo --verbosity quiet 2>&1',
        escapeshellarg((string) commandOnPath('dotnet')),
        escapeshellarg($project),
    ));

    if ($build['code'] !== 0) {
        throw new RuntimeException('The OOXML validator did not build:' . PHP_EOL . $build['out']);
    }

    return $project;
}

/**
 * Why the validator cannot run on this machine, or an empty string when it can.
 *
 * The SDK is not on every machine, and a test that fails where it cannot run says
 * nothing about the code — so the test skips itself and says what is missing
 * instead.
 */
function whyTheValidatorCannotRun(): string
{
    static $answer = null;

    if ($answer !== null) {
        return $answer;
    }

    $dotnet = commandOnPath('dotnet');

    if ($dotnet === null) {
        return $answer = 'The .NET SDK is not on this machine, so the OOXML validator cannot be run.';
    }

    $lines = [];
    $status = 0;

    exec(escapeshellarg($dotnet) . ' --list-sdks 2>&1', $lines, $status);

    if ($status !== 0 || $lines === []) {
        return $answer = 'This machine has the .NET runtime but no .NET SDK, so the validator cannot be built.';
    }

    return $answer = '';
}

/**
 * How many documents the validator found errors in.
 *
 * Counted from the report rather than from the exit code, which says only that
 * something is wrong: the number is what tells a reader how much of the corpus
 * is affected. The line that names the count in total ends in `error(s)` as
 * well, and it is not a document.
 */
function countDocumentsWithErrors(string $report): int
{
    $found = 0;

    foreach (explode(PHP_EOL, $report) as $line) {
        if (preg_match('#^.*\.docx: [1-9][0-9]* error\(s\)$#', $line) === 1) {
            $found++;
        }
    }

    return $found;
}

/**
 * What a command did, and what it said.
 *
 * @return array{code: int, out: string}
 */
function runACommand(string $command): array
{
    $lines = [];
    $code = 0;

    exec($command, $lines, $code);

    return ['code' => $code, 'out' => implode(PHP_EOL, $lines)];
}
