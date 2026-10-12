<?php

declare(strict_types=1);

namespace MarkdownWord\Template;

use DOMElement;
use DOMText;
use DOMXPath;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\NoContentControl;
use MarkdownWord\WordPackage;
use MarkdownWord\Xml;
use ZipArchive;

/**
 * Puts rendered Markdown where a Word content control sits, and removes the control.
 *
 * A region is not a Word idea, so a template cannot say "the Markdown goes here" in
 * Word's own vocabulary — until this: a **content control** is a placeholder a person
 * inserts from the Developer tab and can type into, and a rich-text one holds whole
 * paragraphs rather than a run of text. It is where a letterhead says "put the letter
 * under this", expressed in something Word already understands.
 *
 * ```xml
 * <w:sdt>
 *   <w:sdtPr>
 *     <w:alias w:val="body"/>
 *     <w:tag w:val="body"/>
 *     <w:richText/>
 *   </w:sdtPr>
 *   <w:sdtContent>
 *     <w:p><w:r><w:t>Click here to enter text.</w:t></w:r></w:p>
 *   </w:sdtContent>
 * </w:sdt>
 * ```
 *
 * A template with no content control is one that names no place for the content, and
 * says so rather than guessing — the alternative is content silently landing at the
 * end of a document, above the letterhead's own signature block, which looks like a
 * finished document and is not one.
 *
 * The control is matched on its **tag**, which is the field name Word gives it, rather
 * than its alias: the alias is the label a person sees in Word's own UI and is the
 * one they will rename while editing the template, whereas the tag is what a program
 * reads. A template that has both is written by something that knows the difference.
 *
 * The content control goes once the content is in, for the same reason a filled field
 * is unlinked: a finished document should hold no leftover placeholder, and one left
 * behind invites somebody to type into it.
 */
final class ContentControl
{
    /** What a control is matched on, and what the README calls it. */
    public const BODY = 'body';

    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * The XML for a rich-text content control named `$name`.
     *
     * Written by a template that wants a body rather than only by the renderer, so that
     * the template can be opened and filled in Word on its own before this library has
     * ever seen it.
     */
    public static function markup(string $name, string $placeholder = 'Click here to enter text.'): string
    {
        return sprintf(
            '<w:sdt><w:sdtPr><w:alias w:val="%1$s"/><w:tag w:val="%1$s"/>'
            . '<w:id w:val="%2$d"/><w:richText/></w:sdtPr>'
            . '<w:sdtContent><w:p><w:r><w:t>%3$s</w:t></w:r></w:p></w:sdtContent></w:sdt>',
            htmlspecialchars($name, ENT_QUOTES),
            self::id($name),
            htmlspecialchars($placeholder, ENT_QUOTES),
        );
    }

    /**
     * Put the content where the control is, and take the control away.
     *
     * @param list<string> $elements The rendered blocks, as XML fragments.
     *
     * @throws MalformedDocument When the part holding the control is not XML.
     */
    public function fill(string $docxPath, string $name, array $elements): void
    {
        $zip = new ZipArchive();

        if ($zip->open($docxPath) !== true) {
            throw new MalformedDocument(sprintf('Unable to open "%s" as a zip archive.', $docxPath));
        }

        try {
            $part = WordPackage::bodyPart($zip);
            $xml = $zip->getFromName($part);

            if (!is_string($xml)) {
                return;
            }

            $filled = $this->replace($xml, $part, $name, $elements);

            if ($filled !== $xml) {
                $zip->deleteName($part);
                $zip->addFromString($part, $filled);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * The control of that name, as a list of the XPath results rather than one, because
     * a template may carry the same control in a header and in the body.
     *
     * @param list<string> $elements
     * @throws MalformedDocument When the part is not XML.
     */
    private function replace(string $xml, string $part, string $name, array $elements): string
    {
        $dom = Xml::parse($xml) ?? throw new MalformedDocument(
            sprintf('%s is not valid XML.', $part),
        );

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);

        $control = self::find($xpath, $name);

        if (!$control instanceof DOMElement) {
            throw new NoContentControl($name, self::tagsIn($xpath));
        }

        $content = $control->getElementsByTagNameNS(self::W_NS, 'sdtContent')->item(0);

        if (!$content instanceof DOMElement) {
            throw new MalformedDocument('A content control has no content to replace.');
        }

        // The rendered blocks replace the control's own placeholder rather than being
        // written inside `sdtContent`: the control has served its purpose once the
        // content is there, and a finished document should hold no placeholder
        // inviting somebody to type into it.
        //
        // Beside the control, not inside it, and not at the end of the document — a
        // letterhead's closing line and signature block come after the content, so
        // where the control sits is what decides that.
        $parent = $control->parentNode;

        foreach ($elements as $xml) {
            $block = Xml::parse(sprintf('<w xmlns:w="%s">%s</w>', self::W_NS, $xml));

            if ($block === null) {
                throw new MalformedDocument('A rendered block is not XML.');
            }

            foreach ($block->documentElement?->childNodes ?? [] as $child) {
                // Whitespace between elements is not markup and is not wanted either:
                // a text node that is only a newline is written into the document as
                // a line break nobody asked for.
                if ($child instanceof DOMText && trim($child->textContent) === '') {
                    continue;
                }

                $parent?->insertBefore($dom->importNode($child, true), $control);
            }
        }

        $parent?->removeChild($control);

        // `saveXML()` indents what it did not author, and whitespace that lands
        // between a `w:t` and its text is *text* in Word: a document written out this
        // way has every paragraph reading as the literal characters `<w:p>`. The
        // formatting is off, and what whitespace is left is what the template
        // brought with it rather than what this function added.
        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = true;

        return (string) $dom->saveXML();
    }

    /**
     * Every tag in the part, for a failure that has to say what would have worked.
     *
     * @return list<string>
     */
    private static function tagsIn(DOMXPath $xpath): array
    {
        $tags = [];

        foreach ($xpath->query('//w:sdt') ?: [] as $control) {
            foreach ($control->getElementsByTagNameNS(self::W_NS, 'tag') as $tag) {
                $value = $tag->getAttributeNS(self::W_NS, 'val');

                if ($value !== '') {
                    $tags[] = $value;
                }
            }
        }

        $tags = array_values(array_unique($tags));
        sort($tags);

        return $tags;
    }

    /**
     * The control whose **tag** is that name.
     *
     * The tag rather than the alias: {@see self::markup()} writes both, and the alias
     * is the visible label a person edits while working on the template.
     */
    private static function find(DOMXPath $xpath, string $name): ?DOMElement
    {
        foreach ($xpath->query('//w:sdt') ?: [] as $control) {
            if (!$control instanceof DOMElement) {
                continue;
            }

            foreach ($control->getElementsByTagNameNS(self::W_NS, 'tag') as $tag) {
                if ($tag->getAttributeNS(self::W_NS, 'val') === $name) {
                    return $control;
                }
            }
        }

        return null;
    }

    /**
     * An id for a control, derived from its name so the same name always gives the same
     * one.
     *
     * Word numbers these itself and does not require them to be unique across the
     * document, but a fixed number means a template written twice is the same template
     * and a diff between two of them is a diff of what changed rather than of what was
     * renumbered.
     */
    private static function id(string $name): int
    {
        return (int) sprintf('%u', crc32($name)) % 1000000;
    }
}