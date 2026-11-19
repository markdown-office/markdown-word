<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use MarkdownWord\Configuration\Options;
use PhpOffice\PhpWord\Element\AbstractContainer;

/**
 * Renders raw HTML found in the Markdown.
 *
 * `strip`, the default, removes the tags but keeps the text they wrap:
 * `<div>foo</div>` contributes the word *foo*, not the markup. `preserve` keeps
 * the markup as literal monospaced text and `drop` discards the fragment.
 * Emitting the tags as real OOXML is deliberately not an option — it would mean
 * injecting unvalidated XML into the document.
 */
final class HtmlFragmentRenderer
{
    /** Tags that imply a line break in the rendered output. */
    private const BLOCK_TAGS = [
        'address', 'article', 'aside', 'blockquote', 'div', 'dd', 'dl', 'dt',
        'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3',
        'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre',
        'section', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    public function __construct(
        private readonly Options $options,
        private readonly StyleResolver $styles,
    ) {
    }

    public function render(string $html, AbstractContainer $target, InlineStyle $style = new InlineStyle()): void
    {
        $html = trim($html);

        if ($html === '' || $this->options->html === Options::HTML_DROP) {
            return;
        }

        if ($this->options->html === Options::HTML_PRESERVE) {
            $target->addText($html, $this->styles->fontFor($style->withCode()));

            return;
        }

        $body = $this->parse($html);

        if ($body === null) {
            $target->addText(strip_tags($html), $this->styles->fontFor($style));

            return;
        }

        foreach ($body->childNodes as $child) {
            $this->walk($child, $target, $style);
        }
    }

    /**
     * Inline HTML is spliced into a run that may already contain text, so it gets
     * none of the block-level line breaks a standalone fragment does.
     */
    public function renderInline(string $html, AbstractContainer $target, InlineStyle $style): void
    {
        $html = trim($html);

        if ($html === '' || $this->options->html === Options::HTML_DROP) {
            return;
        }

        if ($this->options->html === Options::HTML_PRESERVE) {
            $target->addText($html, $this->styles->fontFor($style->withCode()));

            return;
        }

        $body = $this->parse($html);

        if ($body === null) {
            $target->addText(strip_tags($html), $this->styles->fontFor($style));

            return;
        }

        foreach ($body->childNodes as $child) {
            $this->walk($child, $target, $style, inline: true);
        }
    }

    private function parse(string $html): ?\DOMNode
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        // `LIBXML_HTML_NOIMPLIED` discards block elements unless there is a body
        // for them to sit in, so the wrapper is what makes a fragment parse whole
        // rather than lose the tags around its text.
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8" ?><body>' . $html . '</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }

        return $dom->getElementsByTagName('body')->item(0);
    }

    private function walk(
        \DOMNode $node,
        AbstractContainer $target,
        InlineStyle $style,
        bool $inline = false,
    ): void {
        // Text is content; a comment is content to nobody, and so is a node that
        // is neither text nor an element.
        if (!$node instanceof \DOMElement) {
            if ($node instanceof \DOMText) {
                $this->appendText($node->textContent, $target, $style, $node->parentNode);
            }

            return;
        }

        $tag = strtolower($node->nodeName);

        // A `<br>` is a line break wherever it appears; the other void elements
        // have no content to contribute.
        if ($tag === 'br') {
            $target->addTextBreak(1, $this->styles->fontFor($style));

            return;
        }

        if (in_array($tag, ['hr', 'img', 'input', 'meta', 'link', 'source'], true)) {
            return;
        }

        $inner = $this->mapStyle($tag, $node, $style);
        $isBlock = !$inline && in_array($tag, self::BLOCK_TAGS, true);

        if ($isBlock) {
            $target->addTextBreak(1, $this->styles->fontFor($style));
        }

        foreach ($node->childNodes as $child) {
            $this->walk($child, $target, $inner, $inline);
        }

        if ($isBlock) {
            $target->addTextBreak(1, $this->styles->fontFor($style));
        }
    }

    private function mapStyle(string $tag, \DOMElement $node, InlineStyle $style): InlineStyle
    {
        $mapped = match ($tag) {
            'b', 'strong' => $style->withBold(),
            'i', 'em', 'cite', 'var', 'dfn' => $style->withItalic(),
            's', 'del', 'strike' => $style->withStrikethrough(),
            'code', 'kbd', 'samp', 'tt' => $style->withCode(),
            default => $style,
        };

        if ($tag === 'a') {
            $href = $node->getAttribute('href');
            if ($href !== '') {
                $mapped = $mapped->withLink($href, $node->getAttribute('title') ?: null);
            }
        }

        return $mapped;
    }

    /**
     * Whitespace between tags is collapsed, as a browser would, except inside
     * `pre`, `textarea` and `code`, where it is left as it was found.
     */
    private function appendText(string $text, AbstractContainer $target, InlineStyle $style, ?\DOMNode $parent): void
    {
        $preserve = $parent instanceof \DOMElement
            && in_array(strtolower($parent->nodeName), ['pre', 'textarea', 'code'], true);

        $text = $preserve ? $text : (string) preg_replace('/[ \t\r\n]+/', ' ', $text);

        if ($text === '') {
            return;
        }

        $target->addText($text, $this->styles->fontFor($style));
    }
}
