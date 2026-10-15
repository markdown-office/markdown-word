<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

/*
 * Directives through a `.dotx` template.
 *
 * The template renderer copies its elements into the template's document rather than
 * writing a package of its own, so the parts this library writes *beside* a body never
 * travel: a `word/footnotes.xml`, a header part, the field a `TocPass` placeholder
 * stands in for.
 *
 * That is only a problem if the elements still claim otherwise. A footnote drawn into
 * the scratch document and copied across leaves `w:footnoteReference` pointing at a
 * note the package does not contain — a mark the reader follows to nothing — and a
 * `::toc` placeholder is a run of invisible separators and the letters MDWT sitting
 * where a table of contents should be. Both are corruption of a document that opens
 * without complaint, which is the worst kind: nothing looks wrong until it is read.
 *
 * So on this path a directive is drawn as what it is. The content control and the
 * fields still work, and the document still says `::toc` where the author wrote it.
 *
 * The parser is the template's own, which is the default one: the renderer reads with
 * `CommonMarkParser::DEFAULT_FLAVOUR` so that a document naming a directive gets the
 * same syntax whether it is converted or filled into a template. It used to pass one
 * explicitly because the default was `gfm` and did not parse a directive at all — which
 * is how this file came to be the only place in the library where a `::toc` was known
 * to survive, and why nothing checked what the default did with one.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * A document with both directive shapes in it, filled into a template.
 *
 * Built per test rather than once in a `beforeEach`, because Pest types the latter's
 * `$this` as a `PendingCalls` that has no properties — which static analysis finds and
 * a reader would not. {@see \MarkdownWord\Tests\Support\Scratch} empties `tmp/pest`
 * after every test either way.
 */
function fillTemplateWithDirectives(): string
{
    $document = Scratch::path('directive-template', '.docx');

    (new MarkdownTemplate(
        Scratch::remember(TemplateFactory::report()),
        Configuration::create(),
    ))
        ->insert("Hello there :footnote[a note].\n\n::toc\n\n# Chapter\n")
        ->save($document);

    return $document;
}

it('writes no placeholder for a ::toc it is not going to build', function () {
    $document = fillTemplateWithDirectives();

    // Checked as the letters rather than as the marker because the marker is private
    // and the letters are what a reader would see.
    expect(TemplateFactory::xmlOf($document))->not->toContain('MDWT');
});

it('draws a ::toc as the source form rather than dropping it', function () {
    // Dropping it would leave a hole and say nothing. The reader gets the two words
    // the author wrote and can act on them.
    expect(TemplateFactory::xmlOf(fillTemplateWithDirectives()))->toContain('::toc');
});

it('writes no footnote reference to a note the package does not contain', function () {
    $document = fillTemplateWithDirectives();

    expect(TemplateFactory::xmlOf($document))->not->toContain('footnoteReference')
        ->and(TemplateFactory::hasPart($document, 'word/footnotes.xml'))->toBeFalse();
});

it('draws a :footnote as the source form rather than as a bare mark', function () {
    expect(TemplateFactory::xmlOf(fillTemplateWithDirectives()))->toContain(':footnote[a note]');
});

it('still puts the content into the template', function () {
    // The thing this path exists for, and the thing the four above must not break.
    expect(TemplateFactory::textOf(fillTemplateWithDirectives()))->toContain('Chapter');
});
