<?php

declare(strict_types=1);

namespace MarkdownWord\Document;

use MarkdownWord\Configuration;

/**
 * Where a configuration comes from, and which one wins.
 *
 * The order is the parameter list of {@see self::resolve()}, read from last to first:
 * defaults, then the config file, then the frontmatter, then the command line. It is a
 * list rather than a chain of `with*()` calls because the precedence is a property of
 * *where a value came from* rather than of when it was applied — two callers who reach
 * the merge by different routes have to arrive at the same configuration, and a builder
 * makes that depend on the order they happened to call its methods in.
 *
 * ```php
 * ConfigurationMerger::resolve(
 *     commandLine: $argv,
 *     frontmatter: Frontmatter::fromDocument($document),
 *     configFile: require 'markdown.php',
 * );
 * ```
 *
 * Each source is applied over the result of the ones beneath it, so the merge reads the
 * way the argument list reads. The order is the one a document author expects: the file
 * is their intent, and the command line is them saying it louder. It is the same order
 * {@see \MarkdownWord\Configuration\Options::withAll()} already implies for a chain of
 * `with*()` calls, so a value behaves the same whether it arrived from a file or an array.
 */
final class ConfigurationMerger
{
    /**
     * The configuration to render with.
     *
     * A source that is `null` or empty is skipped rather than read as "reset
     * everything". A command line with no option flags says nothing about the options,
     * and treating its silence as an instruction to drop them would make two ways of
     * running the same document produce two different documents.
     *
     * @param array{styles?: array<string, mixed>, options?: array<string, mixed>}|null $commandLine
     * @param array{styles?: array<string, mixed>, options?: array<string, mixed>}|null $configFile
     */
    public static function resolve(
        ?array $commandLine = null,
        ?Frontmatter $frontmatter = null,
        ?array $configFile = null,
        ?Configuration $defaults = null,
    ): Configuration {
        $configuration = $defaults ?? Configuration::create();

        // Least significant first, so that each source is applied over the ones it
        // outranks and needs no rule of its own about who wins.
        foreach ([$configFile, $frontmatter?->toConfigurationArray(), $commandLine] as $source) {
            if ($source !== null && $source !== []) {
                $configuration = $configuration->withAll($source);
            }
        }

        return $configuration;
    }
}