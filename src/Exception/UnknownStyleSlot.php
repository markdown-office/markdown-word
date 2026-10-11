<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

use MarkdownWord\Configuration\Validator;

/**
 * A theme named a style slot that does not exist.
 *
 * The same argument as {@see UnknownConfigurationKey}, one level down: a slot that does
 * not exist is dropped silently, and the document comes out styled as though the line had
 * never been written — which for a theme is the whole of what the author was asking for.
 * Raised at load time rather than at render time so the typo is reported before a byte of
 * the document is built, next to the line that caused it.
 */
final class UnknownStyleSlot extends InvalidConfiguration
{
    /**
     * @param list<string> $known every slot that would have been accepted
     */
    public static function named(string $file, string $slot, array $known): self
    {
        return new self(sprintf(
            '%s: %s',
            $file,
            Validator::describe($slot, 'style ', '', $known, 'styles'),
        ));
    }
}