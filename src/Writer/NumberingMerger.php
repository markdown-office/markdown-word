<?php

declare(strict_types=1);

namespace MarkdownWord\Writer;

use DOMDocument;
use DOMElement;
use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Exception\UnsupportedElement;
use MarkdownWord\WordPackage;
use MarkdownWord\Xml;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\XMLWriter;
use ZipArchive;

/**
 * Carries Word list numbering across from a scratch document into a template.
 *
 * When rendered elements are copied into another document, a list paragraph
 * still points at the numbering definition it had where it was created. A
 * template has its own `word/numbering.xml`, so those references resolve to
 * nothing and the lists silently lose their bullets.
 *
 * This assigns the definitions fresh identifiers in the template and rewrites
 * the references to match.
 */
final class NumberingMerger
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const NUMBERING_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering';
    private const NUMBERING_CT = 'application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml';

    /**
     * The lowest `w:numId` this merger writes into a document.
     *
     * The document's references have to exist long before the template's numbering
     * part is read — {@see self::renderElement()} runs during the insert,
     * {@see self::applyTo()} afterwards — so they start out provisional, in a range
     * no template uses, and are pointed at real identifiers once the high-water
     * marks are known.
     */
    private const PROVISIONAL_BASE = 1000000;

    /**
     * Scratch `w:numId` => the `w:numId` the document currently points at.
     *
     * @var array<int, int>
     */
    private array $numIdMap = [];

    /**
     * Scratch `w:abstractNumId` => the definition to add to the template.
     *
     * @var array<int, DOMElement>
     */
    private array $definitions = [];

    /**
     * Scratch `w:numId` => the scratch `w:abstractNumId` it points at.
     *
     * @var array<int, int>
     */
    private array $abstractForNum = [];

    /**
     * Scratch `w:abstractNumId` => the `w:abstractNumId` it is written as.
     *
     * @var array<int, int>
     */
    private array $abstractIdMap = [];

    /**
     * The `w:numId` values the template's own numbering part already uses.
     *
     * @var array<string, true>
     */
    private array $templateNumIds = [];

    private int $nextNumId = 1;

    private int $nextAbstractId = 0;

    public function __construct(private readonly PhpWord $scratch)
    {
    }

    /**
     * Read the numbering definitions the scratch document currently holds.
     *
     * Safe to call after every render: definitions already taken are skipped. The
     * identifiers are left provisional for the reason given on
     * {@see self::PROVISIONAL_BASE}.
     */
    public function collect(): void
    {
        $numbering = $this->readScratchNumbering();

        if ($numbering === null) {
            return;
        }

        $xpath = new \DOMXPath($numbering);
        $xpath->registerNamespace('w', self::W_NS);

        $abstracts = [];
        foreach ($xpath->query('//w:abstractNum') ?: [] as $abstract) {
            $abstracts[$abstract->getAttribute('w:abstractNumId')] = $abstract;
        }

        foreach ($xpath->query('//w:num') ?: [] as $num) {
            $scratchId = (int) $num->getAttribute('w:numId');

            if (isset($this->numIdMap[$scratchId])) {
                continue;
            }

            $abstractId = $this->childValue($num, 'abstractNumId');

            // The definition travels with the reference; without it the new
            // numId would point at nothing.
            if ($abstractId !== null && isset($abstracts[$abstractId])) {
                $this->definitions[$abstractId] = $abstracts[$abstractId];
                $this->abstractForNum[$scratchId] = (int) $abstractId;
            }

            $this->numIdMap[$scratchId] = self::PROVISIONAL_BASE + $scratchId;
        }
    }

    /**
     * The XML the template should contain for one element, with its numbering
     * references already pointing at this merger.
     */
    public function renderElement(AbstractElement $element): string
    {
        $writerClass = 'PhpOffice\\PhpWord\\Writer\\Word2007\\Element\\' . $this->shortName($element);

        if (!class_exists($writerClass)) {
            throw new UnsupportedElement(sprintf(
                'PHPWord has no Word 2007 writer for the element "%s".',
                $element::class,
            ));
        }

        $xmlWriter = new XMLWriter();

        // See {@see OutputEscaping} for what this window is for.
        OutputEscaping::enabled(function () use ($writerClass, $xmlWriter, $element): void {
            $elementWriter = new $writerClass($xmlWriter, $element, false);
            $elementWriter->write();
        });

        return $this->compact($this->remap($xmlWriter->getData()));
    }

    /**
     * Remove the whitespace PHPWord's element writer puts between elements.
     *
     * The writer emits a fragment indented for a human to read, and that is fine in a
     * file being written once. It is not fine when the fragment is handed to something
     * that inserts it into a document: whitespace between a `w:t` and its text *is*
     * text to Word, so a fragment written this way puts the literal characters
     * `<w:p>` at the start of every paragraph it was inserted into.
     *
     * The indent is always *across a line break*, so folding `>`-whitespace-`<` is what
     * is wanted only when a newline is in it. A space with no newline beside it is not
     * indentation: a soft line break in a wrapped Markdown paragraph is written as a
     * run holding one space, and folding that too joins the two words the author wrapped
     * between them — `revenue` and `growth` came out as `revenuegrowth`, in every
     * document, because nothing asserted on text that had been wrapped.
     *
     * {@see self::remap()} preserves whitespace for the same reason.
     */
    private function compact(string $xml): string
    {
        return (string) preg_replace('~>[ \t]*\r?\n\s*<~', '><', $xml);
    }

    /**
     * Rewrite the numbering references in a fragment of `word/document.xml`.
     *
     * {@see self::applyTo()} replaces the provisional values with the real ones.
     *
     * The fragment goes through a DOM because a `w:numId` may be split across runs by
     * whatever built the document, and a string replacement would only find the ones
     * that are whole. It comes back out of `saveXML()` rather than the string it went
     * in as, and {@see self::compact()} removes what that adds.
     */
    public function remap(string $xml): string
    {
        if ($this->numIdMap === [] || !str_contains($xml, 'w:numId')) {
            return $xml;
        }

        // Wrapped in a root carrying the namespace, so that a fragment using the
        // `w:` prefix parses on its own.
        $dom = Xml::parse('<w xmlns:w="' . self::W_NS . '">' . $xml . '</w>');

        if ($dom === null) {
            return $xml;
        }

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);

        foreach ($xpath->query('//w:numId') ?: [] as $node) {
            $scratchId = (int) $node->getAttribute('w:val');

            if (isset($this->numIdMap[$scratchId])) {
                $node->setAttribute('w:val', (string) $this->numIdMap[$scratchId]);
            }
        }

        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = true;

        $out = '';
        foreach ($dom->documentElement?->childNodes ?? [] as $child) {
            $out .= $dom->saveXML($child);
        }

        // The indent that came in is left for {@see self::compact()}, which is the one
        // place that knows how to tell an indent from a space a sentence needs. Doing it
        // here as well meant this copy went on stripping spaces that were content, and
        // the two copies drifted apart the moment either was fixed.
        return $out;
    }

    public function hasNumbering(): bool
    {
        return $this->definitions !== [];
    }

    /**
     * Write the collected definitions into a finished `.docx`, creating the
     * numbering part when the template did not have one.
     *
     * @throws FileNotWritable When the archive cannot be opened for writing, or
     *         no temporary file can be made to read the scratch document.
     * @throws MalformedDocument When a part of either document is not XML.
     */
    public function applyTo(string $docxPath): void
    {
        if ($this->definitions === []) {
            return;
        }

        $zip = new ZipArchive();

        if ($zip->open($docxPath) !== true) {
            throw new FileNotWritable(sprintf('Unable to open "%s" for writing.', $docxPath));
        }

        try {
            $numbering = $this->numberingPart($zip);
            $this->assignTemplateIds();
            $this->appendDefinitions($numbering);
            $this->retargetDocument($zip);

            $zip->deleteName('word/numbering.xml');
            $zip->addFromString('word/numbering.xml', (string) $numbering->saveXML());

            $this->declareContentType($zip);
            $this->declareRelationship($zip);
        } finally {
            $zip->close();
        }
    }

    /**
     * OOXML requires every `w:abstractNum` to come before the `w:num` elements,
     * so the existing ones are lifted out and put back afterwards.
     */
    private function appendDefinitions(DOMDocument $numbering): void
    {
        $root = $numbering->documentElement;

        if ($root === null) {
            return;
        }

        $existingNums = [];

        foreach (iterator_to_array($root->getElementsByTagNameNS(self::W_NS, 'num')) as $node) {
            $existingNums[] = $node;
            $node->parentNode?->removeChild($node);
        }

        foreach ($this->definitions as $scratchAbstractId => $definition) {
            $copy = $numbering->importNode($definition, true);

            if (!$copy instanceof DOMElement) {
                continue;
            }

            $copy->setAttribute('w:abstractNumId', (string) ($this->abstractIdMap[$scratchAbstractId] ?? 0));
            $root->appendChild($copy);
        }

        foreach ($this->numIdMap as $scratchId => $templateId) {
            $num = $numbering->createElementNS(self::W_NS, 'w:num');
            $num->setAttribute('w:numId', (string) $templateId);

            $abstract = $numbering->createElementNS(self::W_NS, 'w:abstractNumId');
            $abstract->setAttribute(
                'w:val',
                (string) ($this->abstractIdMap[$this->abstractForNum[$scratchId] ?? 0] ?? 0),
            );
            $num->appendChild($abstract);

            $root->appendChild($num);
        }

        foreach ($existingNums as $node) {
            $root->appendChild($node);
        }
    }

    /**
     * Give the collected definitions identifiers the template has not taken.
     *
     * Only possible once the template's numbering part has been read, so a
     * duplicate `1` is never written: Word resolves a repeated `1` to whichever of
     * the two it finds first.
     */
    private function assignTemplateIds(): void
    {
        foreach (array_keys($this->numIdMap) as $scratchId) {
            $this->numIdMap[$scratchId] = $this->nextNumId++;
        }

        // One identifier per definition rather than per reference: two identical
        // lists are one definition, and a numbered list that starts over for each
        // is not what the Markdown said.
        foreach (array_unique(array_values($this->abstractForNum)) as $scratchAbstractId) {
            $this->abstractIdMap[$scratchAbstractId] = $this->nextAbstractId++;
        }
    }

    /**
     * Point the document at the identifiers the definitions were given.
     *
     * Only this merger's own references are touched — the ones holding a
     * provisional value the template's lists do not use — so a template's
     * numbering is left as it was.
     */
    private function retargetDocument(ZipArchive $zip): void
    {
        $body = WordPackage::bodyPart($zip);
        $document = $zip->getFromName($body);

        if ($document === false || !str_contains($document, 'w:numId')) {
            return;
        }

        $dom = Xml::parse($document);
        $rewritten = 0;

        if ($dom !== null) {
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('w', self::W_NS);

            foreach ($xpath->query('//w:numId') ?: [] as $node) {
                $value = $node->getAttribute('w:val');

                // A template numbering a list this high would hold a value the
                // provisional range covers; its own references are left alone,
                // and the ones added here stay provisional rather than being
                // pointed at each other's definitions.
                if (isset($this->templateNumIds[$value])) {
                    continue;
                }

                $scratchId = (int) $value - self::PROVISIONAL_BASE;

                if (!isset($this->numIdMap[$scratchId])) {
                    continue;
                }

                $node->setAttribute('w:val', (string) $this->numIdMap[$scratchId]);
                $rewritten++;
            }
        }

        if ($rewritten === 0) {
            return;
        }

        $zip->deleteName($body);
        $zip->addFromString($body, (string) $dom?->saveXML());
    }

    private function numberingPart(ZipArchive $zip): DOMDocument
    {
        $existing = $zip->getFromName('word/numbering.xml');

        if ($existing === false) {
            $dom = new DOMDocument();
            $dom->appendChild($dom->createElementNS(self::W_NS, 'w:numbering'));

            return $dom;
        }

        $dom = new DOMDocument();
        $this->load($dom, $existing);

        // Continue the identifier sequences the template already uses, so the
        // definitions being added cannot collide with its own.
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);

        foreach ($xpath->query('//w:num') ?: [] as $node) {
            $this->templateNumIds[$node->getAttribute('w:numId')] = true;
            $this->nextNumId = max($this->nextNumId, (int) $node->getAttribute('w:numId') + 1);
        }

        foreach ($xpath->query('//w:abstractNum') ?: [] as $node) {
            $this->nextAbstractId = max($this->nextAbstractId, (int) $node->getAttribute('w:abstractNumId') + 1);
        }

        return $dom;
    }

    /**
     * Announce the numbering part in `[Content_Types].xml`, unless the document
     * already does.
     */
    private function declareContentType(ZipArchive $zip): void
    {
        $xml = $zip->getFromName('[Content_Types].xml');

        if ($xml === false) {
            return;
        }

        $dom = new DOMDocument();
        $this->load($dom, $xml);

        foreach ($dom->getElementsByTagName('Override') as $override) {
            if ($override instanceof DOMElement && $override->getAttribute('PartName') === '/word/numbering.xml') {
                return;
            }
        }

        $override = $dom->createElement('Override');
        $override->setAttribute('PartName', '/word/numbering.xml');
        $override->setAttribute('ContentType', self::NUMBERING_CT);
        $dom->documentElement?->appendChild($override);

        $zip->deleteName('[Content_Types].xml');
        $zip->addFromString('[Content_Types].xml', (string) $dom->saveXML());
    }

    /**
     * Relate `word/numbering.xml` to the document, unless it already is.
     */
    private function declareRelationship(ZipArchive $zip): void
    {
        $xml = $zip->getFromName(WordPackage::relationshipsFor(WordPackage::bodyPart($zip)));

        if ($xml === false) {
            return;
        }

        $dom = new DOMDocument();
        $this->load($dom, $xml);

        // A set rather than a list: asking a list of forty thousand identifiers
        // about each of forty thousand candidates is how a hostile template turns
        // a few milliseconds of work into half a minute.
        $used = [];

        foreach ($dom->getElementsByTagName('Relationship') as $relationship) {
            if (!$relationship instanceof DOMElement) {
                continue;
            }

            $used[$relationship->getAttribute('Id')] = true;

            if ($relationship->getAttribute('Target') === 'numbering.xml') {
                return;
            }
        }

        // Counting from one would ask about every identifier below the answer,
        // which is the whole list when they are numbered from one; counting from
        // the number there are lands on a free one. Identifiers need not be
        // numbers, so the walk is kept for the rest.
        $id = count($used) + 1;

        while (isset($used['rId' . $id])) {
            $id++;
        }

        $relationship = $dom->createElementNS(self::REL_NS, 'Relationship');
        $relationship->setAttribute('Id', 'rId' . $id);
        $relationship->setAttribute('Type', self::NUMBERING_REL);
        $relationship->setAttribute('Target', 'numbering.xml');
        $dom->documentElement?->appendChild($relationship);

        $relsPath = WordPackage::relationshipsFor(WordPackage::bodyPart($zip));
        $zip->deleteName($relsPath);
        $zip->addFromString($relsPath, (string) $dom->saveXML());
    }

    private function readScratchNumbering(): ?DOMDocument
    {
        $path = self::scratchFile();

        try {
            OutputEscaping::enabled(function () use ($path): void {
                IOFactory::createWriter($this->scratch, 'Word2007')->save($path);
            });

            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                return null;
            }

            $xml = $zip->getFromName('word/numbering.xml');
            $zip->close();
        } finally {
            @unlink($path);
        }

        if ($xml === false) {
            return null;
        }

        $dom = new DOMDocument();
        $this->load($dom, $xml);

        return $dom;
    }

    /**
     * A temporary file for the scratch document to be read back out of.
     *
     * A whole `.docx` for as long as it takes to read one part out of it, in a
     * directory every local user can list, and `save()` would leave it readable
     * to all of them.
     *
     * @throws FileNotWritable When no temporary file can be made.
     */
    private static function scratchFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mdword_num_');

        if ($path === false) {
            throw new FileNotWritable('Unable to create a temporary file for the numbering part.');
        }

        @chmod($path, 0o600);

        return $path;
    }

    private function childValue(DOMElement $parent, string $localName): ?string
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                return $child->getAttribute('w:val');
            }
        }

        return null;
    }

    private function load(DOMDocument $dom, string $xml): void
    {
        Xml::parse($xml, flags: 0, into: $dom)
            ?? throw new MalformedDocument('The document contains invalid XML.');
    }

    private function shortName(AbstractElement $element): string
    {
        $class = $element::class;

        return substr($class, (int) strrpos($class, '\\') + 1);
    }
}
