<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Format;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Render\SvgRasteriser;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\Upstream;
use MarkdownWord\Writer\Survey;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/*
 * The two output formats that are not `.docx`, and what each of them drops.
 *
 * Two things are being held to account here. The first is that a format says what
 * it cannot carry: `Format::drops()` is the list, `Survey` decides whether this
 * document used any of it, and `pendingLosses()` is the sentence a caller gets. A
 * loss the document never incurred is not reported, because a document with no
 * tables has nothing to say about table borders.
 *
 * The second is that each claim in that list is true of the file PHPWord writes.
 * Every row below was measured against a real conversion rather than against the
 * writer's source, and the interesting ones are the rows that came out worse than
 * expected: RTF leaves every list item out of the document altogether, and ODT
 * turns an ordered list into a bullet.
 *
 * Writing a document reaches the one known upstream deprecation, described in
 * tests/Support/Upstream.
 */
beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/** A document with one of everything the two formats are asked about. */
const EVERYTHING = <<<'MD'
    # Heading

    Text with `code`, **bold**, *italic*, ~~struck~~ and a [**bold link**](https://example.com/b).

    - bullet one
      - nested
    - bullet two

    1. ordered one
    2. ordered two

    | A | B |
    |:---|--:|
    | 1 | 2 |

    > quoted

    ---

    ```php
    $x = 1;
    ```

    ![The alt text](logo.png)
    MD;

/**
 * The directory relative image paths resolve against, holding a `.png` and a `.jpeg`.
 *
 * Written rather than committed because the tests here are about what a writer does
 * with an image it is given, and a fixture would be a second thing to keep. The `.jpeg`
 * is here because a picture's own name is what a writer reads to decide how to label
 * it, and nothing but a file with that extension can say it.
 */
function formatAssets(): string
{
    $directory = Scratch::directory() . '/assets';

    if (is_file($directory . '/logo.png')) {
        return $directory;
    }

    @mkdir($directory, 0o777, true);

    $image = imagecreatetruecolor(96, 96);
    imagefilledrectangle($image, 0, 0, 96, 96, imagecolorallocate($image, 0x8B, 0x1A, 0x1A));
    imagepng($image, $directory . '/logo.png');

    $photo = imagecreatetruecolor(96, 96);
    imagefilledrectangle($photo, 0, 0, 96, 96, imagecolorallocate($photo, 0x1A, 0x8B, 0x1A));
    imagejpeg($photo, $directory . '/photo.jpg');

    // The one vector of the examples, copied rather than committed again: a document
    // that cannot draw an SVG raises rather than going in flattened, so a build
    // without a rasteriser cannot have one in it at all.
    copy(
        dirname(__DIR__, 2) . '/examples/markdown/assets/19-vector.svg',
        $directory . '/diagram.svg',
    );

    return $directory;
}

function formatDocument(string $markdown = EVERYTHING, array $overrides = []): MarkdownToWord
{
    return new MarkdownToWord($markdown, overrides: [...$overrides, 'options' => [
        'imageBasePath' => formatAssets(),
        'images' => Options::IMAGE_EMBED,
    ]]);
}

/** Every part of a zipped document, keyed by name. */
function namedPartsOf(string $archive): array
{
    $zip = new ZipArchive();
    $zip->open($archive);
    $parts = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $parts[(string) $zip->getNameIndex($index)] = (string) $zip->getFromIndex($index);
    }

    $zip->close();

    return $parts;
}

/**
 * One part of a zipped document. Named apart from the same helper in
 * tests/Unit/svg-edges.php because a Pest file is loaded on its own when it is run
 * on its own, and a helper declared in another file is then simply absent.
 *
 * \@return string
 */
function partOfFormat(string $archive, string $part): string
{
    $zip = new ZipArchive();
    $zip->open($archive);
    $contents = (string) $zip->getFromName($part);
    $zip->close();

    return $contents;
}

/**
 * The RTF handed back as a file, because {@see MarkdownToWord::toRtf()} returns the
 * bytes and the passes that shape them work on a path.
 */
function rtfFile(string $markdown = EVERYTHING): string
{
    $converter = formatDocument($markdown);
    $path = Scratch::path('probe', '.rtf');

    file_put_contents($path, $converter->toRtf($markdown));

    return $path;
}

// The format itself

it('names a writer PHPWord has, and an extension that follows from the name', function (Format $format) {
    // A format whose writer name is wrong produces an empty file rather than an
    // error, so the name is pinned to what IOFactory will resolve.
    expect($format->writer())->toBeIn(['Word2007', 'ODText', 'RTF'])
        ->and($format->extension())->toBe('.' . $format->value)
        ->and(IOFactory::createWriter(new PhpWord(), $format->writer()))->toBeInstanceOf(
            PhpOffice\PhpWord\Writer\WriterInterface::class,
        );
})->with(Format::cases());

it('has a case for every writer the writers directory offers', function () {
    $available = array_map(
        static fn (string $file): string => basename($file, '.Writer.php'),
        glob(dirname(__DIR__, 2) . '/vendor/phpoffice/phpword/src/PhpWord/Writer/*/') ?: [],
    );

    // Not every writer is a document format — EPub3 and HTML are neither, and PDF
    // needs a renderer this library does not ship — so the claim is only that a
    // format here is never a name PHPWord will not resolve.
    $named = array_map(static fn (Format $format): string => $format->writer(), Format::cases());

    expect(array_values(array_intersect($named, $available)))->toBe($named);
});

it('drops nothing for the .docx the library defaults to', function () {
    // `.docx` is the baseline every other format is measured against. A feature
    // listed as dropped here would put a sentence in front of every conversion
    // into a document that lost nothing.
    expect(Format::Docx->drops())->toBe([])
        ->and(Format::Docx->carries())->toHaveCount(count(Format::Docx->carries()))
        ->and(Format::Docx->losses(Survey::of(convertTo(EVERYTHING))))->toBe([]);
});

it('writes a .docx from every path that defaults to one', function () {
    // The default is the writer, and it is asserted on the archive rather than on
    // a file name: the name is derived from the format the command line was told,
    // so a run writing an ODT to a `.docx` passes every check there is on the name.
    $markdown = "# Heading\n";
    $converter = formatDocument($markdown);

    $written = Scratch::path('written', '.docx');
    $converter->convert($written);

    $handed = Scratch::path('handed', '.docx');
    file_put_contents($handed, $converter->toDocx($markdown));

    foreach ([$written, $handed] as $path) {
        $parts = array_keys(namedPartsOf($path));

        expect($parts)->toContain('word/document.xml')
            ->and($parts)->not->toContain('content.xml');
    }
});

it('leaves toDocx() and convert() on the same writer', function () {
    // Two entry points that both say `.docx` and would have to agree; a change to
    // one of them is invisible everywhere else, because the only thing the CLI
    // checks about the result is the name it was asked for.
    $markdown = "# Heading\n";

    $one = new MarkdownToWord($markdown);
    $two = new MarkdownToWord($markdown);

    expect(documentParts($one->toDocx($markdown)))->toBe(documentParts($two->convert()));
});

it('never drops a list numbering without the list itself', function (Format $format) {
    $drops = $format->drops();

    // A writer that leaves the items out has lost the numbering with them, and
    // reporting both says the same fact twice.
    expect(in_array('lists', $drops, true) && in_array('numbered-lists', $drops, true))->toBeFalse();
})->with(Format::cases());

// What the ODT writer carries

it('writes an .odt a reader can open', function () {
    $converter = formatDocument();
    $path = Scratch::path('probe', '.odt');

    $converter->convertTo(Format::Odt, $path);

    $parts = namedPartsOf($path);

    // The mimetype is what a reader checks first, and it is a part of the archive
    // rather than a declaration inside it.
    expect($parts)->toHaveKey('mimetype')
        ->and($parts['mimetype'])->toBe('application/vnd.oasis.opendocument.text')
        ->and($parts)->toHaveKey('content.xml')
        ->and($parts)->toHaveKey('styles.xml');
});

it('carries the whole of a default heading into an .odt', function () {
    // The built-in look is direct formatting with the style id named beside it, so
    // the ODF span carries the size, the weight and the colour, and the paragraph
    // resolves the id for the spacing. Nothing about this depends on a stylesheet
    // the ODF reader has of its own.
    $converter = formatDocument();
    $path = Scratch::path('probe', '.odt');

    $converter->convertTo(Format::Odt, $path);

    $content = partOfFormat($path, 'content.xml');

    preg_match('~<text:p text:style-name="Heading1">(.*?)</text:p>~s', $content, $heading);
    $text = $heading[1] ?? '';

    expect($text)->toContain('Heading')
        ->and($content)->toMatch('~<style:style style:name="T1"[^>]*>\s*<style:text-properties[^>]*fo:font-size="16pt"[^>]*fo:color="#2F5496"[^>]*fo:font-weight="bold"~')
        ->and(partOfFormat($path, 'styles.xml'))->toMatch('~<style:style [^>]*style:name="Heading1"[^>]*style:family="paragraph"[^>]*>\s*<style:paragraph-properties[^>]*fo:margin-top="12pt"~')
        // A default document names no style it depends on, so there is no loss to
        // report about named styles however many of them the slots carry.
        ->and(array_column($converter->pendingLosses(), 'feature'))->not->toContain('named-styles');
});

it('still loses a heading whose slot names a style, and says so', function () {
    // The other half of the row, and the one the Look & Feel cannot close: a
    // template author naming their own style gets the id written into the
    // paragraph, and neither ODF nor RTF has that style to resolve it against.
    $converter = formatDocument(EVERYTHING, ['styles' => ['heading.1' => 'CorpTitle']]);
    $path = Scratch::path('probe', '.odt');

    $converter->convertTo(Format::Odt, $path);

    $content = partOfFormat($path, 'content.xml');

    // The template author's style reaches the paragraph as a name and nothing else:
    // the automatic style inherits from an id the file never defines.
    expect($content)->toContain('style:parent-style-name="CorpTitle"')
        ->and(partOfFormat($path, 'styles.xml'))->not->toContain('style:name="CorpTitle"')
        ->and(array_column($converter->pendingLosses(), 'feature'))->toContain('named-styles');
});

it('keeps bold, italics and strikethrough in an .odt', function () {
    $converter = formatDocument();
    $path = Scratch::path('probe', '.odt');

    $converter->convertTo(Format::Odt, $path);

    $content = partOfFormat($path, 'content.xml');

    expect($content)->toContain('fo:font-weight="bold"')
        ->and($content)->toContain('fo:font-style="italic"')
        ->and($content)->toContain('style:text-line-through-type="single"');
});

it('keeps the alt text of a picture in an .odt', function () {
    // PHPWord writes a `draw:frame` with no `svg:desc` at all, so without the pass
    // the picture is there and the one thing a screen reader reads about it is not.
    $converter = formatDocument();
    $path = Scratch::path('probe', '.odt');

    $converter->convertTo(Format::Odt, $path);

    expect(partOfFormat($path, 'content.xml'))->toMatch('~<draw:frame[^>]*svg:desc="The alt text"~');
});

it('embeds the picture itself in the .odt, not only its description', function () {
    $converter = formatDocument();
    $path = Scratch::path('probe', '.odt');

    $converter->convertTo(Format::Odt, $path);

    $pictures = array_filter(
        array_keys(namedPartsOf($path)),
        static fn (string $name): bool => str_starts_with($name, 'Pictures/'),
    );

    expect($pictures)->not->toBeEmpty()
        ->and(partOfFormat($path, 'content.xml'))->toContain('xlink:href="Pictures/');
});

it('turns a link whose label is emphasised into a real link in an .odt', function () {
    $markdown = "[**bold** label](https://example.com/b)\n";
    $converter = formatDocument($markdown);
    $path = Scratch::path('probe', '.odt');

    $converter->convertTo(Format::Odt, $path);

    $content = partOfFormat($path, 'content.xml');

    // Both halves, because either alone is a defect: a link without the bold, or a
    // bold run with the placeholder still standing in for the link.
    expect($content)->not->toContain('MDWL')
        ->and($content)->toMatch('~<text:a xlink:type="simple" xlink:href="https://example\.com/b">~')
        ->and($content)->toMatch('~<text:span>\s*<style:text-properties>[^>]*fo:font-weight="bold"[^>]*>\s*bold</text:span>~')
        ->and($content)->toMatch('~>\s*label</text:span>~');
});

it('keeps the title of a link in an .odt', function () {
    $markdown = "[label](https://example.com/b \"The title\")\n";
    $converter = formatDocument($markdown);
    $path = Scratch::path('probe', '.odt');

    $converter->convertTo(Format::Odt, $path);

    expect(partOfFormat($path, 'content.xml'))->toContain('xlink:title="The title"');
});

it('writes nested lists as nested lists in an .odt', function () {
    $converter = formatDocument();
    $path = Scratch::path('probe', '.odt');

    $converter->convertTo(Format::Odt, $path);

    // Nesting is what the depth is for: a sub-list inside its parent's item, which
    // is one `text:list` opened inside another's `text:list-item`.
    expect(partOfFormat($path, 'content.xml'))->toMatch('~<text:list-item><text:list[^>]*><text:list-item>~');
});

// What the RTF writer carries

it('writes an .rtf a reader can open', function () {
    $rtf = formatDocument()->toRtf(EVERYTHING);

    expect($rtf)->toStartWith('{\rtf1')
        ->and($rtf)->toEndWith('}');
});

it('leaves the braces of an .rtf balanced after the hyperlink pass has run', function () {
    $rtf = file_get_contents(rtfFile());

    $depth = 0;
    $escaped = false;

    for ($index = 0; $index < strlen($rtf); $index++) {
        $character = $rtf[$index];

        if ($escaped) {
            $escaped = false;

            continue;
        }

        if ($character === '\\') {
            $escaped = true;

            continue;
        }

        $depth += $character === '{' ? 1 : ($character === '}' ? -1 : 0);
    }

    // A field written by the pass with one brace out opens the whole run in every
    // reader after it, which reads as a document that stops halfway down.
    expect($depth)->toBe(0);
});

it('turns a link whose label is emphasised into a real link in an .rtf', function () {
    $markdown = "[**bold** label](https://example.com/b)\n";
    $path = rtfFile($markdown);

    $rtf = file_get_contents($path);

    expect($rtf)->not->toContain('MDWL')
        ->and($rtf)->toContain('HYPERLINK "https://example.com/b"')
        ->and($rtf)->toMatch('~\\\\b[\\\\a-z0-9]* bold~')
        ->and($rtf)->toContain(' label}');
});

it('keeps the title of a link out of the .rtf field it cannot carry', function () {
    // RTF's field has a `\o` switch for a tooltip and PHPWord's writer does not
    // emit it, so the title is dropped. It is not claimed as carried anywhere, and
    // this test is here so that stays true rather than becoming true by accident.
    $markdown = "[label](https://example.com/b \"The title\")\n";

    expect(file_get_contents(rtfFile($markdown)))->not->toContain('The title');
});

it('embeds the picture itself in an .rtf, not only its description', function () {
    $rtf = file_get_contents(rtfFile());

    expect($rtf)->toContain('\shppict')
        ->and($rtf)->toContain('\pict');
});

// What the two cannot carry

it('leaves every list item out of an .rtf, and says so', function () {
    $converter = formatDocument();
    $path = Scratch::path('probe', '.rtf');

    $converter->convertTo(Format::Rtf, $path);
    $losses = $converter->pendingLosses();

    // The text of the item is gone, not just its bullet — which is why this is
    // checked against the file and not only against the report.
    expect(file_get_contents($path))->not->toContain('bullet one')
        ->and(array_column($losses, 'feature'))->toContain('lists');
});

it('writes an ordered list as bullets in an .odt, and says so', function () {
    $converter = formatDocument();
    $path = Scratch::path('probe', '.odt');

    $converter->convertTo(Format::Odt, $path);

    // `Writer\ODText\Style\Numbering` writes `text:list-level-style-bullet`
    // whatever a level's format is, so `%1.` goes out as a bullet character — in
    // the list style, which lives in `styles.xml` rather than in the body.
    expect(partOfFormat($path, 'styles.xml'))->toContain('text:bullet-char="%1."')
        ->and(partOfFormat($path, 'content.xml'))->not->toContain('list-level-style-number')
        ->and(array_column($converter->pendingLosses(), 'feature'))->toContain('numbered-lists');
});

it('writes no table borders in an .odt, and says so', function () {
    $converter = formatDocument();
    $path = Scratch::path('probe', '.odt');

    $converter->convertTo(Format::Odt, $path);

    expect(partOfFormat($path, 'content.xml'))->not->toContain('fo:border')
        ->and(array_column($converter->pendingLosses(), 'feature'))->toContain('table-borders');
});

it('reports a table column\'s alignment as lost in the .odt and as carried in the other two', function () {
    $markdown = "| Left | Right |\n|:-----|------:|\n| a | b |\n";

    foreach (Format::cases() as $format) {
        $converter = formatDocument($markdown);
        $path = Scratch::path('probe', $format->extension());

        $converter->convertTo($format, $path);

        $body = $format === Format::Rtf
            ? (string) file_get_contents($path)
            : partOfFormat($path, $format === Format::Odt ? 'content.xml' : 'word/document.xml');

        $losses = array_column($converter->pendingLosses(), 'feature');

        // The `.odt` is the one that loses it: `Writer\ODText\Part\Content` reads a
        // table as a table and never descends into its cells, so the alignment of a
        // column never becomes a style the cell could point at. The report says so,
        // which is the whole of what was wrong with it.
        if ($format === Format::Odt) {
            expect($body)->not->toContain('fo:text-align')
                ->and($losses)->toContain('cell-alignment');

            continue;
        }

        expect($body)->toContain($format === Format::Docx ? '<w:jc w:val="right"/>' : '\qr')
            ->and($losses)->not->toContain('cell-alignment');
    }
});

it('writes no paragraph background in either format, and says so', function () {
    foreach ([Format::Odt, Format::Rtf] as $format) {
        $converter = formatDocument();
        $path = Scratch::path('probe', $format->extension());

        $converter->convertTo($format, $path);

        $body = $format === Format::Rtf ? file_get_contents($path) : partOfFormat($path, 'content.xml');

        expect($body)->not->toContain('background-color')
            ->and($body)->not->toContain('\cbpat')
            ->and(array_column($converter->pendingLosses(), 'feature'))->toContain('shading');
    }
});

it('carries a default heading into an .rtf, and says nothing about losing it', function () {
    $converter = formatDocument();
    $path = Scratch::path('probe', '.rtf');

    $converter->convertTo(Format::Rtf, $path);
    $rtf = file_get_contents($path);

    // The RTF writer has no stylesheet, so `\s1` can never appear; the size and
    // the weight arrive as run properties instead, which is what a reader sees.
    expect($rtf)->not->toContain('\s1')
        ->and($rtf)->toMatch('~\\\\cf\d+\\\\f\d+\\\\fs32\\\\b~')
        ->and(array_column($converter->pendingLosses(), 'feature'))->not->toContain('named-styles');
});

it('writes a heading whose slot names a style as body text in an .rtf, and says so', function () {
    $converter = formatDocument(EVERYTHING, ['styles' => ['heading.1' => 'CorpTitle']]);
    $path = Scratch::path('probe', '.rtf');

    $converter->convertTo(Format::Rtf, $path);

    // `writeOpening()` wants a `Style\Paragraph` and this library's named styles
    // are `Style\Font`, so a named heading carries neither its size nor its weight.
    expect(file_get_contents($path))->not->toMatch('~\\\\fs32\\\\b~')
        ->and(array_column($converter->pendingLosses(), 'feature'))->toContain('named-styles');
});

it('says nothing about a feature the document never used', function () {
    // A document with no table, no list and no picture loses nothing an .rtf
    // cannot carry, so reporting otherwise would be a warning about a fault that
    // is not there — and the habit of ignoring warnings is how a real one is missed.
    $converter = formatDocument("Just a paragraph of text.\n");

    $converter->convertTo(Format::Rtf, Scratch::path('plain', '.rtf'));

    expect($converter->pendingLosses())->toBe([]);
});

it('reports a loss in the words of the reader of the document', function () {
    $converter = formatDocument();
    $converter->convertTo(Format::Rtf, Scratch::path('probe', '.rtf'));

    foreach ($converter->pendingLosses() as $loss) {
        // A sentence naming a file, a class or a method is a note to the next
        // reader of the source. This one is read by whoever opens the document.
        expect($loss->message)->not->toContain('Writer\\')
            ->and($loss->message)->not->toContain('::')
            ->and((string) $loss)->toBe($loss->message);
    }
});

// The two lists, held together

/**
 * Every feature there is, and the sentence a writer that cannot do it loses.
 *
 * Read out of the class rather than written out here: a second list would be one
 * that could drift from the first, and the whole point of the three tests below is
 * that the two are the same.
 *
 * @return array<string, string>
 */
function formatFeatures(): array
{
    $constant = (new ReflectionClass(Format::class))->getReflectionConstant('FEATURES');

    expect($constant)->toBeInstanceOf(
        ReflectionClassConstant::class,
        'Format has to say what every feature is.',
    );

    return $constant->getValue();
}

/**
 * The documents that between them use every feature there is.
 *
 * @return list<array{markdown: string, config: Configuration, parser: CommonMarkParser|null}>
 */
function formatSurveyedDocuments(): array
{
    $assets = formatAssets();

    return [
        [
            // The body of a document: the things the walk of the element tree finds.
            'markdown' => <<<'MD'
                # Heading

                Text with `code`, **bold**, *italic* and ~~struck~~.

                - bullet one
                - bullet two

                1. ordered one
                2. ordered two

                | A | B |
                |:---|--:|
                | 1 | 2 |

                > quoted

                ---

                ```php
                $x = 1;
                ```

                ![The alt text](logo.png)

                ::toc

                ::textbox[Hello]{width=6 height=3}

                ::shape{type=rect width=6 height=3}

                ::field{name="Customer"}

                ::section{columns=2}

                Ahead of plan :footnote[n=12] in the west.
                MD,
            'config' => Configuration::create()->withOptions([
                'imageBasePath' => $assets,
                'images' => Options::IMAGE_EMBED,
            ]),
            'parser' => null,
        ],
        [
            // The furniture a document describes about itself, and a slot named after
            // a style of the caller's own rather than the built-in look's.
            'markdown' => <<<'MD'
                ---
                styles:
                  heading.1: CorpTitle
                header:
                  body: |
                    Running head
                footer:
                  body: |
                    Running foot
                ---

                # Heading
                MD,
            'config' => Configuration::create(),
            'parser' => CommonMarkParser::withFrontMatter(),
        ],
        [
            // The two pictures whose names and kinds are read off the file itself.
            'markdown' => <<<'MD'
                ![A photo](photo.jpg)

                ![A diagram](diagram.svg)
                MD,
            'config' => Configuration::create()->withOptions([
                'imageBasePath' => $assets,
                'images' => Options::IMAGE_EMBED,
            ]),
            'parser' => null,
        ],
    ];
}

/**
 * The features a document reports as lost when it is written as the given format.
 *
 * @return list<string>
 */
function formatSurveyedLosses(array $document, Format $format): array
{
    $converter = new MarkdownToWord(
        $document['markdown'],
        $document['config'],
        $document['parser'],
    );

    $converter->convertTo($format, Scratch::path('survey', $format->extension()));

    return array_column($converter->pendingLosses(), 'feature');
}

it('drops nothing it cannot report', function (Format $format) {
    // A name a format drops that is not a feature is a loss that is never reported:
    // `Format::losses()` walks the features and asks the survey about each of them,
    // so a name it does not know is never asked about and never said.
    $features = formatFeatures();

    foreach ($format->drops() as $dropped) {
        expect($features)->toHaveKey($dropped);
    }
})->with(Format::cases());

it('drops every feature somewhere', function () {
    // The other half of the same question: a feature no format drops is a feature
    // nothing is ever lost in, and the sentence about it is one nobody reads.
    $dropped = [...Format::Odt->drops(), ...Format::Rtf->drops()];

    expect(array_values(array_diff(array_keys(formatFeatures()), $dropped)))->toBe([]);
});

it('reports every feature a document used', function () {
    // The half that needs a document: a feature the survey is never asked about is
    // never reported, whatever a format drops. The documents above use every feature
    // there is between them, and the two formats that drop something drop all of it
    // between them.
    $reported = [];

    foreach (formatSurveyedDocuments() as $document) {
        foreach ([Format::Odt, Format::Rtf] as $format) {
            $reported = [...$reported, ...formatSurveyedLosses($document, $format)];
        }
    }

    $required = array_keys(formatFeatures());

    if (!SvgRasteriser::available()) {
        // A build that cannot draw an SVG cannot have one in a document either: the
        // conversion raises rather than going in flattened, so there is no document
        // here that could carry the vector and lose it.
        $required = array_values(array_diff($required, ['svg-vector']));
    }

    expect(array_values(array_diff($required, $reported)))->toBe([]);
});