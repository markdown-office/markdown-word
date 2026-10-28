<?php

declare(strict_types=1);

/**
 * Renders a document with the most truthful tool the machine has.
 *
 *   php tools/render-document.php <document.docx> [--out <file.pdf>]
 *   php tools/render-document.php --which
 *   php tools/render-document.php --platform-backend
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
 * The platform and the backend can both be overridden, which is what makes the choice
 * testable without Word and without a Windows machine:
 *
 *   * `MARKDOWN_OFFICE_PLATFORM` — `darwin`, `windows`, `linux`, `freebsd`.
 *   * `MARKDOWN_OFFICE_RENDERER` — `word`, `wordcraft`, `libreoffice`.
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
 * The command is the whole of the test: a backend whose command is not on the path is a
 * backend that is not installed, and no amount of looking for it elsewhere will change
 * that.
 */
function installed(string $backend, string $platform): bool
{
    return match ($backend) {
        'word' => $platform === 'darwin'
            ? \command_exists('osascript')
            : ($platform === 'windows' ? \command_exists('powershell') : false),
        'wordcraft' => \command_exists('wordcraft-cli') || \command_exists('deckcraft-cli'),
        'libreoffice' => \command_exists('soffice') || \command_exists('libreoffice'),
        default => false,
    };
}

/**
 * Whether a command is on the path.
 */
function command_exists(string $command): bool
{
    $status = 0;
    \exec('command -v ' . \escapeshellarg($command) . ' 2>/dev/null', $ignored, $status);

    return $status === 0;
}

/**
 * The backend that would be used on this machine.
 *
 * The platform's preference when it is installed, then the next backend that is, then
 * nothing. The order is the order of how much each can be trusted, so the first one that
 * is installed is the one that is used.
 *
 * @return array{backend: string, faithful: bool}
 */
function chosen(string $platform): array
{
    $forced = \getenv('MARKDOWN_OFFICE_RENDERER');

    if (\is_string($forced) && $forced !== '') {
        return ['backend' => $forced, 'faithful' => $forced === TRUTH];
    }

    $order = BACKENDS;
    $preferredBackend = preferred($platform);

    if ($preferredBackend !== 'none') {
        // The platform's preference goes first, and the rest keep the order of trust.
        $order = \array_values(\array_unique(\array_merge([$preferredBackend], BACKENDS)));
    }

    foreach ($order as $backend) {
        if (installed($backend, $platform)) {
            return ['backend' => $backend, 'faithful' => $backend === TRUTH];
        }
    }

    return ['backend' => 'none', 'faithful' => false];
}

/**
 * Renders a document.
 *
 * @throws RuntimeException when the render did not produce the file it promised.
 */
function render(string $document, string $target, string $backend, string $platform): void
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

    if (! \is_file($target) || \filesize($target) === 0) {
        throw new RuntimeException(\sprintf('The render produced no file at %s.', $target));
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
    $command = \str_ends_with(\strtolower($document), '.pptx')
        ? 'deckcraft-cli'
        : 'wordcraft-cli';

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

//
// The run.
//

// `$argv` is defined by the CLI SAPI and by nothing else, so it is read guarded.
$arguments = \array_slice($argv ?? [], 1);
$showWhich = \in_array('--which', $arguments, true);
$showPlatform = \in_array('--platform-backend', $arguments, true);

if ($showWhich || $showPlatform) {
    $machine = platform();

    if ($showPlatform) {
        \printf("%s\n", preferred($machine));
        exit(0);
    }

    $choice = chosen($machine);

    \printf("%s\n", $choice['backend']);

    if ($choice['backend'] !== 'none' && ! $choice['faithful']) {
        \fwrite(\STDERR, \sprintf(
            "%s is not Word, and it does not always agree with Word about where a line falls.\n",
            $choice['backend'],
        ));
    }

    exit(0);
}

$document = null;
$target = null;

for ($at = 0; $at < \count($arguments); $at++) {
    if ($arguments[$at] === '--out') {
        $target = $arguments[$at + 1] ?? null;
        $at++;
        continue;
    }

    $document ??= $arguments[$at];
}

if ($document === null) {
    \fwrite(STDERR, "Usage: php tools/render-document.php <document> [--out <file.pdf>]\n");
    exit(1);
}

if ($target === null) {
    $target = \pathinfo($document, PATHINFO_FILENAME) . '.pdf';
}

$machine = platform();
$choice = chosen($machine);

if ($choice['backend'] === 'none') {
    \fwrite(STDERR, \sprintf(
        "Nothing on this machine can render a document: no Microsoft Word, no WordCraft and no LibreOffice.\n",
    ));
    exit(1);
}

if (! $choice['faithful']) {
    \fwrite(STDERR, \sprintf(
        "Rendered with %s, which is not Word. LibreOffice does not always agree with Word about where a line falls, and an inline picture is laid out in a different place often enough that a page that fits here may not fit in Word.\n",
        $choice['backend'],
    ));
}

try {
    render($document, $target, $choice['backend'], $machine);
} catch (Throwable $problem) {
    \fwrite(STDERR, $problem->getMessage() . "\n");
    exit(1);
}

\printf("%s\n", $target);
