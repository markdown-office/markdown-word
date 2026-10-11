<?php

declare(strict_types=1);

namespace MarkdownWord;

use MarkdownWord\Writer\Survey;

/**
 * One of the document formats a conversion can be asked for.
 *
 * `.docx` is the first case and the default everywhere: {@see MarkdownToWord::toDocx()},
 * {@see Converter::convert()} and the command line all name it when nobody says
 * otherwise. The other two are writers PHPWord already ships, exposed with the same
 * staging guarantee and — because they carry less — with an account of what they drop.
 */
enum Format: string
{
    case Docx = 'docx';
    case Odt = 'odt';
    case Rtf = 'rtf';

    /**
     * What a format's writer is called in PHPWord's `IOFactory`. The names are the
     * library's, not ours, and this is the only place the two halves of the question
     * meet: a format that is not a case here has no writer.
     */
    public function writer(): string
    {
        return match ($this) {
            self::Docx => 'Word2007',
            self::Odt => 'ODText',
            self::Rtf => 'RTF',
        };
    }

    /** The extension a result of this format carries, dot included. */
    public function extension(): string
    {
        return '.' . $this->value;
    }

    /**
     * The features this writer cannot express, whatever the document in front of it.
     *
     * A feature that is not on this list is carried; {@see self::carries()} is the
     * other half of the same answer.
     *
     * @return list<string>
     */
    public function drops(): array
    {
        return match ($this) {
            self::Docx => [],
            // `Writer\ODText\Style\Numbering` writes `text:list-level-style-bullet`
            // whatever a level's format is, so an ordered list comes out bulleted;
            // and neither `Writer\ODText\Element\Table` nor
            // `Writer\ODText\Style\Paragraph` writes a border or a background.
            //
            // What is caught is a slot pointed at a style of the caller's own, which
            // is what {@see \MarkdownWord\Writer\Survey} asks about — it records
            // the loss for a paragraph whose whole style is that name, and for
            // nothing else.
            //
            // Nothing of it survives, and that is the whole of the loss rather
            // than the visible half of one. ODF keeps a style's character half
            // and its paragraph half in two families that do not see each other,
            // so the paragraph half a `text:style-name` points at cannot reach
            // the spans — and the style it names is the caller's, which this
            // library writes no definition of, so there is no paragraph half to
            // inherit either. A `P1_CorpTitle` is emitted with an empty
            // `style:paragraph-properties` and a parent nothing defines.
            //
            // The built-in look is not caught by it, because its slots are
            // direct formatting rather than a name: a default heading carries
            // `Heading1` beside its properties, and those properties are what
            // every reader resolves.
            self::Odt => [
                'named-styles',
                'numbered-lists',
                'table-borders',
                'cell-emphasis',
                'shading',
                'paragraph-border',
                'svg-vector',
            ],
            // `Writer\RTF\Element\AbstractElement::writeOpening()` wants a
            // `Style\Paragraph` and this library's named styles are `Style\Font`;
            // `Element\ListItemRun` has no RTF writer at all; and
            // `Writer\RTF\Part\Header::registerFont()` reaches a typeface through
            // `Style::getStyles()` and through section-level elements, while this
            // library's runs are `Text` inside a `TextRun` — and `TextRun` has no
            // `getFontStyle()` for it to ask.
            self::Rtf => [
                'named-styles',
                'font-face',
                'lists',
                'shading',
                'paragraph-border',
                'image-alt-text',
                'jpeg-label',
                'svg-vector',
            ],
        };
    }

    /**
     * What this writer can express, as the feature keys {@see Survey} asks about.
     *
     * @return list<string>
     */
    public function carries(): array
    {
        return array_values(array_diff(array_keys(self::FEATURES), $this->drops()));
    }

    /**
     * What this writer drops from the document in front of it, in the order the
     * features are declared.
     *
     * @return list<Loss>
     */
    public function losses(Survey $survey): array
    {
        $dropped = array_flip($this->drops());
        $losses = [];

        foreach (self::FEATURES as $feature => $sentence) {
            if (isset($dropped[$feature]) && $survey->uses($feature)) {
                $losses[] = new Loss($feature, $sentence);
            }
        }

        return $losses;
    }

    /**
     * Every feature there is, and what a writer that cannot do it loses. The sentence
     * is written for whoever opens the finished document rather than for whoever
     * wrote the code, because that is the reader who has to act on it.
     *
     * Every key here is a question {@see Survey} answers, and `tests/Unit/format.php`
     * holds the two lists together: a feature nobody asks about is reported for every
     * document, and a key that is not here is a loss that is never reported at all.
     *
     * `numbered-lists` is never dropped alongside `lists`: a writer that leaves the
     * items out has lost the numbering with them, and saying both is the same fact
     * twice.
     *
     * @var array<string, string>
     */
    private const FEATURES = [
        'named-styles' => 'a heading and every other named style come out as body text',
        'font-face' => 'a run keeps its size and its weight but loses its typeface and colour',
        'lists' => 'every list item is left out of the document',
        'numbered-lists' => 'an ordered list comes out with a bullet in front of each item',
        'table-borders' => 'a table comes out with no borders',
        'cell-alignment' => "a table column's alignment is dropped",
        'cell-emphasis' => 'a table header row is no longer bold',
        'shading' => 'the background of a paragraph is dropped',
        'paragraph-border' => 'the rule under a thematic break is dropped',
        'image-alt-text' => 'a picture is left with no alternative text',
        'jpeg-label' => 'a JPEG is written into the file labelled as a PNG',
        'svg-vector' => 'an SVG is flattened into a raster and cannot be scaled like a vector',
    ];
}