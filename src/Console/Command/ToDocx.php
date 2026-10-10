<?php

declare(strict_types=1);

namespace MarkdownWord\Console\Command;

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Options;
use MarkdownWord\Console\Application;
use MarkdownWord\Console\CommandLine;
use MarkdownWord\Console\ConsoleException;
use MarkdownWord\Format;
use ZipArchive;

/**
 * `mdword to-docx` — Markdown in, a Word document out.
 *
 * {@see ToOdt} and {@see ToRtf} are this command with a different
 * {@see self::format()}, so a run is written once and the three word formats cannot
 * drift apart in anything but the format they name.
 */
class ToDocx extends BaseCommand
{
    /** The region a template is expected to have, used when `--region` is not given. */
    public const DEFAULT_REGION = 'body';

    /**
     * @param list<string> $argv
     */
    public function execute(array $argv): int
    {
        $command = $this->parseOptions($argv, $this->summary());

        if ($command === null) {
            return Application::SUCCESS;
        }

        $input = $command->input();
        $markdown = $this->application->readInput($input);
        $output = $this->outputPath($command, $this->format()->extension());

        // Two layers, and the difference is the whole point. `$settings` is the base
        // the document is rendered from, with anything the run inferred folded in; the
        // flags are what was *typed*, and they sit above the frontmatter so that
        // `--table-width` beats a `tableWidth:` in the document itself.
        $settings = $this->application->configuration(
            $command->value('config'),
            $this->overrides($command, $input),
            $command->flag('plain'),
        );

        $overrides = $this->typedConfiguration($command);

        $template = $command->value('template');

        if ($template === null) {
            $this->rejectWithoutTemplate($command);
            $this->convert($settings, $markdown, $input, $output, $overrides);
        } else {
            $this->assertTemplateIsSupported();
            $this->intoTemplate($markdown, $template, $command, $settings, $input, $output, $overrides);
        }

        $this->report($input, $output);

        return Application::SUCCESS;
    }

    /**
     * The format this command writes.
     *
     * The one thing {@see ToOdt} and {@see ToRtf} change; everything else a run does
     * — the layering of configuration, the template, the guards, the report — is the
     * same whichever of the three it is.
     */
    protected function format(): Format
    {
        return Format::Docx;
    }

    protected function summary(): string
    {
        return 'convert Markdown to a Word document.';
    }

    /**
     * A template is a `.docx`, and the renderer fills one in through PHPWord's own
     * template processor, which only reads that package. So this is not a limitation
     * of the two newer writers that could be worked around later; it is the shape of
     * the feature, and saying so is better than writing a document that was never
     * rendered into anything.
     *
     * Which file the caller named is deliberately not part of the answer: whether a
     * template can be filled in at all is a fact about the format, and
     * {@see self::assertRegionExists()} is what reports the file by name.
     */
    protected function assertTemplateIsSupported(): void
    {
        if ($this->format() === Format::Docx) {
            return;
        }

        throw new ConsoleException(
            sprintf('A template cannot be filled in for %s.', $this->format()->value),
            ['A template is a .docx, and only `mdword to-docx` renders into one.'],
        );
    }

    /**
     * The conversion itself, exactly once whichever way the result is going.
     *
     * The two are exclusive because a run's worth of work asked for twice is still
     * twice the work: `-o -` would otherwise convert the whole document, throw the
     * bytes away and build a second converter to do it all again, on the one path
     * the usage text advertises for `| pbcopy`.
     */
    private function convert(
        Configuration $config,
        string $markdown,
        ?string $input,
        string $output,
        ?array $overrides,
    ): void {
        $this->guardAgainstOverwrite($input, $output);

        $format = $this->format();
        $converter = $this->application->converter($config, $markdown, $overrides);

        if ($output === '-') {
            $this->application->writeResult($output, $converter->convertTo($format));
        } else {
            $converter->convertTo($format, $output);
        }

        $this->application->reportImageConversions($converter);
        $this->application->reportLosses($converter, $format);
    }

    protected static function other(): string
    {
        return ToMarkdown::class;
    }

    protected static function formats(): array
    {
        return ['docx', 'word'];
    }

    /**
     * The typed flags as the layer that outranks the frontmatter, or null when the
     * run typed none.
     *
     * Null rather than an empty configuration, because a {@see Configuration} names
     * every setting there is: handed to the merger it would put all of them, defaults
     * included, above the frontmatter and undo the very layering it is for. An array
     * is sparse, so a flag nobody typed leaves the document's own setting standing.
     *
     * @return array{styles?: array<string, mixed>, options?: array<string, mixed>}|null
     */
    private function typedConfiguration(CommandLine $command): ?array
    {
        $options = $this->typed($command);
        $plain = $command->flag('plain') ? self::plain() : [];

        if ($options === [] && $plain === []) {
            return null;
        }

        $typed = ['options' => $options];

        foreach ($plain as $section => $values) {
            $typed[$section] = [...($typed[$section] ?? []), ...$values];
        }

        return $typed;
    }

    /**
     * @return array<string, mixed>
     */
    private function overrides(CommandLine $command, ?string $input): array
    {
        return [...$this->typed($command), ...$this->inferred($command, $input)];
    }

    /**
     * What the run's own flags say, and nothing else.
     *
     * These are the only settings that outrank the frontmatter, because they are the
     * only ones a person typed: `--table-width 3000` is them saying it louder than
     * the `tableWidth:` in the document. {@see self::inferred()} is the other half —
     * values the run worked out for itself, which are defaults and must lose.
     *
     * @return array<string, mixed>
     */
    private function typed(CommandLine $command): array
    {
        $overrides = [];

        if ($command->flag('no-images')) {
            $overrides['images'] = Options::IMAGE_SKIP;
        } elseif ($command->value('images') !== null) {
            $overrides['images'] = self::imageMode((string) $command->value('images'));
        }

        if ($command->value('image-base') !== null) {
            $overrides['imageBasePath'] = (string) $command->value('image-base');
        }

        if ($command->value('table-width') !== null) {
            $overrides['tableWidth'] = self::tableWidth((string) $command->value('table-width'));
        }

        return $overrides;
    }

    /**
     * What the run decided without being asked.
     *
     * @return array<string, mixed>
     */
    private function inferred(CommandLine $command, ?string $input): array
    {
        if ($command->value('image-base') !== null) {
            return [];
        }

        // A relative image path in a file means "next to the file", as a Markdown
        // renderer in an editor would treat it. Standard input has no such anchor,
        // and there the working directory is the only thing to go on.
        $base = Application::directoryOf($input) ?? getcwd();

        return is_string($base) && $base !== '' ? ['imageBasePath' => $base] : [];
    }

    /**
     * `--plain`, as the settings it switches off.
     *
     * Read off {@see Configuration::withoutDecoration()} rather than written out
     * again, so the flag and the method it stands for cannot drift apart: the keys
     * are the ones whose value differs from the default.
     *
     * @return array{styles?: array<string, mixed>, options?: array<string, mixed>}
     */
    private static function plain(): array
    {
        $default = Configuration::create()->toArray();
        $plain = Configuration::create()->withoutDecoration()->toArray();
        $off = [];

        foreach (['styles', 'options'] as $section) {
            foreach ($plain[$section] as $key => $value) {
                if ($value !== $default[$section][$key]) {
                    $off[$section][$key] = $value;
                }
            }
        }

        return $off;
    }

    private static function imageMode(string $mode): string
    {
        return match (strtolower($mode)) {
            'embed' => Options::IMAGE_EMBED,
            'placeholder', 'alt' => Options::IMAGE_PLACEHOLDER,
            'skip', 'none' => Options::IMAGE_SKIP,
            default => throw new ConsoleException(
                sprintf('Unknown image mode "%s".', $mode),
                ['Use embed, placeholder or skip.'],
            ),
        };
    }

    private static function tableWidth(string $width): int
    {
        if (!preg_match('/^\d+$/', $width)) {
            throw new ConsoleException(
                sprintf('The table width "%s" is not a number.', $width),
                ['It is in fiftieths of a percent of the text column, so 5000 is the full width.'],
            );
        }

        return (int) $width;
    }

    private function rejectWithoutTemplate(CommandLine $command): void
    {
        $offenders = [];

        if ($command->repeated('define') !== []) {
            $offenders[] = '--define';
        }

        if ($command->value('region') !== null) {
            $offenders[] = '--region';
        }

        if ($offenders === []) {
            return;
        }

        throw new ConsoleException(
            sprintf('%s only means something together with --template.', implode(' and ', $offenders)),
            ['A template names the region the Markdown goes into.'],
        );
    }

    private function intoTemplate(
        string $markdown,
        string $path,
        CommandLine $command,
        Configuration $config,
        ?string $input,
        string $output,
        ?array $overrides,
    ): void {
        $values = [];

        foreach ($command->repeated('define') as $pair) {
            [$name, $value] = CommandLine::pair($pair, '--define');
            $values[$name] = $value;
        }

        $region = (string) $command->value('region', self::DEFAULT_REGION);

        self::assertRegionExists($path, $region);

        $template = $this->application->template($path, $config, $values, $overrides);
        $template->insert($region, $markdown);

        // The second guard: filling the region is the slow part of a run.
        $this->guardAgainstOverwrite($input, $output);

        if ($output === '-') {
            $this->application->writeResult($output, $template->toString());

            return;
        }

        $template->save($output);
    }

    /**
     * Filling a region that is not there leaves the `${name}` markers in the
     * finished document, so a wrong name would otherwise produce a file that looks
     * fine and is full of placeholders. Failing here says which regions the
     * template does have.
     *
     * @throws ConsoleException when the region is not in the template.
     */
    private static function assertRegionExists(string $path, string $region): void
    {
        if (!is_file($path)) {
            throw new ConsoleException(sprintf('The template "%s" does not exist.', $path));
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new ConsoleException(
                sprintf('The template "%s" could not be opened.', $path),
                ['A Word template is a .docx file, which is a zip archive.'],
            );
        }

        try {
            $document = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }

        if (!is_string($document)) {
            throw new ConsoleException(sprintf('The template "%s" has no document body.', $path));
        }

        // The markers are spread across runs by whoever built the template, so each
        // half is looked for on its own: the closing one is what tells a region
        // apart from a single-line `${name}` value.
        if (str_contains($document, '${' . $region . '}') && str_contains($document, '${/' . $region . '}')) {
            return;
        }

        preg_match_all('/\$\{\/?([A-Za-z_][A-Za-z0-9_]*)\}/', $document, $matches);
        $found = array_values(array_unique($matches[1] ?? []));

        sort($found);

        throw new ConsoleException(
            sprintf('The template has no "${%s}" region.', $region),
            $found === []
                ? ['It has no ${name} regions at all. See the template contract in the README.']
                : ['It does have: ' . implode(', ', array_map(
                    static fn (string $name): string => '${' . $name . '}',
                    $found,
                ))],
        );
    }

    /**
     * @return array{values: list<string>, flags: list<string>, repeated: list<string>, aliases: array<string, string>}
     */
    public static function spec(): array
    {
        return [
            'values' => ['output', 'config', 'template', 'region', 'images', 'image-base', 'table-width', 'to'],
            'flags' => ['help', 'no-images', 'plain'],
            'repeated' => ['define'],
            'aliases' => ['o' => 'output', 't' => 'template', 'c' => 'config', 'h' => 'help'],
        ];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function options(): array
    {
        return [
            ['-o, --output <file>', 'where the result goes; "-" for standard output'],
            ['-c, --config <file.php>', 'a file returning the styles and options to use'],
            ['-t, --template <file.docx>', 'render into a Word template rather than a new document'],
            ['--region <name>', 'the template region to fill in (default: body)'],
            ['--define <name=value>', 'a value for a single-line placeholder; repeatable'],
            ['--images <mode>', 'embed, placeholder or skip'],
            ['--no-images', 'shorthand for --images skip'],
            ['--image-base <dir>', 'where relative image paths resolve from'],
            ['--table-width <n>', 'table width in fiftieths of a percent; 5000 is full width'],
            ['--plain', 'no code colouring, no quote style, no table borders, no added spacing'],
            ['--to <format>', 'which way to convert; detected from the file otherwise'],
            ['-h, --help', 'this text'],
        ];
    }
}
