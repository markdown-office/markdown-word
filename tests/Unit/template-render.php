<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Template\ContentFallback;
use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\Exception\NoContentControl;
use MarkdownWord\Exception\TemplateNotFound;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\Upstream;
use MarkdownWord\Tests\Support\TemplateFactory;

/*
 * Filling in a template writes a document for every block it inserts, so the
 * filter for the one known upstream deprecation has to span the whole test
 * rather than wrap the final save.
 */
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

it('reports a missing template', function () {
    expect(fn () => new MarkdownTemplate('/does/not/exist.docx'))
        ->toThrow(TemplateNotFound::class);
});

it('values are substituted', function () {
    $output = renderTemplate(Scratch::remember(TemplateFactory::report()), ['reference' => 'REF-1']);

    expect(TemplateFactory::textOf($output))->toContain('REF-1');
});

it('markdown is inserted where the placeholder sits', function () {
    $output = renderTemplate(
        Scratch::remember(TemplateFactory::report()),
        ['reference' => 'REF-1'],
        static function (MarkdownTemplate $template): void {
            $template->insert("First line\n\nSecond line");
        },
    );

    expect(TemplateFactory::textOf($output))->toContain('First line');
    expect(TemplateFactory::textOf($output))->toContain('Second line');
});

it('headings keep their structure inside a template', function () {
    $output = renderTemplate(
        Scratch::remember(TemplateFactory::placeholder()),
        [],
        static function (MarkdownTemplate $template): void {
            $template->insert("# Title\n\nBody text");
        },
    );

    expect(TemplateFactory::xmlOf($output))->toContain('w:val="Heading1"');
});

it('lists survive template insertion', function () {
    $output = renderTemplate(
        Scratch::remember(TemplateFactory::placeholder()),
        [],
        static function (MarkdownTemplate $template): void {
            $template->insert("- one\n- two");
        },
    );

    expect(TemplateFactory::xmlOf($output))->toContain('w:numPr');
    expect(TemplateFactory::textOf($output))->toContain('one');
});

it('lists keep their numbering inside a template', function () {
    $output = renderTemplate(
        Scratch::remember(TemplateFactory::placeholder()),
        [],
        static function (MarkdownTemplate $template): void {
            $template->insert("- one\n- two");
        },
    );

    $document = TemplateFactory::xmlOf($output);
    $numbering = TemplateFactory::xmlOf($output, 'word/numbering.xml');

    expect($document)->toContain('w:numId');

    // The numbering definition the paragraphs point at has to exist in the
    // template's own numbering part, or the bullets disappear.
    preg_match('/<w:numId w:val="(\d+)"\/>/', $document, $reference);
    expect($reference)->not->toBeEmpty();
    expect($numbering)->toContain(sprintf('<w:num w:numId="%s">', $reference[1]));
    expect($numbering)->toContain('w:numFmt w:val="bullet"');
});

it('different lists get distinct numbering', function () {
    $output = renderTemplate(
        Scratch::remember(TemplateFactory::placeholder()),
        [],
        static function (MarkdownTemplate $template): void {
            $template->insert("- bullets");
            $template->insert("1. numbers");
        },
    );

    $numbering = TemplateFactory::xmlOf($output, 'word/numbering.xml');

    // Reusing one identifier for both would make the bullets render as
    // numbers, or the numbers continue where the bullets left off.
    expect(substr_count($numbering, 'w:numFmt w:val="bullet"'))->toBe(NUMBERING_LEVELS);
    expect(substr_count($numbering, 'w:numFmt w:val="decimal"'))->toBe(NUMBERING_LEVELS);
});

it('repeated identical lists share one definition', function () {
    $output = renderTemplate(
        Scratch::remember(TemplateFactory::placeholder()),
        [],
        static function (MarkdownTemplate $template): void {
            $template->insert("- first");
            $template->insert("- second");
        },
    );

    // Identical lists need only one definition; duplicating them would bloat
    // the file for no benefit.
    expect(substr_count(TemplateFactory::xmlOf($output, 'word/numbering.xml'), 'w:numFmt w:val="bullet"'))->toBe(NUMBERING_LEVELS);
});

it('tables survive template insertion', function () {
    $output = renderTemplate(
        Scratch::remember(TemplateFactory::placeholder()),
        [],
        static function (MarkdownTemplate $template): void {
            $template->insert("| a | b |\n| --- | --- |\n| 1 | 2 |");
        },
    );

    expect(TemplateFactory::xmlOf($output))->toContain('<w:tbl>');
});

it('template styles can be targeted by name', function () {
    $output = renderTemplate(
        Scratch::remember(TemplateFactory::styled()),
        [],
        static function (MarkdownTemplate $template): void {
            $template->insert('# Title');
        },
        Configuration::create()->withStyles([Styles::HEADING_1 => 'Corp Title']),
    );

    expect(TemplateFactory::xmlOf($output))->toContain('w:val="Corp Title"');
});

it('a repeated row is written as a table in the Markdown', function () {
    // What replaced `repeat()`: there is no row-cloning any more, so a list of records
    // is a table in the document and the template styles it. The alternative — keeping
    // a region dialect alongside Word's fields — is two ways to fill a template, which
    // is what this removed.
    $output = renderTemplate(
        Scratch::remember(TemplateFactory::placeholder()),
        [],
        static function (MarkdownTemplate $template): void {
            $template->insert("| Item | Price |\n| --- | ---: |\n| Licence | 1,200 |\n| Support | 300 |");
        },
    );

    $text = TemplateFactory::textOf($output);

    expect($text)->toContain('Licence');
    expect($text)->toContain('1,200');
    expect(TemplateFactory::xmlOf($output))->toContain('<w:tbl>');
});

it('appends the content when the template names no place for it', function () {
    // The case that decides whether the library is any use against a template it did
    // not write: a letterhead made in Word has no control to find, because nobody
    // making one inserts a content control.
    $output = renderTemplate(
        TemplateFactory::letterhead(),
        [],
        static fn (MarkdownTemplate $t) => $t->insert("# X"),
    );

    expect(TemplateFactory::bodyTextOf($output))
        ->toContain('X')
        ->toContain('Yours sincerely');
});

it('appends below the template’s own closing matter, which is the whole trade', function () {
    // Documented rather than avoided, because the alternative is a document with none of
    // the caller's content in it. A template that cares about the position says where it
    // is, with a control, and then the content goes there instead.
    $output = renderTemplate(
        TemplateFactory::letterhead(),
        [],
        static fn (MarkdownTemplate $t) => $t->insert("# X"),
    );

    $text = TemplateFactory::bodyTextOf($output);

    expect(strpos($text, 'Yours sincerely'))
        ->toBeLessThan(strpos($text, 'X') ?: PHP_INT_MAX);
});

it('still fills the fields in a template that names no place', function () {
    // The two are separate: a template can have values and no region, and refusing it
    // for the region would throw away the fields it does have.
    $output = renderTemplate(
        TemplateFactory::letterhead(),
        ['client' => 'Contoso Industrial', 'reference' => 'REF-9'],
        static fn (MarkdownTemplate $t) => $t->insert("# X"),
    );

    $text = TemplateFactory::bodyTextOf($output);

    expect($text)
        ->toContain('Contoso Industrial')
        ->toContain('REF-9')
        ->not->toContain('«client»')
        ->not->toContain('«reference»');
});

it('a template with nowhere for the content says so when asked to insist', function () {
    $renderer = new MarkdownTemplate(TemplateFactory::noControl());
    $renderer->insert('# X')->contentFallback(ContentFallback::Error);

    expect(fn () => saveTemplate($renderer, Scratch::path('never')))
        ->toThrow(NoContentControl::class, 'It does have: terms');
});

it('a template with no control at all is still refused when asked to insist', function () {
    $renderer = new MarkdownTemplate(TemplateFactory::letterhead());
    $renderer->insert('# X')->contentFallback(ContentFallback::Error);

    // Naming what it does have is the difference between "one tag away" and "nowhere".
    expect(fn () => saveTemplate($renderer, Scratch::path('never')))
        ->toThrow(NoContentControl::class);
});

it('plain hyperlinks survive template insertion', function () {
    $output = renderTemplate(
        Scratch::remember(TemplateFactory::placeholder()),
        [],
        static function (MarkdownTemplate $template): void {
            $template->insert('[docs](https://example.com)');
        },
    );

    expect(TemplateFactory::hyperlinkCount($output))->toBe(1);
    expect(TemplateFactory::targetsOf($output))->toContain('https://example.com');
});

it('hyperlinks with emphasis survive template insertion', function () {
    $output = renderTemplate(
        Scratch::remember(TemplateFactory::placeholder()),
        [],
        static function (MarkdownTemplate $template): void {
            $template->insert('[**bold** link](https://example.com)');
        },
    );

    // The placeholder must not leak into the finished document.
    expect(TemplateFactory::xmlOf($output))->not->toContain('MDWL');
    expect(TemplateFactory::hyperlinkCount($output))->toBe(1);
    expect(TemplateFactory::targetsOf($output))->toContain('https://example.com');
    expect(TemplateFactory::textOf($output))->toContain('bold');
});

it('emphasised hyperlink keeps its bold run', function () {
    $output = renderTemplate(
        Scratch::remember(TemplateFactory::placeholder()),
        [],
        static function (MarkdownTemplate $template): void {
            $template->insert('[**bold** link](https://example.com)');
        },
    );

    $xml = TemplateFactory::xmlOf($output);
    $start = strpos($xml, '<w:hyperlink');

    expect($start)->not->toBeFalse();
    expect(substr($xml, $start, 400))->toContain('<w:b/>');
});

it('inserting empty markdown leaves the template intact', function () {
    $output = renderTemplate(
        Scratch::remember(TemplateFactory::placeholder()),
        [],
        static function (MarkdownTemplate $template): void {
            $template->insert('');
        },
    );

    expect($output)->toBeFile();
});

it('to string produces a document', function () {
    $template = new MarkdownTemplate(Scratch::remember(TemplateFactory::placeholder()));
    $template->insert('# Title');

    $bytes = renderTemplateToString($template);

    expect($bytes)->toStartWith('PK');
    expect(strlen($bytes))->toBeGreaterThan(1000);
});

it('finds a theme beside the document it is given', function () {
    // The template renders a document of its own, so the directory that document was
    // read from has to reach the converter behind it — otherwise a document naming a
    // theme beside it is refused by a template and accepted by a plain conversion.
    $directory = Scratch::directory() . '/beside';
    mkdir($directory . '/themes/house', 0o777, true);
    file_put_contents($directory . '/themes/house/theme.yml', "name: House\n");

    $template = new MarkdownTemplate(
        Scratch::remember(TemplateFactory::placeholder()),
        theme: 'house',
        directory: $directory,
    );

    $template->insert('# Title');

    expect(renderTemplateToString($template))->toStartWith('PK');
});

/**
 * Fill in a template and write the result, returning the path to it.
 *
 * @param array<string, string>                $values
 * @param null|callable(MarkdownTemplate): void $configure
 */


function renderTemplate(
    string $template,
    array $values,
    ?callable $configure = null,
    ?Configuration $config = null,
): string {
    $target = Scratch::path('out');

    $processor = new MarkdownTemplate($template, $config ?? new Configuration(), $values);

    if ($configure !== null) {
        $configure($processor);
    }

    saveTemplate($processor, $target);

    return $target;
}

/**
 * Write a filled-in template out. The wrapper is only here for the upstream
 * deprecation described in tests/Pest.php.
 */
function saveTemplate(MarkdownTemplate $template, string $path): void
{
    withoutUpstreamDeprecations(static function () use ($template, $path): void {
        $template->save($path);
    });
}

function renderTemplateToString(MarkdownTemplate $template): string
{
    return withoutUpstreamDeprecations(static fn (): string => $template->toString());
}
