<?php

declare(strict_types=1);

/*
 * What a machine leaves in a working copy, and what the archive must not carry.
 *
 * The phar is built by copying a staging tree, so anything the developer's
 * machine wrote into `src/`, `bin/` or `themes/` is copied too. A `.DS_Store`
 * records the directory's contents and Finder rewrites it as they change, which
 * makes the archive a function of whose machine ran the build — and the build
 * exists to produce one reproducible artifact, which is a promise this silently
 * breaks rather than one a user notices breaking.
 */

/**
 * The staging filter's own name lists, read from the build script.
 *
 * @return array{files: list<string>, directories: list<string>}
 */
function strayPatterns(): array
{
    static $read = null;

    if ($read !== null) {
        return $read;
    }

    $source = file_get_contents(dirname(__DIR__, 2) . '/tools/build-phar.php');

    $read = [];

    foreach (['STRAY_FILES' => 'files', 'STRAY_DIRECTORIES' => 'directories'] as $constant => $key) {
        if (preg_match('/^const ' . $constant . ' = \[(.*?)\];$/ms', $source, $match) !== 1) {
            throw new RuntimeException(sprintf('Unable to read %s from tools/build-phar.php.', $constant));
        }

        preg_match_all("/'([^']+)'/", $match[1], $names);

        $read[$key] = $names[1];
    }

    return $read;
}

/**
 * Whether a staged path relative to `app/` is dropped by the build.
 */
function isStray(string $relative): bool
{
    $strays = strayPatterns();
    $parts = explode('/', $relative);

    foreach ($parts as $index => $part) {
        $last = $index === count($parts) - 1;

        if ($last ? in_array($part, $strays['files'], true) : in_array($part, $strays['directories'], true)) {
            return true;
        }
    }

    return false;
}

it('drops the files a machine leaves behind, at any depth', function (string $relative) {
    expect(isStray($relative))->toBeTrue();
})->with([
    'src/.DS_Store',
    'bin/.DS_Store',
    'themes/default/.DS_Store',
    'vendor/phpoffice/phpword/.DS_Store',
    // A `__MACOSX` directory holds copies under names of the zip tool's choosing,
    // so only the directory itself can be recognised — the file inside is arbitrary.
    'src/__MACOSX/',
    'src/__MACOSX/src__DS_Store',
    'vendor/nette/utils/__MACOSX/anything',
]);

it('keeps every source file the archive is built from', function (string $relative) {
    expect(isStray($relative))->toBeFalse();
})->with([
    'src/Theme/ThemeLoader.php',
    'src/Configuration/Styles.php',
    'bin/mdword',
    'themes/default/styles.yml',
    'vendor/phpoffice/phpword/src/PhpWord/Style.php',
    'composer.json',
    'README.md',
]);

it('catches the names Finder and Explorer actually write', function () {
    // The list is the whole filter, so a name left out is one that ships. These
    // are what the platforms that create them call them.
    expect(strayPatterns()['files'])->toContain('.DS_Store', 'Thumbs.db', 'desktop.ini');
});

it('does not read a stray into the timestamp the build stamps entries with', function () {
    // `resolveEpoch()` takes the newest input mtime and writes it to every entry,
    // so a stray that is newer than the sources moves every timestamp in the
    // archive. Filtering only the copy would leave the other half in place.
    $source = file_get_contents(dirname(__DIR__, 2) . '/tools/build-phar.php');

    $epoch = substr($source, (int) strpos($source, 'function resolveEpoch'), 2000);

    expect($epoch)->toContain('STRAY_FILES')
        ->and($epoch)->toContain('STRAY_DIRECTORIES');
});

it('does not claim a stray is one of the sources the archive is built from', function () {
    // Left in the input list, a stray both ships and competes for the epoch; the
    // filter has to be in both places, and neither is implied by the other.
    $source = file_get_contents(dirname(__DIR__, 2) . '/tools/build-phar.php');

    $staged = substr($source, (int) strpos($source, '$strays = []'), 3000);

    expect($staged)->toContain('STRAY_DIRECTORIES')
        ->and($staged)->toContain('STRAY_FILES')
        // Removed after the walk rather than during it: the iterator descends
        // into a directory it has already yielded, so removing one there fails
        // the walk on a directory that is already gone.
        ->and($staged)->toMatch('/foreach\s*\(\s*\$strays as \$stray\s*\)/');
});