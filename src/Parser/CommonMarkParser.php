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
use MarkdownOffice\CommonMark\Directive\Directive\DirectiveRegistry;
use MarkdownOffice\CommonMark\Directive\GenericDirectiveExtension;

/**
 * The default parser: full CommonMark plus the GitHub-Flavored Markdown
 * extensions, which bring tables, strikethrough, task lists and autolinks.
 *
 * What matters is what it returns — the *abstract syntax tree*. The renderer
 * walks that rather than HTML, so emphasis nesting, hard breaks, entity
 * references and link reference definitions arrive as the author wrote them
 * instead of flattened into a string of tags.
 *
 * Directives are on by default now, which is a change of behaviour rather than
 * an addition: `Status :green[go]` parses as a directive instead of prose. It
 * is safe because a directive this library does not draw is rendered as its own
 * source form — see {@see \MarkdownWord\Render\Directives} — so a name nothing
 * claims comes back out as the characters the author typed. Only a name the
 * renderer claims changes a document.
 */
final class CommonMarkParser implements MarkdownParserInterface
{
    /**
     * Which of {@see self::FLAVOURS} a `new CommonMarkParser()` gets.
     *
     * A name rather than a list of classes so that the default is stated once and
     * cannot drift between the constant, the docblock and the factory that is
     * supposed to agree with it.
     */
    public const DEFAULT_FLAVOUR = 'directives';
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
        'directives' => [
            GithubFlavoredMarkdownExtension::class,
            GenericDirectiveExtension::class,
        ],
    ];

    /**
     * @var list<class-string>
     */
    private array $extensions;

    /**
     * @param list<class-string>|null $extensions `null` selects
     *        {@see self::DEFAULT_FLAVOUR}; an empty list means CommonMark only, which
     *        is why the two cannot be the same value.
     * @param array<string, mixed> $config        Passed to the CommonMark
     *        {@see \League\CommonMark\Environment\Environment}.
     */
    public function __construct(?array $extensions = null, private readonly array $config = [])
    {
        $this->extensions = $extensions === null
            ? self::FLAVOURS[self::DEFAULT_FLAVOUR]
            : array_values($extensions);
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
     * On top of GFM: the Generic Directives RFC — `:name[…]`, `::name[…]`,
     * `:::name` … `:::`.
     *
     * Was opt-in, because a directive is a word the author chose and
     * `Status :green[go]` is a sentence — the reasoning is below, and it is why
     * this is now the default flavour without the sentence changing.
     *
     * Permissive, which is what the RFC describes and what the extension does
     * when given nothing: any well-formed directive parses.
     * {@see GenericDirectiveExtension} also takes a {@see DirectiveRegistry},
     * which makes it strict — an unregistered name raises `UnknownDirective`
     * rather than being ignored — and that mode is *not* reachable from here, or
     * from {@see self::__construct()}: the extension list is a list of class names
     * and each one is built with `new`, so a caller handing over an instance with
     * a registry on it gets a fresh one without it. Which default this library
     * wants is a question for its users, not one to settle in a constructor.
     *
     * What reaches a Word document is decided by
     * {@see \MarkdownWord\Render\Directives}: a name it draws is rendered, and
     * a name it does not is rendered as its source form. Asserted in
     * tests/Unit/directive-grammar.php.
     *
     * @see https://talk.commonmark.org/t/generic-directives-plugins-syntax/444
     */
    public static function directives(): self
    {
        return new self(self::FLAVOURS['directives']);
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
     *
     * `GenericDirectiveExtension` is left out because {@see self::DEFAULT_FLAVOUR}
     * already carries it and this method is about the extensions that are *not*
     * on by default; listing it here as well would put it in twice.
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
