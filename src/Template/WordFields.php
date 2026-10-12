<?php

declare(strict_types=1);

namespace MarkdownWord\Template;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\WordPackage;
use MarkdownWord\Xml;
use ZipArchive;

/**
 * Fills Word's own fields and then removes them.
 *
 * A `MERGEFIELD` is a Word construct, not one of this library's: a person inserts one
 * with Ctrl+F9, names it, and Word stores it as a field element rather than as text.
 * That is worth supporting in preference to a syntax invented here, because a template
 * authored in Word — which is where a template comes from — already has them.
 *
 * Word stores a field two ways and both are in the wild. The simple form is one
 * element carrying its own cached result:
 *
 * ```xml
 * <w:fldSimple w:instr=" MERGEFIELD client \* MERGEFORMAT ">
 *   <w:r><w:t>«client»</w:t></w:r>
 * </w:fldSimple>
 * ```
 *
 * and the complex form is five runs, where the value sits between `separate` and
 * `end` and everything outside it is instruction:
 *
 * ```xml
 * <w:r><w:fldChar w:fldCharType="begin"/></w:r>
 * <w:r><w:instrText> MERGEFIELD client \* MERGEFORMAT </w:instrText></w:r>
 * <w:r><w:fldChar w:fldCharType="separate"/></w:r>
 * <w:r><w:t>«client»</w:t></w:r>
 * <w:r><w:fldChar w:fldCharType="end"/></w:r>
 * ```
 *
 * Word writes the second most often, so supporting only the first would fail on most
 * templates rather than on none.
 *
 * **Filled fields are unlinked**, which is what a finished document wants: the value
 * stays and the field goes, so there is nothing to refresh with F9 and nothing for the
 * reader to press. It is what Word's own "Finish & Merge → Edit Individual Documents"
 * produces, and it is the reason a value can contain markup characters without becoming
 * an instruction.
 *
 * Only `MERGEFIELD` is touched. A document is full of other fields — `PAGE`, `TOC`,
 * `DATE`, `REF` — which are the document's own business and are left exactly as they
 * are, because a template that filled `PAGE` with a value from a frontmatter block
 * would produce a document whose page numbers were wrong.
 */
final class WordFields
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** @var array<string, string> */
    private readonly array $values;

    /**
     * @param array<string, string|int|float> $values Name to value, as a `MERGEFIELD`'s
     *        instruction names it.
     */
    public function __construct(array $values)
    {
        $folded = [];

        // Word folds field names to upper case when it stores them, so `client` and
        // `CLIENT` are the same field and one of them has to win rather than both.
        foreach ($values as $name => $value) {
            $folded[strtoupper((string) $name)] = (string) $value;
        }

        $this->values = $folded;
    }

    /**
     * Fill the fields in a document on disk, in every part that can hold one.
     *
     * The body, the headers and the footers: a letterhead puts the client's name in a
     * header, and a value the template put there is as much this library's business as
     * one in the body. Numbering and styles carry none and are not opened.
     *
     * @throws FileNotWritable When a part cannot be written back.
     * @throws MalformedDocument When a part that holds fields is not XML.
     */
    public function applyTo(string $docxPath): void
    {
        if ($this->values === []) {
            return;
        }

        $zip = new ZipArchive();

        if ($zip->open($docxPath) !== true) {
            throw new FileNotWritable(sprintf('Unable to open "%s" for writing.', $docxPath));
        }

        try {
            foreach (self::parts($zip) as $part) {
                $xml = $zip->getFromName($part);

                if (!is_string($xml)) {
                    continue;
                }

                $filled = $this->fill($xml, $part);

                if ($filled !== $xml) {
                    $zip->deleteName($part);
                    $zip->addFromString($part, $filled);
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * The parts a field can be in.
     *
     * @return list<string>
     */
    private static function parts(ZipArchive $zip): array
    {
        $parts = [WordPackage::bodyPart($zip)];

        // The header and footer parts are numbered from one and are contiguous only
        // because that is how Word writes them; `locateName` says whether one is there
        // rather than assuming it is.
        for ($index = 1; $index <= 99; $index++) {
            foreach (['header', 'footer'] as $kind) {
                $part = sprintf('word/%s%d.xml', $kind, $index);

                if ($zip->locateName($part) !== false) {
                    $parts[] = $part;
                }
            }
        }

        return $parts;
    }

    /**
     * @throws MalformedDocument When the part is not XML.
     */
    private function fill(string $xml, string $part): string
    {
        $dom = Xml::parse($xml) ?? throw new MalformedDocument(
            sprintf('%s is not valid XML.', $part),
        );

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);

        $this->fillSimple($xpath);
        $this->fillComplex($xpath);

        return (string) $dom->saveXML();
    }

    /** The one-element form, which carries its own result. */
    private function fillSimple(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//w:fldSimple') ?: [] as $field) {
            if (!$field instanceof DOMElement) {
                continue;
            }

            $name = self::nameOf($field->getAttribute('w:instr'));

            if ($name === null) {
                continue;
            }

            $value = $this->values[$name] ?? null;

            if ($value === null) {
                continue;
            }

            $replacement = self::valueRun($field, $value);

            if ($replacement !== null) {
                $field->parentNode?->replaceChild($replacement, $field);
            }
        }
    }

    /**
     * The five-run form, where the result is whatever sits between `separate` and `end`.
     *
     * The runs are collected rather than the `w:t` inside them, because a value
     * replacing a result has to take the result's *run properties* with it or the text
     * comes out in whatever face the instruction runs happened to be in.
     */
    private function fillComplex(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//w:r[w:fldChar/@w:fldCharType="begin"]') ?: [] as $begin) {
            $paragraph = $begin->parentNode;

            if (!$paragraph instanceof DOMElement) {
                continue;
            }

            $name = self::complexName($begin);

            if ($name === null || !isset($this->values[$name])) {
                continue;
            }

            $runs = self::runsFrom($begin);
            $end = self::runWhereCharIs($runs, 'end');

            if ($end === null) {
                continue;
            }

            // The run to take the formatting from is the *result* — the one carrying
            // the text the field last showed — and not the `end` run, which is only
            // a delimiter and carries nothing. A complex field puts the value
            // between `separate` and `end`, and that is where its formatting is.
            $result = self::resultRun($runs) ?? $end;

            $replacement = self::valueRun($result, $this->values[$name]);

            if ($replacement === null) {
                continue;
            }

            // Insert the value where the field began, then drop the runs the field
            // was made of. Removing first would leave the paragraph with nothing to
            // insert before and the replacement would be appended at its end.
            $paragraph->insertBefore($replacement, $begin);

            foreach ($runs as $run) {
                $paragraph->removeChild($run);
            }
        }
    }

    /**
     * The field name in a complex field's instruction, or null when it is not ours.
     *
     * The instruction may be split across several `w:instrText` runs — Word does that
     * when it wraps a long instruction — so every run up to the `separate` is read.
     */
    private static function complexName(DOMElement $begin): ?string
    {
        $instruction = '';
        $node = $begin->nextSibling;

        while ($node !== null) {
            if ($node instanceof DOMElement && $node->localName === 'r') {
                $char = $node->getElementsByTagNameNS(self::W_NS, 'fldChar')->item(0);

                if ($char instanceof DOMElement) {
                    $type = $char->getAttributeNS(self::W_NS, 'fldCharType');

                    if ($type === 'separate' || $type === 'end') {
                        break;
                    }
                }

                foreach ($node->getElementsByTagNameNS(self::W_NS, 'instrText') as $text) {
                    $instruction .= $text->textContent;
                }
            }

            $node = $node->nextSibling;
        }

        return self::nameOf($instruction);
    }

    /**
     * The name a field's instruction asks for, or null when it is not a `MERGEFIELD`.
     *
     * An instruction is free text — ` MERGEFIELD client \* MERGEFORMAT ` — so the name
     * is what follows the field type, and only that. Anything after it is a switch and
     * belongs to Word.
     *
     * A name with a space in it is stored quoted, so it is matched outside the quotes
     * rather than by splitting on every space: `"client name"` is one name and not
     * two, and splitting it leaves an instruction asking for a field called `"client`.
     */
    private static function nameOf(string $instruction): ?string
    {
        // The quoted form first, or `"client name"` is read as the name `"client`.
        if (preg_match('/^\s*MERGEFIELD\s+"([^"]*)"/i', $instruction, $found) === 1) {
            return $found[1] === '' ? null : strtoupper($found[1]);
        }

        if (preg_match('/^\s*MERGEFIELD\s+(\S+)/i', $instruction, $found) !== 1) {
            return null;
        }

        $name = trim($found[1], '"');

        return $name === '' ? null : strtoupper($name);
    }

    /**
     * The runs of a complex field, from its `begin` to its `end`.
     *
     * @return list<DOMElement>
     */
    private static function runsFrom(DOMElement $begin): array
    {
        $runs = [];
        $node = $begin;

        while ($node !== null) {
            $runs[] = $node;
            $node = $node->nextSibling;
        }

        return array_values(array_filter($runs, static fn (DOMNode $n): bool => $n instanceof DOMElement));
    }

    /** @param list<DOMElement> $runs */
    private static function runWhereCharIs(array $runs, string $type): ?DOMElement
    {
        foreach ($runs as $run) {
            foreach ($run->getElementsByTagNameNS(self::W_NS, 'fldChar') as $char) {
                if ($char->getAttributeNS(self::W_NS, 'fldCharType') === $type) {
                    return $run;
                }
            }
        }

        return null;
    }

    /**
     * The run holding the field's cached result: the first one after `separate`.
     *
     * Null when a field was never updated, which is what a template looks like before
     * anyone has pressed F9 on it — the runs between `begin` and `separate` are the
     * instruction and there is nothing between `separate` and `end`.
     *
     * @param list<DOMElement> $runs
     */
    private static function resultRun(array $runs): ?DOMElement
    {
        $separate = self::runWhereCharIs($runs, 'separate');

        if ($separate === null) {
            return null;
        }

        $node = $separate->nextSibling;

        while ($node !== null) {
            if ($node instanceof DOMElement && $node->localName === 'r') {
                return $node;
            }

            $node = $node->nextSibling;
        }

        return null;
    }

    /**
     * A run carrying the value, with the run properties the field's own result had.
     *
     * Built by hand rather than cloned from the result so that the text is set through
     * the DOM and escaped by it: a value holding `<` or `&` written as markup would
     * produce a part Word refuses to open, which is the defect this library's own
     * `OutputEscaping` exists to prevent.
     */
    private static function valueRun(DOMNode $result, string $value): ?DOMElement
    {
        $owner = $result instanceof DOMElement ? $result->ownerDocument : null;

        if ($owner === null) {
            return null;
        }

        $run = $owner->createElementNS(self::W_NS, 'w:r');
        $properties = $result instanceof DOMElement
            ? $result->getElementsByTagNameNS(self::W_NS, 'rPr')->item(0)
            : null;

        if ($properties instanceof DOMElement && $owner->importNode($properties, true) instanceof DOMElement) {
            $run->appendChild($owner->importNode($properties, true));
        }

        $text = $owner->createElementNS(self::W_NS, 'w:t');
        $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $text->appendChild($owner->createTextNode($value));

        $run->appendChild($text);

        return $run;
    }
}
