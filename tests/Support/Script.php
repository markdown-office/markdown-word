<?php

declare(strict_types=1);

namespace MarkdownWord\Tests\Support;

use RuntimeException;

/**
 * Runs a program and says what it did.
 *
 * The tools in `tools/` are programs rather than library code, so the tests that hold
 * them to their promises have to run them. This is the one place that knows how.
 *
 * The program is run without a shell, so nothing it is given is read as a shell word:
 * a path with a space in it is a path with a space in it, and not two arguments. And
 * the environment it is given is the one it is told about rather than the one it
 * inherits, so a test can say which tools the machine has.
 */
final class Script
{
    /**
     * @param list<string> $command
     * @param list<string> $arguments
     * @param array<string, string> $environment
     *
     * @return array{out: string, err: string, status: int}
     */
    public static function run(
        array $command,
        array $arguments = [],
        array $environment = [],
        ?string $workingDirectory = null,
    ): array {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [...$command, ...$arguments],
            $descriptors,
            $pipes,
            $workingDirectory,
            array_merge(getenv(), $environment),
        );

        if (! is_resource($process)) {
            throw new RuntimeException('The program could not be started.');
        }

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['out' => $out, 'err' => $err, 'status' => proc_close($process)];
    }
}
