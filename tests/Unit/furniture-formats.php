<?php

declare(strict_types=1);

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Format;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

/*
 * A header and a footer in each of the three formats.
 *
 * The claim is a table row on the README and it was written backwards once, so it is
 * checked against what each writer actually produced rather than against what the
 * source says the writer supports: `.docx` writes both parts, RTF writes both in its
 * own `{\header`/`{\footer`, and `.odt` writes neither and says so.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * A document declaring both parts, converted to one format.
 *
 * The parser is passed rather than left to the default, because a `header:` block is
 * frontmatter and the default `CommonMarkParser` does not read frontmatter — that is
 * the command line's job. A converter with no parser here would report no loss at all,
 * for the wrong reason.
 */
function furnitureIn(Format $format): array
{
    $markdown = <<<'MD'
        ---
        header:
          align: right
        footer:
          body: |
            Page {page}
        ---

        # Body
        MD;

    $parser = new CommonMarkParser([
        ...CommonMarkParser::FLAVOURS[CommonMarkParser::DEFAULT_FLAVOUR],
        FrontMatterExtension::class,
    ]);

    $converter = new MarkdownToWord($markdown, parser: $parser);
    $path = Scratch::path('furniture-' . $format->extension(), $format->extension());
    $converter->convertTo($format, $path);

    return [$path, array_column($converter->pendingLosses(), 'feature')];
}

/**
 * Whether the written file carries a header and a footer.
 *
 * An `.odt` is a zip and an `.rtf` is not, so the two are asked about differently: the
 * ODT by its parts, the RTF by the control words it writes for them.
 *
 * @return array{0: bool, 1: bool}
 */
function furniturePartsOf(string $path): array
{
    if (str_ends_with($path, '.rtf')) {
        $written = (string) file_get_contents($path);

        // `{\header` and `{\footer`, and not `\header` and `\footer`: a section always
        // carries `\headery` and `\footery` distances in its `\sectd`, whether or not it
        // has either. Asking for the shorter spelling matched those, so a document with
        // no furniture at all came back saying it had both, and the assertion below
        // passed either way.
        return [str_contains($written, '{\header'), str_contains($written, '{\footer')];
    }

    $zip = new ZipArchive();
    $zip->open($path);
    $names = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $names[] = (string) $zip->getNameIndex($index);
    }

    $zip->close();

    return [
        (bool) array_filter($names, static fn (string $n): bool => str_contains($n, 'header')),
        (bool) array_filter($names, static fn (string $n): bool => str_contains($n, 'footer')),
    ];
}

it('writes both parts into a .docx, and reports no loss', function () {
    [$path, $losses] = furnitureIn(Format::Docx);

    expect(furniturePartsOf($path))->toBe([true, true])
        ->and($losses)->not->toContain('header')
        ->and($losses)->not->toContain('footer');
});

it('writes both parts into an .rtf, and reports no loss', function () {
    // RTF has no `Part\Header.php` of the kind `.docx` has, which is how one came to
    // conclude it could not carry a header at all. It writes both, from
    // `Writer\RTF\Part\Document`.
    [$path, $losses] = furnitureIn(Format::Rtf);

    expect(furniturePartsOf($path))->toBe([true, true])
        ->and($losses)->not->toContain('header')
        ->and($losses)->not->toContain('footer');
});

it('reports an .odt losing both, rather than dropping them quietly', function () {
    [$path, $losses] = furnitureIn(Format::Odt);

    expect(furniturePartsOf($path))->toBe([false, false])
        ->and($losses)->toContain('header')
        ->and($losses)->toContain('footer');
});
