<?php

declare(strict_types=1);

namespace MarkdownWord\Exception;

/**
 * A configuration this library will not guess its way through.
 *
 * The base for the two things that are wrong with a `---` block, so one `catch`
 * covers a key that names nothing and a value that is not what it claims to be.
 * {@see UnknownConfigurationKey} extends it, so a caller written against this
 * library as it was still catches the half that has always been raised — only the
 * value errors are new.
 */
class InvalidConfiguration extends InvalidInput
{
}