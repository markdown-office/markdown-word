<?php

declare(strict_types=1);

namespace MarkdownWord\Render;

use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use MarkdownWord\Configuration\Furniture;

/**
 * The `{page}` and its siblings: what a running head may say about the page it is on.
 *
 * Substituted into the Markdown before it is parsed rather than drawn as a directive,
 * because a running head is a document describing itself and the directives dialect is a
 * separate decision: `{page}` has to mean the same thing whether or not this library's
 * parser has `::toc` switched on.
 *
 * That is what fixes the shape of the whole thing. A field cannot be written from the
 * Markdown — PHPWord builds one from a `Field` element and nothing else — so the token
 * becomes a marker in the source and the marker becomes a field afterwards, in the run
 * the renderer built. The swap happens in the element tree rather than in the parse tree
 * because the parse tree has no way to reach the writer.
 *
 * A marker cannot sit inside a `w:t` in the finished document, and the run it landed in
 * may already hold text either side of it, so the tail of that run is rebuilt: removed,
 * then put back with a `Field` where each marker was. Rebuilding is possible only because
 * {@see self::REBUILDABLE} is the whole list of what this library's renderer puts in a
 * run — text, a line break, a link, a picture — and a run holding anything else has its
 * markers put back as the literal `{page}` the author wrote, which is a line they can see
 * and go and fix rather than a document that came out subtly wrong.
 */
final class FurnitureTokens
{
    /**
     * The tokens that stand for a field, and the field each one is.
     *
     * `chapter` is a `STYLEREF` because Word has no field for "the heading this page is
     * under": a style reference is exactly that, and `Heading 1` is the name
     * {@see \MarkdownWord\Render\StyleRegistrar} gives the slot a level-one heading uses.
     *
     * `date` is not here because its picture is a {@see Furniture} rather than a constant
     * — see {@see self::dateField()}.
     *
     * @var array<string, array{name: string, field: string, properties: array<string, string>}>
     */
    private const FIELDS = [
        'page' => ['name' => 'page', 'field' => 'PAGE', 'properties' => []],
        'total' => ['name' => 'total', 'field' => 'NUMPAGES', 'properties' => []],
        'filename' => ['name' => 'filename', 'field' => 'FILENAME', 'properties' => []],
        'chapter' => ['name' => 'chapter', 'field' => 'STYLEREF', 'properties' => ['StyleIdentifier' => 'Heading 1']],
        'date' => ['name' => 'date', 'field' => 'DATE', 'properties' => []],
    ];

    /**
     * The order the switches are written in, which is the order a running head
     * conventionally puts them: which chapter, which page of how many, what the file is
     * called, what day it is.
     *
     * @var list<string>
     */
    private const ORDER = ['chapter', 'page', 'total', 'filename', 'date'];

    /**
     * What a run may hold for {@see self::expand()} to rebuild its tail from.
     *
     * A closed list rather than a guess, because the list is the whole of the guarantee
     * that a rebuild loses nothing: anything outside it ends the rebuild and the markers
     * go back to the tokens they came from.
     *
     * @var list<class-string<AbstractElement>>
     */
    private const REBUILDABLE = [Text::class, TextBreak::class, Link::class, Image::class];

    private const OPEN = "\u{E000}";
    private const CLOSE = "\u{E001}";

    /**
     * What distinguishes this render's markers from any other, and from a document that
     * happens to contain the marker characters.
     *
     * Private-use characters rather than something readable, so a marker that escapes a
     * rebuild is not mistaken for text the author wrote; the nonce rather than the
     * characters alone, so a document that does contain them cannot have its own text
     * turned into a page number.
     */
    private readonly string $nonce;

    public function __construct(private readonly ?string $dateFormat = null)
    {
        $this->nonce = bin2hex(random_bytes(6));
    }

    /**
     * The fields a furniture's switches ask for, in {@see self::ORDER}.
     *
     * The switches are the shorthand: a `page: true` with nothing else writes a bare page
     * number, and a header that wants `Page 3 of 12` says so in its body instead, where
     * the tokens sit between the words.
     *
     * @return list<array{name: string, field: string, properties: array<string, string>}>
     */
    public function switches(Furniture $furniture): array
    {
        $fields = [];

        foreach (self::ORDER as $name) {
            $value = match ($name) {
                'chapter' => $furniture->chapter,
                'page' => $furniture->page,
                'total' => $furniture->total,
                'filename' => $furniture->filename,
                'date' => $furniture->date,
            };

            if ($value === true) {
                $fields[] = $name === 'date' ? self::dateField($this->dateFormat) : self::FIELDS[$name];
            }
        }

        return $fields;
    }

    /**
     * Replace every token with the marker that stands for it.
     *
     * Every token, whether or not the matching switch is on: the switch is a shorthand for
     * something the author wrote in the body, and a token written in the body is not a
     * shorthand for anything.
     */
    public function substitute(string $markdown): string
    {
        $map = [];

        foreach (array_keys(self::FIELDS) as $name) {
            $map['{' . $name . '}'] = $this->marker($name);
        }

        // `strtr()` and not `preg_replace()`: it does not look inside what it has already
        // written, so one token cannot turn into another by way of the text replacing it.
        return strtr($markdown, $map);
    }

    /**
     * Put a field where each marker in this run stands.
     *
     * Only the tail from the first marker is touched, so the text before it is left
     * exactly as the renderer made it.
     */
    public function expand(TextRun $run): void
    {
        $children = $run->getElements();
        $first = $this->firstMarker($children);

        if ($first === null) {
            return;
        }

        $tail = array_slice($children, $first);

        if (!$this->rebuildable($tail)) {
            $this->literalise($tail);

            return;
        }

        foreach ($tail as $element) {
            $run->removeElement($element);
        }

        foreach ($tail as $element) {
            $this->rebuild($run, $element);
        }
    }

    /**
     * A `DATE` field written in the picture the document asked for.
     *
     * The picture is Word's own and is handed to Word untouched: there is no list of them
     * here to disagree with PHPWord's, because PHPWord has none either — it writes
     * whatever it is given — so a document writing `d MMMM yyyy` gets `DATE \@ "d MMMM
     * yyyy"` and a document writing something else gets a field Word may or may not
     * like. Refusing a conversion over the shape of one field in a running head would not
     * be better than that.
     *
     * @return array{name: string, field: string, properties: array<string, string>}
     */
    private static function dateField(?string $picture): array
    {
        return [
            'name' => 'date',
            'field' => 'DATE',
            'properties' => $picture === null || $picture === '' ? [] : ['dateformat' => $picture],
        ];
    }

    /**
     * The first element holding a marker, or null when the run holds none.
     *
     * @param list<AbstractElement> $children
     */
    private function firstMarker(array $children): ?int
    {
        foreach ($children as $index => $element) {
            if ($element instanceof Text && $this->markerIn((string) $element->getText()) !== null) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<AbstractElement> $tail
     */
    private function rebuildable(array $tail): bool
    {
        foreach ($tail as $element) {
            if (!in_array($element::class, self::REBUILDABLE, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The tokens themselves, written back over the markers, for a run that cannot be
     * rebuilt.
     *
     * @param list<AbstractElement> $tail
     */
    private function literalise(array $tail): void
    {
        foreach ($tail as $element) {
            if (!$element instanceof Text) {
                continue;
            }

            $text = (string) $element->getText();

            if ($this->markerIn($text) !== null) {
                $element->setText((string) preg_replace_callback(
                    $this->pattern(),
                    static fn (array $match): string => '{' . $match[1] . '}',
                    $text,
                ));
            }
        }
    }

    private function rebuild(TextRun $run, AbstractElement $element): void
    {
        if ($element instanceof Text) {
            $this->rebuildText($run, $element);

            return;
        }

        if ($element instanceof TextBreak) {
            $run->addTextBreak(1, $element->getFontStyle());

            return;
        }

        if ($element instanceof Link) {
            $run->addLink($element->getSource(), $element->getText(), $element->getFontStyle(), null, $element->isInternal());

            return;
        }

        if ($element instanceof Image) {
            // The source is the path or the bytes {@see \MarkdownWord\Render\ImageResolver}
            // handed over, and both are what `addImage()` takes; a converted picture is
            // re-read from its bytes rather than from a file that was never written.
            $run->addImage($element->getSource(), $element->getStyle());
        }
    }

    private function rebuildText(TextRun $run, Text $element): void
    {
        $font = $element->getFontStyle();

        foreach ($this->pieces((string) $element->getText()) as [$text, $token]) {
            if ($token === null) {
                $run->addText($text, $font);

                continue;
            }

            $run->addField(...$this->fieldOf($token));
        }
    }

    /**
     * @return array{string, array<string, string>}
     */
    private function fieldOf(string $token): array
    {
        $field = $token === 'date' ? self::dateField($this->dateFormat) : self::FIELDS[$token];

        return [$field['field'], $field['properties']];
    }

    /**
     * The text cut into the pieces between the markers and the markers themselves.
     *
     * `preg_split()` is the obvious way and is wrong here: with `PREG_SPLIT_DELIM_CAPTURE`
     * it yields the *captured* groups and not the whole match, so the marker itself would
     * be dropped and only the token name kept — a page number replaced by the word "page".
     * Matching with offsets keeps both, and the name is what says which field to write.
     *
     * @return list<array{string, ?string}> A piece of text and the token it stands for,
     *         or null where the piece is only text.
     */
    private function pieces(string $text): array
    {
        $matches = preg_match_all($this->pattern(), $text, $found, PREG_OFFSET_CAPTURE);

        if ($matches === false || $matches === 0) {
            return [[$text, null]];
        }

        $pieces = [];
        $offset = 0;

        foreach ($found[0] as $index => [$marker, $start]) {
            if ($start > $offset) {
                $pieces[] = [substr($text, $offset, $start - $offset), null];
            }

            $pieces[] = [$marker, $found[1][$index][0]];
            $offset = $start + strlen($marker);
        }

        if ($offset < strlen($text)) {
            $pieces[] = [substr($text, $offset), null];
        }

        return $pieces;
    }

    /**
     * The token a piece of text is, or null when it is text.
     *
     * Matched rather than compared, because the nonce makes the marker a pattern rather
     * than a constant.
     */
    private function markerIn(string $text): ?string
    {
        return preg_match($this->pattern(), $text, $matches) === 1 ? $matches[1] : null;
    }

    private function marker(string $name): string
    {
        return self::OPEN . $this->nonce . ':' . $name . self::CLOSE;
    }

    private function pattern(): string
    {
        return '/' . self::OPEN . $this->nonce . ':([a-z]+)' . self::CLOSE . '/u';
    }
}
