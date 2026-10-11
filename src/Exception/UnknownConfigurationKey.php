<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

use MarkdownWord\Configuration\Validator;

/**
 * A configuration key that names nothing.
 *
 * Thrown rather than ignored because of what the alternative costs. A key that does not
 * exist is dropped in four separate places — an unknown option, an unknown style slot,
 * an unknown font property and an unknown paragraph property — and each of them leaves
 * a document that looks finished and is configured as though the key had never been
 * written. Nothing renders differently, nothing warns, and the setting the author
 * asked for is simply absent.
 *
 * A value that is wrong is a different type, {@see InvalidConfigurationValue}, because
 * nothing about a typo can be guessed: `maxHeadingLevel: deep` used to become 1, which
 * renders every heading in the document as body text.
 *
 * @see Validator for where this is raised on its own
 */
class UnknownConfigurationKey extends InvalidConfiguration
{
    /**
     * @param string       $before what to say before the quoted key
     * @param string       $after  what to say after it
     * @param list<string> $known  every key that would have been accepted
     * @param string       $plural how the alternatives are introduced
     */
    public static function for(string $key, string $before, string $after, array $known, string $plural): self
    {
        return new self(Validator::describe($key, $before, $after, $known, $plural));
    }
}