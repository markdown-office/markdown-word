<?php

declare(strict_types=1);

use MarkdownWord\Tests\Support\Scratch;

/*
 * What `composer require` downloads.
 *
 * Packagist serves a zipball that `git archive` builds from the tag, so the dist
 * is exactly what `.gitattributes` selects — and a line there can be wrong with
 * nothing in the repository failing. The one dev dependency is the test
 * framework, so a consumer cannot run what a mistyped rule hands them, and a
 * missing rule strips the library out of the package entirely.
 *
 * The archive is built rather than the rules being parsed, because the two are
 * not the same thing. `git check-attr` answers `unspecified` for a file inside a
 * directory that `.gitattributes` names, while `git archive` recurses and drops
 * the directory — so a check written against `check-attr` would pass on a dist
 * shipping the entire test suite.
 */

/**
 * Lines of output from a git command run in the repository.
 *
 * @return list<string>
 */
function gitLines(string $arguments): array
{
    $output = [];
    $status = 1;

    exec(
        sprintf('cd %s && git %s 2>&1', escapeshellarg(dirname(__DIR__, 2)), $arguments),
        $output,
        $status,
    );

    if ($status !== 0) {
        throw new RuntimeException(sprintf('`git %s` failed: %s', $arguments, implode("\n", $output)));
    }

    return $output;
}

/**
 * The tree the dist archive is built from, or `HEAD` when nothing is uncommitted.
 *
 * `git stash create` is what lets an uncommitted `.gitattributes` reach
 * `git archive`, which otherwise only ever sees commits, and it leaves the
 * working tree and HEAD alone so a test can rely on it.
 */
function distArchiveTree(): string
{
    $created = gitLines('stash create');

    return $created === [] ? 'HEAD' : $created[0];
}

/**
 * The files in the dist archive for the working tree, directories left out.
 *
 * @return list<string>
 */
function distArchiveFiles(): array
{
    static $files = null;

    if ($files !== null) {
        return $files;
    }

    $archive = Scratch::path('dist', '.zip');
    gitLines(sprintf('archive --format=zip --output=%s %s', escapeshellarg($archive), escapeshellarg(distArchiveTree())));

    $zip = new ZipArchive();

    if ($zip->open($archive) !== true) {
        throw new RuntimeException(sprintf('Unable to open the archive written to "%s".', $archive));
    }

    $entries = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);

        if (!str_ends_with($name, '/')) {
            $entries[] = $name;
        }
    }

    $zip->close();
    sort($entries);

    return $files = $entries;
}

it('does not ship the lock file, which pins nothing for a consumer', function () {
    // Composer does not consult a dependency's `composer.lock`, so the one that
    // shipped until this rule existed described an install that never happened:
    // the pins in it disagreed with the versions Composer actually resolved.
    expect(distArchiveFiles())->not->toContain('composer.lock');
});

it('does not ship the patch lock, which records one machine\'s install', function () {
    // The patch lock says which patch was applied, at which checksum and at what
    // depth. It is this repository's install state, not the package's, and a
    // consumer's install never reads it.
    expect(distArchiveFiles())->not->toContain('patches.lock.json');
});

it('keeps the lock file in the repository, which this repository\'s installs pin to', function () {
    // Both rules are the reason. No consumer ever reads the lock, which is what the
    // export-ignore is for, but this repository's own installs are meant to be the
    // ones the lock describes: `composer validate --strict` fails outright when the
    // two disagree, and a build that resolved afresh would test whatever was newest
    // that morning rather than what was reviewed.
    expect(gitLines('ls-files --error-unmatch composer.lock'))->toBe(['composer.lock']);
});

it('ships the library whole, and what a consumer needs to reach it', function () {
    $files = distArchiveFiles();

    expect(array_values(array_intersect(['composer.json', 'LICENSE', 'README.md'], $files)))
        ->toBe(['composer.json', 'LICENSE', 'README.md']);

    // Every source file in the tree being archived, because a rule that reached
    // `src/` would strip the package of everything it exists to be. Read out of that
    // same tree rather than out of the index, so the two sides of the comparison
    // cannot differ by a deletion that has not been staged yet.
    $tracked = gitLines(sprintf('ls-tree --name-only -r %s -- src', escapeshellarg(distArchiveTree())));
    sort($tracked);

    $shipped = array_values(array_filter(
        $files,
        static fn (string $file): bool => str_starts_with($file, 'src/'),
    ));

    expect($shipped)->toBe($tracked);
});

it('leaves out the development half, which a consumer cannot run', function () {
    $atRoot = ['phpunit.xml.dist', 'smoke.php', 'stress.php', 'sonar-project.properties'];

    $directories = array_map(
        static fn (string $directory): string => preg_quote($directory, '#'),
        ['tests/', 'tools/', '.github/', 'examples/'],
    );
    $underDirectory = '#^(' . implode('|', $directories) . ')#';

    $shipped = array_values(array_filter(
        distArchiveFiles(),
        static fn (string $file): bool => in_array($file, $atRoot, true)
            || preg_match($underDirectory, $file) === 1,
    ));

    expect($shipped)->toBe([]);
});
