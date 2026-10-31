<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * The template holds more than this library is willing to read.
 *
 * A `.dotx` is a zip archive like any other, and a zip says how its contents are laid
 * out and nothing about how much room they will take up. The two reasons are the ones
 * the read path has: the archive holds more parts than are allowed, or one of its
 * parts claims to be larger than is allowed. A template that is fine and a machine
 * that is not is a {@see FileNotWritable}.
 */
class UnreadableTemplate extends InvalidInput
{
}
