<?php

declare(strict_types=1);

use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\Tests\Support\MemoryFinder;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

/*
 * The finder example on the page, run.
 *
 * The page says where a document's files come from: a caller that keeps its own
 * somewhere else hands the library a finder and says so. The example on the page is
 * the finder the suite has — a theme and a picture that are nowhere on disk — so the
 * two claims on the page are checked together: the theme reaches the document, and
 * the finder reaches the template as well as the converter.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

it('takes a finder for the converter and for the template', function () {
    $finder = new MemoryFinder([
        'house/theme.yml' => "name: House\n",
        'house/styles.yml' => "heading.1:\n  color: 112233\n",
    ]);

    $markdown = "---\ntheme: house\n---\n\n# Title\n";

    $document = Scratch::path('readme-finder');
    (new MarkdownToWord($markdown, parser: CommonMarkParser::withAllExtensions(), files: $finder))
        ->save($document);

    // The theme's colour, which is nowhere on disk.
    expect(TemplateFactory::xmlOf($document))->toContain('w:color w:val="112233"');

    $template = new MarkdownTemplate(TemplateFactory::placeholder(), files: $finder);
    $template->insert($markdown);

    $filled = Scratch::path('readme-finder-template');
    saveTemplateDocument($template, $filled);

    expect(TemplateFactory::xmlOf($filled))->toContain('w:color w:val="112233"');
});
