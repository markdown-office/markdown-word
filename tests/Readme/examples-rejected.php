<?php

declare(strict_types=1);

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Configuration;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;

/*
 * The rejected example, checked.
 *
 * `examples/markdown/14-rejected.md` is the only example `build.php` does not build,
 * so nothing would otherwise assert that it is still rejected — a validator that
 * quietly stopped rejecting would leave the file as the one thing in `examples/` with
 * no output and no explanation.
 */

use MarkdownWord\Tests\Support\Upstream;

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

function rejected(string $name): ?string
{
    $markdown = (string) file_get_contents(__DIR__ . '/../../examples/markdown/' . $name . '.md');
    $parser = new CommonMarkParser([...CommonMarkParser::FLAVOURS['gfm'], FrontMatterExtension::class]);

    try {
        (new MarkdownToWord($markdown, Configuration::create(), $parser))->toDocx($markdown);
    } catch (Throwable $error) {
        return $error->getMessage();
    }

    return null;
}

it('still refuses the document it is written to refuse', function () {
    $message = rejected('14-rejected');

    expect($message)->not->toBeNull()
        ->and($message)->toContain('Did you mean "blockQuote"?')
        ->and($message)->toContain('Did you mean "codeFont"?');
});

it('reports all four mistakes in it at once', function () {
    $message = (string) rejected('14-rejected');

    foreach (['blockquote', 'code_font', 'maxheadinglevel', 'imagesz'] as $key) {
        expect($message)->toContain($key);
    }

    expect(substr_count($message, 'Unknown'))->toBe(4);
});

it('writes no document for it', function () {
    expect(rejected('14-rejected'))->not->toBeNull();
});

it('converts it once the typos are corrected', function () {
    $markdown = "---\nstyles:\n  blockQuote: Quote\n  codeFont:\n    name: Courier New\n"
        . "options:\n  maxHeadingLevel: 2\n  images: embed\n---\n\n# One\n\n> Quoted\n";

    $parser = new CommonMarkParser([...CommonMarkParser::FLAVOURS['gfm'], FrontMatterExtension::class]);

    expect((new MarkdownToWord($markdown, Configuration::create(), $parser))->toDocx($markdown))
        ->toStartWith('PK');
});