<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

use MarkdownWord\Configuration\Validator;

/**
 * A configuration value that is not what it is used as.
 *
 * Raised alongside {@see UnknownConfigurationKey} and caught with it, through
 * {@see InvalidConfiguration}. Keys were the easy half: a name that is in no list
 * cannot be used, and saying so costs the reader nothing. A value is worse, because
 * each one can be wrong in a way that still renders — the document comes out and
 * looks plausible while being configured as though the value had not been written,
 * which is the whole reason {@see UnknownConfigurationKey} exists.
 *
 * `maxHeadingLevel: deep` was the worst of them, and the reason this type exists:
 * the option is clamped to 1–6, `(int) "deep"` is 0, and the document came out with
 * every heading in it rendered as body text.
 *
 * Every message names the value itself rather than only the key, and every one says
 * what would have been accepted.
 */
final class InvalidConfigurationValue extends InvalidConfiguration
{
    /**
     * @param string $where the key it was written under, named as it reads in the
     *                      document: `The "images" option`, or
     *                      `The "color" property of the "heading.1" style`
     * @param string $given the value as it was written, spelled by
     *                      {@see Validator::show()}
     * @param string $want  what a value that would have worked looks like
     */
    public static function for(string $where, string $given, string $want): self
    {
        return new self(Validator::describeValue($where, $given, $want));
    }
}