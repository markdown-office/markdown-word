<?php

declare(strict_types=1);

use MarkdownWord\Files\DiskFinder;
use MarkdownWord\Tests\Support\Scratch;

/*
 * The finder: where a document's files come from.
 *
 * The library asks for names and a finder answers, so these are about the answers —
 * a file that is read, a file that is not there, a theme that resolves and a theme
 * that does not. What a finder does *not* do is refuse: a file that is out of reach
 * is answered like any other, because the question of what this render may read is
 * the library's, and the library holds the path to its own scope. That is what lets
 * one adapter serve both halves of this suite, so the tests here are as much about
 * what the finder does not throw as about what it answers.
 */

/** A scratch directory no other test is using. */
function finderDirectory(string $name = 'finder'): string
{
    $directory = Scratch::path($name, '');

    if (!mkdir($directory, 0o777, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create "%s".', $directory));
    }

    return $directory;
}

it('reads a file it is given', function () {
    $base = finderDirectory('pictures');
    file_put_contents($base . '/logo.png', 'the bytes');

    $finder = new DiskFinder(picture: [$base]);

    expect($finder->read('logo.png', 'picture'))->toBe('the bytes');
});

it('reads a theme file through the finder', function () {
    $base = finderDirectory('themefiles');
    mkdir($base . '/house', 0o777, true);
    file_put_contents($base . '/house/theme.yml', "name: House\n");

    $finder = new DiskFinder(theme: [$base]);

    expect($finder->read('house/theme.yml', 'theme'))->toBe("name: House\n");
});

it('gives the path of a file as it was written', function () {
    // The path as written rather than as the file system resolved it: the library
    // does the resolving and the holding, and a path this machine chose is a path
    // this machine's own rules should be asked about.
    $base = finderDirectory('paths');
    file_put_contents($base . '/logo.png', 'the bytes');

    $finder = new DiskFinder(picture: [$base]);

    expect($finder->path('logo.png', 'picture'))->toBe($base . '/logo.png')
        // A name that walks out of the base and back into it names the same file in
        // two spellings, and the finder answers with the one it was given.
        ->and($finder->path('../' . basename($base) . '/logo.png', 'picture'))
        ->toBe($base . '/../' . basename($base) . '/logo.png');
});

it('has no file for a name that is not there', function () {
    $base = finderDirectory('nowhere');
    file_put_contents($base . '/logo.png', 'the bytes');

    $finder = new DiskFinder(picture: [$base]);

    expect($finder->read('logo.png', 'picture'))->toBe('the bytes')
        ->and($finder->read('nowhere.png', 'picture'))->toBeNull()
        ->and($finder->path('nowhere.png', 'picture'))->toBeNull();
});

it('takes a name that is already a path as it stands', function () {
    // A path is a path, whatever the bases are: resolving it against one would name
    // a file nobody meant.
    $base = finderDirectory('absolute');
    file_put_contents($base . '/report.dotx', 'the bytes');

    $finder = new DiskFinder(document: [$base]);

    expect($finder->read($base . '/report.dotx', 'document'))->toBe('the bytes')
        ->and($finder->read($base . '/nowhere.dotx', 'document'))->toBeNull();
});

it('answers for a file that is out of reach rather than refusing it', function () {
    // The scope is the library's question, not the finder's. A finder that refused
    // would throw this package's exception, which the other half of this suite would
    // not catch — and the two finders have the same signatures so that one adapter
    // can serve both.
    $inside = finderDirectory('inside');
    $outside = finderDirectory('outside');
    file_put_contents($outside . '/secret.txt', 'not yours');

    $finder = new DiskFinder(document: [$inside]);
    $name = '../' . basename($outside) . '/secret.txt';

    expect($finder->read($name, 'document'))->toBe('not yours')
        ->and($finder->path($name, 'document'))
        ->toBe($inside . '/' . $name);
});

it('answers for a file that is a symlink out of reach', function () {
    // The other half of the refusal the library makes: the file is in a directory
    // the caller may read and resolves out of it. The finder answers the path; the
    // library holds it to its scope and refuses it.
    $inside = finderDirectory('linked');
    $outside = finderDirectory('secrets');
    file_put_contents($outside . '/secret.txt', 'not yours');

    if (!@symlink($outside . '/secret.txt', $inside . '/looks-fine.txt')) {
        test()->markTestSkipped('symlinks are not available here');
    }

    $finder = new DiskFinder(document: [$inside]);

    expect($finder->read('looks-fine.txt', 'document'))->toBe('not yours');
});

it('reads a file through a symlink', function () {
    // A link that resolves within the roots is a file like any other and must still
    // be read.
    $base = finderDirectory('shared');
    file_put_contents($base . '/real.txt', 'the bytes');

    if (!@symlink($base . '/real.txt', $base . '/linked.txt')) {
        test()->markTestSkipped('symlinks are not available here');
    }

    $finder = new DiskFinder(document: [$base]);

    expect($finder->read('linked.txt', 'document'))->toBe('the bytes');
});

it('resolves a theme by its directory, so one without a manifest shadows a later one', function () {
    // The theme is there and the manifest is not, which is a theme the caller can be
    // told about — and it is not the theme of the same name in the next base.
    $first = finderDirectory('shadow-first');
    $second = finderDirectory('shadow-second');

    mkdir($first . '/house', 0o777, true);
    mkdir($second . '/house', 0o777, true);
    file_put_contents($second . '/house/theme.yml', "name: House\n");

    $finder = new DiskFinder(theme: [$first, $second]);

    expect($finder->read('house/theme.yml', 'theme'))->toBeNull()
        ->and($finder->path('house/theme.yml', 'theme'))->toBeNull()
        // The directory is the theme's, and the later base's file is not in it.
        ->and($finder->files('house'))->toBe([]);
});

it('lists the themes that hold a manifest, sorted', function () {
    $base = finderDirectory('themes');

    mkdir($base . '/house', 0o777, true);
    file_put_contents($base . '/house/theme.yml', "name: House\n");

    mkdir($base . '/zebra', 0o777, true);
    file_put_contents($base . '/zebra/theme.yml', "name: Zebra\n");

    // A directory without a manifest is not listed, and neither is a file that
    // happens to be called like one.
    mkdir($base . '/bare', 0o777, true);
    file_put_contents($base . '/theme.yml', "name: Not a theme\n");

    // Nor is a directory the file system put there.
    mkdir($base . '/.hidden', 0o777, true);
    file_put_contents($base . '/.hidden/theme.yml', "name: Hidden\n");

    $finder = new DiskFinder(theme: [$base]);

    expect($finder->themes())->toBe(['house', 'zebra']);
});

it('looks past a base that is not there', function () {
    $base = finderDirectory('present');
    mkdir($base . '/house', 0o777, true);
    file_put_contents($base . '/house/theme.yml', "name: House\n");

    $finder = new DiskFinder(theme: [$base . '/not-there', $base]);

    expect($finder->themes())->toBe(['house'])
        ->and($finder->read('house/theme.yml', 'theme'))->toBe("name: House\n");
});

it('lists the files of a theme, sorted, and nothing for a theme that is not there', function () {
    $base = finderDirectory('files');
    mkdir($base . '/house', 0o777, true);
    file_put_contents($base . '/house/theme.yml', "name: House\n");
    file_put_contents($base . '/house/styles.yml', "heading.1:\n");
    file_put_contents($base . '/house/cover.png', 'the bytes');

    // Neither a hidden file nor a directory is one of the theme's files.
    file_put_contents($base . '/house/.hidden', '');
    mkdir($base . '/house/assets', 0o777, true);

    $finder = new DiskFinder(theme: [$base]);

    expect($finder->files('house'))->toBe(['cover.png', 'styles.yml', 'theme.yml'])
        ->and($finder->files('missing'))->toBe([]);
});

it('looks for a name in the bases in the order they are given', function () {
    $first = finderDirectory('first');
    $second = finderDirectory('second');

    file_put_contents($first . '/logo.png', 'the first');
    file_put_contents($second . '/logo.png', 'the second');
    file_put_contents($second . '/only.png', 'the second only');

    $finder = new DiskFinder(picture: [$first, $second]);

    expect($finder->read('logo.png', 'picture'))->toBe('the first')
        // And the second base is looked at when the first holds nothing.
        ->and($finder->read('only.png', 'picture'))->toBe('the second only');
});

it('looks in every base for a name with no role', function () {
    $themes = finderDirectory('worlds');
    $pictures = finderDirectory('picturesofthings');

    file_put_contents($pictures . '/logo.png', 'the bytes');

    $finder = new DiskFinder(theme: [$themes], picture: [$pictures]);

    expect($finder->read('logo.png'))->toBe('the bytes');
});

it('finds nothing for a role it has no bases for', function () {
    // The role says which world the name is resolved in, and a world the finder was
    // not given is not one it can answer for.
    $base = finderDirectory('somewhere');
    file_put_contents($base . '/logo.png', 'the bytes');

    $finder = new DiskFinder(theme: [$base]);

    expect($finder->read('logo.png', 'picture'))->toBeNull();
});

it('finds nothing for a role it does not know', function () {
    $base = finderDirectory('noworld');
    file_put_contents($base . '/logo.png', 'the bytes');

    $finder = new DiskFinder(picture: [$base]);

    expect($finder->read('logo.png', 'sound'))->toBeNull();
});

it('finds nothing for a theme file that is not inside a theme', function () {
    // The theme role names a file inside a theme: `<theme>/<file>`. A name with no
    // theme in it is not one of the theme's files.
    $base = finderDirectory('notatheme');
    file_put_contents($base . '/logo.png', 'the bytes');

    $finder = new DiskFinder(theme: [$base]);

    expect($finder->read('logo.png', 'theme'))->toBeNull()
        ->and($finder->read('house/logo.png', 'theme'))->toBeNull();
});
