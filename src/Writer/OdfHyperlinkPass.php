<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\UnreadableDocument;
use MarkdownWord\Render\LinkPlaceholder;
use MarkdownWord\Xml;
use ZipArchive;

/**
 * Rewrites `content.xml` inside an `.odt`, replacing the placeholder runs emitted
 * for links whose label contains emphasis with genuine `text:a` elements.
 *
 * The ODF counterpart of {@see HyperlinkPass}, and it exists for the same reason:
 * `Writer\ODText\Element\Link` takes a single plain string, so a label like
 * `**Release** notes` would lose either the link or the bold without it.
 *
 * ODF has an advantage OOXML does not: a `text:a` is an ordinary element and the
 * runs go straight inside it, so there is no relationship part to add an entry to.
 */
final class OdfHyperlinkPass
{
    private const CONTENT_PATH = 'content.xml';

    private const TEXT_NS = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
    private const STYLE_NS = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';
    private const FO_NS = 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0';
    private const XLINK_NS = 'http://www.w3.org/1999/xlink';

    /**
     * @param list<array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}> $payloads
     */
    public function __construct(private readonly array $payloads)
    {
    }

    /**
     * @throws UnreadableDocument When the file is not a zip archive at all.
     * @throws MalformedDocument When that part is missing or is not XML.
     */
    public function applyTo(string $odtPath): void
    {
        $zip = new ZipArchive();

        if ($zip->open($odtPath) !== true) {
            throw new UnreadableDocument(sprintf('Unable to open "%s" as a zip archive.', $odtPath));
        }

        try {
            $content = $zip->getFromName(self::CONTENT_PATH);

            if ($content === false) {
                throw new MalformedDocument('The document is missing content.xml.');
            }

            $zip->deleteName(self::CONTENT_PATH);
            $zip->addFromString(self::CONTENT_PATH, $this->transform($content));
        } finally {
            $zip->close();
        }
    }

    private function transform(string $contentXml): string
    {
        $index = [];

        foreach ($this->payloads as $payload) {
            $index[$payload['placeholder']] = $payload;
        }

        $dom = Xml::parseOrFail($contentXml, 'content.xml is not valid XML.');

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('text', self::TEXT_NS);

        foreach ($xpath->query('//text:span') ?: [] as $span) {
            $payload = $this->match($span->textContent, $index);
            $paragraph = $span->parentNode;

            if ($payload === null || !$paragraph instanceof \DOMElement) {
                continue;
            }

            $paragraph->replaceChild($this->buildAnchor($dom, $payload), $span);
        }

        return (string) $dom->saveXML();
    }

    /**
     * @param  array<string, array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}>  $index
     * @return array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}|null
     */
    private function match(string $value, array $index): ?array
    {
        if (!str_contains($value, LinkPlaceholder::MARKER)) {
            return null;
        }

        foreach ($index as $placeholder => $payload) {
            if (str_contains($value, $placeholder)) {
                return $payload;
            }
        }

        return null;
    }

    /**
     * @param  array{placeholder: string, url: string, title: ?string, runs: list<array{text: string, style: array<string, mixed>}>}  $payload
     */
    private function buildAnchor(\DOMDocument $dom, array $payload): \DOMElement
    {
        $markup = '<text:a' . $this->declarations()
            . ' xlink:type="simple" xlink:href="' . self::escape($payload['url']) . '"';

        if ($payload['title'] !== null && $payload['title'] !== '') {
            $markup .= ' xlink:title="' . self::escape($payload['title']) . '"';
        }

        $markup .= '>' . implode('', array_map($this->spanMarkup(...), $payload['runs'])) . '</text:a>';

        return $this->parse($dom, $markup, 'text:a');
    }

    /**
     * A run is a `text:span` and its formatting is a `style:text-properties` element
     * inside it, which is how ODF carries character formatting: there is no run-level
     * attribute to hang it on.
     *
     * The properties go inline rather than into `office:automatic-styles`, because a
     * style named here would have to be declared on the root of the document, and the
     * root is a long way from the run that uses it.
     *
     * Assembled as markup and parsed once, rather than node by node: the serialiser
     * repeats a namespace declaration on every element built with `createElementNS()`,
     * so a document with ten such links would carry the same four declarations several
     * dozen times. Parsed, they are written once, on the fragment's own root.
     *
     * @param  array{text: string, style: array<string, mixed>}  $run
     */
    private function spanMarkup(array $run): string
    {
        $style = $run['style'];

        if ($style === []) {
            return '<text:span>' . self::escape($run['text']) . '</text:span>';
        }

        return sprintf(
            '<text:span><style:text-properties>%s</style:text-properties>%s</text:span>',
            $this->propertyMarkup($style),
            self::escape($run['text']),
        );
    }

    /**
     * @param array<string, mixed> $style
     */
    private function propertyMarkup(array $style): string
    {
        $properties = '';

        if (!empty($style['bold'])) {
            $properties .= ' fo:font-weight="bold"';
        }

        if (!empty($style['italic'])) {
            $properties .= ' fo:font-style="italic"';
        }

        if (!empty($style['strikethrough'])) {
            $properties .= ' style:text-line-through-type="single"';
        }

        if (isset($style['underline']) && $style['underline'] !== 'none') {
            $properties .= ' style:text-underline-style="solid"';
        }

        if (!empty($style['color'])) {
            $properties .= ' fo:color="#' . self::escape(ltrim((string) $style['color'], '#')) . '"';
        }

        if (!empty($style['size'])) {
            $properties .= ' fo:font-size="' . self::escape((string) $style['size']) . 'pt"';
        }

        if (!empty($style['name'])) {
            $properties .= ' style:font-name="' . self::escape((string) $style['name']) . '"';
        }

        return $properties;
    }

    /**
     * The namespaces a fragment is built from, declared on the fragment's own root.
     *
     * A fragment is parsed on its own, before it goes anywhere near the document,
     * so a prefix it uses has to be declared there or the parse fails on
     * something that is not malformed. The declarations are dropped again on the
     * way in, because the root of a `content.xml` PHPWord writes already declares
     * all four.
     */
    private function declarations(): string
    {
        return ' xmlns:text="' . self::TEXT_NS . '"'
            . ' xmlns:style="' . self::STYLE_NS . '"'
            . ' xmlns:fo="' . self::FO_NS . '"'
            . ' xmlns:xlink="' . self::XLINK_NS . '"';
    }

    /**
     * @throws MalformedDocument When the markup will not parse, which is a defect
     *         here rather than a property of the document.
     */
    private function parse(\DOMDocument $dom, string $markup, string $what): \DOMElement
    {
        $fragment = $dom->createDocumentFragment();

        try {
            $fragment->appendXML($markup);
        } catch (\DOMException) {
            throw new MalformedDocument(sprintf('The markup for %s could not be built.', $what));
        }

        $element = $fragment->firstChild;

        if (!$element instanceof \DOMElement) {
            throw new MalformedDocument(sprintf('The markup for %s produced no element.', $what));
        }

        return $element;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES);
    }
}