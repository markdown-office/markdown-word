<?php

declare(strict_types=1);

use MarkdownWord\Console\Application;
use MarkdownWord\Document\Frontmatter;
use MarkdownWord\Exception\TemplateNotFound;
use MarkdownWord\Filesystem\PathScope;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Template\TemplateFile;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;
use MarkdownWord\WordPackage;

/*
 * `template_file:` in a document's own `---` block.
 *
 * The README showed a document naming a template this way and `Frontmatter` carried a
 * docblock explaining the key, and nothing read it: the block was parsed, the key was
 * kept, and a document with no template at all came out — silently, and with every
 * word in the right place, so a text-level check could not have seen it.
 *
 * The key is a path a document writes, so most of what follows is about where it may
 * point rather than about rendering. That is not an extra rule invented here: it is
 * the rule a `theme:` has had all along, for the same reason.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

/**
 * A Markdown file in a directory of its own, naming a template beside itself.
 *
 * A directory per test rather than the shared scratch one, because a
 * `template_file:` resolves beside the document: two tests writing the same
 * filename into one directory would resolve to whichever ran last.
 *
 * @return array{0: string, 1: string} the Markdown, and the directory holding it
 */
function notesNaming(string $template = 'report.dotx', string $block = ''): array
{
    $directory = ownDirectory();
    $path = $directory . '/notes.md';

    file_put_contents(
        $path,
        "---\ntemplate_file: {$template}\n{$block}---\n\n# From the frontmatter\n\nbody text\n",
    );

    return [$path, $directory];
}

/** A scratch directory no other test is using. */
function ownDirectory(string $name = 'case'): string
{
    $directory = Scratch::path($name, '');

    if (!mkdir($directory, 0o777, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create "%s".', $directory));
    }

    return $directory;
}

it('reads the key out of the block', function () {
    $document = CommonMarkParser::withAllExtensions()
        ->parse("---\ntemplate_file: report.dotx\n---\n\n# X\n");

    expect(Frontmatter::fromDocument($document)->templateFile())->toBe('report.dotx');
});

it('has no template to read in a block that names none', function () {
    $document = CommonMarkParser::withAllExtensions()->parse("# X\n");

    expect(Frontmatter::fromDocument($document)->templateFile())->toBeNull();
});

it('renders into the template the document names', function () {
    [$markdown, $directory] = notesNaming();

    // A template carrying text of its own, so the assertion can tell a document
    // rendered into it from the same Markdown rendered into nothing: the heading is
    // in the output either way, so a test that only looked for it would pass against
    // a build that ignored the key entirely.
    copy(
        TemplateFactory::dotx('document22.xml', 'report-dotx', TemplateFactory::report()),
        $directory . '/report.dotx',
    );

    // The report template's region is `${summary}`, and `--region` says which one; the
    // default is `body`, which this template does not have.
    $run = runCli([
        'to-docx', $markdown,
        '-o', $directory . '/out.docx',
    ]);

    expect($run['code'])->toBe(Application::SUCCESS);

    $text = TemplateFactory::bodyTextOf($directory . '/out.docx');
    expect($text)->toContain('From the frontmatter');
    expect($text)->toContain('Quarterly Report');

    // The region's markers are consumed. `${reference}` is left — it is a single-line
    // macro for `--define`, not part of the region, and a template is allowed to keep
    // one nobody gave a value for.
    expect($text)->not->toContain('${summary}');
    expect($text)->not->toContain('${slot}');
    expect($text)->not->toContain('${/summary}');

    // A `.dotx` named and nothing else: the result is a document, or `to-docx` would
    // be writing something it does not name.
    expect(TemplateFactory::mainContentTypeOf($directory . '/out.docx'))
        ->toBe(WordPackage::DOCUMENT_MAIN);
});

it('lets the flag outrank the block', function () {
    [$markdown, $directory] = notesNaming('nowhere.dotx');
    copy(TemplateFactory::dotx(), $directory . '/report.dotx');

    // The block names a template that is not there. `--template` is the author saying
    // it louder, so the block is not consulted and the run succeeds — the same order
    // the precedence table gives every other setting.
    $run = runCli([
        'to-docx', $markdown,
        '--template', $directory . '/report.dotx',
        '-o', $directory . '/out.docx',
    ]);

    expect($run['code'])->toBe(Application::SUCCESS);
    expect(TemplateFactory::bodyTextOf($directory . '/out.docx'))->toContain('From the frontmatter');
});

it('keeps the block out of the document', function () {
    [$markdown, $directory] = notesNaming();
    copy(TemplateFactory::dotx(), $directory . '/report.dotx');

    runCli(['to-docx', $markdown, '-o', $directory . '/out.docx']);

    expect(TemplateFactory::bodyTextOf($directory . '/out.docx'))->not->toContain('template_file');
});

it('says where it looked when the template is not there', function () {
    [$markdown] = notesNaming('nowhere.dotx');

    $run = runCli(['to-docx', $markdown, '-o', Scratch::path('never')]);

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('The template "nowhere.dotx" does not exist');
    // Naming where it looked is the whole of the fix for a relative name.
    expect($run['err'])->toContain('beside the Markdown');
});

it('refuses a template outside the directories a document may name', function () {
    // The system temporary directory, which is outside this repository and so outside
    // both roots a run has: the working directory and the document's own. The file is
    // real and readable; it is somewhere a document may not send this run.
    $outside = tempnam(sys_get_temp_dir(), 'mdword-outside-');
    copy(TemplateFactory::dotx(), $outside);

    $directory = ownDirectory('escape');
    $markdown = $directory . '/notes.md';
    file_put_contents($markdown, "---\ntemplate_file: {$outside}\n---\n\n# X\n");

    $run = runCli(['to-docx', $markdown, '-o', $directory . '/out.docx']);

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('outside the directories a document may name');
    expect(file_exists($directory . '/out.docx'))->toBeFalse();

    @unlink($outside);
});

it('accepts a document pointing inside the working directory', function () {
    // The boundary is where a path lands, not where it was written from: a document
    // in `docs/` naming a template one directory up is inside the working directory,
    // so it is a template rather than an escape. Refusing it would make containment
    // mean "must be in the document's own directory", which is stricter than the
    // rule a `theme:` has always had.
    $directory = ownDirectory('sibling');
    $sibling = ownDirectory('sibling-template');
    copy(
        TemplateFactory::dotx('document.xml', 'sibling-dotx', TemplateFactory::report()),
        $sibling . '/report.dotx',
    );

    $markdown = $directory . '/notes.md';
    file_put_contents(
        $markdown,
        "---\ntemplate_file: {$sibling}/report.dotx\n---\n\n# X\n",
    );

    $run = runCli([
        'to-docx', $markdown,
        '-o', $directory . '/out.docx',
    ]);

    expect($run['code'])->toBe(Application::SUCCESS);
    // Text only the template carries, so this cannot pass against a build that
    // ignored the key and wrote the Markdown into a document of its own.
    expect(TemplateFactory::bodyTextOf($directory . '/out.docx'))->toContain('Quarterly Report');
});

it('refuses a URL', function () {
    [$markdown] = notesNaming('https://example.com/report.dotx');

    $run = runCli(['to-docx', $markdown, '-o', Scratch::path('never')]);

    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('is a URL');
});

it('has nothing to resolve a relative name against on standard input', function () {
    $run = runCli(
        ['to-docx', '-', '-o', Scratch::path('never')],
        "---\ntemplate_file: report.dotx\n---\n\n# X\n",
    );

    // Guessing the working directory here would render into whatever happened to be
    // there; the message names the two ways to be explicit instead.
    expect($run['code'])->toBe(Application::FAILURE);
    expect($run['err'])->toContain('came from standard input');
    expect($run['err'])->toContain('--template');
});

describe('resolving a template a document named', function () {
    it('finds one beside the document', function () {
        $directory = ownDirectory();
        copy(TemplateFactory::dotx(), $directory . '/report.dotx');

        $resolved = (new TemplateFile($directory))->resolve('report.dotx');

        expect($resolved)->toBe(PathScope::canonical($directory . '/report.dotx'));
    });

    it('takes an absolute path as it stands', function () {
        $directory = ownDirectory();
        copy(TemplateFactory::dotx(), $directory . '/report.dotx');

        expect((new TemplateFile(null))->resolve($directory . '/report.dotx'))
            ->toBe(PathScope::canonical($directory . '/report.dotx'));
    });

    it('names the roots it allows when refusing one, and not where it pointed', function () {
        $root = ownDirectory('contained');
        $elsewhere = ownDirectory('elsewhere');
        file_put_contents($elsewhere . '/secret.dotx', 'not a real package');

        // A link inside the roots pointing out of them: the name a document wrote
        // looks contained and resolves to somewhere it is not, which is the ordinary
        // way a path that reads harmlessly becomes a read of the wrong file.
        $link = $root . '/looks-fine.dotx';
        if (!@symlink($elsewhere . '/secret.dotx', $link)) {
            // `test()` rather than `$this`: Pest types the latter as a
            // `PendingCalls`, where `markTestSkipped()` is not a method, so this is
            // the one call in the file static analysis cannot see.
            test()->markTestSkipped('symlinks are not available here');
        }

        try {
            (new TemplateFile(null, [$root]))->resolve($link);
            expect(false)->toBeTrue('the path should have been refused');
        } catch (TemplateNotFound $refusal) {
            expect($refusal->getMessage())->toContain('outside the directories');
            expect($refusal->getMessage())->toContain($root);

            // Where it resolved to is a path this machine chose and the document had
            // no business asking about; where it was written from is what the author
            // wrote, and that is what the message carries.
            expect($refusal->getMessage())->not->toContain($elsewhere);
        }
    });
});