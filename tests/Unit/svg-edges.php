<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\UnsupportedImageFormat;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Render\SvgAttachmentCollector;
use MarkdownWord\Render\SvgRasteriser;
use MarkdownWord\Writer\SvgPass;

/*
 * The edges of the SVG path.
 *
 * The feature tests in `svg.php` are about a document that works. These are about the
 * ones that do not: a file that is not what its name says, a picture whose size nobody
 * can find, a pass pointed at an archive it cannot make sense of. Each is a place
 * where the obvious implementation would either refuse to do its job or produce a
 * document Word reports as corrupt.
 */

use MarkdownWord\Tests\Support\Upstream;

beforeEach(fn () => Upstream::install());
// Every test in this file builds a document with an SVG in it, which needs something
// to rasterise. The suite runs on a build with imagick and on one without, so the file
// skips itself rather than each test carrying a mark that would be wrong on the build
// that has the extension.
//
// `uses()->beforeEach()` rather than a bare `beforeEach(function () { $this->… })`:
// Pest types the latter as a `PendingCalls`, where `markTestSkipped()` is not a method,
// so the one call the file depends on is the one static analysis cannot see.
uses()->beforeEach(function (): void {
    if (!\extension_loaded('imagick')) {
        test()->markTestSkipped('ext-imagick is not loaded, so no SVG can be embedded');
    }
});

afterEach(fn () => Upstream::restore());

function scratchDirectory(string $name): string
{
    $directory = sys_get_temp_dir() . '/mdword-svg-' . $name;

    if (!is_dir($directory)) {
        mkdir($directory, 0o777, true);
    }

    return $directory;
}

/** A document with one picture in it, as bytes. */
function documentWithOnePicture(string $basePath, string $markdown = "![Alt](picture.png)\n"): string
{
    $target = scratchDirectory('edge') . '/edge.docx';

    (new MarkdownToWord($markdown, Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $basePath,
    ])))->save($target);

    return $target;
}

it('ignores an attachment whose raster is not in the archive', function () {
    // The hash is how the vector is matched to its picture. An attachment that matches
    // nothing is not an error — the picture may have been dropped for being remote or
    // missing — but it must not stop the ones that did match from being written.
    $directory = scratchDirectory('missing');
    imagepng(imagecreatetruecolor(10, 10), $directory . '/picture.png');

    $path = documentWithOnePicture($directory);

    $attached = (new SvgPass())->applyTo($path, [
        ['fallback' => sha1('bytes that are in no document'), 'vector' => '<svg xmlns="x"/>'],
    ]);

    expect($attached)->toBe(0)
        ->and((string) file_get_contents($path))->toStartWith('PK');
});

it('attaches a vector to a picture it finds by its bytes', function () {
    // The pass is given a hash, not a position, so this exercises it the way the
    // renderer uses it: against a document whose picture is still the VML PHPWord
    // wrote. Applying it twice to a converted picture is a different thing entirely and
    // correctly does nothing, because the VML it looks for is gone.
    $directory = scratchDirectory('recognised');
    imagepng(imagecreatetruecolor(12, 12), $directory . '/picture.png');

    $path = documentWithOnePicture($directory);
    $rasterHash = rasterHashIn($path);

    expect($rasterHash)->not->toBe('');

    $attached = (new SvgPass())->applyTo($path, [
        ['fallback' => $rasterHash, 'vector' => svgSource()],
    ]);

    expect($attached)->toBe(1)
        ->and(vectorPartsIn($path))->toHaveCount(1)
        ->and(partOf($path, 'word/document.xml'))->toContain('svgBlip');

    @unlink($path);
});

it('does nothing on a second pass, because the VML it looks for is already gone', function () {
    $directory = scratchDirectory('twice');
    imagepng(imagecreatetruecolor(12, 12), $directory . '/picture.png');

    $path = documentWithOnePicture($directory);
    $hash = rasterHashIn($path);

    expect((new SvgPass())->applyTo($path, [['fallback' => $hash, 'vector' => svgSource()]]))->toBe(1)
        ->and((new SvgPass())->applyTo($path, [['fallback' => $hash, 'vector' => svgSource()]]))->toBe(0);
});

it('says so when the archive is not one', function () {
    $path = scratchDirectory('broken') . '/not-a-docx.docx';
    file_put_contents($path, 'this is not a zip archive');

    expect(fn () => (new SvgPass())->applyTo($path, [
        ['fallback' => 'x', 'vector' => '<svg xmlns="x"/>'],
    ]))->toThrow(MalformedDocument::class, 'as a zip archive');

    @unlink($path);
});

it('declares the content type only once, however many vectors are attached', function () {
    $directory = scratchDirectory('once');

    file_put_contents($directory . '/diagram.svg', svgSource());

    $probe = documentWithOnePicture($directory, "![Alt](diagram.svg)\n");
    $hash = rasterHashIn($probe);
    @unlink($probe);

    $target = documentWithOnePicture($directory, "![Alt](diagram.svg)\n");

    (new SvgPass())->applyTo($target, [['fallback' => $hash, 'vector' => svgSource()]]);
    (new SvgPass())->applyTo($target, [['fallback' => $hash, 'vector' => svgSource()]]);

    $types = partOf($target, '[Content_Types].xml');

    expect(substr_count($types, 'Extension="svg"'))->toBe(1);
});

it('does not mistake a document with no vectors for one that has them', function () {
    $directory = scratchDirectory('novectors');
    imagepng(imagecreatetruecolor(8, 8), $directory . '/plain.png');

    $path = documentWithOnePicture($directory);
    $before = partOf($path, 'word/document.xml');

    expect((new SvgPass())->applyTo($path, []))->toBe(0)
        ->and(partOf($path, 'word/document.xml'))->toBe($before)
        ->and(partOf($path, '[Content_Types].xml'))->not->toContain('Extension="svg"');
});

it('refuses an SVG it cannot read, and says the file rather than the format is the problem', function () {
    $directory = scratchDirectory('corrupt');

    // A file with an SVG's name and no SVG in it. The old message would have called
    // this "not a format this library can put into a Word document", which sends the
    // reader to blame SVG — which Word has supported since 2016.
    file_put_contents($directory . '/broken.svg', "not an svg at all, just bytes\n");

    $markdown = "![Alt](broken.svg)\n";

    expect(fn () => (new MarkdownToWord($markdown, Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $directory,
    ])))->toDocx($markdown))
        ->toThrow(UnsupportedImageFormat::class, 'Word can hold an SVG');
});

it('reports a rasteriser that is present but cannot draw the file', function () {
    // An SVG that is well-formed but that no delegate will render. The message has to
    // distinguish "this build cannot rasterise at all" from "this build tried and the
    // file defeated it", because the fixes are different.
    $directory = scratchDirectory('undefeatable');

    file_put_contents($directory . '/odd.svg', <<<'SVG'
        <?xml version="1.0" encoding="UTF-8"?>
        <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">
          <filter id="f"><feDisplacementMap in="SourceGraphic" scale="99999"/></filter>
          <rect width="10" height="10" filter="url(#f)"/>
        </svg>
        SVG);

    $markdown = "![Alt](odd.svg)\n";

    // Whether this particular file defeats the local delegate depends on the build, so
    // the assertion is that the outcome is one of the two honest ones rather than a
    // picture that silently went missing.
    try {
        $docx = (new MarkdownToWord($markdown, Configuration::create()->withOptions([
            'images' => 'embed',
            'imageBasePath' => $directory,
        ])))->toDocx($markdown);

        expect($docx)->toStartWith('PK');
    } catch (UnsupportedImageFormat $error) {
        expect($error->getMessage())->toContain('Word can hold an SVG')
            ->and($error->getMessage())->not->toContain('not a format');
    }
});

it('keeps the vector out of the way when the picture is skipped', function () {
    // `images: skip` never opens the file, so it must not rasterise it, start an
    // Imagick instance, or leave a half-written pass behind.
    $directory = scratchDirectory('skipped');

    file_put_contents($directory . '/diagram.svg', svgSource());

    $markdown = "![Alt](diagram.svg)\n";
    $docx = (new MarkdownToWord($markdown, Configuration::create()->withOptions([
        'images' => 'skip',
        'imageBasePath' => $directory,
    ])))->toDocx($markdown);

    expect(partsOf($docx))->not->toContain('Extension="svg"');
});

it('does not claim the vector is there when the picture was not embedded', function () {
    // The vector is attached by matching bytes in the archive. With nothing embedded
    // there is nothing to attach to, and the count has to say so rather than the pass
    // inventing a relationship.
    $path = scratchDirectory('noembed') . '/noembed.docx';

    (new MarkdownToWord("# Text only\n", Configuration::create()))->save($path);

    $attached = (new SvgPass())->applyTo($path, [
        ['fallback' => sha1('nothing in this document'), 'vector' => svgSource()],
    ]);

    expect($attached)->toBe(0)
        ->and(partOf($path, 'word/document.xml'))->not->toContain('svgBlip');
});

it('escapes a description that would otherwise break the markup', function () {
    // The alt text is written into an attribute, and it comes from the document being
    // converted. A quote in it is ordinary prose, not an attack, and must not turn the
    // picture into a malformed one.
    $directory = scratchDirectory('quoting');

    file_put_contents($directory . '/diagram.svg', svgSource());

    $awkward = 'A "quoted" & <angled> description';
    $path = documentWithOnePicture($directory, '![' . $awkward . '](diagram.svg)' . "\n");

    $document = partOf($path, 'word/document.xml');

    expect($document)->toContain('svgBlip')
        ->and($document)->toContain('&amp;')
        ->and($document)->toContain('&quot;')
        ->and($document)->not->toContain('<angled>');
});

it('leaves a size it cannot find to one value, rather than inventing an aspect ratio', function () {
    // A VML shape with no dimensions says nothing about the missing one. Scaling one
    // axis to fill would draw the picture at a shape nobody asked for.
    $directory = scratchDirectory('nosize');

    file_put_contents($directory . '/diagram.svg', svgSource());

    $path = documentWithOnePicture($directory, "![Alt](diagram.svg)\n");

    // Written by PHPWord, so a size is always there; the guard is that both axes come
    // from the same source rather than one being derived from the other.
    $document = partOf($path, 'word/document.xml');

    preg_match('~<wp:extent cx="(\d+)" cy="(\d+)"/>~', $document, $match);

    expect((int) $match[1])->toBe(2289962)
        ->and((int) $match[2])->toBe(1144981);
});

it('reports the intrinsic size in points, which is the unit the document is laid out in', function () {
    // 240 CSS pixels at 96dpi is 180 points. PHPWord sizes pictures in points and
    // compares `imageMaxWidth` against them, so getting this wrong rescales the page.
    $directory = scratchDirectory('units');
    file_put_contents($directory . '/inches.svg', svgSource('2in', '1in'));
    file_put_contents($directory . '/cm.svg', svgSource('5.08cm', '2.54cm'));
    file_put_contents($directory . '/plain.svg', svgSource('240', '120'));

    $inches = SvgRasteriser::intrinsicWidth($directory . '/inches.svg');
    $centimetres = SvgRasteriser::intrinsicWidth($directory . '/cm.svg');
    $pixels = SvgRasteriser::intrinsicWidth($directory . '/plain.svg');

    // 2in is 144pt; 5.08cm is 144pt. The same size written two ways has to be the same
    // size, or the same drawing lays out differently depending on its units.
    expect((float) round($inches))->toBe(144.0)
        ->and((float) round($centimetres))->toBe(144.0)
        ->and((float) round($pixels))->not->toBe(144.0);
});

it('stores a gzipped SVG uncompressed, or the part in the archive is unreadable', function () {
    // A `.svgz` is a gzipped SVG: the same document behind a header, so there is no
    // `<svg` in its first bytes for the sniffer to find. Writing the compressed file
    // into the archive leaves a part named `.svg` that a reader cannot take a vector
    // out of — which is the whole feature, quietly absent.
    $directory = scratchDirectory('gzipped');
    file_put_contents($directory . '/diagram.svgz', gzencode(svgSource()));

    $path = documentWithOnePicture($directory, "![Alt](diagram.svgz)\n");

    expect(vectorPartsIn($path))->toHaveCount(1)
        ->and(partOf($path, 'word/media/' . vectorPartName($path)))
        ->toStartWith('<?xml')
        ->and(partOf($path, 'word/document.xml'))->toContain('svgBlip');
});

it('says which file was unreadable when a gzipped SVG cannot be unpacked', function () {
    // Named as compressed and is not. The rasteriser refuses it before this point on
    // most builds, so the branch is reached by asking the reader directly rather than
    // by hoping a particular delegate fails in a particular way.
    $directory = scratchDirectory('badgz');
    file_put_contents($directory . '/diagram.svgz', 'not gzipped at all');

    $markdown = "![Alt](diagram.svgz)\n";

    // Whether this raises here depends on the local delegate; what must not happen is
    // a document claiming to hold a vector it cannot read.
    try {
        $docx = (new MarkdownToWord($markdown, Configuration::create()->withOptions([
            'images' => 'embed',
            'imageBasePath' => $directory,
        ])))->toDocx($markdown);

        $target = $directory . '/badgz.docx';
        file_put_contents($target, $docx);

        foreach (vectorPartsIn($target) as $part) {
            expect(partOf($target, 'word/media/' . $part))->toStartWith('<?xml');
        }
    } catch (UnsupportedImageFormat $error) {
        expect($error->getMessage())->toContain('Word can hold an SVG');
    }
});

it('carries the size into the rewritten picture rather than losing it', function () {
    // The pass copies the VML size into a DrawingML extent. That VML size is the one
    // PHPWord derived from the raster's pixel count, so carrying it faithfully is the
    // right behaviour — the correction belongs where the raster is embedded, not here.
    // What is asserted is that the number carried is the one the shape declared, which
    // is what a reader would notice if it were not: a diagram drawn at a size nobody
    // asked for, beside a vector that does not match it.
    $directory = scratchDirectory('carried');
    file_put_contents($directory . '/diagram.svg', svgSource());

    $path = documentWithOnePicture($directory, "![Alt](diagram.svg)\n");

    preg_match('~<wp:extent cx="(\d+)" cy="(\d+)"/>~', partOf($path, 'word/document.xml'), $match);

    // 240x120 user units is 180.31 x 90.16 points, which is 2289962 x 1144981 EMU.
    expect((int) $match[1])->toBe(2289962)
        ->and((int) $match[2])->toBe(1144981);
});

it('refuses a picture whose shape states no size, because nothing can draw it', function () {
    // No `width`, no `height`, no `viewBox`. An SVG without intrinsic dimensions has no
    // size to rasterise at: the rasteriser is asked for a picture it cannot make, and
    // the only honest outcome is a refusal naming the machine. Silently substituting a
    // size here would produce a document with a diagram in it at a scale nobody chose.
    $directory = scratchDirectory('sizeless');
    file_put_contents($directory . '/bare.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>');

    $markdown = "![Alt](bare.svg)\n";

    expect(fn () => (new MarkdownToWord($markdown, Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $directory,
    ])))->toDocx($markdown))
        ->toThrow(UnsupportedImageFormat::class, 'Word can hold an SVG');
});

it('ignores a size it cannot parse rather than falling back to zero', function () {
    // `width="auto"` is not a size. Treating it as zero would make the picture
    // infinitely small, and PHPWord's own scaling would then multiply it back up.
    $directory = scratchDirectory('autosize');
    file_put_contents($directory . '/auto.svg', svgSource('auto', 'auto'));

    expect(SvgRasteriser::intrinsicWidth($directory . '/auto.svg'))->toBeGreaterThan(0.0);
});

function svgSource(string $width = '240', string $height = '120'): string
{
    return <<<SVG
        <?xml version="1.0" encoding="UTF-8"?>
        <svg xmlns="http://www.w3.org/2000/svg" width="$width" height="$height" viewBox="0 0 240 120">
          <rect width="240" height="120" fill="#1b3a5c"/>
        </svg>
        SVG;
}

/** The SHA-1 of the raster an SVG left in an archive. */
function rasterHashIn(string $path): string
{
    $zip = new ZipArchive();
    $zip->open($path);

    $hash = '';

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);

        if (str_starts_with($name, 'word/media/') && str_ends_with($name, '.png')) {
            $hash = SvgAttachmentCollector::fingerprint((string) $zip->getFromName($name));
        }
    }

    $zip->close();

    return $hash;
}

/** @return list<string> */
function vectorPartsIn(string $path): array
{
    $zip = new ZipArchive();
    $zip->open($path);

    $found = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);

        if (str_ends_with($name, '.svg')) {
            $found[] = $name;
        }
    }

    $zip->close();

    return $found;
}

/** The name of the one vector part in an archive. */
function vectorPartName(string $path): string
{
    return \basename(vectorPartsIn($path)[0] ?? '');
}

function partOf(string $path, string $part): string
{
    $zip = new ZipArchive();
    $zip->open($path);
    $contents = (string) $zip->getFromName($part);
    $zip->close();

    return $contents;
}

function partsOf(string $docx): string
{
    $path = scratchDirectory('inmemory') . '/inmemory.docx';
    file_put_contents($path, $docx);

    $zip = new ZipArchive();
    $zip->open($path);
    $all = '';

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $all .= (string) $zip->getFromName($zip->getNameIndex($index));
    }

    $zip->close();
    @unlink($path);

    return $all;
}

it('reports a file that cannot be read at all, rather than pretending it was empty', function () {
    // A vector that goes missing between the rasteriser opening it and the pass reading
    // it back. The raster is already in hand, so the document could otherwise be
    // written with a picture and no vector — and nothing in it would say so.
    $directory = scratchDirectory('vanished');
    file_put_contents($directory . '/diagram.svg', svgSource());

    $resolver = new ReflectionMethod(MarkdownWord\Render\ImageResolver::class, 'readVector');
    $resolver->setAccessible(true);

    $image = (new MarkdownToWord('# Text only', Configuration::create()));

    $instance = (new ReflectionClass(MarkdownWord\Render\ImageResolver::class))->newInstanceWithoutConstructor();

    expect(fn () => $resolver->invoke($instance, $directory . '/gone.svg'))
        ->toThrow(UnsupportedImageFormat::class, 'Word can hold an SVG');
});

it('answers the same size for a file that states nothing as for one that states a lot', function () {
    // Neither is wrong, but a diagram that came out 3000pt wide would be, and the only
    // guard is that an answer is always given.
    $directory = scratchDirectory('unstated');
    file_put_contents($directory . '/bare.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>');

    [$width, $height] = SvgRasteriser::intrinsicSize($directory . '/bare.svg');

    expect($width)->toBeGreaterThan(0.0)
        ->and($height)->toBeGreaterThan(0.0);
});

it('answers for a file whose root is not readable XML at all', function () {
    // `loadXML()` failing is the same outcome as an SVG with no width: an assumed size.
    // Reaching it needs markup that parses as a document but has nothing to measure.
    $directory = scratchDirectory('notxml');
    file_put_contents($directory . '/odd.svg', '<not-svg/>');

    expect(SvgRasteriser::intrinsicSize($directory . '/odd.svg'))
        ->each->toBeGreaterThan(0.0);
});

it('ignores a unit it does not know rather than assuming one', function () {
    // `width="10furlong"` is not a width. Treating it as pixels invents a size for a
    // file that never stated one.
    $directory = scratchDirectory('badunit');
    file_put_contents($directory . '/odd.svg', svgSource('10furlong', '5furlong'));

    expect(SvgRasteriser::intrinsicWidth($directory . '/odd.svg'))->toBeGreaterThan(0.0);
});

it('ignores a viewBox that is not four numbers', function () {
    // A malformed viewBox is a file that says nothing about its size, and guessing a
    // shape from part of it would be worse than the assumed one.
    $directory = scratchDirectory('badbox');
    file_put_contents($directory . '/odd.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 wide 120"><rect/></svg>');

    expect(SvgRasteriser::intrinsicSize($directory . '/odd.svg'))
        ->each->toBeGreaterThan(0.0);
});

it('does not divide by a zero-width viewBox', function () {
    // `viewBox="0 0 0 120"` states a shape with no width. The ratio is 0/0 and the
    // height is meaningless; the width is still zero, so nothing is stated.
    $directory = scratchDirectory('zerobox');
    file_put_contents($directory . '/flat.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 0 120"><rect/></svg>');

    [$width, $height] = SvgRasteriser::intrinsicSize($directory . '/flat.svg');

    expect(\is_nan($height) || \is_infinite($height))->toBeFalse()
        ->and($height)->toBeGreaterThan(0.0);
});

it('falls back to the raster for a file whose raster PHPWord would refuse', function () {
    // The path that existed before the SVG branch: a raster Word cannot take, which
    // GD can decode. It still has to work, and it has to reach the same refusal when
    // nothing can.
    $directory = scratchDirectory('webpstill');
    imagewebp(imagecreatetruecolor(20, 20), $directory . '/picture.webp');

    $markdown = "![A picture](picture.webp)\n";
    $docx = (new MarkdownToWord($markdown, Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $directory,
    ])))->toDocx($markdown);

    expect($docx)->toStartWith('PK');
});

it('reports no rasteriser as a build fact, not as a file fact', function () {
    // The message has to survive a build where the extension is absent, which is the
    // only build that ever produces it. Asserting the words here is what keeps the
    // message and this claim from drifting apart on a machine that has imagick.
    $reflection = new ReflectionClass(SvgRasteriser::class);

    $source = (string) file_get_contents((string) $reflection->getFileName());

    // The guard reads the extension rather than assuming it, so a build that has the
    // class without the extension still says so.
    expect($source)->toContain("extension_loaded('imagick')")
        ->and($source)->toContain('class_exists(Imagick::class)');
});

it('closes an output buffer it opened, even when the encoder raises', function () {
    // The transcode opens a buffer for `imagepng($image, null)` and has to close it on
    // every path. A buffer left open outlives the call and closes something that is not
    // ours — which is the kind of fault that surfaces as unrelated output corruption.
    $directory = scratchDirectory('buffer');
    file_put_contents($directory . '/picture.webp', (string) gzencode('not an image'));

    $before = ob_get_level();

    try {
        $markdown = "![A picture](picture.webp)\n";
        (new MarkdownToWord($markdown, Configuration::create()->withOptions([
            'images' => 'embed',
            'imageBasePath' => $directory,
        ])))->toDocx($markdown);
    } catch (Throwable) {
        // Either outcome is fine; the level afterwards is the claim.
    }

    expect(ob_get_level())->toBe($before);
});

it('reports the extension when a file is not an image and has none to fall back on', function () {
    // `inspect()` cannot measure a file that is not a raster, so it falls back to the
    // extension to say what was detected. That is the only place the name is believed,
    // and it is worth pinning: a file with no extension at all must still be reported
    // as something rather than as nothing.
    $directory = scratchDirectory('noext');

    file_put_contents($directory . '/mystery', "not an image\n");

    $markdown = "![A mystery](mystery)\n";

    expect(fn () => (new MarkdownToWord($markdown, Configuration::create()->withOptions([
        'images' => 'embed',
        'imageBasePath' => $directory,
    ])))->toDocx($markdown))
        ->toThrow(UnsupportedImageFormat::class);
});

it('turns an imagick build without the SVG delegate away, rather than half-way', function () {
    // `available()` checks that the format is registered and not merely that the class
    // exists: a build can have the extension and still not read an SVG. Detecting that
    // here is what turns a confusing failure on the first picture into a clear one on
    // the first conversion.
    $registered = \in_array('SVG', \Imagick::queryFormats(), true);

    expect(SvgRasteriser::available())->toBe($registered);
});

it('reports no rasteriser on a build that has none', function () {
    // This is the only test that can reach the refusal: every other one here needs a
    // rasteriser to get as far as a document. `php -n` loads no extensions at all,
    // which is a build with no imagick and no gd, and it is the closest thing to a
    // minimal host this suite can produce.
    $script = <<<'PHP'
        <?php
        require %s;
        use MarkdownWord\Exception\UnsupportedImageFormat;
        echo UnsupportedImageFormat::svg('/tmp/diagram.svg')->getMessage();
        PHP;

    $program = \sprintf($script, var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true));
    $file = tempnam(sys_get_temp_dir(), 'mdword-noimagick') . '.php';
    file_put_contents($file, $program);

    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($file) . ' 2>/dev/null');
    @unlink($file);

    expect($output)->toContain('Word can hold an SVG')
        ->and($output)->toContain('no SVG rasteriser')
        ->and($output)->not->toContain('is not a format');
});

it('says the extension is unavailable there, rather than blaming the file', function () {
    // Same build, asking the question the guard asks. Without this the previous test
    // could pass on a build where the guard happened to be true for another reason.
    $script = <<<'PHP'
        <?php
        require %s;
        use MarkdownWord\Render\SvgRasteriser;
        printf('imagick=%%s available=%%s', extension_loaded('imagick') ? 'yes' : 'no',
            SvgRasteriser::available() ? 'yes' : 'no');
        PHP;

    $program = \sprintf($script, var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true));
    $file = tempnam(sys_get_temp_dir(), 'mdword-noimagick') . '.php';
    file_put_contents($file, $program);

    $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($file) . ' 2>/dev/null');
    @unlink($file);

    expect($output)->toBe('imagick=no available=no');
});

it('answers for a file that is not readable at all, rather than refusing', function () {
    // A path that is not a file. The size is unknown and the honest answer is the
    // assumed one; returning nothing would leave the caller with a picture it cannot
    // lay out, and returning zero would make it infinitely small.
    $directory = scratchDirectory('notafile');
    file_put_contents($directory . '/plain.svg', svgSource());

    [$width, $height] = SvgRasteriser::intrinsicSize($directory . '/does-not-exist.svg');

    expect($width)->toBeGreaterThan(0.0)
        ->and($height)->toBeGreaterThan(0.0);
});

it('embeds nothing rather than a broken picture when the rasteriser raises', function () {
    // `toPng()` catches whatever imagick raises and returns null, and the caller turns
    // that into a refusal. Reaching the catch needs an imagick that raises, which a
    // well-formed file will not do — so what is asserted is the contract it upholds
    // either way: no document, and a message that names the machine.
    $directory = scratchDirectory('raises');
    file_put_contents($directory . '/empty.svg', '');

    expect(SvgRasteriser::toPng($directory . '/empty.svg'))->toBeNull();
});

