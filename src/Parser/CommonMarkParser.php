<?php

declare(strict_types=1);

namespace MarkdownWord\Parser;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Attributes\AttributesExtension;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\DefaultAttributes\DefaultAttributesExtension;
use League\CommonMark\Extension\DescriptionList\DescriptionListExtension;
use League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\FrontMatter\Data\SymfonyYamlFrontMatterParser;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\Highlight\HighlightExtension;
use League\CommonMark\Extension\Mention\MentionExtension;
use League\CommonMark\Extension\NormalizeHeadings\NormalizeHeadingsExtension;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Parser\MarkdownParser;

/**
 * The default parser: full CommonMark plus the GitHub-Flavored Markdown
 * extensions, which bring tables, strikethrough, task lists and autolinks.
 *
 * What matters is what it returns — the *abstract syntax tree*. The renderer
 * walks that rather than HTML, so emphasis nesting, hard breaks, entity
 * references and link reference definitions arrive as the author wrote them
 * instead of flattened into a string of tags.
 */
final class CommonMarkParser implements MarkdownParserInterface
{
    /**
     * What each set adds *on top of* CommonMark, which {@see self::parse()} adds
     * regardless.
     *
     * @var array<string, list<class-string>>
     */
    public const FLAVOURS = [
        'commonmark' => [],
        'gfm' => [GithubFlavoredMarkdownExtension::class],
        'extended' => [
            GithubFlavoredMarkdownExtension::class,
            FootnoteExtension::class,
            DescriptionListExtension::class,
        ],
    ];

    /**
     * @var list<class-string>
     */
    private array $extensions;

    /**
     * @param list<class-string>|null $extensions `null` selects the default, GFM;
     *        an empty list means CommonMark only, which is why the two cannot be
     *        the same value.
     * @param array<string, mixed> $config        Passed to the CommonMark
     *        {@see \League\CommonMark\Environment\Environment}.
     */
    public function __construct(?array $extensions = null, private readonly array $config = [])
    {
        $this->extensions = $extensions === null ? self::FLAVOURS['gfm'] : array_values($extensions);
    }

    public static function commonMarkOnly(): self
    {
        return new self(self::FLAVOURS['commonmark']);
    }

    /** On top of GFM: footnotes and description lists. */
    public static function extended(): self
    {
        return new self(self::FLAVOURS['extended']);
    }

    /**
     * A parser with every extension the installed `league/commonmark` release
     * ships with that can be enabled without extra configuration.
     *
     * Deliberately left out, each for a concrete reason:
     *
     *  - `SmartPunctExtension` rewrites the author's characters: straight quotes
     *    into curly ones, `--` into a dash, `...` into an ellipsis;
     *  - `InlinesOnlyExtension` adds its own `*` and `_` emphasis delimiters,
     *    and the environment refuses to build with two processors for one char;
     *  - `EmbedExtension` requires an `embed.adapter` object; and
     *  - `TableOfContentsExtension` requires its own configuration and yields a
     *    placeholder that means little in a Word document.
     *
     * They remain available by constructing the parser with an explicit list.
     */
    public static function withAllExtensions(): self
    {
        return new self([
            ...self::FLAVOURS['gfm'],
            AttributesExtension::class,
            AutolinkExtension::class,
            DefaultAttributesExtension::class,
            DescriptionListExtension::class,
            DisallowedRawHtmlExtension::class,
            ExternalLinkExtension::class,
            FootnoteExtension::class,
            FrontMatterExtension::class,
            HeadingPermalinkExtension::class,
            HighlightExtension::class,
            MentionExtension::class,
            NormalizeHeadingsExtension::class,
        ]);
    }

    public function parse(string $markdown): Document
    {
        $environment = new Environment($this->config);

        $environment->addExtension(new CommonMarkCoreExtension());

        foreach ($this->extensions as $extension) {
            $environment->addExtension($extension === FrontMatterExtension::class
                ? self::frontMatter()
                : new $extension());
        }

        return (new MarkdownParser($environment))->parse($markdown);
    }

    /**
     * Front matter, read by symfony/yaml.
     *
     * Given no parser, `FrontMatterExtension` takes libyaml wherever `ext-yaml`
     * is loaded and falls back to symfony/yaml only where it is not, so the same
     * block reads differently on a CI runner and on a checkout. The two disagree
     * about values and not merely about types: `color: 000000` is the string
     * `000000` under symfony/yaml and the integer `0` under libyaml, which is the
     * difference between a black heading and a document refused as malformed.
     */
    private static function frontMatter(): FrontMatterExtension
    {
        return new FrontMatterExtension(new SymfonyYamlFrontMatterParser());
    }
}
