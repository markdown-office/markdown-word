<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Exception\Exception;
use MarkdownWord\Exception\InvalidInput;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\NothingToConvert;
use MarkdownWord\Exception\UnreadableDocument;
use MarkdownWord\Exception\UnreadableFile;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Reverse\Options;
use MarkdownWord\Reverse\Package;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\Upstream;
use MarkdownWord\WordToMarkdown;
use MarkdownWord\Writer\HyperlinkPass;
use MarkdownWord\Writer\ImageDescriptionPass;
use MarkdownWord\Writer\NumberingMerger;
use MarkdownWord\Writer\OdfHyperlinkPass;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\PhpWord;

/*
 * The exceptions, and the paths that reach them.
 *
 * Every failure this library reports is one of these, and a caller who wants to
 * tell "you gave me the wrong thing" from "the disk is full" can only do that if
 * the types mean something. So they are checked twice over: that each path
 * throws the type it says it does, and that the hierarchy is the one a caller
 * would catch against.
 */
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/** A file that is not a zip archive. */
function notAnArchive(string $name = 'notes.md'): string
{
    $path = Scratch::path($name);
    file_put_contents($path, "# Markdown, not a document\n");

    return $path;
}

/** A zip archive that is not a Word document: it opens, and has nothing in it that one must have. */
function archiveWithoutADocument(string $name = 'empty.docx'): string
{
    $path = Scratch::path($name);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('readme.txt', 'This is a zip, and nothing more.');
    $zip->close();

    return $path;
}

/** A real document with one of its parts replaced by something that is not XML. */
function documentWithBrokenXml(string $name = 'broken.docx'): string
{
    $path = Scratch::path($name);
    (new MarkdownToWord("# Real\n"))->save($path);

    $zip = new ZipArchive();
    $zip->open($path);
    $zip->deleteName('word/document.xml');
    $zip->addFromString('word/document.xml', '<w:document><this is not xml');
    $zip->close();

    return $path;
}

// The paths

it('reports a file that is not an archive as an unreadable document', function () {
    $path = notAnArchive();

    expect(fn () => Package::open($path))
        ->toThrow(UnreadableDocument::class, 'as a zip archive');

    // The same thing through the public entry point, since that is how a caller
    // meets it.
    expect(fn () => (new WordToMarkdown($path))->convert())
        ->toThrow(UnreadableDocument::class);
});

it('reports bytes that are not an archive as an unreadable document', function () {
    expect(fn () => (new WordToMarkdown('plain text, no file'))->convert())
        ->toThrow(UnreadableDocument::class, 'neither a Word document nor the name of one');
});

it('reports an archive that has no document body in it', function () {
    // It opens as a zip, so the failure is later and of a different kind: the
    // archive is intact and the document inside it is not there.
    expect(fn () => Package::open(archiveWithoutADocument())->document())
        ->toThrow(MalformedDocument::class, 'is missing "word/document.xml"');
});

it('reports a document whose XML does not parse', function () {
    expect(fn () => Package::open(documentWithBrokenXml())->document())
        ->toThrow(MalformedDocument::class, 'is not valid XML');
});

it('reports a file it cannot read as such', function () {
    skipWithoutPermissions('A file with no read bit is still readable as root');

    $path = Scratch::path('unreadable');
    file_put_contents($path, "# Secret\n");
    chmod($path, 0o000);

    expect(fn () => (new MarkdownToWord($path))->convert())
        ->toThrow(UnreadableFile::class, 'Unable to read');
});

it('reports being given nothing to convert', function () {
    expect(fn () => (new MarkdownToWord())->convert())
        ->toThrow(NothingToConvert::class, 'There is no Markdown to convert');

    expect(fn () => (new WordToMarkdown())->convert())
        ->toThrow(NothingToConvert::class, 'There is no document to convert');
});

it('reports a media directory it cannot create', function () {
    skipWithoutPermissions('A path whose parent is a file is still usable as root');

    $document = Scratch::path('media', '.docx');
    saveDocument("# Real\n", $document);

    // A path whose parent is a file, not a directory: the directory can neither
    // be found nor made.
    $blocker = Scratch::path('blocker');
    file_put_contents($blocker, 'not a directory');

    $options = Options::fromArray(['mediaDirectory' => $blocker . '/assets']);

    expect(fn () => (new WordToMarkdown($document, $options))->convert())
        ->toThrow(MarkdownWord\Exception\FileNotWritable::class, 'Unable to create the media directory');
});

// The two writer passes
//
// These run over the finished archive, so their failures cannot be reached
// through a conversion that succeeded — the file they are handed is opened again
// and can be anything at all.

it('reports an archive the image pass cannot open', function () {
    expect(fn () => (new ImageDescriptionPass(['A red square']))->applyTo(notAnArchive('not.zip')))
        ->toThrow(UnreadableDocument::class, 'as a zip archive');
});

it('reports a document the image pass cannot parse', function () {
    $pass = new ImageDescriptionPass(['A red square']);

    expect(fn () => $pass->applyTo(documentWithBrokenXml('image-broken.docx')))
        ->toThrow(MalformedDocument::class, 'is not valid XML');
});

it('reports an archive the ODF link pass cannot open', function () {
    // The two ODT passes and the two OOXML passes were written together, and only
    // the ODT link one was left throwing a bare RuntimeException — so a caller
    // catching the type every other one of them raises caught nothing here.
    expect(fn () => (new OdfHyperlinkPass([]))->applyTo(notAnArchive('not.odt')))
        ->toThrow(UnreadableDocument::class, 'as a zip archive');
});

it('reports an ODF document with no content.xml in it', function () {
    // Opens, and the part this pass exists to rewrite is not in it. A caller
    // catching `UnreadableDocument` for both gets this one through it, and has to
    // be able to read which of the two happened from the message.
    expect(fn () => (new OdfHyperlinkPass([]))->applyTo(archiveWithoutADocument('empty.odt')))
        ->toThrow(MalformedDocument::class, 'missing content.xml');
});

it('tells the two failures apart the same way for a .docx as for an .odt', function () {
    // The docx pass is the older of the two and answered both with a bare
    // RuntimeException, which is no type a caller can catch: it is not an
    // `MarkdownWord\Exception\Exception`, so `catch (Exception $e)` around a
    // conversion missed it. Both are still `RuntimeException`s, so anything
    // catching that keeps working.
    $expectations = [
        fn () => (new HyperlinkPass([]))->applyTo(notAnArchive('not-links.docx')),
        fn () => (new OdfHyperlinkPass([]))->applyTo(notAnArchive('not-links.odt')),
    ];

    foreach ($expectations as $apply) {
        expect($apply)->toThrow(UnreadableDocument::class, 'as a zip archive');
    }

    $expectations = [
        fn () => (new HyperlinkPass([]))->applyTo(archiveWithoutADocument('no-parts.docx')),
        fn () => (new OdfHyperlinkPass([]))->applyTo(archiveWithoutADocument('no-parts.odt')),
    ];

    foreach ($expectations as $apply) {
        expect($apply)->toThrow(MalformedDocument::class, 'The document is missing');
    }
});

it('reports an element the dependency has no writer for', function () {
    $merger = new NumberingMerger(new PhpWord());

    expect(fn () => $merger->renderElement(new ElementWithNoWriter()))
        ->toThrow(MarkdownWord\Exception\UnsupportedElement::class, 'has no Word 2007 writer');
});

it('reports an archive the numbering pass cannot open for writing', function () {
    expect(fn () => mergerWithAList()->applyTo(notAnArchive('numbering.zip')))
        ->toThrow(MarkdownWord\Exception\FileNotWritable::class, 'for writing');
});

it('leaves XML the numbering pass cannot parse alone rather than failing over it', function () {
    // `remap()` is handed the template's own XML while a region is being
    // inserted. Refusing to render a document because one rewrite found text it
    // could not parse would be a poor trade, so the text is passed through
    // untouched — a numbering reference in it keeps whatever value it had.
    $xml = '<w:p><w:numPr><w:numId w:val="9"/></w:numPr><not xml</w:p>';

    expect(mergerWithAList()->remap($xml))->toBe($xml);
});

it('reports a numbering part the numbering pass cannot parse', function () {
    // The archive is opened, the list definitions are there to write, and the
    // numbering part it has to extend does not parse.
    $document = Scratch::path('numbering-broken', '.docx');
    saveDocument("- one\n- two\n", $document);

    $zip = new ZipArchive();
    $zip->open($document);
    $zip->deleteName('word/numbering.xml');
    $zip->addFromString('word/numbering.xml', '<w:numbering><unclosed');
    $zip->close();

    expect(fn () => mergerWithAList()->applyTo($document))
        ->toThrow(MalformedDocument::class, 'contains invalid XML');
});

// The public surface

it('converts a document held in memory, as toDocx does the other way', function () {
    $bytes = (new MarkdownToWord())->toDocx("# Through toMarkdown\n");

    // The counterpart of MarkdownToWord::toDocx(), and nothing was calling it.
    expect((new WordToMarkdown())->toMarkdown($bytes))->toContain('# Through toMarkdown');
});

it('gives the configuration and the options back', function () {
    $config = Configuration::create()->withOptions(['tableBorders' => false]);
    $options = Options::fromArray(['tableHeader' => false]);

    expect((new MarkdownToWord(null, $config))->getConfiguration())->toBe($config);
    expect((new WordToMarkdown(null, $options))->getOptions())->toBe($options);
});

// The types

it('puts every failure under one type a caller can catch', function () {
    $types = [
        MarkdownWord\Exception\Exception::class,
        InvalidInput::class,
        NothingToConvert::class,
        UnreadableFile::class,
        UnreadableDocument::class,
        MalformedDocument::class,
        MarkdownWord\Exception\FileNotWritable::class,
        MarkdownWord\Exception\UnsupportedElement::class,
    ];

    foreach ($types as $type) {
        // Extending RuntimeException is what keeps code written before these
        // existed working, so it is part of the contract rather than an accident.
        expect(is_subclass_of($type, RuntimeException::class))->toBeTrue();
    }

    expect(is_subclass_of(InvalidInput::class, Exception::class))->toBeTrue();
    expect(is_subclass_of(NothingToConvert::class, InvalidInput::class))->toBeTrue();
    expect(is_subclass_of(UnreadableFile::class, InvalidInput::class))->toBeTrue();
    expect(is_subclass_of(UnreadableDocument::class, InvalidInput::class))->toBeTrue();

    // A document that is broken is still a document that could not be read, which
    // is worth catching as one thing.
    expect(is_subclass_of(MalformedDocument::class, UnreadableDocument::class))->toBeTrue();
});

it('does not let a caller mistake our side failing for their input being wrong', function () {
    // Full disk, a path with no permission: nothing the caller can fix by passing
    // something different, so it is deliberately not an InvalidInput.
    foreach ([
        MarkdownWord\Exception\FileNotWritable::class,
        MarkdownWord\Exception\UnsupportedElement::class,
    ] as $type) {
        expect(is_subclass_of($type, InvalidInput::class))->toBeFalse();
    }
});

/**
 * An element PHPWord has no Word 2007 writer for, which is the one way its
 * renderer can be asked for something it cannot write.
 */
final class ElementWithNoWriter extends AbstractElement
{
}

/**
 * A merger holding at least one list definition.
 *
 * {@see NumberingMerger::applyTo()} returns before opening anything when there
 * is nothing to write, so these paths need a list to have been collected first.
 */
function mergerWithAList(): NumberingMerger
{
    // Rendered the way MarkdownTemplate does it, so the numbering definitions
    // arrive the way they do in real use.
    $scratch = new PhpWord();
    $section = $scratch->addSection();

    (new MarkdownToWord())->renderIntoContainer("- one\n- two\n", $section, $scratch);

    $merger = new NumberingMerger($scratch);
    $merger->collect();

    expect($merger->hasNumbering())->toBeTrue();

    return $merger;
}
