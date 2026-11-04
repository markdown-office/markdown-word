<?php

declare(strict_types=1);

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Document\Frontmatter;
use MarkdownWord\Exception\Exception;
use MarkdownWord\Exception\InvalidConfiguration;
use MarkdownWord\Exception\MalformedTheme;
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

/**
 * Write a theme directory, so a test can say what a theme file says.
 *
 * The slots are the body of the theme's `styles:` section, written as the YAML the
 * test means: a colour left unquoted stays unquoted, because how YAML reads it is
 * part of what the loader has to cope with.
 */
function writeTheme(string $themes, string $name, string $theme = "name: Test\n", string $styles = ''): string
{
    $directory = $themes . '/' . $name;
    mkdir($directory, 0o777, true);
    file_put_contents($directory . '/theme.yml', $theme . yamlSection('styles', $styles));

    return $directory;
}

/**
 * Every colour a style names, by the path it is at.
 *
 * @param array<string, mixed>|string|null $style
 * @return array<string, mixed>
 */
function coloursIn(array|string|null $style, string $where = ''): array
{
    if (!is_array($style)) {
        return [];
    }

    $colours = [];

    foreach ($style as $property => $value) {
        $path = $where === '' ? (string) $property : $where . '.' . $property;

        if (in_array($property, ['color', 'borderColor', 'fill'], true)) {
            $colours[$path] = $value;
        }

        if (is_array($value)) {
            $colours += coloursIn($value, $path);
        }
    }

    return $colours;
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

it('writes every colour a theme names as a string of six hexadecimal digits', function () {
    $resolver = new ThemeResolver(workingDirectory: scratchThemes()[0]);

    // YAML reads `2E5395` as a number and not as the colour it is — it is
    // `2 × 10^5395` — and the digits are gone with it. The loader can put back a
    // colour YAML read as an integer, because `404040` is the number it read; one
    // it read as a float cannot be put back at all, and the colour reaches the
    // writer as nothing at all.
    $unusable = [];

    foreach ($resolver->available() as $name) {
        foreach ($resolver->resolve($name)->styles()->toArray() as $slot => $style) {
            foreach (coloursIn($style) as $property => $colour) {
                if (!is_string($colour) || preg_match('/\A[0-9A-Fa-f]{6}\z/', $colour) !== 1) {
                    $unusable[] = sprintf('%s: %s.%s', $name, $slot, $property);
                }
            }
        }
    }

    expect($unusable)->toBe([], 'A theme writes a colour that is not a colour.');
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

it('reads a theme named as the manifest file that is it', function () {
    // A theme is its `theme.yml`, so a document may name the manifest rather than the
    // directory around it. The file is the theme, and the directory it sits in is
    // where the theme was found.
    [$document, $documentThemes] = scratchThemes();
    writeTheme($documentThemes, 'house', styles: "heading.1:\n  color: 112233\n");

    $resolver = new ThemeResolver(
        workingDirectory: scratchThemes()[0],
        documentDirectory: $document,
    );

    $loaded = $resolver->resolve('./themes/house/theme.yml');

    expect($loaded->theme()->name())->toBe('Test')
        ->and($loaded->styles()->get(Styles::HEADING_1)['color'])->toBe('112233')
        ->and($loaded->directory())->toBe(realpath($documentThemes . '/house'));
});

it('draws a document in the theme whose manifest it names', function () {
    // The form the feature is for: a document names the file, the file is the theme,
    // and the look comes from the file itself rather than from a file beside it.
    [$document] = scratchThemes();
    file_put_contents(
        $document . '/theme.yml',
        "name: House\nstyles:\n  heading.1:\n    color: 112233\n",
    );

    $markdown = "---\ntheme: ./theme.yml\n---\n\n# T\n";
    $converter = new MarkdownToWord(
        $markdown,
        parser: CommonMarkParser::withAllExtensions(),
        directory: $document,
    );

    $config = $converter->configurationFor($converter->parse($markdown), $markdown);

    expect($config->getStyles()->get(Styles::HEADING_1)['color'])->toBe('112233');
});

it('writes a manifest path from beside the document', function () {
    // The rule a `template_file:` and a relative image path follow, and the one the
    // theme path was given: a relative path is written from the document's own
    // directory, so the theme beside the document is the one that is found.
    [$document, $documentThemes] = scratchThemes();
    writeTheme($documentThemes, 'house', styles: "heading.1:\n  color: 112233\n");

    [$working, $workingThemes] = scratchThemes();
    writeTheme($workingThemes, 'house', styles: "heading.1:\n  color: 445566\n");

    $resolver = new ThemeResolver(
        workingDirectory: $working,
        documentDirectory: $document,
    );

    expect($resolver->resolve('./themes/house/theme.yml')->styles()->get(Styles::HEADING_1)['color'])
        ->toBe('112233');
});

it('refuses a file that is not a theme manifest', function () {
    // The manifest of a theme is a `theme.yml`; the file beside it is one of the theme's
    // own files rather than the theme, and naming it is a mistake worth saying so about.
    [$document] = scratchThemes();
    file_put_contents($document . '/styles.yml', "heading.1:\n  color: 112233\n");

    $resolver = new ThemeResolver(
        workingDirectory: scratchThemes()[0],
        documentDirectory: $document,
    );

    expect(fn () => $resolver->resolve('./styles.yml'))
        ->toThrow(ThemeNotFound::class, 'There is no theme directory named "./styles.yml"');

    try {
        $resolver->resolve('./styles.yml');
    } catch (ThemeNotFound $e) {
        // And the rule that makes it a mistake, so the reader is not left guessing.
        expect($e->getMessage())->toContain('A theme is a directory holding a theme.yml, or the theme.yml itself.');
    }
});

it('still refuses a directory with no theme.yml', function () {
    // The other half of the refusal above, and a different message: the directory is
    // there, and what it is missing is the manifest.
    [$document, $documentThemes] = scratchThemes();
    mkdir($documentThemes . '/bare', 0o777, true);

    $resolver = new ThemeResolver(
        workingDirectory: scratchThemes()[0],
        documentDirectory: $document,
    );

    expect(fn () => $resolver->resolve('./themes/bare'))
        ->toThrow(ThemeNotFound::class, 'is not a theme: it has no theme.yml');
});

it('refuses a manifest file outside the roots', function () {
    // The scope is asked of the file as well as of the directory: a path a document
    // writes may not read a manifest outside the roots, whatever the file is called.
    [$outside, $outsideThemes] = scratchThemes();
    writeTheme($outsideThemes, 'house');

    [$document] = scratchThemes();

    $resolver = new ThemeResolver(
        workingDirectory: scratchThemes()[0],
        documentDirectory: $document,
    );

    expect(fn () => $resolver->resolve($outside . '/themes/house/theme.yml'))
        ->toThrow(ThemeNotFound::class, 'outside the directories a document may name');
});

it('refuses a styles file beside a manifest, whatever it is a link to', function () {
    // The scope is never asked of the file: it is refused for being the format this
    // library used to have, and not for where it resolves to.
    [$document] = scratchThemes();
    file_put_contents($document . '/theme.yml', "name: Evil\n");

    [, $outside] = scratchThemes();
    file_put_contents($outside . '/styles.yml', "heading.1:\n  color: DEADBEEF\n");
    symlink($outside . '/styles.yml', $document . '/styles.yml');

    $resolver = new ThemeResolver(
        workingDirectory: scratchThemes()[0],
        documentDirectory: $document,
    );

    expect(fn () => $resolver->resolve('./theme.yml'))
        ->toThrow(MalformedTheme::class, 'is not read any more');
});

it('still reads a bare name as a theme in a directory', function () {
    // The file form is an addition rather than a replacement: a name that is not a path
    // is looked for under `./themes/`, as it always was.
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'house', styles: "heading.1:\n  color: 112233\n");

    $loaded = (new ThemeResolver($root))->resolve('house');

    expect($loaded->theme()->name())->toBe('Test')
        ->and($loaded->styles()->get(Styles::HEADING_1)['color'])->toBe('112233')
        ->and($loaded->directory())->toBe(realpath($themes . '/house'));
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
        ->toThrow(ThemeNotFound::class, 'A theme is on this machine, not something to fetch.');
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

it('lets the document styles block override a theme of its own, slot by slot', function () {
    // The same contract, over a theme the test writes rather than over one that ships:
    // the document's own `styles:` wins for the slot it names, and the rest of what the
    // theme said about that slot is still there. A theme that is a starting point is
    // the half's own promise, and it does not depend on the file's shape.
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'house', styles: "heading.1:\n  color: 112233\n  size: 20\nparagraph:\n  color: 445566\n");

    $markdown = "---\ntheme: house\nstyles:\n  heading.1:\n    color: FF00FF\n---\n\n# T\n";
    $converter = new MarkdownToWord(
        $markdown,
        parser: CommonMarkParser::withAllExtensions(),
        directory: $root,
    );

    $styles = $converter->configurationFor($converter->parse($markdown), $markdown)->getStyles();

    expect($styles->get(Styles::HEADING_1)['color'])->toBe('FF00FF')
        // The theme's own size is still there: one property changed, the slot did not.
        ->and($styles->get(Styles::HEADING_1)['size'])->toBe(20)
        // And the slot the document said nothing about is the theme's own.
        ->and($styles->get(Styles::PARAGRAPH)['color'])->toBe('445566');
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

it('reads a theme file of its own accord, without a styles section', function () {
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'bare', theme: "name: Bare\ndescription: Nothing else.\nauthor: fabi\n");

    $loaded = (new ThemeResolver($root))->resolve('bare');

    expect($loaded->theme()->name())->toBe('Bare')
        ->and($loaded->theme()->description())->toBe('Nothing else.')
        ->and($loaded->theme()->author())->toBe('fabi')
        // No `styles:` section is a theme drawn in the built-in look, which is a theme
        // rather than a broken one.
        ->and($loaded->styles()->toArray())->toBe((new Styles())->toArray());
});

it('refuses a styles file beside a theme, and says every step the author has to take', function () {
    // A theme that carried one is a theme whose styles have gone missing, and a file
    // that is quietly not read is a theme that looks like the one its author wrote and
    // is not. The message says where the content goes, and that the file has to be
    // deleted: the file is refused for being there, so an author who moved the styles
    // and left it beside the theme is refused again with the same sentence.
    [$root, $themes] = scratchThemes();
    $directory = $themes . '/one';
    mkdir($directory, 0o777, true);
    file_put_contents($directory . '/theme.yml', "name: Test\n");
    file_put_contents($directory . '/styles.yml', "heading.1:\n  color: 112233\n");

    expect(fn () => (new ThemeResolver($root))->resolve('one'))
        ->toThrow(MalformedTheme::class, 'is not read any more');

    // Where the content goes, which is the first of the two things the author has to
    // do about it.
    expect(fn () => (new ThemeResolver($root))->resolve('one'))
        ->toThrow(MalformedTheme::class, '"styles:" section of its "theme.yml"');

    // And that the file has to be deleted, which is the step the sentence has to name
    // as well: the file is refused for being there and not for what it holds.
    expect(fn () => (new ThemeResolver($root))->resolve('one'))
        ->toThrow(MalformedTheme::class, 'Delete the file, and the theme is read again.');
});

it('reads a theme whose styles are in its own file, and refuses the file beside it', function () {
    // The two clauses of the message, told apart by the file rather than by the
    // content: a theme whose styles are in its `theme.yml` is read, and the same
    // theme with the file of the old format beside it is refused. The file is
    // refused for being there, so the styles being in the `theme.yml` already does
    // not make it acceptable.
    [$root, $themes] = scratchThemes();
    $directory = writeTheme($themes, 'one', styles: "heading.1:\n  color: 112233\n");

    $resolver = new ThemeResolver($root);

    expect($resolver->resolve('one')->styles()->get(Styles::HEADING_1)['color'])->toBe('112233');

    file_put_contents($directory . '/styles.yml', "heading.1:\n  color: 112233\n");

    expect(fn () => $resolver->resolve('one'))
        ->toThrow(MalformedTheme::class, 'Delete the file, and the theme is read again.');
});

it('refuses a theme file with a key it does not know', function () {
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'one', theme: "name: Test\nstyels:\n  heading.1: Title\n");

    expect(fn () => (new ThemeResolver($root))->resolve('one'))
        ->toThrow(InvalidConfiguration::class, 'Unknown key "styels"');

    // The keys this half has, and the two sections it keeps its look in.
    expect(fn () => (new ThemeResolver($root))->resolve('one'))
        ->toThrow(InvalidConfiguration::class, 'A theme may have: name, description, author, styles, textStyles.');
});

it('refuses the slides half\'s palette, because the halves part at the look', function () {
    // The other half's palette is refused rather than read and ignored: a theme
    // carrying it would look like it worked and change nothing.
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'one', theme: "name: Test\ncolors:\n  dk1: \"#000000\"\n");

    expect(fn () => (new ThemeResolver($root))->resolve('one'))
        ->toThrow(InvalidConfiguration::class, 'Unknown key "colors"');

    // And the keys this half has: `styles:` for its own look, and the four the two
    // halves share.
    expect(fn () => (new ThemeResolver($root))->resolve('one'))
        ->toThrow(InvalidConfiguration::class, 'A theme may have: name, description, author, styles, textStyles.');
});

it('reads the vocabulary the two halves share, and refuses the other half\'s look', function () {
    // The two vocabularies overlap: `name`, `description`, `author` and `textStyles`
    // are in both, so a theme file carrying only those is read here and in the slides
    // half alike, and each draws it its own way. What this half refuses is the other
    // half's look.
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'shared', theme: "name: Shared\ndescription: Read by both.\nauthor: Nobody\ntextStyles:\n  brand:\n    color: \"0B5CAB\"\n");

    $theme = (new ThemeResolver($root))->resolve('shared')->theme();

    expect($theme->name())->toBe('Shared')
        ->and($theme->description())->toBe('Read by both.')
        ->and($theme->author())->toBe('Nobody')
        ->and($theme->textStyles()->toArray())->toBe(['brand' => ['color' => '0B5CAB']]);

    // The other half's look, key by key: `dark`, `colors`, `fonts`, `style` and
    // `layouts` are the slides half's, and not one of them is a key this half knows.
    foreach (['dark', 'colors', 'fonts', 'style', 'layouts'] as $key) {
        [$root, $themes] = scratchThemes();
        writeTheme($themes, 'slides', theme: "name: Slides\n{$key}: {}\n");

        expect(fn () => (new ThemeResolver($root))->resolve('slides'))
            ->toThrow(InvalidConfiguration::class, sprintf('Unknown key "%s"', $key));
    }
});

it('refuses a theme file that does not name itself', function () {
    // The two ways of not naming itself: a file that says something else, and a file
    // that says nothing at all. Both are told what is missing rather than what the
    // file is.
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'one', theme: "description: Nameless.\n");

    $empty = $themes . '/empty';
    mkdir($empty, 0o777, true);
    file_put_contents($empty . '/theme.yml', '');

    expect(fn () => (new ThemeResolver($root))->resolve('one'))
        ->toThrow(InvalidConfiguration::class, 'has no "name"');

    expect(fn () => (new ThemeResolver($root))->resolve('empty'))
        ->toThrow(InvalidConfiguration::class, 'has no "name"');
});

it('refuses a theme file that is not a mapping', function () {
    // A theme file is a mapping of keys to values, and a list of them is not one.
    [$root, $themes] = scratchThemes();
    $directory = $themes . '/one';
    mkdir($directory, 0o777, true);
    file_put_contents($directory . '/theme.yml', "- House\n- Report\n");

    expect(fn () => (new ThemeResolver($root))->resolve('one'))
        ->toThrow(InvalidConfiguration::class, 'is a list');
});

it('refuses a styles section that is a list rather than a mapping', function () {
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
    // The theme's directory is inside the roots and the file in it is not: a
    // `theme.yml` that points outside is a way to put a file outside the roots into
    // the document.
    [$root, $themes] = scratchThemes();

    // A second working directory, so the file the theme names resolves outside the
    // roots: the theme's own directory is inside them, and the file in it is not.
    [, $outside] = scratchThemes();
    file_put_contents($outside . '/theme.yml', "name: Evil\nstyles:\n  heading.1:\n    color: DEADBEEF\n");

    mkdir($themes . '/evil', 0o777, true);
    symlink($outside . '/theme.yml', $themes . '/evil/theme.yml');

    expect(fn () => (new ThemeResolver($root))->resolve('evil'))
        ->toThrow(ThemeNotFound::class, 'outside the directories a document may name');
});

it('reads a theme whose own file is a symlink that stays inside the scope', function () {
    // The other half of the refusal above: a symlink resolving within the roots is a file
    // like any other and must still load.
    [$root, $themes] = scratchThemes();
    mkdir($themes . '/shared', 0o777, true);
    file_put_contents($themes . '/shared/theme.yml', "name: Shared\nstyles:\n  heading.1:\n    color: 112233\n");
    mkdir($themes . '/user', 0o777, true);
    symlink($themes . '/shared/theme.yml', $themes . '/user/theme.yml');

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

it('names only the five keys a theme.yml may carry', function () {
    expect(Theme::KEYS)->toBe(['name', 'description', 'author', 'styles', 'textStyles']);
});

it('gives a theme back as it was written, sections and all', function () {
    // The theme as it was written rather than as it is drawn: the slots the theme
    // named, not the whole merged look underneath them.
    $theme = Theme::fromArray([
        'name' => 'House',
        'styles' => ['heading.1' => ['color' => '112233']],
        'textStyles' => ['brand' => ['color' => '0B5CAB']],
    ]);

    expect($theme->toArray())->toBe([
        'name' => 'House',
        'description' => null,
        'author' => null,
        'styles' => ['heading.1' => ['color' => '112233']],
        'textStyles' => ['brand' => ['color' => '0B5CAB']],
    ]);
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

it('refuses a border colour YAML read as something other than a string', function () {
    // The table's colours are colours in the same way the font's are, and they were not
    // checked: a border colour YAML read as a number reached the writer, and the
    // document it wrote had an empty colour in it rather than the one that was named.
    [$root, $themes] = scratchThemes();
    writeTheme($themes, 'quoted', styles: "table:\n  borderColor: \"2E5395\"\n");
    writeTheme($themes, 'bare', styles: "table:\n  borderColor: 2E5395\n");

    $resolver = new ThemeResolver($root);

    expect($resolver->resolve('quoted')->styles()->get(Styles::TABLE)['borderColor'])->toBe('2E5395')
        ->and(fn () => $resolver->resolve('bare'))->toThrow(InvalidConfiguration::class);
});