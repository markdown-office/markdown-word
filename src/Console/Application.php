<?php

declare(strict_types=1);

namespace MarkdownWord\Console;

use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use MarkdownWord\Configuration;
use MarkdownWord\Converter;
use MarkdownWord\Console\CommandLine;
use MarkdownWord\Console\Command\Command;
use MarkdownWord\Console\Command\ToDocx;
use MarkdownWord\Console\Command\ToMarkdown;
use MarkdownWord\Console\Command\ToOdt;
use MarkdownWord\Console\Command\ToRtf;
use MarkdownWord\Exception\InvalidInput;
use MarkdownWord\Format;
use MarkdownWord\Input;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Parser\CommonMarkParser;
use MarkdownWord\Reverse\Options as ReverseOptions;
use MarkdownWord\Template\MarkdownTemplate;
use MarkdownWord\WordToMarkdown;
use Throwable;

/**
 * The `mdword` command: two commands, one per direction, so that the name of the
 * command says what the run does.
 *
 * The whole interface lives here rather than in a script so it can be tested like
 * the rest of the library, and so the same code serves both the phar and a plain
 * checkout of the source. The result goes to standard output and everything else
 * to standard error, so `mdword to-docx in.md -o - | pbcopy` does what it looks
 * like it does.
 */
final class Application
{
    public const NAME = 'mdword';

    // The phar reads this to stamp its own manifest, and the release job fails the
    // run when this and the tag pushed disagree. `CHANGELOG.md` carries the same
    // number and a test holds the two together, so a bump here needs a heading
    // there.
    public const VERSION = '0.1.1';

    /**
     * Exit code for a run that did what it was asked: a conversion, or an answer
     * to `help`, `--version` or a command's own `--help`.
     */
    public const SUCCESS = 0;

    /**
     * Exit code for a run that did not finish, whether the cause is a mistake the
     * caller can put right or a defect they cannot. What it printed says which.
     */
    public const FAILURE = 1;

    /**
     * The commands, by the name they are typed under.
     *
     * The one list the dispatcher, the usage text and the alias table all read.
     *
     * @var array<string, class-string<Command>>
     */
    private const COMMANDS = [
        'to-docx' => ToDocx::class,
        'to-odt' => ToOdt::class,
        'to-rtf' => ToRtf::class,
        'to-markdown' => ToMarkdown::class,
    ];

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /** @var resource */
    private $in;

    /**
     * Bytes read from the input to work out its direction, handed on again by
     * {@see self::readInput()}.
     */
    private string $peeked = '';

    /**
     * @param resource|null $out Standard output; defaults to the process's.
     * @param resource|null $err Standard error; defaults to the process's.
     * @param resource|null $in  Standard input; defaults to the process's.
     */
    public function __construct($out = null, $err = null, $in = null)
    {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
        $this->in = $in ?? STDIN;
    }

    /**
     * Run a command and return the exit code.
     *
     * @param list<string> $argv The arguments, without the program name.
     */
    public function run(array $argv): int
    {
        // One filter for the whole run, because a document is written in several
        // places and it has to span all of them. Wrapped rather than installed
        // and taken off again, so a host that installed the filter itself before
        // embedding this keeps it: taking it off would leave the rest of that
        // process with nothing between it and PHPWord's warnings.
        return UpstreamDeprecations::quietly(fn (): int => $this->runQuietly($argv));
    }

    /**
     * The run itself, with the deprecation filter already in place.
     *
     * Both of the first two catches are the same failure: something the caller handed
     * in that they can hand in differently — a bad command line, a key in a
     * frontmatter block that names nothing, an image that is in hand and unusable.
     * Their messages are written for a person reading them. Anything else is a defect
     * in this library, and is reported as one.
     *
     * @param list<string> $argv
     */
    private function runQuietly(array $argv): int
    {
        try {
            return $this->dispatch($argv);
        } catch (ConsoleException|InvalidInput $e) {
            $this->error($e->getMessage());

            // Only a `ConsoleException` carries hints. An `InvalidInput`'s message is
            // the whole of what it has to say, and it says it for a person reading it.
            if ($e instanceof ConsoleException) {
                foreach ($e->hints() as $hint) {
                    $this->error('  ' . $hint);
                }
            }
        } catch (Throwable $e) {
            // The type, the message and where it happened. No stack trace — the phar
            // has no source paths that mean anything to the person reading it — and
            // the type named is what tells a defect from a mistake.
            $this->error(sprintf('%s: %s', $e::class, $e->getMessage()));
            $this->error(sprintf('  at %s:%d', $e->getFile(), $e->getLine()));
        }

        return self::FAILURE;
    }

    /**
     * @param list<string> $argv
     */
    private function dispatch(array $argv): int
    {
        $command = $argv[0] ?? null;

        if ($command === null || $command === 'help' || $command === '--help' || $command === '-h') {
            $this->write($this->out, $this->usage());

            return self::SUCCESS;
        }

        if ($command === '--version' || $command === '-V' || $command === 'version') {
            $this->write($this->out, sprintf("%s %s%s", self::NAME, self::VERSION, PHP_EOL));

            return self::SUCCESS;
        }

        // The direction is a command when it is asked for and worked out from the
        // file when it is not, so `mdword notes.md` does the obvious thing and a
        // script can still be explicit.
        if (isset(self::COMMANDS[$command])) {
            $class = self::COMMANDS[$command];

            return (new $class($this))->execute(array_slice($argv, 1));
        }

        // Nothing is stripped: whatever is in front is either the file to read or
        // an option, and the whole line goes to the command that gets chosen. A
        // bare word that is not a file is reported as a missing file rather than
        // as an unknown command, because a file may be called anything at all.
        return $this->runInDirection($argv);
    }

    /**
     * `--to` overrides what the file says, which is what makes reading from
     * standard input work at all: a pipe has no name to go on.
     *
     * @param list<string> $argv The whole command line, with no command name in it.
     */
    private function runInDirection(array $argv): int
    {
        $command = CommandLine::parse(
            $argv,
            values: ['to', 'output', 'config', 'template', 'region', 'images', 'image-base', 'table-width', 'media', 'line-ending'],
            flags: ['help', 'no-images', 'plain', 'setext', 'no-fence', 'no-header'],
            repeated: ['define'],
            aliases: self::aliasMap(),
        );

        $input = $command->input();
        $detected = $input !== null && $input !== '-' ? $this->detectDirection($input) : null;
        $forced = $command->value('to');

        if ($forced === null) {
            $direction = $detected ?? $this->detectDirection($input);
        } else {
            $direction = self::directionFor($forced);

            // Compared by which way the run reads rather than by which command it
            // ends up in: `.docx`, `.odt` and `.rtf` are three answers to the same
            // question, and a file of Markdown is Markdown whichever of them is
            // going to be written.
            if ($detected !== null && self::readsMarkdown($detected) !== self::readsMarkdown($direction)) {
                throw new ConsoleException(
                    sprintf('--to %s does not match "%s".', $forced, $input),
                    [sprintf('That file is %s.', self::readsMarkdown($detected) ? 'Markdown' : 'a Word document')],
                );
            }
        }

        $class = self::COMMANDS[$direction];

        return (new $class($this))->execute($argv);
    }

    private static function directionFor(string $asked): string
    {
        return match (strtolower($asked)) {
            'docx', 'word' => 'to-docx',
            'odt', 'opendocument' => 'to-odt',
            'rtf', 'rich text' => 'to-rtf',
            'markdown', 'md' => 'to-markdown',
            default => throw new ConsoleException(
                sprintf('Unknown format "%s".', $asked),
                ['Use docx, odt, rtf or markdown.'],
            ),
        };
    }

    /**
     * Whether a command takes Markdown in, for the one comparison that must not
     * care which of the three word formats is meant.
     */
    private static function readsMarkdown(string $command): bool
    {
        return $command !== 'to-markdown';
    }

    /**
     * Which way a file has to go, from what it contains.
     *
     * A `.docx` is a zip archive and begins `PK\x03\x04`; Markdown is text and
     * begins with readable characters. Those bytes are part of the format rather
     * than a convention, so this holds for a file with any name at all.
     */
    private function detectDirection(?string $input): string
    {
        $fromStream = $input === null || $input === '-';

        // Suppressed so a missing file gives the sentence below rather than PHP's
        // own warning followed by it.
        $handle = $fromStream ? $this->in : @fopen($input, 'rb');

        if ($handle === false) {
            throw new ConsoleException(
                sprintf('The file "%s" does not exist.', (string) $input),
                ['Name the file to read, or omit it to read standard input.'],
            );
        }

        // A file is opened separately and thrown away, so nothing is consumed from
        // the input. A stream cannot be rewound, so the bytes read are kept and
        // given back to whoever reads the input next — otherwise the document
        // would arrive four bytes short and no longer be an archive.
        $magic = (string) fread($handle, 4);

        if ($fromStream) {
            $this->peeked .= $magic;
        } else {
            fclose($handle);
        }

        return Input::looksLikeDocument($magic) ? 'to-markdown' : 'to-docx';
    }

    /**
     * The short spellings, from every command that has one.
     *
     * @return array<string, string>
     */
    public static function aliasMap(): array
    {
        $aliases = [];

        foreach (self::COMMANDS as $class) {
            $aliases = array_merge($aliases, $class::spec()['aliases']);
        }

        return $aliases;
    }

    /**
     * The parser specification of one command, for telling a person which command
     * an option they used actually belongs to.
     *
     * @param class-string<Command> $command
     * @return array<string, string>
     */
    public static function specFor(string $command): array
    {
        $foreign = [];
        $name = self::commandName($command);

        foreach (self::names($command::spec()) as $option) {
            $foreign[$option] = $name;
        }

        return $foreign;
    }

    /**
     * @param class-string<Command> $command
     */
    private static function commandName(string $command): string
    {
        $short = substr($command, (int) strrpos($command, '\\') + 1);

        // `ToMarkdown` reads as `to-markdown`. The matched letter is kept by
        // referring to it in the replacement; replacing it outright would give
        // `to-arkdown`.
        return strtolower((string) preg_replace('/(?<!^)([A-Z])/', '-$1', $short));
    }

    /**
     * Every option name a specification mentions.
     *
     * @param array{values: list<string>, flags: list<string>, repeated: list<string>, aliases: array<string, string>} $spec
     * @return list<string>
     */
    public static function names(array $spec): array
    {
        return array_values(array_unique(array_merge(
            $spec['values'],
            $spec['flags'],
            $spec['repeated'],
            array_values($spec['aliases']),
        )));
    }

    /**
     * The text `mdword help` prints: one section per command over the option table
     * each one publishes for readers. {@see Command::options()} says why that is a
     * second list.
     */
    public function usage(): string
    {
        $lines = [
            self::NAME . ' — convert Markdown to Word, and back.',
            '',
            'USAGE',
            '  ' . self::NAME . ' <file>            # the direction is worked out from the file',
            '  ' . self::NAME . ' to-docx     [<markdown>] [options]',
            '  ' . self::NAME . ' to-odt      [<markdown>] [options]',
            '  ' . self::NAME . ' to-rtf      [<markdown>] [options]',
            '  ' . self::NAME . ' to-markdown [<docx>]    [options]',
            '  ' . self::NAME . ' help',
            '  ' . self::NAME . ' --version',
            '',
            'A Word document is a zip archive and Markdown is text, so the file says',
            'which way it has to go. Naming the command anyway is allowed and is what',
            'a script should do; `--to docx`, `--to odt`, `--to rtf` or `--to markdown`',
            'says it in one word and is the only way to be explicit when reading from',
            'standard input.',
            '',
            'to-docx, to-odt and to-rtf are one conversion in three formats, and .docx',
            'is the default of all three. What the other two cannot carry is set out in',
            'the README, and a run that loses any of it says so on standard error.',
            '',
            'The input is read from standard input when no file is named. The result is',
            'written to standard output when the output is "-", or when there is no input',
            'file to derive a name from.',
            '',
        ];

        foreach (self::COMMANDS as $class) {
            foreach (self::describeCommand($class) as $line) {
                $lines[] = $line;
            }

            $lines[] = '';
        }

        $lines[] = 'EXAMPLES';
        $lines[] = '  ' . self::NAME . ' README.md                    # -> README.docx';
        $lines[] = '  ' . self::NAME . ' README.docx                  # -> README.md';
        $lines[] = '  ' . self::NAME . ' to-docx README.md -o README.docx';
        $lines[] = '  ' . self::NAME . ' to-odt README.md';
        $lines[] = '  ' . self::NAME . ' to-docx notes.md --template report.docx --region body --define customer=Northwind';
        $lines[] = '  ' . self::NAME . ' to-markdown report.docx --media assets -o report.md';
        $lines[] = '  ' . self::NAME . ' README.md --to rtf';
        $lines[] = '  ' . self::NAME . ' cat notes.md | ' . self::NAME . ' to-docx - -o - | pbcopy';

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * The heading and option table for one command.
     *
     * @param class-string<Command> $command
     * @return list<string>
     */
    public static function describeCommand(string $command): array
    {
        // `ToDocx` reads as "to docx", which is how the command is written.
        $short = (string) preg_replace('/(?<!^)[A-Z]/', ' $0', substr($command, (int) strrpos($command, '\\') + 1));
        $heading = strtoupper(str_replace('_', ' ', $short));
        $lines = [$heading, str_repeat('-', strlen($heading))];

        foreach ($command::options() as [$flags, $description]) {
            $lines[] = sprintf('  %-30s %s', $flags, $description);
        }

        return $lines;
    }

    /**
     * @param class-string<Command> $command
     */
    public static function commandHelp(string $command, string $summary): string
    {
        // The name as it is typed, not as the heading spells it out: the heading
        // reads "TO DOCX" and the usage line has to read `to-docx`.
        $name = self::commandName($command);

        return implode(PHP_EOL, [
            self::NAME . ' ' . $name . ' — ' . $summary,
            '',
            'USAGE',
            '  ' . self::NAME . ' ' . $name . ' [<file>] [options]',
            '',
            'OPTIONS',
            ...self::describeCommand($command),
            '',
        ]) . PHP_EOL;
    }

    // Public so a caller embedding this in a command line of their own uses the
    // same implementations rather than writing their own.

    /**
     * The configuration for a run: the one a `--config` file names, with
     * `--plain` applied and then the individual options layered on top.
     *
     * @param array<string, mixed> $overrides
     */
    public function configuration(?string $configFile, array $overrides, bool $plain = false): Configuration
    {
        $config = $configFile === null ? new Configuration() : $this->loadConfiguration($configFile);

        if ($plain) {
            $config = $config->withoutDecoration();
        }

        return $overrides === [] ? $config : $config->withOptions($overrides);
    }

    /**
     * Read a configuration file: a PHP file returning either a
     * {@see Configuration} or the array form {@see Configuration::fromArray()}
     * understands.
     *
     * @throws ConsoleException
     */
    private function loadConfiguration(string $path): Configuration
    {
        if (!is_file($path)) {
            throw new ConsoleException(sprintf('The configuration file "%s" does not exist.', $path));
        }

        $loaded = require $path;

        if ($loaded instanceof Configuration) {
            return $loaded;
        }

        if (is_array($loaded)) {
            return Configuration::fromArray($loaded);
        }

        throw new ConsoleException(
            sprintf('The configuration file "%s" must return an array or a Configuration.', $path),
            ['Returning `MarkdownWord\Configuration::fromArray([...])` is the usual form.'],
        );
    }

    /**
     * @throws ConsoleException
     */
    public function readInput(?string $path): string
    {
        if ($path === null || $path === '-') {
            // Whatever the direction check read is given back here, so the command
            // sees the whole input rather than the part after it.
            $peeked = $this->peeked;
            $this->peeked = '';

            return $peeked . (string) stream_get_contents($this->in);
        }

        if (!is_file($path)) {
            throw new ConsoleException(sprintf('The file "%s" does not exist.', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new ConsoleException(sprintf('Unable to read "%s".', $path));
        }

        return $contents;
    }

    /**
     * Write the result: to a file, or to standard output when the path is `-`.
     *
     * @throws ConsoleException
     */
    public function writeResult(string $path, string $contents): void
    {
        if ($path === '-') {
            $this->write($this->out, $contents);

            return;
        }

        $directory = dirname($path);

        if ($directory !== '' && !is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new ConsoleException(sprintf('Unable to create the directory "%s".', $directory));
        }

        // Staged beside the target and renamed, because `file_put_contents()`
        // opens an existing file with O_TRUNC: writing to a name that is a hard
        // link to the input would truncate the input itself, which the guard in
        // `BaseCommand` cannot see through.
        $temp = tempnam($directory, '.mdword_');

        if ($temp === false) {
            throw new ConsoleException(sprintf('Unable to write "%s".', $path));
        }

        try {
            if (@file_put_contents($temp, $contents) === false) {
                throw new ConsoleException(sprintf('Unable to write "%s".', $path));
            }

            if (!@rename($temp, $path)) {
                throw new ConsoleException(sprintf('Unable to write "%s".', $path));
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Where the result should go, when the user did not say.
     *
     * Standard input has no name to derive one from, so the result goes to
     * standard output rather than to a file nobody asked for.
     */
    public function outputPath(?string $input, string $extension, ?string $requested): string
    {
        if ($requested !== null) {
            return $requested;
        }

        if ($input === null || $input === '-') {
            return '-';
        }

        // In the directory it was read from, so `docs/notes.md` becomes
        // `docs/notes.docx` rather than something that merely looks like it.
        $directory = pathinfo($input, PATHINFO_DIRNAME);
        $stem = pathinfo($input, PATHINFO_FILENAME);

        // A file whose whole name is an extension, such as `.md`, has no stem to
        // work with; the name is then used whole.
        if ($stem === '') {
            $stem = $input;
            $directory = '.';
        }

        $prefix = ($directory === '' || $directory === '.') ? '' : $directory . '/';

        return $prefix . $stem . $extension;
    }

    /**
     * The dialect the command line reads Markdown with.
 *
     * Frontmatter is on here and not in the library's default parser, and that is
     * the difference between a document that carries its own configuration and one
     * that does not: without the extension a leading `---` is a thematic break and
     * the rest of the block arrives as paragraphs of text at the top of the document.
     *
     * It is on because the precedence the README gives has a row for the frontmatter
     * sitting below the command line, and that row means nothing from a terminal
     * unless the terminal reads the block at all.
     */
    public static function parser(): CommonMarkParser
    {
        return new CommonMarkParser([...CommonMarkParser::FLAVOURS['gfm'], FrontMatterExtension::class]);
    }

    /**
     * Typed as the interface rather than the class, so a caller holding one of the
     * two directions cannot tell them apart by accident.
     *
     * `$overrides` is what the command line itself said, and it sits *above* the
     * frontmatter rather than with the configuration the file names — see
     * {@see \MarkdownWord\Document\ConfigurationMerger} for the order. It is a
     * separate argument rather than merged into `$config` because the two are
     * different claims: the configuration is the base a document is rendered from,
     * and an override is one that outranks what the document says about itself.
     */
    public function converter(
        Configuration $config,
        ?string $source = null,
        Configuration|array|null $overrides = null,
    ): Converter {
        return new MarkdownToWord($source, $config, self::parser(), $overrides);
    }

    /**
     * The other direction, the same contract.
     *
     * @see self::converter()
     */
    public function reader(ReverseOptions $options, ?string $source = null): Converter
    {
        return new WordToMarkdown($source, $options);
    }

    public function template(string $path, Configuration $config, array $values, Configuration|array|null $overrides = null): MarkdownTemplate
    {
        return new MarkdownTemplate($path, $config, $values, self::parser(), $overrides);
    }

    /**
     * Say what had to be decoded on the way into the document.
     *
     * A `.webp` is embedded rather than dropped, but it is re-encoded on the way in
     * and a PNG of a photograph is several times the size of the WebP it came from.
     * That is a trade the run made on the reader's behalf, so it goes to standard
     * error with the rest of the progress rather than into the document.
     *
     * Typed as the interface, because {@see self::reader()} returns one too and only
     * the Markdown direction has images to convert.
     */
    public function reportImageConversions(Converter $converter): void
    {
        if (!$converter instanceof MarkdownToWord) {
            return;
        }

        foreach ($converter->pendingImageConversions() as $conversion) {
            $this->progress(sprintf(
                'converted %s (%s) to %s for embedding',
                $conversion['source'],
                $conversion['format'],
                $conversion['embeddedAs'],
            ));
        }
    }

    /**
     * Say what the format asked for dropped, on standard error with the rest of the
     * progress rather than into the document.
     *
     * `.docx` drops nothing and is the baseline the others are measured against, so
     * a run that lost nothing says nothing here either — the alternative is a line on
     * every conversion telling a reader of a perfect `.docx` that the document is
     * what it always was.
     */
    public function reportLosses(Converter $converter, Format $format): void
    {
        if (!$converter instanceof MarkdownToWord) {
            return;
        }

        foreach ($converter->pendingLosses() as $loss) {
            $this->progress(sprintf('%s cannot carry %s: %s', $format->value, $loss->feature, $loss->message));
        }
    }

    /**
     * The directory of the input file, which relative image paths resolve
     * against — the same rule a Markdown renderer in an editor would follow.
     */
    public static function directoryOf(?string $path): ?string
    {
        if ($path === null || $path === '' || $path === '-') {
            return null;
        }

        $resolved = realpath($path);

        return $resolved === false ? null : dirname($resolved);
    }

    /**
     * Note progress on standard error, so a piped standard output stays clean.
     */
    public function progress(string $message): void
    {
        $this->error($message);
    }

    public function error(string $message): void
    {
        $this->write($this->err, self::NAME . ': ' . $message . PHP_EOL);
    }

    /**
     * Write to standard output, for the help — the only thing that goes out this
     * way; a converted document leaves through {@see self::writeResult()}. Both go
     * through `write()` rather than reaching for `STDOUT`, which is what makes a
     * run drivable from a test.
     *
     * @see self::error()
     */
    public function print(string $text): void
    {
        $this->write($this->out, $text);
    }

    /**
     * Say on standard error what a run converted and where it put it. `-` is how
     * a stream is asked for, so a person is told which stream rather than being
     * shown a dash.
     */
    public function report(?string $input, string $output): void
    {
        $this->progress(sprintf(
            '%s → %s',
            $input === null || $input === '-' ? 'standard input' : $input,
            $output === '-' ? 'standard output' : $output,
        ));
    }

    /**
     * @param resource $stream
     */
    public function write($stream, string $text): void
    {
        // A memory sink opened with `php://memory` in a test accepts a plain
        // write just as a pipe does.
        fwrite($stream, $text);
    }
}
