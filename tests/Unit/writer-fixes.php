<?php

declare(strict_types=1);

use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;
use MarkdownWord\WordToMarkdown;
use MarkdownWord\Writer\NumberingMerger;
use MarkdownWord\Writer\Staging;
use PhpOffice\PhpWord\PhpWord;

/*
 * The writer's handling of the file it produces, and of the numbering it copies
 * into another document.
 *
 * Every document is staged in the system temp directory and then moved into
 * place, which is what keeps a half-written archive from ever being the file
 * someone opens — and which is also what makes the staging file a liability of
 * its own: it is a whole document, in a directory every local user can list, for
 * as long as the move takes. The tests below are about that, about the two ways
 * the move itself goes wrong, and about the list identifiers a template brings
 * with it.
 *
 * Writing a document reaches the known upstream deprecation described in
 * tests/Support/Upstream, which is why the filter is installed for the duration
 * of each test rather than around a single call.
 */
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/** Directories a test made unwritable, so that they can be made writable again. */
$GLOBALS['writerFixesBlocked'] = [];

afterEach(function (): void {
    foreach ($GLOBALS['writerFixesBlocked'] ?? [] as $directory) {
        if (is_dir($directory)) {
            chmod($directory, 0o755);
        }
    }

    $GLOBALS['writerFixesBlocked'] = [];
});

// The staging

it('leaves nothing in the temp directory when the document cannot land', function () {
    if (writerFixesIsRoot()) {
        expect(true)->toBeTrue('Running as root, which can write to any directory.');

        return;
    }

    $blocked = writerFixesUnwritableDirectory();

    // A marker of its own, so the leak is found by what the file holds rather
    // than by which files appeared: two test runs on one machine write into the
    // same temp directory, and only one of them is this document.
    $marker = 'mdword-leak-' . bin2hex(random_bytes(8));
    $since = time();

    expect(fn () => saveDocument("# {$marker}\n\n- one\n- two\n", $blocked . '/out.docx'))
        ->toThrow(RuntimeException::class);

    $leaked = [];

    foreach (writerFixesStagedFiles() as $file) {
        clearstatcache(true, $file);

        if (filemtime($file) < $since) {
            continue;
        }

        if (writerFixesHolds($file, $marker)) {
            $leaked[] = basename($file);
        }
    }

    expect($leaked)->toBe([]);
});

it('reports a document it could not put in place as a failure of its own', function () {
    $blocked = writerFixesUnwritableDirectory();

    // A directory that cannot be written to is not the caller's input being
    // wrong, so it belongs with the other things this side gets wrong: the type
    // a caller can catch every failure with.
    expect(fn () => saveDocument('# Unwritable', $blocked . '/out.docx'))
        ->toThrow(FileNotWritable::class);
});

it('does not hand the archive to anyone while it is in flight', function () {
    $staged = writerFixesCall(Staging::class, 'stage', convertTo('# In flight'), 'Word2007', null);

    try {
        // PHPWord's `save()` leaves the file at the process umask, which is 0644
        // for almost everyone: readable by every other account on the machine,
        // for as long as the archive is staged in a shared directory.
        expect(substr(sprintf('%o', fileperms($staged)), -4))->toBe('0600');
    } finally {
        @unlink($staged);
    }
});

it('does not leave a readable copy of the scratch document behind either', function () {
    $scratch = writerFixesCall(NumberingMerger::class, 'scratchFile');

    try {
        expect(substr(sprintf('%o', fileperms($scratch)), -4))->toBe('0600');
    } finally {
        @unlink($scratch);
    }
});

// The move

it('puts the archive in place when it cannot be renamed into it', function () {
    if (writerFixesIsRoot()) {
        expect(true)->toBeTrue('Running as root, whose permissions ignore the directory mode.');

        return;
    }

    $staging = writerFixesDirectory();
    $staged = $staging . '/staged.docx';
    $destination = Scratch::path('moved');

    file_put_contents($staged, 'STAGED');
    file_put_contents($staging . '/probe', 'PROBE');
    writerFixesSeal($staging);

    // The precondition, stated rather than assumed. A directory the process may
    // not write to is one way a file cannot be renamed out of it; a temp
    // directory on another filesystem — which is what a container gives you when
    // the output is a mounted volume — is the other, and the one that reaches
    // this in anger. `rename()` answers EXDEV to both, and `copy()` answers the
    // first.
    expect(@rename($staging . '/probe', $staging . '/probe-moved'))->toBeFalse();

    writerFixesCall(Staging::class, 'move', $staged, $destination);

    expect(file_get_contents($destination))->toBe('STAGED');

    // The staged copy cannot be removed from a directory the process may not
    // write to, which is a thing about this arrangement and not about the move:
    // where a real cross-device move happens the staging file is in the system's
    // own temp directory, and the test below covers that it is removed there.
    @chmod($staging, 0o755);
    @unlink($staging . '/probe');
});

it('moves the archive into place rather than copying it', function () {
    $staged = writerFixesCall(Staging::class, 'stage', convertTo('# Moved'), 'Word2007', null);
    $destination = Scratch::path('moved');

    $inode = fileinode($staged);

    writerFixesCall(Staging::class, 'move', $staged, $destination);

    clearstatcache();

    expect(file_exists($staged))->toBeFalse();
    expect(fileinode($destination))->toBe($inode);
});

it('writes through a symlink rather than replacing it', function () {
    $document = Scratch::path('real');
    $alias = Scratch::path('alias');

    file_put_contents($document, 'ORIGINAL');
    symlink($document, $alias);

    saveDocument("# New content\n", $alias);

    // `rename()` replaces a link with a regular file, so the link is gone and
    // the file it pointed at still holds what it always did: two files, one of
    // them stale, and nothing said so.
    expect(is_link($alias))->toBeTrue();
    expect(TemplateFactory::textOf($document))->toContain('New content');
});

it('creates what a symlink points at, rather than the link', function () {
    $document = Scratch::path('target');
    $alias = Scratch::path('alias');

    // A link to a file that is not there yet is how a deploy says where a
    // document goes; the document is what should appear.
    symlink($document, $alias);

    saveDocument("# New content\n", $alias);

    expect(is_link($alias))->toBeTrue();
    expect(is_file($document))->toBeTrue();
    expect(TemplateFactory::textOf($document))->toContain('New content');
});

// The numbering

it('continues a template that already has a list rather than colliding with it', function () {
    $output = writerFixesRenderInto(TemplateFactory::withList(), "- alpha\n- beta");

    $numbering = TemplateFactory::xmlOf($output, 'word/numbering.xml');

    preg_match_all('/<w:num w:numId="(\d+)"/', $numbering, $ids);

    // A `w:numId` may be defined once only: Word takes the first definition that
    // carries it, so a second is the one that never applies.
    expect($ids[1])->toBe(array_values(array_unique($ids[1])));

    // The paragraphs the Markdown added have to point at their own definition,
    // and it has to be a bullet one.
    preg_match('/<w:numId w:val="(\d+)"\/>/', TemplateFactory::xmlOf($output), $reference);

    expect($reference)->not->toBeEmpty();
    expect($numbering)->toContain(sprintf('<w:num w:numId="%s">', $reference[1]));
    expect(writerFixesNumberingOf($numbering, (int) $reference[1]))->toContain('w:numFmt w:val="bullet"');
});

it('keeps the Markdown list a bulleted list when the template brings its own', function () {
    $output = writerFixesRenderInto(TemplateFactory::withList(), "- alpha\n- beta");

    $markdown = (new WordToMarkdown($output))->convert();

    // The failure this catches is not visible in the XML: the two lists agree on
    // an identifier, so the Markdown list is drawn with the template's decimal
    // format and the two run into one another.
    expect($markdown)->toContain('- alpha');
    expect($markdown)->toContain('- beta');
    expect($markdown)->not->toContain('1)');

    // The template's own list is still its own.
    expect($markdown)->toContain('1. template one');
    expect($markdown)->toContain('2. template two');
});

it('gives the definitions it adds identifiers the template has not taken', function () {
    $output = writerFixesRenderInto(TemplateFactory::withList(), "- alpha\n- beta");

    $numbering = TemplateFactory::xmlOf($output, 'word/numbering.xml');

    // The template's own list keeps numId 1, so the definition added for the
    // Markdown cannot be 1 as well — and the abstract definitions, which the
    // template numbered from 1, are the same problem one level down.
    expect(writerFixesNumIdOf($output, 'alpha'))->toBe(2);
    expect(writerFixesNumIdOf($output, 'template one'))->toBe(1);

    expect(substr_count($numbering, 'w:abstractNumId="1"'))->toBe(1);
    expect(substr_count($numbering, 'w:abstractNumId="2"'))->toBe(1);
    expect(writerFixesNumberingOf($numbering, 1))->toContain('w:numFmt w:val="decimal"');
    expect(writerFixesNumberingOf($numbering, 2))->toContain('w:numFmt w:val="bullet"');
});

it('continues the identifiers a document with many relationships uses', function () {
    $document = writerFixesHostDocument(2000);

    writerFixesMerger()->applyTo($document);

    $rels = TemplateFactory::xmlOf($document, 'word/_rels/document.xml.rels');

    // The document declares two thousand relationships and does not relate the
    // numbering part, so one more has to be added without taking an identifier
    // that is already there.
    expect($rels)->toContain('Id="rId2001"');
});

it('finds the free relationship identifier without a search per one taken', function () {
    $document = writerFixesHostDocument(80000);

    $start = hrtime(true);
    writerFixesMerger()->applyTo($document);
    $elapsed = (hrtime(true) - $start) / 1e9;

    // The obvious way to write this is to count up from 1 and ask whether each
    // identifier is taken, which is a walk of the whole list for every one of
    // them: the cost is quadratic in the number the document happens to have.
    expect($elapsed)->toBeLessThan(
        2.0,
        sprintf('Adding a relationship to 80,000 took %.3f s, which is a search per identifier.', $elapsed),
    );
});

// Helpers

/**
 * Call a method the writer only uses internally.
 *
 * The staging file and the move across a filesystem boundary have no route
 * through the public API: the first is gone before the call returns, and the
 * second needs a directory the process is not allowed to write to.
 */
function writerFixesCall(string $class, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($class, $method))->invoke(null, ...$arguments);
}

function writerFixesIsRoot(): bool
{
    return function_exists('posix_geteuid') && posix_geteuid() === 0;
}

/**
 * A directory in the scratch space, so a test can fill one before sealing it.
 */
function writerFixesDirectory(): string
{
    $directory = Scratch::directory() . '/staging-' . bin2hex(random_bytes(6));

    mkdir($directory, 0o777, true);

    return $directory;
}

/**
 * Take away write permission, and remember how to give it back.
 */
function writerFixesSeal(string $directory): void
{
    chmod($directory, 0o555);

    $GLOBALS['writerFixesBlocked'][] = $directory;
}

/**
 * A directory the process may look in but not write to.
 */
function writerFixesUnwritableDirectory(): string
{
    $directory = writerFixesDirectory();

    writerFixesSeal($directory);

    return $directory;
}

/**
 * @return list<string>
 */
function writerFixesStagedFiles(): array
{
    $files = glob(sys_get_temp_dir() . '/mdword_*') ?: [];

    sort($files);

    return $files;
}

/**
 * Whether a document in the temp directory holds this text.
 *
 * The archive is zipped, so the text is looked for inside it rather than in the
 * bytes of the file, which are deflated.
 */
function writerFixesHolds(string $archive, string $text): bool
{
    $zip = new ZipArchive();

    if ($zip->open($archive) !== true) {
        return false;
    }

    $document = $zip->getFromName('word/document.xml');
    $zip->close();

    return $document !== false && str_contains($document, $text);
}

function writerFixesRenderInto(string $template, string $markdown): string
{
    $output = Scratch::path('rendered');

    $processor = new MarkdownTemplate($template);
    $processor->insert('body', $markdown);

    saveTemplateDocument($processor, $output);

    return $output;
}

function writerFixesNumIdOf(string $docx, string $text): ?int
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadXML(TemplateFactory::xmlOf($docx), LIBXML_NOCDATA);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    foreach ($xpath->query('//w:p') ?: [] as $paragraph) {
        if (!str_contains($paragraph->textContent, $text)) {
            continue;
        }

        $reference = $xpath->query('.//w:numId', $paragraph)?->item(0);

        return $reference === null ? null : (int) $reference->getAttribute('w:val');
    }

    return null;
}

function writerFixesNumberingOf(string $numbering, int $numId): string
{
    preg_match(sprintf('/<w:num w:numId="%d">(.*?)<\/w:num>/s', $numId), $numbering, $match);
    preg_match('/<w:abstractNumId w:val="(\d+)"\/>/', $match[1] ?? '', $abstract);

    preg_match(
        sprintf('/<w:abstractNum w:abstractNumId="%d">(.*?)<\/w:abstractNum>/s', (int) ($abstract[1] ?? -1)),
        $numbering,
        $definition,
    );

    return $definition[1] ?? '';
}

function writerFixesMerger(): NumberingMerger
{
    $scratch = new PhpWord();
    $section = $scratch->addSection();

    (new MarkdownToWord())->renderIntoContainer("- alpha\n", $section, $scratch);

    $merger = new NumberingMerger($scratch);
    $merger->collect();

    return $merger;
}

/**
 * A `.docx` with a list of its own, no numbering relationship, and as many
 * relationships as asked for.
 *
 * Assembled part by part: a document this library writes has six relationships,
 * and the count is the thing under test.
 */
function writerFixesHostDocument(int $relationships): string
{
    $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $package = 'http://schemas.openxmlformats.org/package/2006/relationships';
    $types = 'http://schemas.openxmlformats.org/package/2006/content-types';
    $office = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="' . $w . '"><w:body>'
        . '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr>'
        . '<w:r><w:t>alpha</w:t></w:r></w:p>'
        . '</w:body></w:document>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="' . $package . '">';

    for ($id = 1; $id <= $relationships; $id++) {
        $rels .= sprintf(
            '<Relationship Id="rId%d" Type="%s/image" Target="media/image%d.png"/>',
            $id,
            $office,
            $id,
        );
    }

    $rels .= '</Relationships>';

    $numbering = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:numbering xmlns:w="' . $w . '">'
        . '<w:abstractNum w:abstractNumId="7"><w:lvl w:ilvl="0">'
        . '<w:numFmt w:val="decimal"/></w:lvl></w:abstractNum>'
        . '<w:num w:numId="1"><w:abstractNumId w:val="7"/></w:num>'
        . '</w:numbering>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="' . $types . '">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml"'
        . ' ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '</Types>';

    $root = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="' . $package . '">'
        . '<Relationship Id="rId1" Type="' . $office . '/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';

    $path = Scratch::path('host', '.docx');

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $root);
    $zip->addFromString('word/document.xml', $document);
    $zip->addFromString('word/numbering.xml', $numbering);
    $zip->addFromString('word/_rels/document.xml.rels', $rels);
    $zip->close();

    return $path;
}
