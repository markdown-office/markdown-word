<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Exception\InvalidConfiguration;
use MarkdownWord\Exception\InvalidConfigurationValue;
use MarkdownWord\Exception\UnsupportedImageFormat;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Font;

/*
 * The values, not only the keys.
 *
 * tests/Unit/validator.php covers a key that names nothing. This is the other half,
 * and it is the quieter one: a wrong value still renders. `maxHeadingLevel: deep` is
 * cast to 0, clamped to 1, and every heading in the document comes out as body text;
 * `color: "#8B0000"` reaches `w:color` with a `#` in it and Word ignores it; a
 * `space` with a word in it keeps the number beside it and drops the word. Nothing
 * about the finished document says any of it went wrong.
 *
 * So every message has to carry the value, what would have worked instead, and the
 * line — and these tests are written against the whole sentence for that reason.
 */

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Exception\UnknownConfigurationKey;
use MarkdownWord\Parser\CommonMarkParser;

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * Renders a document with the block and returns the configuration problem it raised.
 */
function frontmatterRejection(string $frontmatter): ?string
{
    try {
        renderFrontmatter($frontmatter);
    } catch (InvalidConfiguration $error) {
        return $error->getMessage();
    }

    return null;
}

function renderFrontmatter(string $frontmatter): string
{
    $markdown = "---\n" . $frontmatter . "---\n\n# One\n\n| a |\n| - |\n| b |\n";

    return (new MarkdownToWord($markdown, Configuration::create(), new CommonMarkParser([
        ...CommonMarkParser::FLAVOURS['gfm'],
        FrontMatterExtension::class,
    ])))->toDocx($markdown);
}

it('rejects a colour that is not one, and shows it back', function () {
    // The `#` is the CSS spelling and reaches `w:color` unchanged, which is a value
    // the OOXML schema does not have. Word ignores the colour rather than refusing
    // the file, so the document comes out in whatever the theme says.
    $message = frontmatterRejection("styles:\n  heading.1:\n    color: \"#8B0000\"\n");

    expect($message)->toContain('Line 4: The "color" property of the "heading.1" style is "#8B0000".')
        ->toContain('six hexadecimal digits, as in 8B0000');
});

it('accepts a colour written as six hexadecimal digits, quoted or not', function () {
    // The check is on the digits rather than on the YAML type, because `8B0000` is a
    // string, `808080` is an integer and `000000` is a string again, and all three
    // are the same colour. `0xFF0000` is not: YAML has already made it 16711680.
    expect(frontmatterRejection("styles:\n  heading.1:\n    color: 8B0000\n"))->toBeNull()
        ->and(frontmatterRejection("styles:\n  heading.1:\n    color: \"808080\"\n"))->toBeNull()
        ->and(frontmatterRejection("styles:\n  heading.1:\n    color: 000000\n"))->toBeNull()
        ->and(frontmatterRejection("styles:\n  heading.1:\n    color: 0xFF0000\n"))
        ->toContain('is "16711680"');
});

it('rejects a size that is not a number', function () {
    // `size: large` used to be written into the document as nothing at all, so the
    // heading came out at the size the theme gave it and the block looked ignored.
    expect(frontmatterRejection("styles:\n  heading.1:\n    size: large\n"))
        ->toContain('The "size" property of the "heading.1" style is "large". It is a number.');
});

it('rejects a space with a word in it, and names the key it should be under', function () {
    // `before: "lots"` is dropped and `after: 480` is kept, so the paragraph ends up
    // with half the spacing asked for and no sign of which half went missing.
    $message = frontmatterRejection("styles:\n  heading.1:\n    space:\n      before: \"lots\"\n      after: 480\n");

    expect($message)->toContain('The "space" property of the "heading.1" style')
        ->toContain('"lots" is not a number')
        ->toContain('before: and after:');
});

it('rejects a heading level outside the six Word has styles for', function () {
    // `deep` is `(int) 0`, and the clamp turns 0 into 1, which renders every heading
    // in the document as body text. `9` is the other direction: clamped to 6, so the
    // request to go deeper is quietly dropped.
    expect(frontmatterRejection("options:\n  maxHeadingLevel: deep\n"))
        ->toContain('is "deep". It is a whole number between 1 and 6.')
        ->and(frontmatterRejection("options:\n  maxHeadingLevel: 9\n"))->toContain('is "9". It is a whole number between 1 and 6.')
        ->and(frontmatterRejection("options:\n  maxHeadingLevel: 0\n"))->toContain('between 1 and 6')
        ->and(frontmatterRejection("options:\n  maxHeadingLevel: 3\n"))->toBeNull();
});

it('rejects an image mode that is not one of the three', function () {
    // Anything that is not `embed` falls past the embed branch and comes out as the
    // alt text, which is what `placeholder` does — silently, and for every image in
    // the document at once.
    expect(frontmatterRejection("options:\n  images: maybe\n"))
        ->toContain('The "images" option is "maybe". It is one of: embed, placeholder, skip.');
});

it('rejects a number that is not one, rather than casting it to zero', function (string $key, string $value) {
    // `(float) "wide"` is 0.0 and `(int) "full"` is 0, and 0 means "no scaling" and
    // "let Word size it" — so the document is built and configured as though the
    // request had not been made.
    expect(frontmatterRejection(sprintf("options:\n  %s: %s\n", $key, $value)))
        ->toContain(sprintf('The "%s" option is "%s".', $key, $value));
})->with([
    'image width' => ['imageMaxWidth', 'wide'],
    'table width' => ['tableWidth', 'full'],
]);

it('rejects a boolean option given something that is not one', function () {
    // `(bool) "no"` is true, so a quoted `false` in a block turns an option *on*.
    // The spellings YAML accepts for a boolean are accepted here too, so the mistake
    // that is caught is the value and not the quoting.
    expect(frontmatterRejection("options:\n  tableBorders: \"false\"\n"))->toBeNull()
        ->and(frontmatterRejection("options:\n  tableBorders: \"yes\"\n"))->toBeNull()
        ->and(frontmatterRejection("options:\n  tableBorders: please\n"))
        ->toContain('The "tableBorders" option is "please". It is true or false.');
});

it('rejects an alignment Word has no value for', function () {
    // `setAlignment()` keeps the previous value for anything `Jc` does not hold, so
    // `middle` is written out as the default `left` and nothing says so.
    $message = frontmatterRejection("styles:\n  tableCell:\n    alignment: middle\n");

    expect($message)->toContain('is "middle".')
        ->toContain('center')
        ->toContain('justify');
});

it('rejects an underline style Word has no value for', function () {
    // `squiggly` is the CSS spelling. PHPWord takes any string for `setUnderline()`,
    // so it reaches `w:u` and Word ignores it — the link comes out undecorated.
    $message = frontmatterRejection("styles:\n  linkFont:\n    underline: squiggly\n");

    expect($message)->toContain('The "underline" property of the "linkFont" style is "squiggly".')
        ->toContain('wavy')
        ->and(frontmatterRejection("styles:\n  linkFont:\n    underline: single\n"))->toBeNull();
});

it('rejects a slot holding a list rather than a set of properties', function () {
    // A list under a slot reads as a property named "0", which is not a thing anyone
    // wrote, and the alternatives are twenty names none of which is it.
    expect(frontmatterRejection("styles:\n  heading.1:\n    - size\n    - bold\n"))
        ->toContain('The "heading.1" style is a list: ["size", "bold"]')
        ->toContain('neither the name of a style nor a set of properties');
});

it('rejects a block that is not a mapping at all', function (string $frontmatter, string $shows) {
    // A scalar block raised a TypeError from `array_key_exists()` with a stack trace
    // in it, and a list was ignored without a word — the document came out with the
    // configuration silently absent.
    expect(frontmatterRejection($frontmatter))
        ->toContain('not a mapping of keys')
        ->toContain($shows)
        ->toContain('Line 2');
})->with([
    'the whole block is a list' => ["- a\n- b\n", 'a list'],
    'the whole block is a scalar' => ["hello\n", 'is "hello"'],
    'options is a scalar' => ["options: 3\n", 'The "options" key'],
    'styles is a scalar' => ["styles: Quote\n", 'The "styles" key'],
    'options is a list' => ["options:\n  - tableBorders\n", 'a list: ["tableBorders"]'],
]);

it('reports the block and the keys in it at once, and one type for the batch', function () {
    // The shape of the block and what is inside it are two findings, and a document
    // with both must not take two runs to fix.
    expect(frontmatterRejection("options: 3\nstyles:\n  blockquote: Quote\n"))
        ->toContain('The "options" key of the frontmatter block')
        ->toContain('Unknown style "blockquote"')
        ->toContain('Line 2')
        ->toContain('Line 4');
});

it('gives the line each problem is on, through a comment', function () {
    // A block routinely has a comment above every key in it, and a comment is not a
    // boundary — treating one as the end of a block loses the line for everything
    // after it.
    $message = frontmatterRejection(
        "# What this document is.\n"
        . "options:\n"
        . "  # How deep the headings go.\n"
        . "  maxHeadingLevel: deep\n"
        . "styles:\n"
        . "  heading.1:\n"
        . "    size: large\n",
    );

    expect($message)->toContain('Line 5: The "maxHeadingLevel" option is "deep".')
        ->and($message)->toContain('Line 8: The "size" property');
});

it('reports without a line when the block\'s text is not available', function () {
    // `configurationFor()` is public and can be called on a document parsed
    // elsewhere, where the Markdown is in nobody's hands. The message still has to
    // stand on its own.
    $document = (new CommonMarkParser([
        ...CommonMarkParser::FLAVOURS['gfm'],
        FrontMatterExtension::class,
    ]))->parse("---\noptions:\n  maxHeadingLevel: deep\n---\n\n# One\n");

    try {
        (new MarkdownToWord())->configurationFor($document);

        $message = '';
    } catch (InvalidConfiguration $error) {
        $message = $error->getMessage();
    }

    expect($message)->toContain('The "maxHeadingLevel" option is "deep".')
        ->and($message)->not->toContain('Line ');
});

it('raises a value problem as its own type and a key problem as the old one', function () {
    // `UnknownConfigurationKey` is what a caller has been catching since keys were
    // checked, so it keeps covering them; `InvalidConfiguration` covers both.
    expect(fn () => renderFrontmatter("options:\n  maxheadinglevel: 2\n"))
        ->toThrow(UnknownConfigurationKey::class);

    expect(fn () => renderFrontmatter("options:\n  images: maybe\n"))
        ->toThrow(InvalidConfigurationValue::class);

    expect(fn () => renderFrontmatter("options:\n  images: maybe\n"))
        ->toThrow(InvalidConfiguration::class);
});

it('still writes the document for a block it cannot fault', function () {
    // The check is on the block, not on the document: a block naming a template and
    // a theme is metadata, and none of it is an option.
    $markdown = "---\ntitle: Quarterly\nauthor: Fabi\ntheme: corporate-dark\n"
        . "template_file: report.dotx\noptions:\n  maxHeadingLevel: 3\n  images: skip\n---\n\n# One\n";

    expect((new MarkdownToWord($markdown, Configuration::create(), new CommonMarkParser([
        ...CommonMarkParser::FLAVOURS['gfm'],
        FrontMatterExtension::class,
    ])))->toDocx($markdown))->toStartWith('PK');
});

it('takes its closed sets from PHPWord rather than from a list of its own', function () {
    // `w:jc` and `w:u` are enumerated by the writer, so a copy of either would go
    // stale the day PHPWord adds a value. The message has to say exactly what is
    // accepted without that being a second list to maintain.
    $alignment = array_values(array_filter(
        (new ReflectionClass(Jc::class))->getConstants(),
        is_string(...),
    ));
    sort($alignment);

    $underlines = [];

    foreach ((new ReflectionClass(Font::class))->getConstants() as $name => $value) {
        if (is_string($value) && str_starts_with((string) $name, 'UNDERLINE_')) {
            $underlines[] = $value;
        }
    }

    sort($underlines);

    expect(frontmatterRejection("styles:\n  tableCell:\n    alignment: sideways\n"))
        ->toContain(implode(', ', $alignment))
        ->and(frontmatterRejection("styles:\n  linkFont:\n    underline: sideways\n"))
        ->toContain(implode(', ', $underlines));
});

it('leaves the value objects casting what they always cast', function () {
    // The reason the check lives on the frontmatter and not in `Options`: a config
    // file has been allowed to carry loose values for a decade, and a caller relying
    // on the cast is not going to get an exception instead.
    expect(Options::fromArray(['maxHeadingLevel' => 'deep'])->maxHeadingLevel)->toBe(1)
        ->and(Options::fromArray(['tableWidth' => 'full'])->tableWidth)->toBe(0)
        ->and((new Options())->withAll(['imageMaxWidth' => 'wide'])->imageMaxWidth)->toBe(0.0);
});

it('keeps the value objects\' own properties when one changes', function () {
    // The trap a `with()` built from the defaults falls into: everything the caller
    // had set away from the default silently returns to it.
    $base = Options::fromArray([
        'images' => Options::IMAGE_PLACEHOLDER,
        'imageBasePath' => 'assets',
        'tableBorders' => false,
        'maxHeadingLevel' => 3,
    ]);

    $changed = $base->withTableWidth(2500);

    expect($changed->tableWidth)->toBe(2500)
        ->and($changed->images)->toBe(Options::IMAGE_PLACEHOLDER)
        ->and($changed->imageBasePath)->toBe('assets')
        ->and($changed->tableBorders)->toBeFalse()
        ->and($changed->maxHeadingLevel)->toBe(3);
});

it('converts an image Word cannot embed, and says that it did', function () {
    // The bug this closes: PHPWord has never supported WebP, `addImage()` raised,
    // the throwable was caught, and the document came out looking finished with the
    // picture replaced by its alt text and nothing anywhere saying so.
    $webp = Scratch::webp();

    if ($webp === null) {
        expect(true)->toBeTrue();

        return;
    }

    $config = Configuration::create()->withOptions([
        'images' => Options::IMAGE_EMBED,
        'imageBasePath' => Scratch::directory(),
    ]);

    $converter = new MarkdownToWord('![A green dot](fixture.webp)', $config);
    $target = Scratch::path('webp');
    $converter->save($target);

    $media = mediaParts($target);

    // The picture is there — one part in `word/media`, and it is a PNG rather than
    // the WebP that went in.
    expect($media)->toHaveCount(1)
        ->and(array_key_first($media))->toEndWith('.png')
        ->and(getimagesizefromstring($media[array_key_first($media)]))->not->toBeFalse();

    // And the run told anybody who was looking. The conversion is a decision the run
    // made on the reader's behalf, and it is several times larger than what went in.
    expect($converter->pendingImageConversions())->toHaveCount(1)
        ->and($converter->pendingImageConversions()[0])
        ->toMatchArray(['source' => $webp, 'format' => 'image/webp', 'embeddedAs' => 'PNG'])
        ->and(TemplateFactory::textOf($target))->not->toContain('A green dot');
});

it('refuses an image it has and can use neither way', function () {
    // The other half of the same bug. An SVG, or a truncated download, is a file that
    // is there and unusable, and falling back to its alt text would produce the same
    // document-that-looks-finished this exists to stop.
    $path = Scratch::remember(Scratch::directory() . '/diagram.svg');
    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>');

    $config = Configuration::create()->withOptions([
        'images' => Options::IMAGE_EMBED,
        'imageBasePath' => Scratch::directory(),
    ]);

    expect(fn () => (new MarkdownToWord('![A diagram](diagram.svg)', $config))->convert())
        ->toThrow(UnsupportedImageFormat::class, 'SVG');
});

it('still falls back to the alt text when the file is not there', function () {
    // The line it draws: a file it does not have is a document that has to render,
    // and a remote URL cannot be fetched without an HTTP client. Neither is a
    // mistake in the document, and both have always fallen back.
    $config = Configuration::create()->withOptions([
        'images' => Options::IMAGE_EMBED,
        'imageBasePath' => Scratch::directory(),
    ]);

    $missing = Scratch::path('missing-image');
    (new MarkdownToWord('![Not there](nope.png)', $config))->save($missing);

    $remote = Scratch::path('remote-image');
    (new MarkdownToWord('![Remote](https://example.com/x.png)', $config))->save($remote);

    expect(TemplateFactory::textOf($missing))->toContain('Not there')
        ->and(TemplateFactory::textOf($remote))->toContain('Remote')
        ->and(mediaParts($missing))->toBe([])
        ->and(mediaParts($remote))->toBe([]);
});

/**
 * @return array<string, string> Part name to contents, for `word/media` alone.
 */
function mediaParts(string $docx): array
{
    $zip = new ZipArchive();
    $zip->open($docx);

    $media = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = (string) $zip->getNameIndex($index);

        if (str_starts_with($name, 'word/media/')) {
            $media[$name] = (string) $zip->getFromIndex($index);
        }
    }

    $zip->close();

    return $media;
}