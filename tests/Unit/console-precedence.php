<?php

declare(strict_types=1);

use MarkdownWord\Console\Application;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

/*
 * The command line as the top of the precedence order.
 *
 * The README's table has four sources and the command line at the top of it, and
 * until now nothing could put one there: `ToDocx` folded `--images` and
 * `--table-width` into the configuration file's own layer, so a document's own
 * `tableWidth:` outranked a flag typed next to it. The flag was not wrong about
 * being accepted — it just lost, silently, to a line in a file.
 *
 * The other half is what must *not* outrank the frontmatter: `imageBasePath` is
 * worked out from where the file sits when `--image-base` is not given, and an
 * inferred default must lose to a decision somebody wrote down.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * A document whose frontmatter says the opposite of the flags, so a flag that wins
 * and a flag that loses are told apart by which number ends up in the document.
 */
function precedenceDocument(): string
{
    return inputFile(
        'precedence.md',
        "---\n"
        . "options:\n"
        . "  tableWidth: 4000\n"
        . "  tableBorders: false\n"
        . "  images: skip\n"
        . "  imageBasePath: nowhere\n"
        . "styles:\n"
        . "  codeFont:\n"
        . "    name: Courier New\n"
        . "---\n\n"
        . "# Title\n\n"
        . "| a | b |\n| - | - |\n| 1 | 2 |\n\n"
        . "```\nx = 1\n```\n",
    );
}

/** How wide the first table in a document is, in fiftieths of a percent. */
function tableWidthIn(string $docx): ?string
{
    preg_match('#<w:tblW w:w="(\d+)"#', TemplateFactory::xmlOf($docx), $match);

    return $match[1] ?? null;
}

it('lets a flag outrank the frontmatter it contradicts', function () {
    // The claim the README makes about four sources. Without it `--table-width` is
    // folded in with the configuration file and the document's own 4000 wins.
    $input = precedenceDocument();
    $output = Scratch::path('precedence');

    $run = runCli(['to-docx', $input, '--table-width', '2500', '-o', $output]);

    expect($run['code'])->toBe(0)
        ->and(tableWidthIn($output))->toBe('2500');
});

it('reads the block from the command line at all', function () {
    // Without the extension a leading `---` is a thematic break and the rest of the
    // block arrives as paragraphs of text at the top of the document — which is what
    // `examples/README.md` says happens without it.
    $input = precedenceDocument();
    $output = Scratch::path('read');

    runCli(['to-docx', $input, '-o', $output]);

    $text = TemplateFactory::textOf($output);

    expect($text)->toContain('Title')
        ->and($text)->not->toContain('tableWidth')
        ->and($text)->not->toContain('Courier New')
        ->and(TemplateFactory::xmlOf($output))->toContain('Courier New');
});

it('lets --plain outrank a frontmatter that turns the decoration back on', function () {
    // `--plain` is a bundle rather than one setting, so it is read off
    // `withoutDecoration()` and applied as a layer of its own. A document asking for
    // a code font does not get one.
    $input = inputFile(
        'plain.md',
        "---\noptions:\n  codeBlockShading: true\nstyles:\n  codeFont:\n    name: Courier New\n"
        . "---\n\n```\nx = 1\n```\n",
    );

    $output = Scratch::path('plain');
    $run = runCli(['to-docx', $input, '--plain', '-o', $output]);

    expect($run['code'])->toBe(0)
        ->and(TemplateFactory::xmlOf($output))->not->toContain('Courier New');
});

it('leaves the frontmatter in charge of the values no flag mentions', function () {
    // The half that must not move: a flag nobody typed is not a claim, and the
    // settings the document makes on its own account survive a run that touched
    // something else.
    $input = precedenceDocument();
    $output = Scratch::path('untouched');

    runCli(['to-docx', $input, '--table-width', '2500', '-o', $output]);

    $xml = TemplateFactory::xmlOf($output);

    // `tableBorders: false` and `images: skip` are in the block and no flag touched
    // them; `imageBasePath: nowhere` is a directory that does not exist, which is
    // also what the inferred base would have to beat.
    expect($xml)->not->toContain('<w:tblBorders>')
        ->and($xml)->toContain('Courier New');
});

it('does not let the base path it worked out for itself outrank the block', function () {
    // The inference is the reason `--image-base` exists as a flag: without it the
    // directory the file happens to be in wins, and a document that names its own
    // assets loses to where it was run from.
    Scratch::image('beside.png');

    $input = inputFile(
        'base.md',
        "---\noptions:\n  imageBasePath: here\n---\n\n![Beside](beside.png)\n",
    );

    $wrong = Scratch::path('base-wrong');
    $right = Scratch::path('base-right');

    runCli(['to-docx', $input, '-o', $wrong]);
    runCli(['to-docx', $input, '--image-base', Scratch::directory(), '-o', $right]);

    $media = static fn (string $docx): int => substr_count(TemplateFactory::xmlOf($docx), '<w:pict>');

    expect($media($wrong))->toBe(0)
        ->and($media($right))->toBe(1);
});

it('keeps the typed flag above the inferred one for the base path too', function () {
    $input = precedenceDocument();
    $output = Scratch::path('base-typed');

    runCli(['to-docx', $input, '--image-base', Scratch::directory(), '-o', $output]);

    expect($output)->toBeFile();
});

it('takes the overrides through the template as well', function () {
    // The template builds its Markdown converter in its own constructor, so a flag
    // would have had to be threaded there separately. It is the same argument in the
    // same layer, and the width is the one setting a template does not override.
    $markdown = "---\noptions:\n  tableWidth: 4000\n---\n\n# One\n\n| a | b |\n| - | - |\n| 1 | 2 |\n";
    $templateFile = Scratch::path('template');
    copy(TemplateFactory::placeholder(), $templateFile);

    $output = Scratch::path('template-override');

    $run = runCli([
        'to-docx',
        '-',
        '--template',
        $templateFile,
        '--table-width',
        '2500',
        '-o',
        $output,
    ], $markdown);

    expect($run['code'])->toBe(0)
        ->and(tableWidthIn($output))->toBe('2500');
});

it('reports a conversion it had to make, on standard error', function () {
    // A `.webp` is embedded rather than dropped, but it is re-encoded on the way in
    // and the file that comes out is several times the size of the one that went in.
    // That is a trade the run made on the reader's behalf, so it is said.
    $webp = Scratch::webp();

    if ($webp === null) {
        expect(true)->toBeTrue();

        return;
    }

    $input = inputFile('converted.md', "![A green dot](" . basename((string) $webp) . ")\n");
    $output = Scratch::path('converted');

    $run = runCli(['to-docx', $input, '-o', $output]);

    expect($run['code'])->toBe(0)
        ->and($run['err'])->toContain('image/webp')
        ->toContain('to PNG for embedding')
        // Nothing on standard output but the document, so a piped run is unaffected.
        ->and($run['out'])->toBe('');
});

it('prints a document problem as it stands, without a stack trace', function () {
    // A wrong key in a block is the caller's mistake, not a defect, so it reads like
    // the other ones rather than like a class name and a line in this repository.
    $input = inputFile('rejected.md', "---\noptions:\n  maxheadinglevel: 2\n---\n\n# One\n");
    $run = runCli(['to-docx', $input, '-o', Scratch::path('rejected')]);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('Unknown option "maxheadinglevel"')
        ->and($run['err'])->toContain('Did you mean "maxHeadingLevel"?')
        ->and($run['err'])->toContain('Line 3')
        ->and($run['err'])->not->toContain('src/');
});

it('names the application once in everything it says', function () {
    // Every line the run writes is prefixed the same way, so a mixed batch of
    // progress and problems is still one conversation.
    $input = inputFile('rejected2.md', "---\noptions:\n  images: maybe\n---\n\n# One\n");
    $run = runCli(['to-docx', $input, '-o', Scratch::path('rejected2')]);

    expect($run['err'])->toContain(Application::NAME . ': Line 3: The "images" option is "maybe".');
});