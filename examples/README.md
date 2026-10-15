# Examples

Generated documents, so you can see what the library produces without writing
any code.

```
php examples/build.php
```

The Markdown sources are in `markdown/`, the Word templates in `templates/`, and
everything the build generates in `out/`:

```
out/
  documents/     every document above, plus the Markdown the round trip produces
  previews/      a .png of each document's first page
```

Previews need LibreOffice; without it the documents still build and the script says
so rather than failing. Where a stem has more than one format behind it, the format
goes into the preview's name as well.

`out/` is emptied at the start of every build. It holds nothing that is not made
here, and a build that only writes what it still names leaves behind every name it
has stopped naming — which is how a template written in the `${…}` dialect that has
since been removed came to sit in it, under a number the numbering has since moved
past.

| File | What it shows |
| --- | --- |
| `01-kitchen-sink.docx` | Every construct at once: emphasis nesting, hard and soft breaks, links with formatting in the label, nested lists, tables, quotes, code, raw HTML, escapes and entities. |
| `02-tables.docx` | GFM pipe tables, column alignment from the delimiter row, formatting inside cells, empty cells. The default appearance of a table, for comparison with `16` and `17`. |
| `03-lists.docx` | Nesting five levels deep, custom start values, `1)` delimiters, tight versus loose lists. |
| `04-code.docx` | Inline, fenced, indented and tilde-fenced code, including a fence nested inside a longer fence. |
| `05-links-and-images.docx` | Hyperlinks with **bold** and *italic* in the label, reference links, autolinks, embedded images, a WebP converted on the way in, and the fallback for images that cannot be found. |
| `06-quotes.docx` | Block quotes: several paragraphs, nesting, and other block content inside a quote. |
| `07-no-decoration.docx` | The kitchen sink with every aesthetic default switched off, next to `01`. |
| `08-house-style.docx` | The kitchen sink in a custom house style defined in code. Compare with `01`. |
| `09-extended-parser.docx` | Footnotes and description lists, via `CommonMarkParser::extended()`. |
| `10-template.docx` | Markdown rendered into a Word template: a `MERGEFIELD` filled from the document's own `fields:`, and a content control standing where the content goes. |
| `11-frontmatter.docx` | A `---` block read as configuration: the heading sizes, the code and link fonts and the table borders all come out of it. |
| `12-frontmatter-none.docx` | The same Markdown with no block, for the pair. |
| `13-frontmatter-override.docx` | The same block, outranked by the configuration passed in code. |
| `15-images-embed.docx` | Images, embedded: relative paths resolved through `imageBasePath`, `imageMaxWidth`, a WebP converted into the document, and the alt-text fallback. |
| `15-images-placeholder.docx` | The same source with `images: placeholder` — the alt text, then the path. |
| `15-images-skip.docx` | The same source with `images: skip` — no pictures and no captions. |
| `16-styled-tables.docx` | `styles.table`, `styles.tableHeaderRow` and `styles.tableCell` from the block, with `tableWidth` and `tableHeaderBold`. |
| `17-borderless-tables.docx` | `tableBorders`, `tableHeaderBold` and `tableWidth` with no style slot at all. |
| `18-round-trip.md` | `01-kitchen-sink.docx` read back into Markdown, for comparing the two side by side. |
| `19-vector.docx` | An SVG embedded as a **vector** beside its raster, which is the only way Word holds one. Not built where `ext-imagick` is absent; the build says so rather than failing. |
| `20-formats.docx` | One page holding every construct the three output formats are asked about, as the `.docx` the other two are measured against. |
| `20-formats.odt` | The same page as an OpenDocument Text. The headings, the quote and the spacing are the same as in the `.docx`; the table has no borders, its header row is not bold, and the ordered list comes out bulleted. |
| `20-formats.rtf` | The same page as Rich Text. There is no list in it at all, the code block has no background and no colour, and there is no rule under the `---`. |
| `22-corporate-report.docx` | A quarterly review rendered into a Word **template** — a `.dotx` — whose own styles the body inherits. See [the templates](#the-templates). |
| `22-corporate-report.docx-template.docx` | The same Markdown through an identical `.docx` template. Open the two together: identical in every way a reader sees, and both documents. |
| `23-plain-letterhead.docx` | The same report into a template that is a logo and **nothing else** — no styles, and no content control either. The content is appended because the template names no place for it. Compare with `22`. |
| `24-furniture.docx` | A running head and foot a document describes about itself, under `header:` and `footer:`. The head carries a logo and a wordmark stacked and right-aligned over a rule; the foot a centred `Page 1 of 1` written as Word fields rather than numbers. See [Headers and footers](#headers-and-footers). |
| `25-toc.docx` | A `::toc` drawn as a real Word field, so the contents are built by Word on F9 rather than written down here. Three footnotes on the page, for the mark and the note beside each other. |
| `26-directives.docx` | What a directive becomes in each place: a table of contents and a footnote, and three names this library does not draw — an inline one keeps its braces, a leaf one its whole source form, a container its body without its fence. |

Numbering is one sequence and nothing shares a number. `markdown/14-rejected.md` is
not in this table because `build.php` does not build it — converting it is the failure
it exists to demonstrate — and `18-round-trip.md` used to be `14-round-trip.md` before
the images and the two table examples took 15, 16 and 17.

## The three output formats

**`20-formats.docx`, `20-formats.odt` and `20-formats.rtf`** are one Markdown page
written three times, and they exist to be opened together. `.docx` is what this
library defaults to and what the other two are measured against; each of the two
loses something, and a page with everything on it shows what.

Open the three side by side and the differences are immediate — and the first
thing you will *not* see is a difference, because the headings, the quote and the
spacing are written onto the text itself rather than pointed at a style only Word
has. What is left is the writer's:

- **The `.rtf` has no list in it.** Not the bullet — the text of the item. There is
  no way to write a `.rtf` with a list in it from this library, and a run that tries
  prints a line on standard error saying so.
- **The `.odt` has `%1.` where the numbers should be.** An ordered list comes out
  with a literal `%1.` bullet in front of each item.
- **Neither has a rule under the `---`,** because a rule is a paragraph with a
  bottom border and neither writer writes one.
- **Neither has a background behind the code block,** for the same reason.
- **Only the `.odt` is missing the table borders** — the `.rtf` has them — and the
  `.odt`'s header row is not bold either, because PHPWord never walks into a table's
  cells when it collects the text styles of a document.

The preview images are named after the document and, for the two that are not the
default, after the format too: `20-formats.png`, `20-formats.odt.png` and
`20-formats.rtf.png`. Each is a first page rendered by LibreOffice where it is
installed.

The README table says the same thing in writing, and
`tests/Readme/examples-formats.php` asserts every row of it against the files the
build produces.

## Comparing pairs

`01` against `07` shows exactly what the defaults contribute; `01` against `08`
shows what a house style replaces. Both use the same Markdown.

`11` against `12` shows what a frontmatter block does: `12` is `11` with the block
removed and the words untouched. `11` against `13` shows the block losing to the
configuration passed in code.

`15-images-embed`, `15-images-placeholder` and `15-images-skip` are one document and
three configurations, so that the three image modes can be compared rather than
described. `02` against `16` and `17` shows the same table as Markdown, as a styled
table, and as one with the decoration taken off again.

## Frontmatter

Three of these documents are about the `---` block at the top of a file, and none of
them shows it in the document.

**The parser decides whether there is any.** Frontmatter is read by CommonMark's
`FrontMatterExtension`, which the default dialect does not carry. Build these with a
parser that has it:

```php
$parser = new CommonMarkParser([
    ...CommonMarkParser::FLAVOURS['gfm'],
    FrontMatterExtension::class,
]);

(new MarkdownToWord($markdown, Configuration::create(), $parser))->save($path);
```

Naming only `FrontMatterExtension` would *replace* the default dialect rather than add
to it, and every table would come out as a paragraph of pipes.

**What to look at.** In `11` the H1 is 24pt in `#1B3A5C` and the H2 is 16pt, both
from `styles:`. The H3 is a paragraph, because `options.maxHeadingLevel` is 2. The
table has borders. The code is Menlo 9pt in `#A31515`.

In `12` the same words use the library's built-in look — blue 16pt and 13pt
headings — the H3 is a heading, and the code font is the built-in one.

In `13` the block asks for the opposite of all of that and loses: the H1 is 11pt grey
and the table has no borders, because the configuration passed to the constructor sits
above the frontmatter in the order the main README gives.

**What none of them shows.** Not the `---`, not `options:`, not `styles:`. The block
is configuration, so it never arrives as content — which is worth checking, because a
block that leaked would look like a very short first paragraph.

**Why the slot names are spelled out.** `codeFont`, `linkFont`, `blockQuote` — the
camelCase names from `Styles::defaults()`, exactly. An earlier version of this file
wrote `code_font`, which is not a slot, and it was discarded without a word: the code
came out in the built-in Consolas and the document looked finished. Every key in a
frontmatter block is now checked, so that mistake raises instead:

```
Unknown style "code_font". Did you mean "codeFont"? Known styles: ...
```

`examples/markdown/14-rejected.md` is a document that fails this way on purpose. It
is not built, because building it is the failure.

## Values that are not what they are used as

A key is checked against a list; a value is checked against what it is *for*, and the
two halves have different failure modes. `maxHeadingLevel: deep` is cast to `0`, the
clamp turns `0` into `1`, and the document comes out with every heading in it
rendered as body text. `color: "#8B0000"` reaches `w:color` with a `#` in it, which is
not a colour there, and Word ignores it. A `space` with a word in it keeps the number
beside it and drops the word, so the paragraph ends up with half the spacing that was
asked for and nothing to say which half went missing.

So a value is checked too, and the message names the value, the line it is on, and
what would have worked:

```
Line 4: The "color" property of the "heading.1" style is "#8B0000". It is six
hexadecimal digits, as in 8B0000, with no leading # and no colour name.

Line 3: The "maxHeadingLevel" option is "deep". It is a whole number between 1 and 6.
```

Every problem in a block is reported at once, keys and values together, and they all
raise `Exception\InvalidConfiguration` — `UnknownConfigurationKey` for the key half,
which is what callers have been catching, and `InvalidConfigurationValue` for the
rest. `Options::fromArray()` and `Styles::withAll()` still discard and cast what they
always have: a config file has been allowed to carry loose values for a long time, and
the check is on the block.

## Images

**`imageBasePath`** is how a relative path in the Markdown finds its file, and in
these examples it points at `examples/markdown`, so `assets/logo.png` resolves. Pass
`Configuration::create()->withOptions(['imageBasePath' => …])`, or `--image-base` on
`mdoffice word` — the command line lives in
[`markdown-office/mdoffice`](https://github.com/markdown-office/mdoffice), not in this
repository — and the same Markdown works from any working directory.

**`imageMaxWidth`** is in centimetres and is applied to every picture, so
`15-images-embed.docx` is at 8cm against a default of 15cm — a little over half the text
column — and only for the photograph: the 96-pixel square beside it is narrower than
the cap already and keeps its own size, because the option is a maximum rather than a
target. `0` disables the scaling and the picture comes out at its own size, which for
anything larger than the page is not what anybody wanted.

**`images`** has three values and the three `15-images-*` documents show them on the
same source: `embed` puts the picture in the document, `placeholder` writes the alt
text and then the path it was given, and `skip` writes nothing at all.

**WebP** is the one that needed deciding. Word cannot embed it and PHPWord has never
supported it, so the renderer decodes it with GD and writes it into the document as
PNG. The alternative was failing, and failing on a format every browser has supported
for years seemed worse than a bigger file. But a PNG of a photograph is several times
the size of the WebP it came from, and the picture is a re-encoding rather than the
original bytes — so it is never done quietly, and a caller in code can ask
afterwards:

```php
$converter = new MarkdownToWord($markdown, $config);
$converter->save($path);
$converter->pendingImageConversions();   // [['source' => …, 'format' => 'image/webp', 'embeddedAs' => 'PNG']]
```

A file that is there and *cannot* be used — an SVG, a truncated download, a format
this build of GD has no decoder for — raises `Exception\UnsupportedImageFormat` naming
the file and the format, rather than replacing the picture with its caption and
looking finished. The escape hatch is the setting above it: `images: placeholder`
never attempts an embed, so it never meets one.

A file that is *not* there, and a URL that is remote, still fall back to the alt text.
There is nothing wrong with the document in those cases: the library has no HTTP
client and will not invent a download.

## Tables

A Markdown table carries three things: the cells, which column is aligned which way,
and which row is the header. Everything else about how it looks is configuration, and
these two documents are the two ways to configure it.

**`16-styled-tables.docx`** puts a definition on the table itself. `styles.table`
reaches the table — its borders, its cell padding, its alignment — and its two
neighbours reach the header row and every cell in it:

| Slot | Reaches | In `16` |
| --- | --- | --- |
| `table` | the table: borders, cell padding, alignment | `2E5F86` rules, 8pt, 100 twips of padding |
| `tableHeaderRow` | the first row, as a row | shaded `E8EEF5` |
| `tableCell` | every cell, header included | 10pt in `1F2933`, with air above and below |

`options.tableWidth: 4000` narrows it to four fifths of the text column — fiftieths of
a percent, so `5000` is the whole thing and `0` hands the sizing back to Word. And
`options.tableHeaderBold: false` is in the block for a reason: the header row is bold
by default, and with a shaded header underneath, bold says the same thing twice.

Note where the borders come from. They are the `borderColor` and `borderSize` on the
`styles.table` line, *not* `options.tableBorders` — that option draws the borders of
the default table, and a table with a definition of its own brings its own. `02-tables`
is what the default looks like, and `17` is what the option does on its own.

**`17-borderless-tables.docx`** has no style slot anywhere: `tableBorders: false`,
`tableHeaderBold: false` and `tableWidth: 0`, and the same Markdown. The columns are
still measured and a long cell still wraps inside its column, so borderless is not
unstructured — it reads as a printed report rather than as a spreadsheet.

## Vectors

**`19-vector.docx`** holds a diagram as an **SVG**, which is a different thing in a
Word file from a picture of one. Unzip it and there are two parts where the other
examples have one:

```
word/media/section_image1.png    a raster of the diagram
word/media/section_image1.svg    the diagram itself
```

Word has held SVG since 2016 and holds it the only way it ever can: the raster, plus
the vector referenced from an extension on the same picture element. The raster is not
a fallback — every reader draws it unless it knows to prefer the vector, and a picture
carrying the vector with no raster behind it renders nothing at all. So an SVG has to
be rasterised on the way in, which is what `ext-imagick` is for; without it, `build.php`
prints a line and skips the example rather than failing.

Two mechanics are worth knowing, because both are consequences of PHPWord rather than
of SVG. It writes images as VML, which has nowhere to hang an extension, so a picture
with a vector is rewritten as DrawingML while every other image keeps its markup. And
the size is taken from the SVG rather than from the raster, because PHPWord reads an
image's pixels as points and the raster is the SVG at 96dpi — which would lay a
480-unit diagram out a third too large.

## The way back

`18-round-trip.md` is not generated by hand: it is `01-kitchen-sink.docx` run
backwards through `MarkdownWord\WordToMarkdown`. Compare it with
`markdown/01-kitchen-sink.md` and the differences are the ones Word does not
record — a fenced code block comes back as one no matter which fence it used, a
table's first row becomes the header whether or not it was one, and a line that
wrapped in the source comes back as a single line. The words, the formatting and
the structure are all still there.

`composer test:roundtrip` proves that over all 1300 specification examples.

## Directives

`25-toc` and `26-directives` are the two pages about the
[directive syntax](../README.md#directives), and they are worth opening in
Word rather than reading here.

**The table of contents in `25` is a field, and it is empty on purpose.** Word
fills it in when the document opens; until it does it says *Press F9 to build
the table of contents*, and nothing is cached in the file. So a reader who opens
it and presses nothing sees that sentence, and a reader who presses F9 — or who
opens it in Word, which builds it by itself — sees the contents. That is the
trade this library makes deliberately: a cached table of contents carries page
numbers nobody computed, and this one never shows a wrong one.

**The footnotes are real ones.** The superscript sits inside the sentence, at the
foot of the page is the body, and Word renumbers them if you add or delete one
above another. Look at the mark after "every region but one" and at the first
numbered line at the foot of the page: same number.

**`26` is the page for what a directive becomes.** Its first half uses the two
names this library draws. Its second half uses names it does not: an inline one
keeps its braces and its attributes, a leaf one keeps its whole source form, and
a container keeps its body without its fence. A name that means nothing is drawn
as the words the author typed, which is what makes the syntax safe to have on by
default.

**What to check in `26`:** the paragraph holding `:badge[PRODUCTION]` reads as
written and is not a paragraph of its own; the `::image[…]` line is one; and the
`:::callout` body is drawn with its fence above it omitted.

Three things on that page cannot be seen there at all, and are there so the
document says so rather than the page being quietly wrong about itself: a format
with no footnote writer, and everything above when rendered **into a `.dotx`**.
`build.php` writes neither — it is the library being exercised, not a command
line — so those two are made by hand with
[`mdoffice`](https://github.com/markdown-office/mdoffice):

```bash
# An .odt has no footnote writer: source form back, loss reported.
mdoffice word to-odt examples/markdown/26-directives.md -o tmp/26.odt

# Into a template: the parts written beside a body do not travel with the elements.
mdoffice word to-docx examples/markdown/26-directives.md \
  --template=examples/templates/meridian-qbr.dotx -o tmp/26-template.docx
```

## Things worth looking at closely

**Links with formatting.** `05` has a link whose label is `**bold** italic
and `code``. Word models a hyperlink as a `w:hyperlink` wrapping runs, and
PHPWord's `Link` element holds only a single string, so the renderer writes a
placeholder and rewrites `word/document.xml` into a real `w:hyperlink` while
saving. Open the file and click the link: it works, and it is blue.

**Lists inside quotes.** In `06` a list inside a block quote is indented into
the quote and inherits its character style, and a nested quote visibly steps in
further.

**Code blocks.** Open `04` and turn on formatting marks. The leading spaces and
tabs of every code line are exactly the ones in the source, which Word
preserves because the runs are written with `xml:space="preserve"`.

**Raw HTML.** In `01`, a `<div>` containing a paragraph contributes the words
inside it and not the markup, which is what the same Markdown looks like in a
browser.

## The templates

Three Word templates live in `templates/`, all of them `.dotx` and all of them
committed, so a fresh clone can open them without running anything:

| Template | What it carries |
| --- | --- |
| `meridian-qbr.dotx` | The corporate letterhead: the logo in the header, six `MERGEFIELD`s, a body content control, and the house fonts and sizes. |
| `plain-letterhead.dotx` | A logo and **nothing else** — no styles, and no content control. Nobody making a letterhead inserts one, which is what makes this the case the fallback exists for. |
| `invoice.dotx` | Two font styles, an invoice heading, two `MERGEFIELD`s and a body control. Small, because it carries no logo. |

Every one says the same two things, and both are things Word already understands:

```
No. { MERGEFIELD number } ·   Issued for { MERGEFIELD customer }

┌ { w:sdt, tag=body } ─────────────────────┐
│ Click here to enter text.                │   ← replaced, then removed
└──────────────────────────────────────────┘
```

`10-template.docx` is `invoice.dotx` filled in, so you can open the template and the
result next to each other.

Rebuilding them is a separate step:

```sh
php examples/build.php --write-templates
```

It is separate because it is not repeatable. `docProps/core.xml` carries the time a
document was written, so two runs a minute apart differ there and nowhere else — a
build that rewrote a committed binary every run would leave the working tree
permanently dirty for a difference nobody can see. The trade is that a change to
`buildInvoiceTemplate()` and friends does not reach the committed files until this is
run, and the flag says so rather than pretending otherwise.

One trap if you build a template in code rather than in Word: PHPWord's second
argument to `addText()` is a *character* style. A paragraph style goes in the third,
or Word will ignore it.

## One template, two types

`php examples/build.php` renders the same Markdown through `meridian-qbr.dotx` and
through an identical copy of it typed as a `.docx`:

| | |
| --- | --- |
| `22-corporate-report.docx` | rendered through `templates/meridian-qbr.dotx` |
| `22-corporate-report.docx-template.docx` | rendered through the `.docx` twin |

Open the two results side by side. The words, the styles and the layout are
identical, because which kind of template it is makes no difference to the result.
What it does change is what the result *is*: both are documents. A `.dotx` and a
`.docx` differ by one line of `[Content_Types].xml`, and Word opens a package typed
as a template by creating a *new* document from it — so a filled-in template that
kept its own type is not a document, whatever it was saved as.

> **They are not byte-for-byte equal, and could not be.** Two runs of the *same*
> template differ too: `word/numbering.xml` carries a `w:nsid` per abstract numbering
> definition, which is a fresh value every run and which Word uses to recognise that
> two definitions are the same across documents. Nothing about it reaches the page.
> Every other part of the two archives is identical, which is the comparison worth
> making.

The template itself is worth opening: it is the template, with its masthead, its logo
and its body control unfilled.

> **The name of a package decides nothing.** `assertPackageType()` checks each
> file carries the type its name promises, and the build stops otherwise —
> because getting the conversion backwards leaves both files working and both of
> them wrong: a `.dotx` Word opens as an ordinary file, and a `.docx` it opens
> by spawning a new document. Neither complains.

It is a report for a fictional consultancy, because a template that drives the
body is only visible when there is a body worth looking at. The template defines
`MrdHeading1`…`MrdHeading3`, `MrdBody`, `MrdQuote`, `MrdCode` and `MrdCodeBlock`,
and the configuration points the Markdown slots at those names — so the Georgia
and the navy are the template's, not the renderer's.

Two more traps in it, both found by rendering the page and looking at it:

- **`addParagraphStyle()` and `addFontStyle()` each write half a paragraph
  style.** The first carries the run properties and drops the paragraph ones, the
  second does the reverse, and a style defined with only one of them resolves to
  almost nothing. `addFontStyle($id, $fontProperties, $paragraphProperties)`
  writes both — and the third argument has to be an *array*. A built
  `new Paragraph([...])` is accepted and quietly applies none of it.
- **PHPWord will not write a paragraph border into a style at all**, in any
  spelling, and `Configuration\Styles` has no border among the paragraph
  properties it understands. `ruleStyles()` writes the `<w:pBdr>` itself, in the
  place inside `<w:pPr>` that OOXML's sequence requires — `w:pBdr` comes before
  `w:spacing` and `w:ind`, and Word refuses a part that is out of order.

## The house style

`houseStyle()` in `build.php` is the whole configuration surface in one place —
headings, quotes, the code font, the link font and a table cell style:

```php
Configuration::create()
    ->withStyles([
        Styles::HEADING_1 => ['size' => 20, 'bold' => true, 'color' => '1B3A5C'],
        Styles::BLOCK_QUOTE => ['italic' => true, 'color' => '55606B'],
        Styles::CODE_FONT => ['name' => 'Consolas', 'size' => 9],
    ])
    ->withOptions(['codeBlockShading' => true, 'orderedListFormat' => 'decimal']);
```

The character half of a style slot — `size`, `bold`, `color`, `italic` — is
applied to the runs inside the paragraph, because a Word paragraph has no
character formatting of its own. The rest is applied to the paragraph.
