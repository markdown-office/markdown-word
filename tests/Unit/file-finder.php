<?php

declare(strict_types=1);

use MarkdownWord\Exception\InvalidInput;
use MarkdownWord\Files\DiskFinder;
use MarkdownWord\Filesystem\PathScope;
use MarkdownWord\Tests\Support\Scratch;

/*
 * The finder: where a document's files come from.
 *
 * The library asks for names and a finder answers, so these are about the answers —
 * a file that is read, a file that may not be, a theme that resolves and a theme
 * that does not. The scope is the part worth reading twice: a name that looks
 * harmless and resolves somewhere else is the ordinary way a path becomes a read of
 * the wrong file, and the check belongs on the file rather than on the directory it
 * was found in.
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

it('gives the path of a file it holds', function () {
    $base = finderDirectory('paths');
    file_put_contents($base . '/logo.png', 'the bytes');

    $finder = new DiskFinder(picture: [$base]);

    expect($finder->path('logo.png', 'picture'))
        ->toBe(PathScope::canonical($base . '/logo.png'));
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

    $finder = new DiskFinder(document: [$base], scope: new PathScope([$base]));

    expect($finder->read($base . '/report.dotx', 'document'))->toBe('the bytes')
        ->and($finder->read($base . '/nowhere.dotx', 'document'))->toBeNull();
});

it('refuses a file that resolves outside the scope', function () {
    $inside = finderDirectory('inside');
    $outside = finderDirectory('outside');
    file_put_contents($outside . '/secret.txt', 'not yours');

    $finder = new DiskFinder(document: [$inside], scope: new PathScope([$inside]));

    expect(fn () => $finder->read('../' . basename($outside) . '/secret.txt', 'document'))
        ->toThrow(InvalidInput::class, 'outside the directories this render may read');
});

it('refuses a file that is a symlink out of the scope', function () {
    // The file is in a directory the scope holds and resolves out of it: the name
    // looks contained and the file is not, which is the ordinary way a path that
    // reads harmlessly becomes a read of the wrong file.
    $inside = finderDirectory('linked');
    $outside = finderDirectory('secrets');
    file_put_contents($outside . '/secret.txt', 'not yours');

    if (!@symlink($outside . '/secret.txt', $inside . '/looks-fine.txt')) {
        test()->markTestSkipped('symlinks are not available here');
    }

    $finder = new DiskFinder(document: [$inside], scope: new PathScope([$inside]));

    expect(fn () => $finder->read('looks-fine.txt', 'document'))
        ->toThrow(InvalidInput::class);
});

it('reads a file through a symlink that stays inside the scope', function () {
    // The other half of the refusal above: a link that resolves within the roots is
    // a file like any other and must still be read.
    $base = finderDirectory('shared');
    file_put_contents($base . '/real.txt', 'the bytes');

    if (!@symlink($base . '/real.txt', $base . '/linked.txt')) {
        test()->markTestSkipped('symlinks are not available here');
    }

    $finder = new DiskFinder(document: [$base]);

    expect($finder->read('linked.txt', 'document'))->toBe('the bytes');
});

it('names the roots it allows when refusing a file, and not where it pointed', function () {
    $inside = finderDirectory('roots');
    $outside = finderDirectory('elsewhere');
    file_put_contents($outside . '/secret.txt', 'not yours');

    $finder = new DiskFinder(document: [$inside], scope: new PathScope([$inside]));

    try {
        $finder->read('../' . basename($outside) . '/secret.txt', 'document');
        expect(false)->toBeTrue('the file should have been refused');
    } catch (InvalidInput $refusal) {
        // The roots are this program's own and the same for every caller; where the
        // name resolved to is a path this machine chose.
        expect($refusal->getMessage())->toContain('outside the directories this render may read')
            ->toContain($inside)
            ->not->toContain($outside);
    }
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

    expect($finder->read('logo.png', 'picture'))->toBeNull()
        // The same file is there for the role the finder was given bases for.
        ->and($finder->read('logo.png', 'theme'))->toBe('the bytes');
});
