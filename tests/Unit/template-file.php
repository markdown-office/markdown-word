<?php

declare(strict_types=1);

use MarkdownWord\Document\Frontmatter;
use MarkdownWord\Exception\TemplateNotFound;
use MarkdownWord\Files\DiskFinder;
use MarkdownWord\Filesystem\PathScope;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\Template\TemplateFile;
use MarkdownWord\Tests\Support\MemoryFinder;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;
use MarkdownWord\Tests\Support\Upstream;

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
 *
 * Reading the key and resolving it are all this covers. Honouring it — deciding that
 * a document naming a template is rendered into one, and what then outranks what — is
 * the command line's, and moved with it to `mdoffice`.
 */

beforeEach(fn () => Upstream::install());
afterEach(fn () => Upstream::restore());

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

describe('resolving a template the source holds', function () {
    it('takes a path the source answers for', function () {
        $directory = ownDirectory('found');
        copy(TemplateFactory::dotx(), $directory . '/report.dotx');

        $file = new TemplateFile($directory, files: new DiskFinder(document: [$directory]));

        expect($file->resolve('report.dotx'))->toBe(PathScope::canonical($directory . '/report.dotx'));
    });

    it('stages the bytes a source holds and no file for', function () {
        // The source the finder exists for: a plugin that keeps its templates in a
        // database and never had a directory to hand over. A `.dotx` is a package,
        // and a package is opened as a file.
        $finder = new MemoryFinder(['report.dotx' => (string) file_get_contents(TemplateFactory::dotx())]);

        $file = new TemplateFile(null, files: $finder);
        $path = $file->resolve('report.dotx');

        expect(is_file($path))->toBeTrue()
            ->and(file_get_contents($path))->toBe((string) file_get_contents(TemplateFactory::dotx()));
    });

    it('refuses a path the source answers for outside the roots, in the library\'s words', function () {
        // The finder answers the path; the library holds it to its own scope and
        // refuses it. A finder that refused would throw this package's exception,
        // which the other half of this suite would not catch.
        $outside = ownDirectory('outside-roots');
        file_put_contents($outside . '/report.dotx', 'not a real package');

        $inside = ownDirectory('inside-roots');

        $file = new TemplateFile(null, [$inside], files: new DiskFinder(document: [$outside]));

        expect(fn () => $file->resolve('report.dotx'))
            ->toThrow(TemplateNotFound::class, 'outside the directories');
    });

    it('gives the staged template back when the conversion is over', function () {
        $finder = new MemoryFinder(['report.dotx' => (string) file_get_contents(TemplateFactory::dotx())]);

        $file = new TemplateFile(null, files: $finder);
        $path = $file->resolve('report.dotx');

        expect(is_file($path))->toBeTrue();

        // The conversion, with the template the source held.
        $template = new MarkdownTemplate($path);
        $template->insert("# T\n");
        $template->save(Scratch::path('staged-template', '.docx'));

        $file->release();

        expect(is_file($path))->toBeFalse();
    });

    it('gives the staged template back when the conversion fails', function () {
        // The other path out: the conversion fails, and the file the library made is
        // not left behind for something else to find.
        $finder = new MemoryFinder(['report.dotx' => (string) file_get_contents(TemplateFactory::dotx())]);

        $path = null;

        try {
            $file = new TemplateFile(null, files: $finder);
            $path = $file->resolve('report.dotx');

            expect(is_file($path))->toBeTrue();

            throw new RuntimeException('the conversion failed');
        } catch (RuntimeException) {
            // The resolver goes out of scope with the failure, and the file with it.
            unset($file);
        }

        expect(is_file($path))->toBeFalse();
    });

    it('reports a template the source does not have', function () {
        // A source with nothing for the name is the same failure as a directory with
        // nothing in it: the template is not there, and the name of a different one
        // is the whole of the fix.
        $file = new TemplateFile(ownDirectory('empty'), files: new MemoryFinder());

        expect(fn () => $file->resolve('report.dotx'))
            ->toThrow(TemplateNotFound::class, 'does not exist');
    });

    it('refuses a name that is a URL', function () {
        $file = new TemplateFile(null, files: new MemoryFinder());

        expect(fn () => $file->resolve('https://example.com/report.dotx'))
            ->toThrow(TemplateNotFound::class, 'is a URL');
    });

    it('refuses a relative name when there is no directory to write it from', function () {
        // The Markdown came from standard input, so there is no file beside it and a
        // relative name has nothing to resolve against.
        $file = new TemplateFile(null, files: new MemoryFinder());

        expect(fn () => $file->resolve('report.dotx'))
            ->toThrow(TemplateNotFound::class, 'the Markdown came from standard input');
    });
});
