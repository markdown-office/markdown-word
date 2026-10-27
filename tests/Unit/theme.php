<?php

declare(strict_types=1);

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Document\Frontmatter;
use MarkdownWord\Exception\Exception;
use MarkdownWord\Exception\InvalidConfiguration;
use MarkdownWord\Exception\ThemeNotFound;
use MarkdownWord\Exception\UnknownStyleSlot;
use MarkdownWord\Filesystem\PathScope;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Theme\LoadedTheme;
use MarkdownWord\Theme\Theme;
use MarkdownWord\Theme\ThemeLoader;
use MarkdownWord\Theme\ThemeResolver;

/**
 * The `theme:` a document may name, what a theme is made of, and what a document's own
 * styling does to it.
 *
 * The precedence tests here are the point of the feature rather than a detail of it. A
 * theme that could not be overridden would be a worse way of working than the
 * configuration file it sits beside, because every document would have to be written
 * against whichever theme it was last rendered with.
 */

/** The configuration a converter would render a document with. */
function configurationFor(string $markdown, ?Configuration $config = null, ?array $overrides = null): Configuration
{
    $converter = new MarkdownToWord(
        $markdown,
        $config ?? Configuration::create(),
        CommonMarkParser::withAllExtensions(),
        $overrides,
    );

    return $converter->configurationFor($converter->parse($markdown), $markdown);
}

/**
 * A scratch directory with nothing in it, for a root a document may name a theme in.
 */
function scratchRoot(): string
{
    $root = sys_get_temp_dir() . '/mdword-scratch-' . bin2hex(random_bytes(6));
    mkdir($root, 0o777, true);

    register_shutdown_function(static function () use ($root): void {
        removeTree($root);
    });

    return $root;
}

/**
 * A scratch working directory, with `themes/` in it where a bare theme name is looked for.
 *
 * @return array{0: string, 1: string} The working directory, and its `themes/` directory.
 */
function scratchThemes(): array
{
    $root = scratchRoot();
    $themes = $root . '/themes';
    mkdir($themes, 0o777, true);

    return [$root, $themes];
}

/** @param resource $directory */
function removeTree(string $directory): void
{
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . '/' . $entry;
        is_dir($path) ? removeTree($path) : unlink($path);
    }

    rmdir($directory);
}

/** Write a theme directory, so a test can say what a theme file says. */
function writeTheme(string $themes, string $name, string $theme = "name: Test\n", string $styles = ''): string
{
    $directory = $themes . '/' . $name;
    mkdir($directory, 0o777, true);
    file_put_contents($directory . '/theme.yml', $theme);
    file_put_contents($directory . '/styles.yml', $styles);

    return $directory;
}

it('ships themes that all load, and the default one changes nothing', function () {
    $resolver = new ThemeResolver(workingDirectory: scratchThemes()[0]);
    $baseline = (new Styles())->toArray();

    foreach ($resolver->available() as $name) {
        $styles = $resolver->resolve($name)->styles();

        expect($styles->toArray())->toHaveCount(count($baseline));
    }

    // A `default` theme nobody edits has to be the look the library draws anyway, or
    // naming it is a claim that it is the same document when it is not.
    expect($resolver->resolve('default')->styles()->toArray())->toBe($baseline);
});

it('ships themes that differ from one another in more than a colour', function () {
    $resolver = new ThemeResolver(workingDirectory: scratchThemes()[0]);
    $baseline = (new Styles())->toArray();

    // A theme that changes only a swatch is not a theme; it is the default with a
    // different accent, and picking it would change nothing a reader would notice.
    foreach (['minimal', 'corporate', 'report'] as $name) {
        $styles = $resolver->resolve($name)->styles()->toArray();

        $changed = array_keys(array_filter(
            $styles,
            static fn (mixed $value, string $slot): bool => $value !== $baseline[$slot],
            ARRAY_FILTER_USE_BOTH,
        ));

        expect(count($changed))->toBeGreaterThan(3);
    }
});

it('merges what a theme names into the built-in look rather than replacing the slot', function () {
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'one', styles: "heading.1:\n  color: 112233\n");

    $styles = (new ThemeResolver($root))->resolve('one')->styles();

    expect($styles->get(Styles::HEADING_1))
        ->toMatchArray(['styleName' => 'Heading1', 'color' => '112233'])
        ->and($styles->get(Styles::HEADING_1)['space'])->toBe(['before' => 240, 'after' => 120]);
});

it('lets a theme drop a slot whole the same way a styles block does', function () {
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'one', styles: "blockQuote: null\ncodeFont: MyCode\n");

    $styles = (new ThemeResolver($root))->resolve('one')->styles();

    expect($styles->get(Styles::BLOCK_QUOTE))->toBeNull()
        ->and($styles->get(Styles::CODE_FONT))->toBe('MyCode');
});

it('reads a colour written as six digits and unquoted back as the string it is', function () {
    // YAML reads `404040` as an integer, and a colour is the one value in a style slot
    // that is legitimately written as nothing but digits. Left alone it reaches PHPWord
    // as a number where a string was meant.
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'one', styles: "heading.1:\n  color: 404040\nblockQuote:\n  color: 000000\n");

    $styles = (new ThemeResolver($root))->resolve('one')->styles();

    expect($styles->get(Styles::HEADING_1)['color'])->toBe('404040')
        ->and($styles->get(Styles::BLOCK_QUOTE)['color'])->toBe('000000');
});

it('reports a slot a theme does not have, and names the ones it does', function () {
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'one', styles: "blockquote:\n  italic: true\n");

    expect(fn () => (new ThemeResolver($root))->resolve('one'))
        ->toThrow(UnknownStyleSlot::class);

    try {
        (new ThemeResolver($root))->resolve('one');
    } catch (UnknownStyleSlot $e) {
        expect($e->getMessage())->toContain('Unknown style "blockquote"')
            ->and($e->getMessage())->toContain('Did you mean "blockQuote"?')
            ->and($e->getMessage())->toContain('codeBlock');
    }
});

it('refuses a theme directory with no theme.yml', function () {
    [$root, $themes] = scratchThemes();
    mkdir($themes . '/bare', 0o777, true);

    expect(fn () => (new ThemeResolver($root))->resolve('bare'))->toThrow(ThemeNotFound::class);
});

it('names what does resolve rather than falling back when a theme is not there', function () {
    // The failure this guards against is the quiet one: a document in the default's
    // colours with nothing in the output to say the name was not honoured.
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'house', styles: "heading.1:\n  color: 112233\n");

    expect(fn () => (new ThemeResolver($root))->resolve('missing'))
        ->toThrow(ThemeNotFound::class);

    try {
        (new ThemeResolver($root))->resolve('missing');
    } catch (ThemeNotFound $e) {
        // Local and built-in in one list: a name resolves to one of them and the reader
        // of the list cannot tell which, so listing one kind ahead of the other would
        // say something untrue.
        expect($e->getMessage())->toContain('There is no theme called "missing".')
            ->and($e->getMessage())->toContain('house')
            ->and($e->getMessage())->toContain('corporate');
    }
});

it('reads a theme out of the working directory before the built-in ones', function () {
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'minimal', styles: "heading.1:\n  color: AABBCC\n");

    $resolver = new ThemeResolver($root);

    expect($resolver->resolve('minimal')->styles()->get(Styles::HEADING_1)['color'])->toBe('AABBCC')
        // Both names are offered in one list, because the reader cannot tell which kind
        // of directory a name resolves to.
        ->and($resolver->available())->toContain('minimal')->toContain('corporate');
});

it('reads a theme out of the document\'s own directory before the working directory\'s', function () {
    // The defect this guards against: a document names a theme, the theme sits beside
    // the document, and the name was resolved against the process's working directory
    // — so the theme was found only when the run happened to start in the document's
    // own directory.
    [$document, $documentThemes] = scratchThemes();
    writeTheme($documentThemes, 'house', styles: "heading.1:\n  color: 112233\n");

    [$working, $workingThemes] = scratchThemes();
    writeTheme($workingThemes, 'house', styles: "heading.1:\n  color: 445566\n");

    $resolver = new ThemeResolver(
        workingDirectory: $working,
        documentDirectory: $document,
    );

    expect($resolver->resolve('house')->styles()->get(Styles::HEADING_1)['color'])->toBe('112233');
});

it('reads a theme out of the working directory when the document\'s own has none', function () {
    [$document, $documentThemes] = scratchThemes();
    writeTheme($documentThemes, 'house', styles: "heading.1:\n  color: 112233\n");

    [$working, $workingThemes] = scratchThemes();
    writeTheme($workingThemes, 'kitchen', styles: "heading.1:\n  color: 445566\n");

    $resolver = new ThemeResolver(
        workingDirectory: $working,
        documentDirectory: $document,
    );

    expect($resolver->resolve('kitchen')->styles()->get(Styles::HEADING_1)['color'])->toBe('445566');
});

it('names the themes of the document\'s own directory and of the library together', function () {
    // One list, because a name the resolver would accept and not list is a name a
    // failure message leaves the reader to guess at.
    [$document, $documentThemes] = scratchThemes();
    writeTheme($documentThemes, 'house');

    $resolver = new ThemeResolver(
        workingDirectory: scratchThemes()[0],
        documentDirectory: $document,
    );

    expect($resolver->available())->toContain('house')->toContain('corporate');
});

it('refuses a theme outside the roots, the document\'s own directory included', function () {
    [$outside, $outsideThemes] = scratchThemes();
    writeTheme($outsideThemes, 'secrets');

    [$document] = scratchThemes();
    [$working] = scratchThemes();

    $resolver = new ThemeResolver(
        workingDirectory: $working,
        documentDirectory: $document,
    );

    expect(fn () => $resolver->resolve($outside . '/themes/secrets'))
        ->toThrow(ThemeNotFound::class, 'outside the directories a document may name');
});

it('reads a theme path from beside the document', function () {
    // A path is written from the document's own directory, the way a relative image
    // path and a `template_file:` are.
    [$document, $documentThemes] = scratchThemes();
    writeTheme($documentThemes, 'house', styles: "heading.1:\n  color: 112233\n");

    $resolver = new ThemeResolver(
        workingDirectory: scratchThemes()[0],
        documentDirectory: $document,
    );

    expect($resolver->resolve('./themes/house')->styles()->get(Styles::HEADING_1)['color'])->toBe('112233');
});

it('refuses a theme path that walks out of the document\'s own directory', function () {
    [$outside, $outsideThemes] = scratchThemes();
    writeTheme($outsideThemes, 'secrets');

    [$document] = scratchThemes();

    $resolver = new ThemeResolver(
        workingDirectory: scratchThemes()[0],
        documentDirectory: $document,
    );

    expect(fn () => $resolver->resolve('../' . basename($outside) . '/themes/secrets'))
        ->toThrow(ThemeNotFound::class, 'outside the directories a document may name');
});

it('reads a theme from a root the calling program allowed', function () {
    // A shared library of themes beside the project: the roots widen what a document
    // may read, and a name is looked for in one of them the way it is in the themes
    // that ship with this package.
    $library = scratchRoot();
    writeTheme($library, 'shared', styles: "heading.1:\n  color: 112233\n");

    $resolver = new ThemeResolver(
        workingDirectory: scratchThemes()[0],
        roots: [$library],
    );

    expect($resolver->resolve('shared')->styles()->get(Styles::HEADING_1)['color'])->toBe('112233')
        ->and($resolver->available())->toContain('shared');
});

it('refuses a theme a document points outside the directories it may name', function () {
    // A `theme:` is a path a document writes, and a document naming a directory outside
    // the roots must not load it however innocent the reference looks.
    [$outside, $outsideThemes] = scratchThemes();
    writeTheme($outsideThemes, 'secrets', theme: "name: Secrets\nauthor: /etc/passwd\n");

    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'house');

    expect(fn () => (new ThemeResolver($root))->resolve($outside . '/themes/secrets'))
        ->toThrow(ThemeNotFound::class, 'outside the directories a document may name');
});

it('refuses a theme name that is a URL', function () {
    expect(fn () => (new ThemeResolver(scratchThemes()[0]))->resolve('https://example.com/theme.yml'))
        ->toThrow(ThemeNotFound::class, 'A theme is a directory on this machine, not something to fetch.');
});

it('draws a document in the theme its own frontmatter names', function () {
    $config = configurationFor("---\ntheme: report\n---\n\n# T\n");

    expect($config->getStyles()->get(Styles::PARAGRAPH)['name'])->toBe('Georgia');
});

it('lets the document styles block override the theme it named', function () {
    // The whole point of a theme being a starting point: the one property this document
    // cares about wins, and everything else the theme said is still there.
    $config = configurationFor("---\ntheme: report\nstyles:\n  heading.1:\n    color: FF00FF\n---\n\n# T\n");
    $heading = $config->getStyles()->get(Styles::HEADING_1);

    expect($heading['color'])->toBe('FF00FF')
        // Still the report's heading: its size, its typeface, its spacing.
        ->and($heading['size'])->toBe(18)
        ->and($heading['name'])->toBe('Calibri');
});

it('lets the configuration passed to the converter override the theme', function () {
    $config = configurationFor(
        "---\ntheme: report\n---\n\n# T\n",
        Configuration::create()->withStyles([Styles::HEADING_1 => ['color' => '00FF00']]),
    );
    $heading = $config->getStyles()->get(Styles::HEADING_1);

    expect($heading['color'])->toBe('00FF00')
        ->and($heading['size'])->toBe(18);
});

it('lets the command line override a theme the document named', function () {
    $config = configurationFor(
        "---\ntheme: report\nstyles:\n  heading.1:\n    color: FF00FF\n---\n\n# T\n",
        null,
        ['styles' => [Styles::HEADING_1 => ['color' => '0000FF']]],
    );

    expect($config->getStyles()->get(Styles::HEADING_1)['color'])->toBe('0000FF');
});

it('takes the theme from the converter in code over the one in the block', function () {
    $markdown = "---\ntheme: minimal\n---\n\n# T\n";
    $converter = (new MarkdownToWord($markdown, parser: CommonMarkParser::withAllExtensions()))
        ->withTheme('corporate');

    $config = $converter->configurationFor($converter->parse($markdown), $markdown);

    expect($config->getStyles()->get(Styles::HEADING_1)['name'])->toBe('Georgia');
});

it('draws a document in the theme beside it, whatever the process is working in', function () {
    // The converter is told where the document is: a document handed over as a string
    // has no directory of its own, and the working directory is where the program
    // happens to be rather than where the document is.
    [$document, $documentThemes] = scratchThemes();
    writeTheme($documentThemes, 'house', styles: "heading.1:\n  color: 112233\n");

    [$elsewhere] = scratchThemes();
    $before = getcwd();

    chdir($elsewhere);

    try {
        $markdown = "---\ntheme: house\n---\n\n# T\n";
        $converter = new MarkdownToWord(
            $markdown,
            parser: CommonMarkParser::withAllExtensions(),
            directory: $document,
        );

        $config = $converter->configurationFor($converter->parse($markdown), $markdown);

        expect($config->getStyles()->get(Styles::HEADING_1)['color'])->toBe('112233');
    } finally {
        chdir($before === false ? '.' : $before);
    }
});

it('takes a theme named in code from beside the document', function () {
    // The name is checked when it is given rather than at render time, so a theme that
    // is only beside the document has to be found there.
    [$document, $documentThemes] = scratchThemes();
    writeTheme($documentThemes, 'house', styles: "heading.1:\n  color: 112233\n");

    $converter = (new MarkdownToWord('# T', directory: $document))->withTheme('house');
    $config = $converter->configurationFor($converter->parse('# T'), '# T');

    expect($config->getStyles()->get(Styles::HEADING_1)['color'])->toBe('112233');
});

it('reads the block when no theme was named in code', function () {
    $markdown = "---\ntheme: corporate\n---\n\n# T\n";
    $converter = (new MarkdownToWord($markdown, parser: CommonMarkParser::withAllExtensions()));

    $config = $converter->configurationFor($converter->parse($markdown), $markdown);

    expect($config->getStyles()->get(Styles::HEADING_1)['name'])->toBe('Georgia');
});

it('refuses a theme named in code that is not there', function () {
    // Checked when the name is given rather than at render time, so a typo is reported
    // before the Markdown is read at all.
    expect(fn () => (new MarkdownToWord('# T'))->withTheme('missing'))
        ->toThrow(ThemeNotFound::class);
});

it('draws in the theme it is given and nothing else', function () {
    $resolver = new ThemeResolver(workingDirectory: scratchThemes()[0]);

    $show = fn (Configuration $config): string => sprintf(
        '%s/%s/%s',
        $config->getStyles()->get(Styles::HEADING_1)['name'] ?? 'none',
        $config->getStyles()->get(Styles::HEADING_1)['size'],
        $config->getStyles()->get(Styles::HEADING_1)['color'],
    );

    $minimal = $resolver->resolve('minimal')->styles();
    $corporate = $resolver->resolve('corporate')->styles();
    $report = $resolver->resolve('report')->styles();

    expect($show(Configuration::create()->withTheme($minimal)))->toBe('none/17/000000')
        ->and($show(Configuration::create()->withTheme($corporate)))->toBe('Georgia/22/1F3864');
});

it('takes the last theme given rather than splicing the two together', function () {
    // `withAll()` records what it merged as what the caller wrote, so a theme's slots would
    // otherwise look like the caller's and the first theme would come back over the second —
    // leaving a document with one theme's typeface and another theme's size.
    $resolver = new ThemeResolver(workingDirectory: scratchThemes()[0]);
    $minimal = $resolver->resolve('minimal')->styles();
    $corporate = $resolver->resolve('corporate')->styles();
    $report = $resolver->resolve('report')->styles();

    $heading = fn (Configuration $config): array => $config->getStyles()->get(Styles::HEADING_1);

    expect($heading(Configuration::create()->withTheme($minimal)->withTheme($corporate)))
        ->toMatchArray(['name' => 'Georgia', 'size' => 22, 'color' => '1F3864'])
        // And back again, so it is the *last* theme rather than the first either way.
        ->and($heading(Configuration::create()->withTheme($corporate)->withTheme($minimal)))
        ->toMatchArray(['size' => 17, 'color' => '000000'])
        ->and($heading(Configuration::create()->withTheme($minimal)->withTheme($report)))
        ->toMatchArray(['name' => 'Calibri', 'size' => 18]);
});

it('keeps what the caller wrote across every theme applied', function () {
    $resolver = new ThemeResolver(workingDirectory: scratchThemes()[0]);
    $caller = Configuration::create()->withStyles([Styles::HEADING_1 => ['color' => 'ABCDEF']]);

    $heading = $caller
        ->withTheme($resolver->resolve('report')->styles())
        ->withTheme($resolver->resolve('corporate')->styles())
        ->getStyles()
        ->get(Styles::HEADING_1);

    expect($heading['color'])->toBe('ABCDEF')
        // The last theme's own values, not the first one's and not the defaults'.
        ->and($heading)->toMatchArray(['name' => 'Georgia', 'size' => 22]);
});

it('leaves the theme it was given untouched', function () {
    $resolver = new ThemeResolver(workingDirectory: scratchThemes()[0]);
    $corporate = $resolver->resolve('corporate')->styles();
    $before = $corporate->toArray();

    Configuration::create()->withStyles([Styles::HEADING_1 => ['color' => 'ABCDEF']])
        ->withTheme($corporate)
        ->withTheme($resolver->resolve('minimal')->styles());

    expect($corporate->toArray())->toBe($before)
        ->and($corporate->get(Styles::HEADING_1)['name'])->toBe('Georgia');
});

it('says nothing about a block with no theme in it', function () {
    $frontmatter = Frontmatter::of(['title' => 'Quarterly']);

    expect($frontmatter->themeName())->toBeNull()
        ->and(Frontmatter::none()->themeName())->toBeNull()
        // An empty string is a document saying nothing, not one naming a theme.
        ->and(Frontmatter::of(['theme' => '  '])->themeName())->toBeNull();
});

it('keeps a theme out of the configuration array', function () {
    // `styles:` and `options:` are the two keys a Configuration reads; a theme name is
    // neither, and folding it in would put a key there that nothing looks at.
    $frontmatter = Frontmatter::of(['theme' => 'report', 'styles' => ['heading.1' => 'Title']]);

    expect($frontmatter->toConfigurationArray())->toBe(['styles' => ['heading.1' => 'Title']]);
});

it('reads a theme file of its own accord, without a styles file', function () {
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'bare', theme: "name: Bare\ndescription: Nothing else.\nauthor: fabi\n");

    $loaded = (new ThemeResolver($root))->resolve('bare');

    expect($loaded->theme()->name())->toBe('Bare')
        ->and($loaded->theme()->description())->toBe('Nothing else.')
        ->and($loaded->theme()->author())->toBe('fabi')
        // No styles.yml is a theme drawn in the built-in look, which is a theme rather
        // than a broken one.
        ->and($loaded->styles()->toArray())->toBe((new Styles())->toArray());
});

it('refuses a theme file with a key it does not know', function () {
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'one', theme: "name: Test\ncolours:\n  dk1: \"#000000\"\n");

    expect(fn () => (new ThemeResolver($root))->resolve('one'))
        ->toThrow(InvalidConfiguration::class, 'Unknown key "colours"');

    // The presentation half's palette is not this half's vocabulary, and a theme that
    // carried one would look like it worked and change nothing.
    expect(fn () => (new ThemeResolver($root))->resolve('one'))
        ->toThrow(InvalidConfiguration::class, 'A theme may have: name, description, author.');
});

it('refuses a theme file that does not name itself', function () {
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'one', theme: "description: Nameless.\n");

    expect(fn () => (new ThemeResolver($root))->resolve('one'))
        ->toThrow(InvalidConfiguration::class, 'has no "name"');
});

it('refuses a styles file that is a list rather than a mapping', function () {
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'one', styles: "- heading.1\n- paragraph\n");

    expect(fn () => (new ThemeResolver($root))->resolve('one'))
        ->toThrow(InvalidConfiguration::class, 'It has to be a mapping of style slots to styles.');
});

it('reports a theme file that is not YAML at all', function () {
    [$root, $themes] = scratchThemes();
    $directory = $themes . '/broken';
    mkdir($directory, 0o777, true);
    file_put_contents($directory . '/theme.yml', "name: [unclosed\n");

    expect(fn () => (new ThemeResolver($root))->resolve('broken'))
        ->toThrow(Exception::class, 'is not valid YAML');
});

it('resolves a root inside the archive it is running from', function () {
    // The one case `realpath()` cannot serve: inside the archive this library ships as,
    // the built-in themes are `phar://…/app/themes`, and `realpath()` returns false for
    // every path under a wrapper. A resolver that only knew how to ask the file system
    // would refuse every theme by name in the shipped `.phar`.
    $inside = 'phar://mdword.phar/app/themes';

    expect(realpath($inside))->toBeFalse()
        ->and(PathScope::resolve($inside))->toBe($inside)
        // A path that is on neither the file system nor the archive is nothing.
        ->and(PathScope::resolve('/tmp/mdword-not-here'))->toBeNull();
});

it('will not walk out of the archive a theme name sits in', function () {
    // `realpath()` is what resolves `..` for a path on disk; inside a phar nothing does,
    // so the segments are walked by hand. Without that, a name could leave the archive —
    // and the root that decides it is the one place that has to be a boundary.
    $scope = new PathScope(['phar://mdword.phar/app/themes']);

    expect(PathScope::resolve('phar://mdword.phar/app/../../etc'))
        ->toBe('phar://mdword.phar/etc')
        ->and($scope->holds('phar://mdword.phar/app/themes/house'))->toBeTrue()
        ->and($scope->holds('phar://mdword.phar/etc'))->toBeFalse()
        // A sibling whose name merely begins with the root's is not inside it.
        ->and($scope->holds('phar://mdword.phar/app/themes-elsewhere'))->toBeFalse();
});

it('refuses a theme file that is a symlink out of the scope', function () {
    // The directory is checked; the files inside it were not. A `styles.yml` that points
    // outside is read just as readily as one inside, and its values reach the document as
    // direct formatting — a way to put a file outside the roots into the output.
    [$root, $themes] = scratchThemes();

    // A second working directory, so the target is outside the *scope* rather than merely
    // outside the theme: a sibling directory under the same root is still inside it.
    [, $outside] = scratchThemes();
    file_put_contents($outside . '/styles.yml', "heading.1:\n  color: DEADBEEF\n");

    mkdir($themes . '/evil', 0o777, true);
    file_put_contents($themes . '/evil/theme.yml', "name: Evil\n");
    symlink($outside . '/styles.yml', $themes . '/evil/styles.yml');

    expect(fn () => (new ThemeResolver($root))->resolve('evil'))
        ->toThrow(Exception::class, 'resolves outside the directories this render may read');
});

it('reads a theme whose own file is a symlink that stays inside the scope', function () {
    // The other half of the refusal above: a symlink resolving within the roots is a file
    // like any other and must still load.
    [$root, $themes] = scratchThemes();
    mkdir($themes . '/shared', 0o777, true);
    file_put_contents($themes . '/shared/styles.yml', "heading.1:\n  color: 112233\n");
    mkdir($themes . '/user', 0o777, true);
    file_put_contents($themes . '/user/theme.yml', "name: User\n");
    symlink($themes . '/shared/styles.yml', $themes . '/user/styles.yml');

    expect((new ThemeResolver($root))->resolve('user')->styles()->get(Styles::HEADING_1)['color'])
        ->toBe('112233');
});

it('does not treat the filesystem root as a directory a document may name', function () {
    // `/` is already a prefix of every absolute path, so holding it would make `holds()`
    // true for anything at all — the scope would stop being one, silently. A container
    // started with `WORKDIR /` is a real place for a working directory to be `/`.
    //
    // Compared through the scope's own resolution rather than a `realpath()` written here,
    // so the test states the property instead of the spelling of this machine's temp root.
    $scratch = scratchThemes()[0];

    expect((new PathScope(['/']))->roots())->toBe([])
        ->and((new PathScope([$scratch, '/']))->roots())->toBe((new PathScope([$scratch]))->roots());
});

it('keeps the ordinary roots when another one is refused', function () {
    $resolver = new ThemeResolver(scratchThemes()[0]);

    expect($resolver->available())->toContain('minimal', 'corporate', 'default', 'report');
});

it('takes a cell colour as a string and a table colour as a mapping', function () {
    // PHPWord's `Cell::setShading()` wants the colour itself; `Table::setShading()` wants
    // a mapping with a `fill` under it. A theme naming the mapping for a cell got past the
    // validator and then reached XMLWriter as an array — a TypeError at write time rather
    // than a bad value reported when the theme was read.
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'cells', styles: "tableCell:\n  shading: \"EEF1F7\"\ntable:\n  shading: { fill: \"EEF1F7\" }\n");

    expect((new ThemeResolver($root))->resolve('cells'))->toBeInstanceOf(LoadedTheme::class);

    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'wrong', styles: "tableCell:\n  shading: { fill: \"EEF1F7\" }\n");

    expect(fn () => (new ThemeResolver($root))->resolve('wrong'))
        ->toThrow(InvalidConfiguration::class, 'six hexadecimal digits');
});

it('names only the three keys a theme.yml may carry', function () {
    expect(Theme::KEYS)->toBe(['name', 'description', 'author']);
});

it('names every slot a theme may write, and takes them from the built-in look', function () {
    // A theme that named a slot the renderer does not read would be validated against a
    // list of its own and the two could drift apart without either failing.
    $names = array_keys(Styles::defaults());

    expect($names)->toContain('heading.1', 'blockQuote', 'codeFont', 'table', 'tableHeaderRow');

    foreach ($names as $slot) {
        [$root, $themes] = scratchThemes();

        // A style id rather than a property, because the three table slots are handed a
        // `Table`, a `Row` and a `Cell` and take names of their own — `italic` is not one
        // of any of them, and asking each slot for the same property would be testing that
        // they differ rather than that they are all writable.
        writeTheme($themes, 'one', styles: $slot . ": SomeStyle\n");

        $loaded = (new ThemeResolver($root))->resolve('one');

        expect($loaded->styles()->get($slot))->toBe('SomeStyle');
    }
});

it('checks what is inside a slot, the way a styles block is checked', function () {
    // Slot *names* alone would let a theme carry a value nothing can use — a mistyped
    // property, or a colour written with a leading `#` — straight into the document,
    // where it arrives as direct formatting and no reader of the output can tell it was
    // never what the author wrote. A `styles:` block refuses all three.
    foreach ([
        'heading.1: {colour: 8B0000}' => 'Unknown "colour" property',
        'heading.1: {color: "#8B0000"}' => 'six hexadecimal digits',
        'heading.1: {size: huge}' => 'It is a number.',
    ] as $styles => $expected) {
        [$root, $themes] = scratchThemes();
        writeTheme($themes, 'one', styles: $styles . "\n");

        expect(fn () => (new ThemeResolver($root))->resolve('one'))
            ->toThrow(InvalidConfiguration::class, $expected);
    }
});

it('refuses a colour YAML read as something other than a string', function () {
    // `2E5395` is a hex colour and also scientific notation, and YAML resolves it to
    // infinity before anything here sees it. Quoted it is a colour; unquoted it is not,
    // and saying so at load time beats writing the literal text `INF` into the document.
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'quoted', styles: "heading.1:\n  color: \"2E5395\"\n");
    writeTheme($themes, 'bare', styles: "heading.1:\n  color: 2E5395\n");

    $resolver = new ThemeResolver($root);

    expect($resolver->resolve('quoted')->styles()->get(Styles::HEADING_1)['color'])->toBe('2E5395')
        ->and(fn () => $resolver->resolve('bare'))->toThrow(InvalidConfiguration::class);
});