<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Theme\ThemeResolver;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;
use Symfony\Component\Yaml\Yaml;

/*
 * The theme the examples draw from, and the file it is read from.
 *
 * A theme is one file, and `28-inline-styles` is the example that draws from one.
 * The page says which file that is and what the file carries, so both are read here
 * from the file itself rather than from the page: a page that names a theme file the
 * library does not read is the failure this test exists for.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/** The repository, which is where the example's own theme is looked for. */
const EXAMPLE_ROOT = __DIR__ . '/../..';

/** The themes that ship with the library. */
const EXAMPLE_THEMES = EXAMPLE_ROOT . '/themes';

/**
 * The Markdown of the inline styles example, read from where the examples keep their input.
 */
function exampleThemeMarkdown(): string
{
    return (string) file_get_contents(EXAMPLE_ROOT . '/examples/markdown/28-inline-styles.md');
}

/**
 * The theme file the library reads for a name, and what it says.
 *
 * The resolver is given a working directory of its own, so the theme that comes back
 * is the one that ships with the library rather than one the machine happens to have.
 *
 * @return array{path: string, written: array<string, mixed>}
 */
function exampleThemeFile(string $name): array
{
    $resolver = new ThemeResolver(workingDirectory: Scratch::path('example-theme', ''));
    $theme = $resolver->resolve($name);

    $path = $theme->directory() . '/theme.yml';

    return ['path' => $path, 'written' => (array) Yaml::parseFile($path)];
}

/**
 * The example as a document, drawn in the theme it names.
 */
function exampleThemeDocument(): string
{
    $document = Scratch::path('example-theme');

    (new MarkdownToWord(
        exampleThemeMarkdown(),
        Configuration::create(),
        CommonMarkParser::withFrontMatter(),
        directory: EXAMPLE_ROOT,
    ))->save($document);

    return $document;
}

it('the example names a theme, and the file behind that name is the one the page points at', function () {
    // The front matter of the example, and the theme it names.
    expect(exampleThemeMarkdown())->toContain('theme: corporate');

    // The file, resolved the way the library resolves a bare name — so the page and
    // the library cannot drift apart without one of them failing here.
    $file = exampleThemeFile('corporate');

    expect($file['path'])->toBe(realpath(EXAMPLE_THEMES . '/corporate/theme.yml'))
        // And the page says the same.
        ->and((string) file_get_contents(EXAMPLE_ROOT . '/examples/README.md'))
        ->toContain('themes/corporate/theme.yml');
});

it('the theme file carries what the page says it carries', function () {
    $file = exampleThemeFile('corporate');

    // The keys the page's table names, and no others: the table is the file's own
    // shape, and a key it does not mention would be a key the page does not describe.
    expect(array_keys($file['written']))->toBe(['name', 'description', 'author', 'styles', 'textStyles'])
        ->and($file['written']['name'])->toBe('Corporate')
        ->and($file['written']['description'])->toBeString()->not->toBeEmpty()
        ->and($file['written']['author'])->toBeString()->not->toBeEmpty();

    // The slots the page names, and the named styles it names.
    expect($file['written']['styles'])->toHaveKeys(['heading.1', 'paragraph', 'codeFont'])
        ->and($file['written']['textStyles'])->toHaveKeys(['brand', 'muted']);
});

it('the words in the document are in the styles the file names', function () {
    $file = exampleThemeFile('corporate');

    $xml = TemplateFactory::xmlOf(exampleThemeDocument());

    // The colours the file says, rather than a pair written into the test: a theme
    // that changed its colours has to change this document with it.
    $brand = 'w:color w:val="' . $file['written']['textStyles']['brand']['color'] . '"';
    $muted = 'w:color w:val="' . $file['written']['textStyles']['muted']['color'] . '"';

    expect(str_contains($xml, $brand))->toBeTrue('the brand colour the theme file names is not in the document')
        ->and(str_contains($xml, $muted))->toBeTrue('the muted colour the theme file names is not in the document');
});

it('the other themes that ship with the library are one file each as well', function () {
    foreach (['default', 'minimal', 'report'] as $name) {
        $file = exampleThemeFile($name);

        // The same file, and the keys every theme carries — a theme that brings no
        // named styles has no `textStyles:` section.
        expect(array_keys($file['written']))->toContain('name', 'description', 'author', 'styles')
            ->and($file['written'])->not->toHaveKey('textStyles')
            // And no file beside it: the format this library used to have is refused
            // rather than read, so one left here would make the theme unreadable.
            ->and(is_file(EXAMPLE_THEMES . '/' . $name . '/styles.yml'))->toBeFalse();
    }
});
