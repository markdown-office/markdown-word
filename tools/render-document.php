<?php

declare(strict_types=1);

/**
 * Renders a document with the most truthful tool the machine has.
 *
 *   php render-document.php <document.docx> [--out <file.pdf>]
 *   php render-document.php --which [<document>]
 *   php render-document.php --platform-backend
 *
 * The point of a dispatcher is that the caller does not have to know what is installed.
 * Three backends can draw a document, and they are not equally good:
 *
 *   * **Microsoft Word** is the truth. It is the only one that will say whether a document
 *     opens, and the only one whose layout can be trusted: LibreOffice lays an inline
 *     picture out in a different place often enough that a page whose text fits in
 *     LibreOffice may not fit in Word.
 *   * **WordCraft** is faithful. It is a clean-room implementation of Word, and it is
 *     close enough to be worth trusting where Word is not there — and it is the only one
 *     of the three that runs everywhere, which is why it is the fallback and not the
 *     first choice.
 *   * **LibreOffice** is approximate. It is free and it is everywhere, and it disagrees
 *     with Word about where a line falls often enough that it cannot answer the question
 *     a renderer is asked.
 *
 * So the backends are tried in that order, and **a run that falls back says so**: a
 * renderer that cannot be trusted is worse than no renderer, because a caller who does not
 * know is told a layout is right when it is not.
 *
 * A backend that fails is not the end of the run: the next one is tried, and the run says
 * which one drew the document and why the one before it did not.
 *
 * The platform and the backend can both be overridden, which is what makes the choice
 * testable without Word and without a Windows machine:
 *
 *   * `MARKDOWN_OFFICE_PLATFORM` — `darwin`, `windows`, `linux`, `freebsd`.
 *   * `MARKDOWN_OFFICE_RENDERER` — `word`, `wordcraft`, `libreoffice`.
 *   * `MARKDOWN_OFFICE_WORD` — where Word is, where it is not where it is looked for.
 *
 * A run that produced no file exits non-zero, so a caller reading only the exit status
 * learns the render did not happen.
 */

const TRUTH = 'word';

/**
 * The backends, in the order of how much they can be trusted.
 *
 * `word` comes first because Word is the truth. The rest are fallbacks, and a fallback is
 * always announced.
 */
const BACKENDS = ['word', 'wordcraft', 'libreoffice'];

/**
 * The backend each platform prefers, and why.
 *
 * A platform that can run Word runs Word: Word is installed on macOS and on Windows, and
 * where it is installed it is the answer. Everywhere else WordCraft is the answer, because
 * it runs on every platform the suite supports and it is faithful where LibreOffice is
 * only approximate.
 */
const PREFERRED = [
    'darwin' => 'word',
    'windows' => 'word',
    'linux' => 'wordcraft',
    'freebsd' => 'wordcraft',
];

/**
 * The platform this run is on.
 *
 * From the environment where the environment says, and from the machine otherwise: the
 * override is what lets the choice be tested on a machine that is not the platform being
 * chosen for.
 */
function platform(): string
{
    $given = \getenv('MARKDOWN_OFFICE_PLATFORM');

    if (\is_string($given) && $given !== '') {
        return \strtolower($given);
    }

    return \strtolower((string) \php_uname('s'));
}

/**
 * The backend the platform prefers, or `none` where there is no preference.
 *
 * A platform this does not know has no preference, which is not the same as a platform
 * with a preference that is not installed: a preference is a statement about which
 * backend is *right* for the platform, and an unknown platform has no statement to make.
 */
function preferred(string $platform): string
{
    return PREFERRED[$platform] ?? 'none';
}

/**
 * Whether a backend can be used on this machine.
 *
 * The command that drives the backend is the first half of the test, and the backend
 * itself is the second: `osascript` is on every Mac whether or not Word is, and a backend
 * that answers "word" where there is no Word fails later, inside Word, with a message
 * about the document rather than about Word.
 *
 * The command asked about is the one that will be run, which for the craft backends
 * depends on the document: {@see craftFor()} is the same answer the invocation gives.
 */
function installed(string $backend, string $platform, string $document): bool
{
    return match ($backend) {
        'word' => $platform === 'darwin'
            ? \command_exists('osascript') && word_is_installed('darwin')
            : ($platform === 'windows'
                ? \command_exists('powershell') && word_is_installed('windows')
                : false),
        'wordcraft' => \command_exists(craftFor($document)),
        'libreoffice' => \command_exists('soffice') || \command_exists('libreoffice'),
        default => false,
    };
}

/**
 * The command that draws a document.
 *
 * WordCraft draws a Word document and DeckCraft draws a deck, and they are two programs:
 * a machine may have one without the other, and a backend asked for a document it cannot
 * draw fails with a message about the document rather than about itself.
 *
 * The check and the invocation ask the same question, because a check that says a backend
 * is there and an invocation that runs a different program is a run that announces a
 * renderer it then cannot run.
 */
function craftFor(string $document): string
{
    return \str_ends_with(\strtolower($document), '.pptx') ? 'deckcraft-cli' : 'wordcraft-cli';
}

/**
 * Whether Microsoft Word is installed on this machine.
 *
 * The application is looked for rather than asked about, because asking means starting
 * it: a check that starts the thing it is checking is not a check.
 *
 * `MARKDOWN_OFFICE_WORD` names the place to look instead of the default one. It is what
 * makes the check testable — a test can point it at something that is not Word and see
 * that the tool says so — and it is the way to say where Word is on a machine that
 * keeps it somewhere else.
 *
 * On macOS it is the path of the application bundle; on Windows it is the registry key
 * that holds Word's class.
 */
function word_is_installed(string $platform): bool
{
    $given = \getenv('MARKDOWN_OFFICE_WORD');

    if ($platform === 'darwin') {
        $candidates = \is_string($given) && $given !== ''
            ? [$given]
            : [
                '/Applications/Microsoft Word.app',
                \trim((\getenv('HOME') ?: '') . '/Applications/Microsoft Word.app'),
            ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && \is_dir($candidate)) {
                return true;
            }
        }

        return false;
    }

    if ($platform === 'windows') {
        $key = \is_string($given) && $given !== '' ? $given : 'HKCR\Word.Application\CLSID';

        $status = 0;
        \exec('reg query ' . \escapeshellarg($key) . ' >NUL 2>&1', $ignored, $status);

        return $status === 0;
    }

    return false;
}

/**
 * Whether a command is on the path.
 *
 * The lookup is the running platform's, because the question is about this machine: a
 * Windows command prompt has no `command -v`, and a Unix shell has no `where`. Asking
 * the wrong one of the two is how a tool ends up reporting that nothing is installed on
 * the one platform it was never tried on.
 */
function command_exists(string $command): bool
{
    $status = 0;

    $lookup = PHP_OS_FAMILY === 'Windows'
        ? 'where ' . \escapeshellarg($command)
        : 'command -v ' . \escapeshellarg($command) . ' 2>/dev/null';

    \exec($lookup, $ignored, $status);

    return $status === 0;
}

/**
 * The backends that would be used on this machine, in the order they would be tried.
 *
 * The platform's preference when it is installed, then the next backend that is, then
 * nothing. The order is the order of how much each can be trusted, so the first one that
 * is installed is the one that is used — and the ones after it are the ones a backend that
 * fails falls back to.
 *
 * @return list<string>
 */
function chosen(string $platform, string $document): array
{
    $forced = \getenv('MARKDOWN_OFFICE_RENDERER');

    if (\is_string($forced) && $forced !== '') {
        return [$forced];
    }

    $order = BACKENDS;
    $preferredBackend = preferred($platform);

    if ($preferredBackend !== 'none') {
        // The platform's preference goes first, and the rest keep the order of trust.
        $order = \array_values(\array_unique(\array_merge([$preferredBackend], BACKENDS)));
    }

    return \array_values(\array_filter(
        $order,
        static fn (string $backend): bool => installed($backend, $platform, $document),
    ));
}

/**
 * Renders a document with the first backend that can, and says which one that was.
 *
 * A backend that fails is not the end of the run: the next one is tried. A renderer that
 * is installed and does not work is a commoner thing than a renderer that is not installed
 * at all, and a run that gave up at the first failure produced nothing where a run that
 * tried the next one produced a PDF.
 *
 * @param list<string> $backends The backends to try, in the order of how much each can be
 *        trusted.
 *
 * @return array{backend: string, failures: list<string>} The backend that drew the document
 *         and the ones that could not, each with the reason.
 *
 * @throws RuntimeException when no backend produced the file it promised.
 */
function render(string $document, string $target, array $backends, string $platform): array
{
    $failures = [];

    foreach ($backends as $backend) {
        try {
            draw($backend, $document, $target, $platform);

            if (! \is_file($target) || \filesize($target) === 0) {
                throw new RuntimeException(\sprintf('The render produced no file at %s.', $target));
            }

            return ['backend' => $backend, 'failures' => $failures];
        } catch (RuntimeException $failure) {
            $failures[] = \sprintf('%s: %s', $backend, $failure->getMessage());
        }
    }

    throw new RuntimeException(
        $failures === []
            ? 'No backend can render a document on this machine.'
            : \implode("\n", $failures),
    );
}

/**
 * Draws a document with one backend.
 *
 * @throws RuntimeException when the backend did not produce the file it promised.
 */
function draw(string $backend, string $document, string $target, string $platform): void
{
    $scratch = \sys_get_temp_dir() . '/markdown-word-render-' . \bin2hex(\random_bytes(6));

    if (! @\mkdir($scratch, 0o777, true) && ! \is_dir($scratch)) {
        throw new RuntimeException('A temporary directory could not be made, so nothing was rendered.');
    }

    try {
        switch ($backend) {
            case 'word':
                renderWithWord($document, $target, $scratch, $platform);
                break;

            case 'wordcraft':
                renderWithCraft($document, $target);
                break;

            case 'libreoffice':
                renderWithLibreOffice($document, $target, $scratch);
                break;

            default:
                throw new RuntimeException('No backend can render a document on this machine.');
        }
    } finally {
        foreach ((array) \glob($scratch . '/*') as $left) {
            @\unlink($left);
        }

        @\rmdir($scratch);
    }
}

/**
 * Renders through Microsoft Word.
 */
function renderWithWord(string $document, string $target, string $scratch, string $platform): void
{
    $tool = __DIR__ . '/render-with-word.' . ($platform === 'windows' ? 'ps1' : 'applescript');

    $command = $platform === 'windows'
        ? \sprintf('powershell -NoProfile -ExecutionPolicy Bypass -File %s', \escapeshellarg($tool))
        : \sprintf('osascript %s', \escapeshellarg($tool));

    $status = 0;
    \exec(
        \sprintf(
            '%s %s --out %s 2>&1',
            $command,
            \escapeshellarg($document),
            \escapeshellarg($scratch),
        ),
        $output,
        $status,
    );

    if ($status !== 0) {
        throw new RuntimeException(\trim(\implode("\n", $output)) ?: 'Word did not render the document.');
    }

    // The tool names the PDF after the document it came from, and the caller asked for a
    // name of their own.
    $produced = $scratch . '/' . \pathinfo($document, PATHINFO_FILENAME) . '.pdf';

    if (! \is_file($produced)) {
        throw new RuntimeException(\sprintf('Word wrote no PDF for %s.', $document));
    }

    if (! @\rename($produced, $target)) {
        if (! @\copy($produced, $target)) {
            throw new RuntimeException(\sprintf('The PDF could not be moved to %s.', $target));
        }
    }
}

/**
 * Renders through WordCraft or DeckCraft.
 */
function renderWithCraft(string $document, string $target): void
{
    $command = craftFor($document);

    $status = 0;
    \exec(
        \sprintf('%s convert %s %s 2>&1', $command, \escapeshellarg($document), \escapeshellarg($target)),
        $output,
        $status,
    );

    if ($status !== 0) {
        throw new RuntimeException(\trim(\implode("\n", $output)) ?: 'The renderer did not produce a PDF.');
    }
}

/**
 * Renders through LibreOffice.
 */
function renderWithLibreOffice(string $document, string $target, string $scratch): void
{
    $command = \command_exists('soffice') ? 'soffice' : 'libreoffice';

    $status = 0;
    \exec(
        \sprintf(
            '%s --headless --convert-to pdf --outdir %s %s 2>&1',
            $command,
            \escapeshellarg($scratch),
            \escapeshellarg($document),
        ),
        $output,
        $status,
    );

    if ($status !== 0) {
        throw new RuntimeException(\trim(\implode("\n", $output)) ?: 'LibreOffice did not produce a PDF.');
    }

    $produced = $scratch . '/' . \pathinfo($document, PATHINFO_FILENAME) . '.pdf';

    if (! \is_file($produced)) {
        throw new RuntimeException(\sprintf('LibreOffice wrote no PDF for %s.', $document));
    }

    if (! @\rename($produced, $target)) {
        if (! @\copy($produced, $target)) {
            throw new RuntimeException(\sprintf('The PDF could not be moved to %s.', $target));
        }
    }
}
// `$argv` is defined by the CLI SAPI and by nothing else, so it is read guarded.
$arguments = \array_slice($argv ?? [], 1);
$showWhich = \in_array('--which', $arguments, true);
$showPlatform = \in_array('--platform-backend', $arguments, true);

$document = null;
$target = null;

for ($at = 0; $at < \count($arguments); $at++) {
    if ($arguments[$at] === '--out') {
        $target = $arguments[$at + 1] ?? null;
        $at++;
        continue;
    }

    if ($arguments[$at] === '--which' || $arguments[$at] === '--platform-backend') {
        continue;
    }

    $document ??= $arguments[$at];
}

if ($showPlatform) {
    \printf("%s\n", preferred(platform()));
    exit(0);
}

if ($showWhich) {
    // A document the caller did not name is a Word document: the craft backend for a
    // document that is not a deck is WordCraft, and that is what a bare `--which` asks
    // about.
    $backends = chosen(platform(), $document ?? 'document.docx');
    $backend = $backends[0] ?? 'none';

    \printf("%s\n", $backend);

    if ($backend !== 'none' && $backend !== TRUTH) {
        \fwrite(\STDERR, \sprintf(
            "%s is not Word, and it does not always agree with Word about where a line falls.\n",
            $backend,
        ));
    }

    exit(0);
}

if ($document === null) {
    \fwrite(\STDERR, "Usage: php render-document.php <document> [--out <file.pdf>]\n");
    exit(1);
}

if ($target === null) {
    $target = \pathinfo($document, PATHINFO_FILENAME) . '.pdf';
}

$machine = platform();
$backends = chosen($machine, $document);

if ($backends === []) {
    \fwrite(\STDERR, \sprintf(
        "Nothing on this machine can render a document: no Microsoft Word, no WordCraft and no LibreOffice.\n",
    ));
    exit(1);
}

try {
    $rendered = render($document, $target, $backends, $machine);
} catch (Throwable $problem) {
    \fwrite(\STDERR, $problem->getMessage() . "\n");
    exit(1);
}

// The note is printed after the render rather than before it, because the backend that
// ran is the one that drew the document and not the one that was chosen: a backend that
// failed falls through to the next, and a run that announced the first would be wrong.
if ($rendered['backend'] !== TRUTH) {
    \fwrite(\STDERR, \sprintf(
        "Rendered with %s, which is not Word. A renderer that is not Word does not always agree with Word about where a line falls, and an inline picture is laid out in a different place often enough that a page that fits here may not fit in Word.\n",
        $rendered['backend'],
    ));
}

foreach ($rendered['failures'] as $failure) {
    \fwrite(\STDERR, \sprintf("%s\n", $failure));
}

\printf("%s\n", $target);
