# markdown-word

[![tests](https://github.com/markdown-office/markdown-word/actions/workflows/tests.yml/badge.svg)](https://github.com/markdown-office/markdown-word/actions/workflows/tests.yml)
[![Quality gate status](https://sonarcloud.io/api/project_badges/measure?project=markdown-office_markdown-word&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=markdown-office_markdown-word)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=markdown-office_markdown-word&metric=coverage)](https://sonarcloud.io/summary/new_code?id=markdown-office_markdown-word)

[![Latest release](https://img.shields.io/github/v/release/markdown-office/markdown-word)](https://github.com/markdown-office/markdown-word/releases/latest)
[![Licence](https://img.shields.io/github/license/markdown-office/markdown-word)](https://github.com/markdown-office/markdown-word#licence)
[![php](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fraw.githubusercontent.com%2Fmarkdown-office%2Fmarkdown-word%2Fmain%2Fcomposer.json&query=%24.require.php&label=php&logo=php&logoColor=white)](https://github.com/markdown-office/markdown-word#requirements)

Convert Markdown to Word documents in pure PHP — and back again.

Parsing is done by [`league/commonmark`][commonmark], writing by
[`phpoffice/phpword`][phpword]. Everything in between — mapping the Markdown
syntax tree onto Word's document model — is this library.

**Markdown to Word:**

```php
use MarkdownWord\MarkdownToWord;

(new MarkdownToWord('README.md'))->save('README.docx');
```

**Word to Markdown:**

```php
use MarkdownWord\WordToMarkdown;

(new WordToMarkdown('README.docx'))->save('README.md');
```

The two are the same line with the ends swapped. The thing being converted goes
in the constructor, named as a path or handed over as the content itself, and
`save()` writes the result to the file named after it; `convert()` returns the
result as a string instead when that is what is wanted:

```php
(new MarkdownToWord('notes.md'))->convert();          // → the document's bytes
(new MarkdownToWord('notes.md'))->save('notes.docx'); // → a file
(new WordToMarkdown('notes.docx'))->convert();        // → the Markdown
(new WordToMarkdown('notes.docx'))->save('notes.md'); // → a file
```

So there is no need to read a file first. A string that names a file that exists
is read from it, and anything else is taken as the content — the same rule in
both directions:

```php
(new MarkdownToWord('notes.md'))->save('notes.docx');                  // a path
(new MarkdownToWord(file_get_contents('notes.md')))->save('notes.docx'); // or the text
```

Both implement one interface, so code that does not care which way the data goes
can be written once:

```php
use MarkdownWord\Converter;
use MarkdownWord\MarkdownToWord;
use MarkdownWord\WordToMarkdown;

function convert(Converter $converter, string $target): void
{
    $converter->save($target);   // Markdown in, or a document in — either works
}

convert(new MarkdownToWord('notes.md'), 'notes.docx');
convert(new WordToMarkdown('report.docx'), 'report.md');
```

A round trip is two of them and nothing else:

```php
$word = new MarkdownToWord(file_get_contents('notes.md'));
$back = (new WordToMarkdown($word->convert()))->convert();
```

Or from a terminal, with no PHP to write. That is [`mdoffice`][mdoffice], the
command line for this library and for Markdown to PowerPoint, which requires
this package and drives it. Every command, every option and every default is in
the [documentation][command-line].

## Installation

```sh
composer require markdown-office/markdown-word
```

That is the library and nothing else. There is no `bin` entry, so a Composer
install of this package puts no command in `vendor/bin`. The command line is a
separate program — [`mdoffice`][mdoffice] — whose commands and options are in
the [documentation][command-line].

**The project that installs this one has to allow [`cweagans/composer-patches`][composer-patches].**
The documents this library writes are Word and Open XML, and the released PHPWord writes
a few of them in an order the schema does not allow — the properties of a run, of a
paragraph, of a table, of a section and of a style, and a few elements that belong
somewhere else. The fixes are in pull requests upstream, and until they are released
they reach this library as a patch applied to the installed package. The plugin applies
that patch, and Composer runs it only when the project allows it:

```json
{
    "config": {
        "allow-plugins": {
            "cweagans/composer-patches": true
        }
    }
}
```

Without that line the install stops with Composer's own message naming
`cweagans/composer-patches`. The documents are valid Office Open XML only with it.

See [Requirements](#requirements) for what PHP and which extensions it needs.
Where to get help: [the issue tracker][issues].

## For an agent

There is a **skill**: a directory an agent reads, holding this library's
documentation, its examples, and the tools that render a document so that it can
be looked at. It is built from the documentation site, so it is never out of step
with it, and it is installed with one line in `opencode.json` or by unpacking it
where your agent looks for one.

[How to install it][skill].

## Why it exists

The obvious way to build a Markdown-to-Word converter is to render HTML and hand
it to PHPWord's HTML importer. That works for simple documents and quietly falls
apart on real ones: emphasis nested inside emphasis, line breaks, code blocks,
links whose text is itself formatted. Those are exactly the things READMEs are
made of.

This library walks the Markdown **syntax tree** instead. Nothing is flattened on
the way through HTML, so what comes out is what the author wrote.

## Conformance

The test suite runs every example from the official specification suites and
checks that the resulting document carries exactly the text the specification
says it should:

| Suite | Examples |
| --- | --- |
| [CommonMark 0.31.2][cm-spec] | 654 |
| [GitHub-Flavored Markdown 0.29][gfm-spec] | 646 |

`league/commonmark` is a fully conforming parser, so the visible text of its HTML
output *is* the specification's answer. Comparing the text of the generated Word
document against that is a strong, automatic check — a construct that loses or
invents text fails the build.

```sh
composer check             # the lot, in the order continuous integration runs it
composer test              # the test suite
composer test:unit         # the unit tests alone
composer test:spec         # just the conformance suite
composer test:roundtrip    # just the round-trip suite
composer test:readme       # just the examples on this page
composer test:ooxml        # just the schema gate over the documents
composer test:coverage     # with a report in build/coverage
php smoke.php              # does the library work on this version of PHP?
php stress.php             # malformed input across every configuration
php examples/build.php     # a document for every example in examples/
```

`stress.php` feeds deliberately awkward input — unterminated delimiters, control
characters, lone `<` and `&`, very deep nesting — through nine configurations,
and then through a template with the seven of those that are not merely a choice
of parser. Every result has to be a valid `.docx`. It is what caught the escaping
defect described below.

The examples on this page are `tests/Readme/examples.php`, one test each, and
they run with everything else, so the documentation cannot quietly stop
describing what the code does.

Tests are written with [Pest][pest] 5, against PHPUnit 13.

### Continuous integration

| Workflow | What it does |
| --- | --- |
| `tests` | `composer validate --strict`, `composer audit`, the README examples, then the suite on PHP 8.4 and 8.5 with a coverage report |
| `ooxml` (a job in `tests`) | the documents this library writes, checked against the OOXML schema by Microsoft's own validator, which needs the .NET SDK |
| `sonarcloud` (a job in `tests`) | static analysis on [SonarCloud][sonarcloud], failing the build when the quality gate is red |

PHP 8.2 is in the matrix too, but the test suite cannot run there: **Pest 5
requires PHP 8.4**, while the library itself supports 8.2. That gap is covered by
`smoke.php`, which uses no test framework and checks that both directions work on
whatever PHP it is given, and by `stress.php` beside it. Between the two, every
supported version is exercised.

Everything installs what `composer.lock` pins, so a build is repeatable. A weekly
`dependencies` job resolves afresh instead and runs the suite against the result,
which is how a new release of a dependency still gets tested.

### Static analysis

SonarCloud runs from this repository's own workflow rather than from its GitHub
integration, so the quality gate can fail a build. What it analyses is set in
[`sonar-project.properties`](sonar-project.properties) rather than in the workflow,
so it is visible to anyone reading the repository.

It authenticates with a `SONAR_TOKEN` repository secret, which is never in the
repository. Create one at [your account's security page][sonar-token] and add it
under **Settings → Secrets and variables → Actions**. Without it the analysis job
stops and says so.

## Word to Markdown

The same mapping runs in reverse, and the class mirrors the way in:

```php
use MarkdownWord\WordToMarkdown;

echo (new WordToMarkdown('report.docx'))->convert();       // as a string
(new WordToMarkdown('report.docx'))->save('report.md');   // straight to a file
echo (new WordToMarkdown())->toMarkdown($bytes);          // bytes already in hand
```

A Word document is a lower-fidelity form of the Markdown it came from, so the
round trip preserves what Word was told to keep and is explicit about the rest.
[What it does not preserve](#what-the-round-trip-does-not-preserve) is worth
knowing before the options, which configure the reader rather than undo the loss.
The distinctions Word does not record are options:

| Option | Default | Meaning |
| --- | --- | --- |
| `headingStyles` | `Heading`, `Title` | style ids read as ATX headings |
| `quoteStyles` | `IntenseQuote`, `Quote`, `BlockQuote` | style ids read as block quotes |
| `monospaceFonts` | Consolas, Courier, Menlo, … | typefaces read as code |
| `quoteIndent` | `720` | twips per level of quote nesting |
| `fenceCodeBlocks` | `true` | two or more monospaced paragraphs are a code block |
| `tableHeader` | `true` | the first row is written as the header |
| `headingSetext` | `false` | first- and second-level headings underlined |
| `mediaDirectory` | `null` | a directory the images are taken out into |
| `lineEnding` | newline | what the output file uses |
| `maxPartBytes` | 256 MB | largest a part of the archive may be uncompressed |
| `maxEntries` | `4096` | largest number of parts the archive may have |
| `maxStyleDepth` | `32` | how far a `basedOn` chain of styles is followed |

A document is not this library's, so the last three are what it is willing to be
told: a `.docx` is a zip, and a zip says how its contents are laid out without
saying how much room they will take up.

```php
use MarkdownWord\Reverse\Options;

new WordToMarkdown(null, Options::fromArray(['mediaDirectory' => 'assets']));
```

`Reverse\Options::toArray()` gives the whole table back, which is handy in a config
file or a log. And for a caller that wants the document rather than the Markdown
— to index it, or to decide what to do with it before writing anything:

```php
$blocks = (new WordToMarkdown())->read('report.docx');   // list of Reverse\Block
```

The output is GitHub-Flavored Markdown: a Word table can only be a GFM table,
and struck-through text can only be GFM's `~~`.

### Round trips

```
Markdown --> Word --> Markdown --> Word
             |------ same text -----|
```

#### What the round trip does not preserve

Word does not record these, so there is nothing for the reader to find. All of
them are what the round trip actually produces:

- **A word in a text style comes back as the word.** `:brand[Bakery]` is written as
  direct formatting — a colour, a weight, a size — and the reader reads bold, italic
  and strike and no colour. The word is there; the style is not, and neither is the
  name it was written under.

- **A table's header row comes back bold.** A run is bold in the document, and
  the document does not say whether the renderer or the author made it so:

  ```markdown
  | A | B |               | **A** | **B** |
  | --- | --- |     →     | :-- | :-- |
  | 1 | 2 |               | 1 | 2 |
  ```

  The alignment does survive — `:--`, `--:` and `:-:` say what `---`, `---:` and
  `:---:` said — and the delimiter row is the only part rewritten. It is the
  bold that is added, and `tableHeaderBold: false` is what stops it.

- **A fenced code block comes back without its language.** The fence and the text
  are in the file; the word that followed the fence is not:

  ````markdown
  ```php
  $x = 1;
  $y = 2;
  ```
  ````

  comes back as the same two lines between plain fences, which is all the reader
  has to go on.

- **A fenced code block of one line comes back as inline code.** `fenceCodeBlocks`
  reads *two or more* monospaced paragraphs as a block, so a single line is not
  enough: ```` ```php\n$x = 1;\n``` ```` is written back as `` `$x = 1;` ``.

- **A quote configured as plain indentation, rather than as a style, comes back
  as a plain paragraph.** The `>` is a paragraph style in Word, and with
  `blockQuote` set to `null` there is no style for the reader to recognise and no
  option that puts one back. The paragraph *is* indented, but `quoteIndent` only
  measures depth inside a quote already identified by its style.

- **The last line has no newline after it.** The body of a document ends at its
  last paragraph and records nothing about the end of the text, so the reader
  writes no trailing newline: `"# Title\n"` comes back as `"# Title"`. It is the
  first thing a `diff` shows. `lineEnding` set to `crlf` does add one, because
  that is the one case where the writer appends its own.

- **A text box comes back without its text.** The text of a box is in a
  `w:txbxContent` inside the run that holds it, and the reader reads the
  paragraphs of the body and not the boxes inside them. A shape has no text and
  so comes back as nothing at all.

- **A form field comes back as the blank field Word draws.** A `FORMTEXT` is a
  field with a run of thirty spaces in it, and that is what the reader finds.

- **A page break and the columns of a section come back as nothing.** Both are
  in the file — `w:br` and `w:cols` — and the reader reads neither.

The round-trip suite runs all 1300 specification examples through both
directions and checks that the second Word document says the same thing as the
first. It is the check the forward direction alone cannot make: a defect in the
reader cannot hide behind a document nobody reads back.

It is what found that a link's title was dropped, that an image's alt text never
reached the file, and that a task list marker was silently discarded — none of
which the forward suite could see, because each of them loses nothing the
specification's expected text mentions.

`examples/out/11-round-trip.md` is `01-kitchen-sink.docx` read back, for looking
at side by side.

## Output formats

`.docx` is the format everything defaults to: `toDocx()` and `convert()` are what
they were, and it is what a conversion asked for nothing else produces. The
other two are there for the readers that want them, and each has to say for
itself what it cannot carry — a document that quietly lost its lists is worse
than one that says it has none, because the loss is only visible by comparing
the Markdown with the result.

```php
use MarkdownWord\Format;
use MarkdownWord\MarkdownToWord;

$converter = new MarkdownToWord('notes.md');

$converter->save('notes.docx');                       // the default
$converter->convertTo(Format::Odt, 'notes.odt');
$converter->convertTo(Format::Rtf, 'notes.rtf');

$bytes = $converter->toOdt('notes.md');               // → the document's bytes
$bytes = $converter->toRtf('notes.md');
```

All three go through the same staging the `.docx` path has always used: the
document is finished in the temporary directory and moved into place in one step,
so a failed conversion never leaves half a document where someone will open it
and never exposes a whole document to every account on the machine while it is in
flight. That is a property of the move rather than of any writer, so it holds for
the two new formats as it does for the old one.

### What each format carries

Every row below was measured by writing the same document through each of
PHPWord's three writers, rendering the result, and reading the file back.
"Dropped" means the feature is not in the file; "flattened" means something is
there but not what it was.

A row that says *a named style* is the one case the built-in look cannot help
with: it is written as direct formatting, so it reaches every writer, while a
slot you have pointed at a style of your own is a name and a name is all two of
these formats resolve it to. See [The built-in
look](#the-built-in-look) for the other half of that sentence.

| | `.docx` | `.odt` | `.rtf` |
| --- | --- | --- | --- |
| Headings | carried | carried | carried |
| A slot naming a style of your own | carried | dropped, written as body text | dropped, written as body text |
| Bold, italic, underline, strikethrough | carried | carried | carried |
| Font size | carried | carried | carried |
| Typeface | carried | carried | dropped |
| Run colour | carried | carried | carried when the same colour is in a registered style, otherwise dropped |
| Bullet lists, including nesting | carried | carried | dropped, every item left out |
| Ordered list numbering | carried | dropped, each item comes out bulleted | dropped, every item left out |
| Table borders | carried | dropped | carried |
| Table column alignment | carried | dropped | carried |
| Bold header row | carried | dropped | carried |
| Block quotes | carried | carried | carried |
| Paragraph background (a code block) | carried | dropped | dropped |
| The rule under a thematic break | carried | dropped | dropped |
| A link | carried | carried | carried |
| A link whose label contains emphasis | carried | carried | carried |
| A PNG or JPEG picture | carried | carried | carried |
| A WebP picture | carried as PNG | carried as PNG | carried as PNG |
| A picture's alternative text | carried | carried | dropped |
| An SVG | carried, vector beside its raster | flattened to a raster | flattened to a raster |
| `:footnote[…]` | carried as a Word footnote | source form back | source form back |
| A header and a footer | carried | not carried | carried |
| `::toc` | carried as a field | source form back | source form back |
| `::image`, `::caption`, `::pagebreak` | carried | carried | carried |
| `::section{columns=2}` | carried, the columns and all | carried, the count of them | the break, not the columns |
| `::textbox`, `::shape`, `::field` | carried | source form back | source form back |
| Rendering into a template | carried | not available | not available |

The ones a reader is least likely to notice are worth naming in full.

**RTF leaves every list item out of the document.** Not the bullet, not the
number — the text of the item. PHPWord has no writer for a list item under its RTF
writer, and its element writer skips an element it has none for, so three items
of Markdown become an empty body. There is no way to write a `.rtf` with a list
in it from this library, and the run says so on standard error rather than
leaving it to be found.

**A JPEG is written into an `.rtf` labelled as a PNG.** The RTF writer emits
`\pngblip` whatever the bytes are, and a reader is left to work out what it has
been given. It is named in the report for the same reason as the rest.

**An `.rtf` run keeps a colour only if something else in the document already
uses it.** RTF has a colour table, and PHPWord fills it by walking the styles
registered on the document — not by walking the runs. A heading comes out blue
because the `Heading1` style this library defines into every document is blue, so
the colour is in the table before the run asks for it. An inline code span's
`#A31515` is in no registered style, and comes out black. Nothing in the library
promises this; it is what the writer does.

**A named style is still only a name in an `.odt`.** The built-in look writes
its properties onto the text, so a default document is unaffected. A slot you
have pointed at `CorpTitle` writes that id into the paragraph, and ODF and RTF
have no such style to resolve it against, so the paragraph comes out as body
text. `pendingLosses()` reports it, and only for a document that did it.

### Being told what was lost

`Format::drops()` is the list of features a writer cannot express, whatever the
document. Whether this document used any of them is a separate question, answered
over the same element tree every writer is handed and over the directives that
recorded what they were asked for — a directive a writer cannot carry is drawn
as its own source text, so there is nothing of it in the tree to find. A feature
nobody used is not reported: a document with no tables says nothing about table
borders.

```php
foreach ($converter->pendingLosses() as $loss) {
    echo $loss->message, "\n";
    // every list item is left out of the document
    // a run keeps its size and its weight but loses its typeface and colour
}
```

Nothing reports them for you: writing the document is the library's job and
deciding where a warning goes is the caller's, so a caller that wants the losses
on standard error rather than in a return value prints them. A `.docx` drops
nothing and is what the other two are measured against, so a run into one never
produces a line of this kind.

### What is not here

**Only Word packages are read back.** `WordToMarkdown` reads a `.docx` and a `.dotx`;
handing it an `.odt` is not supported, and neither is an `.rtf`. A `.dotx` read back is
whatever it currently holds, which for an unfilled template is its own text, the
placeholder inside the content control, and the cached results Word keeps beside each
field. Those are text, and reading them back is the honest answer to be asked for a
template that has not been filled in.

**A template is a `.docx` or a `.dotx`.** PHPWord's template processor reads that
package and nothing else, so there is nothing to render one into for either of
the other two formats, and asking for both at once is refused rather than half
attempted. A `.dotx` is the same package with its body declared as a template's, and
whatever it is rendered into, the result is a document — see [Word
templates](#word-templates).

**An SVG needs `ext-imagick` unless `svgFallback` is `none`.** The raster beside
the vector is drawn by something, and without it an SVG raises and says so rather
than going in silently flattened.

## Command line

There is none in this repository. The command line is [`mdoffice`][mdoffice],
one program for Markdown to Word and to PowerPoint, which requires this package
and calls it rather than reimplementing any of it.

Every command, every option and every default is in the
[documentation][command-line] — a chapter of its own, with the options of each half on a
page of their own — and that is where they are kept, so there is one place to look and
one place to correct.

Everything below about how a conversion behaves — which direction a file goes in,
what a template renders into, what an `.rtf` cannot carry — is a claim about this
library, and is what `mdoffice` calls.

## Supported Markdown

| Construct | Result in Word |
| --- | --- |
| ATX and Setext headings | `Heading1`…`Heading6` styles |
| Paragraphs, soft and hard breaks | paragraphs and line breaks |
| `**bold**`, `*italic*`, `~~strike~~` | character formatting, nestable |
| `` `code` ``, fenced and indented blocks | monospaced, shaded paragraphs |
| Bullet and ordered lists, nested | real Word numbering, any depth |
| Task lists | `☐` or `☒` at the start of the item, read back as `- [ ]` / `- [x]` |
| `> block quotes`, nested | indented, styled paragraphs |
| Tables with alignment | `w:tbl` with the alignment applied |
| `[links](url)`, reference links, autolinks | `w:hyperlink`, including emphasis inside the label |
| Images | embedded, or alt text when the file is missing |
| `---` | a paragraph with a bottom border |
| Raw HTML | rebuilt as Word content, or shown as text, or dropped |
| Footnotes, description lists | with `CommonMarkParser::extended()` |
| A table of contents | `::toc`; see [A table of contents](#a-table-of-contents) |
| Directives — `:name[…]`, `::name[…]`, `:::name` … `:::` | on by default; a name nothing draws comes back as the characters it was written as, see [Directives](#directives) |
| Inline styles — `:name[text]`, `:name[text]{attrs}` | a word in a style, in the middle of a sentence, see [Inline styles](#inline-styles) |
| Objects — `::image`, `::caption`, `::pagebreak`, `::section`, `::textbox`, `::shape`, `::field` | a picture, a caption, a page break, a section, a box, a shape, a form field; see [Objects in a document](#objects-in-a-document) |

A link whose label contains formatting, such as `[**bold** link](url)`, becomes a
genuine Word hyperlink *with* the bold applied. PHPWord cannot express that
directly, so the renderer emits a placeholder and rewrites `word/document.xml`
into a `w:hyperlink` element while writing the file.

### Directives

The [Generic Directives RFC](https://talk.commonmark.org/t/generic-directives-plugins-syntax/444)
is parsed by `CommonMarkParser`, from
[`markdown-office/commonmark-generic-directives`](https://github.com/markdown-office/commonmark-generic-directives).
Three shapes, told apart by their colons and by nothing else:

```markdown
Ship it :badge[PRODUCTION]{type=success} today.

::chart[caption]{src="chart.png"}

:::callout{type=info}
Inside the container.
:::
```

**A directive this library does not draw is drawn as the source form.** That is
what makes the syntax safe to have on by default, and it is a real decision
rather than an absence of one. It is the node rendered back into its own
syntax, not the author's bytes, so three things come out slightly changed:
attribute values are re-quoted where they were bare, runs of whitespace inside a
label collapse, and the brackets of an empty label are not written. A sentence
containing a directive you have not heard of therefore still reads as the
sentence you wrote, and `:green[go   fast]` reads as `:green[go fast]`.

| Written | In the document |
| --- | --- |
| `:::callout{type=info}` … `:::` | the body, as ordinary Markdown; the fence is gone |
| `:badge[PRODUCTION]{type=success}` | `:badge[PRODUCTION]{type="success"}` — the same characters back |
| `::chart[caption]{src="chart.png"}` | `::chart[caption]{src="chart.png"}` |

A container is transparent because its body is the author's own Markdown and the
fence is not; drawing the fence would put `:callout` in the document as a line of
its own. A leaf has no body to fall back on, so the alternative to drawing it was
dropping it — which took the paragraph it stood between with it.

The third row is what a change of default turns on. Directives were once opt-in,
because `Status :green[go]` is a sentence and a parser nobody asked to change
should not change the syntax of every document converted after it. That reasoning
still holds for a *drawn* name, so it is kept by drawing only the names this
library has an implementation for, and everything else returns the characters as
typed. Two directives differing only in their attributes still produce two
different lines.

What a name means is a decision per name, and it lives in
[`src/Render/Directives.php`](src/Render/Directives.php) rather than in the
parser. Names are matched without regard to case, since a document cannot be asked
to care.

Names are not otherwise checked. `GenericDirectiveExtension` takes a
`DirectiveRegistry` that makes it strict — an unregistered name raises
`UnknownDirective` rather than being rendered as text — but `CommonMarkParser`
builds its extensions from class names and cannot pass one in, so strict mode is
not reachable through the factory.

#### Inline styles

A word in a style, in the middle of a sentence:

```markdown
Resilience :brand[Bakery] is open.
```

Three forms, one grammar:

| Written | In the document |
| --- | --- |
| `:accent[brand]` | the word "brand", in the style the theme calls `accent` |
| `:accent[brand]{color=FF0000}` | the same word in red: the attribute wins over the style |
| `:style[brand]{color=00B4D8}` | the word "brand" in teal, with no theme style involved |

The named styles come from the [theme](#themes), and the eight attributes are the
same wherever a style is written:

| Attribute | Carries |
| --- | --- |
| `color` | the ink: six hexadecimal digits, as `8B0000` |
| `size` | the size, in points |
| `font` | the family |
| `bold` | the weight |
| `italic` | the slant |
| `underline` | the underline |
| `strike` | the strike |
| `highlight` | a colour behind the word |

**An attribute overrides the named style, property by property.** An attribute the
style does not set and that does not name itself is not set either: the run
inherits whatever the paragraph around it gave it, and nothing is reset to a
default.

**A label is plain text.** The directives package stores a label as a string
rather than parsing it as inline Markdown, because CommonMark's inline engine
offers no way for a parser to say "the rest of this run is mine". So
`:brand[**Bakery**]` cannot have its emphasis honoured — and a *complete* Markdown
construct in a label is not honoured either: `**text**`, `__text__`, `*text*`,
`_text_`, `` `code` `` and `[text](url)` all leave the label alone. A bare `*` or
`_` is not Markdown: `2 * 3` and `snake_case` are ordinary text, and the word is
written in the style as it stands. So is a construct CommonMark does not call
emphasis: `__ Bakery__` and `** text**` are text, because a delimiter that opens an
emphasis may not be followed by a space and one that closes it may not be preceded
by one.

**What this library cannot draw is drawn as its own source text.** That is the
policy of this half, and the inline styles keep it:

| Written | In the document |
| --- | --- |
| `:brand[Bakery]` where the theme has no `brand` | `:brand[Bakery]` |
| `:style[Bakery]` with no attributes | `:style[Bakery]` |
| `:brand[Bakery]{weight=900}` | `:brand[Bakery]{weight="900"}` |
| `:brand[**Bakery**]` | `:brand[**Bakery**]` |

The presentation half refuses all four and names the alternative. This half draws
them, because *a sentence and a parser nobody asked to change should not change
the syntax of every document converted after it* — and the author gets back the
characters they typed.

### Objects in a document

A document is more than paragraphs, and these are the names that place the rest of
it:

| Directive | Carries | Carried by |
| --- | --- | --- |
| `::image{src=… width=… height=… align=…}` | a picture | `.docx`, `.odt`, `.rtf` |
| `::caption[Figure 1]{}` | a caption | `.docx`, `.odt`, `.rtf` |
| `::pagebreak` | a page break | `.docx`, `.odt`, `.rtf` |
| `::section{columns=2}` | a section break, and its columns | `.docx`, `.odt` |
| `::textbox[…]{width=… height=…}` | a box with text in it | `.docx` only |
| `::shape{type=rect …}` | a shape | `.docx` only |
| `::field{name=…}` | a form field | `.docx` only |

```markdown
::image[Revenue by quarter]{src="chart.png" width=12 height=8 align=center}

::caption[Figure 1: Revenue by quarter]{}

::pagebreak

::section{columns=2}
```

**A picture is measured in centimetres**, the unit `imageMaxWidth` is in, so a
document that names a size and a document that leaves it to the option are in the
same unit. A size that is asked for is the size the picture gets: the cap is what
happens to a picture that says nothing about its own size, and shrinking a
picture to a limit the author did not know about is the kind of quiet change this
library does not make.

| Attribute | |
| --- | --- |
| `src` | the file, resolved against `imageBasePath` the way a Markdown image's URL is |
| `width`, `height` | the size in centimetres; one of them leaves the other to the picture's own proportions |
| `align` | `left`, `center`, `right` |

The label is the alternative text, and a file that is not there falls back to it —
the same answer a Markdown image gives.

**A caption is not numbered.** PHPWord has no `SEQ` field, which is the field Word
numbers a figure with, so a caption that numbered itself would be a number
written out at conversion time — wrong the moment a figure is inserted above it.
The number is in the caption, where a reader can see it and an author can change
it.

**The columns of a section are carried by `.docx` and `.odt`, and not by `.rtf`.**
RTF has no columns at all, so the section break survives and the columns do not.
That is reported the way every other loss is, and the sentence names the
directive: *the columns of a ::section are lost*.

**A text box, a shape and a form field are carried by `.docx` alone.** PHPWord
writes them as VML and as Word's own `FORMTEXT`, `FORMCHECKBOX` and
`FORMDROPDOWN` fields, and neither the ODF writer nor the RTF writer has an
element for any of them. A writer that cannot carry one is given the characters
the author wrote — the same answer an unclaimed directive gets — and the loss is
reported: *a ::textbox is left out of the document*.

The three of them take these:

| Attribute | |
| --- | --- |
| `type` | for `::field`: `textinput` (the default), `checkbox`, `dropdown` |
| `name` | the name of the form field |
| `entries` | the choices a dropdown offers, separated by commas |
| `value` | what a text field starts with |
| `checked` | for `::field`: `true` leaves a checkbox ticked |
| `width`, `height` | the size of a box or a shape, in centimetres |
| `left`, `top` | where a text box floats: from the left margin, and from the top of the paragraph it stands in |
| `border` | the colour of the line round a text box |
| `fill`, `line` | the colours a shape is drawn in, six hexadecimal digits without a `#` |

**A text box floats, and the text flows around it.** A box that sat in the flow
would be a paragraph with a border round it, and a paragraph is what a document
can already say. `left` and `top` place it, in centimetres like every other
size here.

**A dropdown turns the document into a form.** Word only lets a reader choose
from one while the document is protected for forms, so a document with a dropdown
in it is protected for forms. A field that cannot be answered is a field that is
not there.

**A dropdown cannot start with a value.** Word records a dropdown's choice as the
position of the chosen entry in the list, not as its text, so the text of an entry
has nowhere to go. A `.docx` that carries one is refused rather than given a word
where a number belongs; the choices themselves go in `entries`. The other two formats
cannot carry a field at all, so they draw the directive as its own source text, as
they do every other one.

### A table of contents

`::toc` writes a real Word table-of-contents field. Word builds it from the
headings when the document opens, and only a field keeps it right as the
document grows — a list of lines written here would be wrong the moment a
section moved.

```markdown
::toc
::toc{depth=2}
::toc{title="Contents"}
::toc{numbers=false}
```

| Attribute | |
| --- | --- |
| `depth` | how many heading levels, `1` to `6`. Defaults to `maxHeadingLevel` |
| `title` | a heading above the field |
| `numbers` | `false` leaves page numbers out (Word's `\n`) |

Three details are worth knowing, because all of them are visible in the file:

**The headings must carry the built-in names.** A `.docx` identifies a built-in style
by the `w:name` in its definition, and Word spells those `heading 1` … `heading 9`. A
definition carrying `w:name="Heading1"` is a style *called* Heading1 — it resembles the
built-in heading and is not it — and Word collects nothing, which it says in the field
rather than in a dialog:

> Es wurden keine Einträge für das Inhaltsverzeichnis gefunden.

With the names right, Word collects every heading with its page number. That is the
whole of what it needs, and it was checked by opening the file in Word rather than by
reading the markup, which is the only way to know it.

Both halves are written after the file is closed, by `Writer\BuiltInHeadingPass`,
because PHPWord offers no hook for either. `Writer\Word2007\Part\Styles` takes `w:name`
from the `w:styleId` and spells `heading N` itself in exactly one place — the
`if ($styleType == 'title')` branch of `writeFontStyle()`, which only `addTitleStyle()`
reaches — and `w:outlineLvl` is written for numbering styles only. Nothing else in
a definition is touched, so a size, a colour or a spacing a document set is exactly as
it was.

**The outline level is for everyone else.** A heading style is also expected to say what
level it is, and Word writes one on every heading style in its own template — but Word
does not collect by it, `TOC \o` reads the style. LibreOffice reads the level and not
the name: the same document is `<text:p>Findings</text:p>` without one and
`<text:h text:outline-level="1">Findings</text:h>` with it. Without it, these headings
were body text to anything that is not Word.

**There are no cached entries.** Word fills the field in on open; until it
does, the field says *Press F9 to build the table of contents.* A cached
alternative is possible and is worse — the page numbers would have to be
guessed, and a table of contents claiming everything is on page 1 misleads
precisely the reader who cannot refresh it.

**`title` is drawn in the heading look but not with the heading style.** A title
that carried a heading style would list itself as the first entry of the contents
it titles.

Only `.docx` can carry the field. An `.odt` or an `.rtf` gets the source form
back — `::toc` as a paragraph — and says so through the usual
`Format::drops()` channel, the same as any other thing a format cannot hold.

### Footnotes

`:footnote[…]` is a Word footnote, with its mark where the directive stood and its
body at the foot of the page.

```markdown
Trading closed ahead of plan :footnote[The western figure excludes two hires.] in
every region.
```

The body is **text, never Markdown** — the directive's label goes in as written.
The mark is a real superscript reference and the body is numbered by Word, so the
notes renumber themselves when one is inserted or deleted above.

Two things about the syntax, both from the directive grammar rather than from this
library:

- **The label goes in one pair of brackets.** `:footnote[n=12]`. Written
  `:footnote:[n=12]` the second colon starts something else and the note ends up
  empty — which is drawn as the source form rather than as a mark pointing at
  nothing.
- **A mark cannot sit flush against a word.** `plan:footnote[…]` is prose — the
  grammar leaves a colon after a word alone, because `status:error` is prose too.
  There has to be a space or punctuation in front of the colon, so the document
  reads `plan :footnote[…]` with a visible space before the mark.
- **A mark on a line of its own is a paragraph holding a mark.** `:footnote` is an
  inline directive, so one between two blank lines is not an annotation of the sentence
  above it — it is a whole paragraph with nothing in it but a superscript, and a reader
  sees an empty line. Put it at the end of the sentence it belongs to.

Only `.docx` carries a footnote: neither other format has a writer for one, so
they get the source form back and the loss is reported the usual way.

**Through a `.dotx` template, every one of these three is drawn as its source
form.** The template renderer copies elements into the template's own document
rather than writing a package, so the parts this library writes *beside* a body
never travel — a footnote part, a header part, the field a `::toc` placeholder
stands for. Emitting them anyway leaves a document carrying a footnote
reference to a note it does not contain and a run of invisible separators where
a table of contents should be. So `::toc` and `:footnote` are drawn as
`:footnote[…]`, `:footnote` reads as written rather than as a mark, and a
`header:` block has no effect. Render without a template to use them.

### Headers and footers

A document's running head and foot come from its frontmatter, and the body of
each is **Markdown** — the same renderer, so bold, links and images all work.

```markdown
---
options:
  imageMaxWidth: 4
header:
  body: |
    ![Logo](assets/logo.png)

    **Meridian Analytics**
  align: right
  border: bottom
  firstPage: true
footer:
  body: |
    Page {page} of {total} — {filename}
  align: center
```

A blank line is what separates them. Two lines with nothing between them are one
paragraph, and an image inside a paragraph is inline — text flows beside it rather
than under it. Leave the line empty to stack them, or set `softBreak: lineBreak` to
have every line break in the document become one.

| Key | |
| --- | --- |
| `body` | Markdown for the part |
| `align` | `left`, `center`, `right` — a tab stop for the fields |
| `border` | `top`, `bottom`, `both` |
| `firstPage` | `true` gives page 1 a header of its own |
| `even` | `true` gives even pages one, and turns on `w:evenAndOddHeaders` |

`{page}`, `{total}`, `{date}`, `{chapter}` and `{filename}` become **real Word
fields** — `PAGE`, `NUMPAGES`, `DATE`, `STYLEREF`, `FILENAME` — not text
substituted at conversion time. A page number written into the file is wrong by
the time the document is printed; the field is not. The `date`, `chapter`,
`page`, `total` and `filename` keys draw the same field without a token, and
`dateFormat` chooses the picture a date is written in.

Three things that are easy to get wrong, and are therefore worth stating:

**Size a header logo with `options: imageMaxWidth`, or with a `::image` of its
own.** A header's body is Markdown, so the directive is available there too —
`::image[Logo]{src="assets/logo.png" width=2}` is two centimetres wide, whatever
the global cap says. The default cap is 15 cm, which is a body picture's width
and fills a third of the page as a logo, so a header that says nothing about its
logo gets a very large one.

**`firstPage: true` applies to the whole section, not just the header.** Word's
`<w:titlePg/>` has no finer setting, so page 1 then looks for a *first-page
footer* as well — and a document that gave only an ordinary footer has none, so
page 1 has no footer. Give `footer:` a `firstPage` too, or do not ask for one.

**An empty `header:` writes no header part at all.** Not an empty band on every
page — which is what writing the part unconditionally would give a template that
already carries a header of its own.

A `.dotx` may carry its own header and footer, and this does not merge with
them: the template's parts are its own, and a `header:` in the frontmatter is
ignored on that path. Set the header in the template, or render without one.

`.docx` and `.rtf` both carry a header and a footer. `.odt` carries neither —
PHPWord has no part for either — so a document declaring one gets the loss
reported through `Format::drops()`. A link in a header works; alt text and an
SVG logo do not, because the passes that write those rewrite `word/document.xml`
and not the header parts.

## Word templates

Point the renderer at the **styleIds** your template defines and it inherits the
whole design — fonts, colours, spacing, headers and footers. A `.docx` and a `.dotx`
are both accepted, and which one it is makes no difference to the result: **a filled-in
template is always a document.** See [What a `.dotx` costs](#what-a-dotx-costs) for
why that is worth saying.

Everything a template says is something Word already understands, because a template
comes from Word and anything else would have to be invented here:

| | |
| --- | --- |
| A value | a **`MERGEFIELD`**, the field a person inserts with `Ctrl+F9` |
| A place for the content | a **rich-text content control**, named `body` |

There is no third thing. A row of records is a table in the Markdown, and the template
styles it like any other content — which is why there is no loop to explain.

```php
use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;
use MarkdownWord\Template\MarkdownTemplate;

$config = Configuration::create()->withStyles([
    Styles::HEADING_1 => 'ReportTitle',   // a styleId in your template
    Styles::PARAGRAPH => 'BodyText',
    Styles::BLOCK_QUOTE => 'PullQuote',
]);

(new MarkdownTemplate('report-template.docx', $config))
    ->insert(file_get_contents('summary.md'))
    ->save('northwind.docx');
```

`summary.md` carries its own values, so the document and everything in it travel
together:

```markdown
---
fields:
  customer: Northwind Ltd
---

# Highlights

- one
- two
```

**`body` is this library's convention, not Word's.** ECMA-376 §17.5.2.36 defines a
content control's `w:tag` as "an arbitrary string which applications can associate
with a structured document tag", reserves no value for it, and Word never reads one —
it round-trips it and leaves it alone. So the name collides with nothing Word
recognises, and no standard is being missed by not matching one. A template that
names its control something else is asked for that name:
`insert($markdown, 'letter')`. Nobody else does this: the templating libraries that
exist either use plain text delimiters (`{name}` in docxtemplater, `{{ var }}` in
python-docx-template) or invent their own prefix, and none of them claims `body`.

**Filled fields are unlinked.** The value stays and the field goes, which is what
Word's own *Finish & Merge → Edit Individual Documents* produces and what a finished
document wants: nothing to refresh with `F9`, and a value that can hold `&` or `<`
without becoming an instruction.

### What a `.dotx` costs

Nothing to use one, and one thing to *produce* from one: the result is a document, not
a filled template. That is the only difference between the two, and it is worth
stating because the alternative is worse.

A `.dotx` and a `.docx` are the same archive with one line of `[Content_Types].xml`
different, and that line is what Word decides a file by. A package typed as a template
is opened by *creating a new document from it* — double-clicking a filled-in `.dotx`
gives you `Document1.docx` rather than the file you were sent, and anything
dispatching on MIME type treats it as a template. So rendering into one writes a
document, and says so by writing one:

```php
$template = new MarkdownTemplate('report-template.dotx');
$template->insert('body', $markdown)->save('northwind.docx');   // a .docx, whatever went in
```

The line matters for a second reason, which is why it is settled on the way in rather
than patched on the way out. Which part holds the body is also read from that file,
and it is not always `word/document.xml` — documents from Word Online carry
`word/document22.xml`, which is why PHPWord looks the name up at all. Its lookup
matches the *document* content type, so on a template it finds nothing, assumes
`word/document.xml`, and writes there. The result was a document whose real body still
held `${body}${slot}${/body}` and whose Markdown had gone into a part nothing pointed
at: the content silently lost, with every word of the template's own text in place.

Normalising the type on the way in answers both at once, so the template on disk is
never opened for writing — the copy the renderer works from is ours, and it is removed
when the renderer goes.

### The template contract

A template says two things, and both are Word constructs:

- **A value** is a `MERGEFIELD`. Insert one with `Ctrl+F9`, name it `customer`, and
  the document supplies `customer` under `fields:`.
- **A place for the content** is a rich-text **content control**, inserted from the
  Developer tab. Its *tag* names it — `body` unless `insert()` is told otherwise —
  because the tag is what a program reads and the alias is the visible label somebody
  renames while working on the template.

A template with no control is not a failure — it is the ordinary case. Nobody making a
company letterhead inserts a content control, so a template with one is one this
library's generator wrote, and refusing the rest would leave it able to use only the
templates it wrote itself. **Any `.dotx` works.** Filling the fields and placing the
content are separate: a letterhead with `MERGEFIELD`s for the client and no control at
all gets both — the values filled, and the prose appended.

Where the content goes without a control is at the end of the body, before the final
`w:sectPr`. Before, because a `w:sectPr` must be the last child of a `w:body` and
content after it belongs to no section — it would carry none of the margins, header,
footer or page numbering that section defines, which for a letterhead is all of the
letterhead. The cost is that a template ending in a signature block gets the body below
it. A template that cares where the content lands says so, with a control, and then the
control decides.

No other tool has a rule to copy here. docxtemplater, python-docx-template,
docx-templates and Word's own mail merge all leave a template with no placeholder
exactly as they found it and insert nothing, so the document opens cleanly and contains
none of the caller's content. Where a pipeline would rather know than have it appended,
ask:

```php
use MarkdownWord\Template\ContentFallback;

(new MarkdownTemplate('letterhead.dotx'))
    ->contentFallback(ContentFallback::Error)
    ->insert($markdown)
    ->save('letter.docx');
```

Either raises a `NoContentControl` naming the controls the template *does* have — a
letterhead whose control is called `terms` is one tag away from working, and a message
saying only that it has no `body` reads as though it has none.

```
Report for «client»          ← a MERGEFIELD, filled and then unlinked
┌ body ──────────────────┐
│ Click here to enter…   │  ← a content control, replaced and then removed
└───────────────────────┘
```

The `«»` Word shows around a field is what a template carries before it is filled, not
what a finished document has: fields are unlinked, so nothing in the output is a
placeholder anyone could still type into.

Two things are carried across into the template that a naive copy would lose, and
that the library therefore rebuilds on the way out:

- **Hyperlink relationships.** A `w:hyperlink` points at a relationship in the
  document it was written into.
- **List numbering.** A list paragraph points at a numbering definition, and the
  template has its own `word/numbering.xml`.

## Configuration

`Configuration` is immutable; every `with*` method returns a new instance.

```php
Configuration::create()
    ->withStyles([Styles::CODE_FONT => ['name' => 'Fira Code', 'size' => 10]])
    ->withOptions(['images' => Options::IMAGE_PLACEHOLDER]);
```

It can equally be built from a plain array, which keeps the look of your
documents in a config file:

```php
Configuration::fromArray(require 'config/markdown.php');
```

Whatever a configuration holds can be read back as the array it came from, which
is what to write into a config file, to log, or to compare:

```php
$config->toArray();   // ['styles' => [...], 'options' => [...], 'textStyles' => [...]]
```

`Configuration\Styles` and `Configuration\Options` have a `toArray()` of their own,
and so does `Reverse\Options` — which is how the reader's options are spelled as
an array in the first place.

**A configuration holds three things.** The slots and the options are the first
two, and the named text styles are the third: `withTextStyles()` adds them and
`getTextStyles()` reads them back. A [theme](#themes) brings them, and so does a
caller that would rather name them in code:

```php
$config = Configuration::create()->withTextStyles(['brand' => ['color' => '0B5CAB']]);

$config->getTextStyles()->names();   // ['brand']
```

### The built-in look

Every slot has a formatting before anybody configures it, and it is written onto
the document rather than pointed at a style in Word's catalogue. That is the
whole reason a `.docx`, an `.odt` and an `.rtf` of the same Markdown look the
same: the ODF and RTF writers resolve a named style against a stylesheet of their
own, and there is no `Heading1` in either.

| Slot | |
| --- | --- |
| `heading.1` … `heading.6` | bold; 16 / 13 / 12 / 11 / 11 / 11pt; `#2F5496`, `#1F3763`; italic on 4 and 6; air above and below; kept with the next paragraph |
| `paragraph` | 6pt after; 1.15 lines |
| `blockQuote` | italic, `#404040`; half an inch in from both sides; 6pt above and below |
| `codeBlock` | indented a quarter inch; no space above or below |
| `listParagraph` | 3pt after |
| `codeFont` | Consolas 9pt `#A31515` |
| `linkFont` | `#0563C1`, underlined |

It lives in `Configuration\LookAndFeel` and is reachable as `Styles::defaults()`.
Any of it is overridden by writing the slot, in code or in a `styles:` block:

```yaml
styles:
  heading.1:
    size: 24
    color: 8B0000
    space:
      before: 0
      after: 480
```

A slot given as a mapping is **merged** into the built-in one rather than
replacing it, at every level: the two properties above change the size and the
colour and leave the weight and the space-after alone, and `space: {before: 0}`
moves the gap without deleting the one at the other end. This is what makes the
example above mean what it looks like. A heading that lost the Word style name
underneath it would stop being a Word heading — it would come out of the document
as large body text, absent from the navigation pane and from any table of
contents — and that is too much to lose to a colour.

Two things still replace a slot whole, because each is a statement about the slot
rather than about one of its properties:

| Written | Means |
| --- | --- |
| a style id — `heading.1: Title` | use that style; nothing of the built-in look is layered over it |
| `null` — `blockQuote: null` | no styling at all |

`Configuration::withoutDecoration()` is the named way to ask for the second over a
set of slots at once, and `withBuiltInHeadingStyles()` for the first.

A heading also keeps the Word style name underneath it — `Heading1` through
`Heading6`, `IntenseQuote` for the quote — and the definition of each is written
into the document, so a `.docx` heading is a real `Heading 1` and a template's
own style of that name has something to be resolved against. That is why a slot
carries `styleName` as well as its properties.

That name is the conventional hook and nothing more. PHPWord writes a style's
`w:name` from the same string as its `w:styleId`, and `Heading1` is not the
canonical `heading 1`, so a reader that maps Word's built-in styles by name treats
it as a style of its own — LibreOffice does, and gives the paragraph no outline
level at all when it reads one back. PHPWord's paragraph writer only emits
`w:outlineLvl` for a numbered paragraph, so there is no setting here that
changes that, and nothing in this library claims otherwise.

Two rules follow from it, and they are the two halves of every slot:

- **A slot configured with a style *name* is used verbatim.** That is a template
  saying what its own `Heading1` looks like, and nothing of the built-in look is
  layered over it.
- **A slot left at its default is written as direct formatting.** That is what
  makes the three formats agree.

`withBuiltInHeadingStyles()` is the way back to the first rule for everything at
once: it hands every heading to `Heading1`…`Heading6`, the quote to `Quote` and
the lists to `ListBullet` and `ListNumber`, and clears the body, list and code
block spacing, so Word's own styles decide what the document looks like.

```php
$config = Configuration::create()->withBuiltInHeadingStyles();
```

The two formats that cannot resolve a style name will then bring out the
paragraph as body text, which is what a Word built-in style looks like when
nothing defines it — and `pendingLosses()` says so.

### Frontmatter

A document can carry its own configuration in the YAML block at the top of the
file, which is then used to render it:

```markdown
---
template_file: report.dotx
options:
  maxHeadingLevel: 3
  tableBorders: false
styles:
  heading.1: Title
---

# Quarterly
```

The block never reaches the document — it is configuration, not content — and
`options:` and `styles:` mean exactly what they mean in a config file. `template_file`
names a template and is read separately, because it means nothing to a Word conversion
and putting it in `Options` would add a key no renderer looks at. `theme:` is read too,
by the [theme resolver](#themes) rather than by the validator.

`template_file` is a path, so it is held to the same rule as a `theme`: resolved with
`realpath()` and landed inside the working directory or the directory the Markdown was
read from. A document is not trusted to mean the directory it names — `../` walks out
of it, and an absolute path is read from wherever it points — and one written by a
model, from a file it was given, is enough to be careful here. The boundary is *where
a path lands*, not where it was written from: a document in `docs/` naming a template
one directory up is inside the working directory and is a template, not an escape.

Reading and resolving the key is all this library does with it; deciding that a
document naming a template is rendered into one, and what then outranks what, is
`mdoffice`'s, and is [documented there][command-line].

Read the block off a parsed document and merge it with whatever else is in play:

```php
use MarkdownWord\Document\ConfigurationMerger;
use MarkdownWord\Document\Frontmatter;

$document = CommonMarkParser::withAllExtensions()->parse($markdown);

$configuration = ConfigurationMerger::resolve(
    commandLine: ['options' => ['maxHeadingLevel' => 2]],
    frontmatter: Frontmatter::fromDocument($document),
    configFile: $configFile,
);
```

Four sources, and each one outranks the one below it:

| Source | Wins over |
| --- | --- |
| the command line | the frontmatter |
| the frontmatter | the config file |
| the config file | the defaults |

The file is what the author wrote; the command line is them saying it louder. A
source that says nothing is skipped rather than read as "reset everything", so a
command line with no option flags leaves the frontmatter in charge instead of
clearing it.

Merging preserves what a source did not mention. That matters because
`Configuration::fromArray()` and `Configuration::withAll()` read a `null`
differently on purpose — the first means "not configured, the default wins", the
second "not mentioned, the current value stands" — and merging is the second kind.

> **Pass an array, not a `Configuration`,** for the command line layer.
> `MarkdownToWord`'s fourth constructor argument takes either, and `MarkdownTemplate`
> passes its own on. An array is sparse — a key nobody mentioned leaves the
> frontmatter standing — where a `Configuration` names every setting there is, so
> handed to the merger it puts the defaults above the frontmatter along with
> everything else.

**The default parser does not read the block.** The default flavour is
GitHub-Flavored Markdown plus this project's own directives, and neither knows
what front matter is, so a leading `---` is a thematic break and the rest of the
block is printed at the top of the document. Ask for
`CommonMarkParser::withAllExtensions()`, which carries `FrontMatterExtension`, when
a document is to configure itself.

**A document that configures itself and writes a directive needs both.** The two
parsers are apart by design — `withAllExtensions()` carries the front matter and not
the directives, and the default flavour the other way round — so a document that
names a theme in its own block and writes a word in one of the theme's styles wants
`CommonMarkParser::withFrontMatter()`, which has both. The command line reads with
that parser already.

`Frontmatter` also reads the block for its own sake, if a caller wants it rather
than the configuration: `getString()`, `getInt()`, `getBool()`, `getArray()`,
`has()` and `toArray()`. A key of the wrong type returns the caller's default
rather than being coerced, so `options: 3` cannot become a set of options.

> Reading the block needs [`symfony/yaml`](https://symfony.com/doc/current/components/yaml.html),
> which is a hard requirement. It used to be optional, which meant
> `CommonMarkParser::withAllExtensions()` threw `MissingDependencyException` on any
> document with frontmatter — including every document anyone would use it for.

#### A key that names nothing, or a value that is not what it is used as

Four places take a configuration key and quietly discard one they do not recognise:
an unknown option, an unknown style slot, an unknown font property and an unknown
paragraph property. Each leaves a document that renders cleanly and is configured as
though the key had never been written — nothing looks broken, and the setting is
simply absent. So a key in frontmatter is checked, and every key that fails is
reported at once, with the alternatives and the nearest match:

```
Line 9: Unknown style "blockquote". Did you mean "blockQuote"? Known styles:
blockQuote, bulletList, codeBlock, codeFont, heading.1, heading.2, ...

Line 4: Unknown option "maxheadinglevel". Did you mean "maxHeadingLevel"? Known
options: codeBlockShading, deferredHyperlinks, hardBreak, html, imageBasePath, ...

Line 6: Unknown "colour" property of the "heading.1" style. Did you mean "color"?
Known style properties: alignment, bold, color, indentation, italic, keepNext, ...
```

A value is the same class of problem and was the quieter half, because a wrong value
still renders. `maxHeadingLevel: deep` is cast to `0`, the clamp turns `0` into `1`,
and the document comes out with **every heading in it** rendered as body text.
`color: "#8B0000"` reaches `w:color` with a `#` in it, which is not a colour there,
and Word ignores it. A `space` with a word in it keeps the number beside it and drops
the word, so the paragraph ends up with half the spacing that was asked for and
nothing to say which half went missing. So the value is checked too, and the message
carries the value, the line it is on, and what would have worked:

```
Line 3: The "maxHeadingLevel" option is "deep". It is a whole number between 1 and 6.

Line 7: The "color" property of the "heading.1" style is "#8B0000". It is six
hexadecimal digits, as in 8B0000, with no leading # and no colour name.

Line 9: The "images" option is "maybe". It is one of: embed, placeholder, skip.
```

Every problem in a block is reported together, keys and values alike, and each of them
names where it is. A block that is not a mapping at all is reported too:

```
Line 2: The frontmatter block is "hello", which is not a mapping of keys. It has to
be key: value pairs, as `options:` and `styles:` are.

Line 4: The "options" key of the frontmatter block is 3, which is not a mapping of
keys. Each option or style goes on its own line, indented beneath it.
```

The line comes from the source text of the block, which the renderer has while it is
converting. `Frontmatter::lineOf('styles/heading.1/color')` asks for one directly, and
returns null when there is no source to ask — a document parsed elsewhere and handed
to `configurationFor()`, where the message still stands without a line.

`Exception\InvalidConfiguration` is the one type to catch: `UnknownConfigurationKey`
for the key half, which is what callers have been catching since keys were checked,
and `InvalidConfigurationValue` for the rest. Both extend it, and both extend
`Exception\InvalidInput`.

Only the `options:` and `styles:` sub-keys are checked. The rest of the block is
metadata — `title`, `author`, `template_file` — which is open-ended by
nature and would fail every deck that carries one. `theme:` is read too, but
by the theme resolver rather than by the validator.

The check is *not* in `Options::fromArray()` or `Styles::withAll()`, which keep
discarding what they do not know and casting what they are handed: a config file has
been allowed to carry entries for something else, and loose values, for a long time,
and changing that would break callers. For a configuration built in code, ask
explicitly:

```php
use MarkdownWord\Configuration\Validator;

Validator::problems($config->toArray());   // a list of messages
Validator::assertValid($config->toArray()); // throws, listing all of them
```

### Themes

A theme is a `theme.yml` and, usually, a `styles.yml` beside it. Four ship with the
library, in `themes/`, each in a directory of its own:

| Theme | What it is for |
| --- | --- |
| `default` | the built-in look, written out; naming it changes nothing |
| `minimal` | monochrome and quiet — headings in the ink, code on the page |
| `corporate` | serif headings in navy, wide spacing, ruled tables |
| `report` | serif body on an open leading, for something meant to be read to the end |

A document names one for itself, so it travels on its own:

```yaml
---
theme: report
---
```

Or in code:

```php
(new MarkdownToWord($markdown))->withTheme('corporate');
```

**A theme is a starting point, not a decision.** Everything a person wrote for one
document outranks it — a `styles:` block, a `-c` config file, a flag — and naming one
slot on top of a theme is the normal case rather than a conflict:

```yaml
---
theme: report
styles:
  heading.1:
    color: 8B0000   # the report's heading, in dark red
---
```

A name is resolved in `./themes/<name>` beside the document first, then in
`./themes/<name>` beside the working directory, then among the themes that ship with
the package, and anything with a `/` in it is read as a path instead — written from
beside the document, the way a relative image path is. A name that does not resolve is
an error naming the ones that do — never a silent fallback to `default`, which would be
a document in the wrong colours with nothing in the output to say so. A path is resolved
with `realpath()` and held to the document's own directory, the working directory and
the built-in themes, because `theme:` is a path a document writes.

**A path may name the manifest rather than the directory around it.** A theme is its
`theme.yml`, so `theme: ./themes/house/theme.yml` is the same theme as
`theme: ./themes/house` — the file is the manifest, and the `styles.yml` is read from
the directory that holds it either way. A path is looked for in the same place and held
to the same scope whether it names a directory or a file; only what is read from it
differs. Only a `theme.yml` is a manifest, so a `styles.yml` named as the theme is one
of a theme's own files rather than the theme itself.

```yaml
---
theme: ./themes/house/theme.yml
---
```

**A theme is found beside the document.** The library is told where the document is,
because a document handed over as a string has no directory of its own:

```php
(new MarkdownToWord($markdown, directory: '/srv/project'))->save('report.docx');
```

A theme beside the document is the one that travels with it, so it is looked for
before the working directory's own `themes/`. Without the directory, the working
directory is what a name is looked for in.

`styles.yml` takes the slot names from `Styles::defaults()` and nothing else, and is
checked the way a `styles:` block is: an unknown slot, an unknown property inside one, a
colour written with a leading `#` and a size holding a word are all errors at load time,
next to the file that has them. A slot written as a mapping merges over the built-in look
exactly as a `styles:` block does, so a theme that names one property leaves the rest
alone — including properties it would rather drop, which it cannot: a slot merges, so
removing something the built-in look set means naming a style id or `null` for the whole
slot.

**Quote a colour that YAML would read as something else.** `2E5395` is a valid hex colour
and also scientific notation, and YAML resolves it to infinity before the theme is read.
Quoted, it is a colour; unquoted, it is a load-time error rather than a document drawn in
the wrong ink.

**A theme may bring named text styles.** `styles.yml` takes one key beside the slots, and
the key is not a slot — it is the styles a document may write a word in, under
[Inline styles](#inline-styles):

```yaml
textStyles:
  brand:
    color: "0B5CAB"
    bold: true
  muted:
    color: "6B7280"
    size: 10
```

The properties are the same eight the inline attributes have, and a size is in points as it
is everywhere else. These are refused when the theme is read, because each of them
is a mistake that would otherwise reach the document looking as though the line had never
been written: a style that is not a set of properties at all, a style that carries no
property, a style whose properties name an attribute that does not exist, a value that is
not one — a colour, a size, a weight or a face, refused the same way — a style called
`style`, that word being reserved for the raw form, a style named after a directive this
library draws — `footnote` above all, because the footnote is drawn before the style is
and the style would never reach a word — and a theme that names one style twice, in two
spellings. A theme without the key has no named styles, and every named form written
against it is drawn as its own source text.

A configuration carries the named styles as well, and the same names are refused in it:
`withTextStyles()` takes them, and one called `footnote` is refused the way a theme's
is.

**A style's name is a word, and a word does not care about case.** A theme that spells a
style `Brand` has it under `:brand[…]` and `:Brand[…]` alike, the way the directives
package folds the names of the directives themselves. The names this library publishes
spell the styles the way the theme wrote them, because that is the spelling the author
has to change.

A theme applies to a document rendered into a template too. The template keeps its own
style definitions, and the theme is written onto the text as direct formatting over them
— so a theme reaches the document there as it does anywhere else.

`bulletList` and `orderedList` name a numbering style, and what that name buys you depends
on the format. In an `.odt` it becomes the `text:list-style` the document's lists refer to,
so the name is there and readable in the file. In a `.docx` it is not written at all:
PHPWord's Word writer emits `w:abstractNum` and `w:abstractNumId` and never a `w:name`,
and the numbering is reached by id instead. Either way the name is a label — bullet
characters are not configurable through these slots, and the default set is a filled
bullet, `o`, and a small filled square, repeated by nesting depth.

#### Images

An image is resolved against `imageBasePath`, embedded, and scaled to
`imageMaxWidth` when it is wider than that — the option is a maximum, so a small
picture is not enlarged to reach it. `0` disables the scaling. A picture that
names a size of its own — [`::image`](#objects-in-a-document) — keeps it.

**A picture is measured in pixels, and the file says so.** PHPWord measures an image
in pixels and its RTF and ODF writers read the number back as pixels; only its Word
writer labels the number `pt`, which is how a picture of 96 pixels came to be laid
out 96 points wide — a third too large, since a point is a 72nd of an inch and a
pixel a 96th. The unit is therefore stated on the picture rather than left to
PHPWord's default:

```
<v:shape type="#_x0000_t75" stroked="f" style="width:96px; height:48px; …">
```

The same picture is 72 points wide in the `.rtf` and 2.54 cm wide in the `.odt`,
which is the same size in each format's own unit.

**A format Word cannot embed is converted, and never quietly.** Word has no support
for WebP and PHPWord has never added any, so a `.webp` is decoded with GD and written
into the document as PNG. Failing on a format every browser has supported for years
seemed worse than a bigger file; but a PNG of a photograph is several times the size
of the WebP it came from and the picture is a re-encoding rather than the original
bytes, so it is reported rather than swallowed:

```php
$converter = new MarkdownToWord($markdown, $config);
$converter->save($path);
$converter->pendingImageConversions();
// [['source' => '…/hero.webp', 'format' => 'image/webp', 'embeddedAs' => 'PNG']]
```

**An SVG is embedded as a vector, not flattened into a picture.** Word has held SVG
since 2016 and holds it the only way it ever can: a raster beside the vector, with the
vector referenced from an extension on the same picture element.

```
<a:blip r:embed="rId7">
  <a:extLst>
    <a:ext uri="{96DAC541-7B7A-43D3-8B79-37D633B846F1}">
      <asvg:svgBlip r:embed="rId8"/>
    </a:ext>
  </a:extLst>
</a:blip>
```

`rId7` is the PNG, `rId8` is the SVG, and both are in `word/media/`. A 2016-or-later
Word scales the vector like one; an older one draws the raster.

The raster is there for the readers that cannot take the vector — **Google Docs among
them**, which draws a picture carrying only the `svgBlip` as nothing at all. It is not
optional in the sense of "used only if nothing better is available": every reader draws
the `a:blip` unless it knows to prefer the vector, so a picture carrying the extension
with no raster behind it renders nothing in all of them.

Which of those two outcomes a document gets is `svgFallback`:

| `svgFallback` | Written as `.docx` | Needs `ext-imagick` | Drawn by |
| --- | --- | --- | --- |
| `raster` *(default)* | raster beside the vector | yes | every reader |
| `none` | the vector alone | no | Word 2016+, recent LibreOffice |

`r:embed` is optional on `a:blip`, so `none` is a shape Word opens: the blip carries
the extension and nothing else. It is the smaller document and the one that puts no
rasteriser on the default path, and it costs the readers in the second column their
pictures. Choose it when you know what will open the file.

**The option is about `.docx`, because only `.docx` keeps the vector.** A raster is what
an `.odt` or an `.rtf` has to draw the picture from, so those two always want one whatever
this is set to, and where no usable rasteriser is installed they refuse an SVG rather than
write a picture that is one transparent pixel.

`raster` needs `ext-imagick`, which is **optional**. Where there is no usable
rasteriser, the vector is written on its own rather than the conversion refused — a
document whose readers may miss one picture beats no document — and the run says so
through `pendingLosses()`:

```
a reader that does not prefer the vector draws nothing where an SVG was
```

The capability is tested by drawing a letter rather than by asking ImageMagick whether
it has an SVG delegate, because a build without one still registers the format: it
answers yes, then hands the file to its own renderer and draws the shapes without the
words. That is the one outcome worse than either alternative, because it does not fail.
A build that cannot draw text gets the vector alone rather than a picture missing it.

Two things still raise, because both are about the file rather than the host. A file
that holds no SVG whatever it is called, and one that states no `width`, `height` or
`viewBox` to place it at:

```
Cannot embed "assets/diagram.svg": Word can hold an SVG, but only beside a raster of
it, so an SVG needs rasterising before it can go in — and it could not be embedded
because it states no width, height or viewBox, so there is nothing to place it at.
```

Two things are worth knowing about the mechanics. PHPWord writes images as VML, which
has nowhere to hang an extension on, so a picture with a vector is rewritten as
DrawingML — the form Word writes today — while every other image keeps PHPWord's
markup untouched. And the picture is found by its position among the document's
images, the same way its alt text is, rather than by the bytes of the raster beside it:
PHPWord names media parts as it writes them, so a part name says nothing about which
picture became it. The size comes from the SVG's own dimensions rather than the
raster's, because the raster is the SVG at 96dpi and its pixel count is a third larger
than the width the drawing asked for: a 480-unit diagram is 360 points, which is 480
pixels, and measured from the file it would be laid out 480 pixels wide.

**A file that is there and cannot be used at all is refused.** A truncated download, a
format this build has no decoder for: `Exception\UnsupportedImageFormat`, naming the
file, what it is, and what can be done about it. The alternative is a document that
looks finished with the picture missing and its alt text where the picture was.
`images: placeholder` and `images: skip` never attempt an embed, so they never meet
one.

**A file that is not there, and a URL that is remote, still fall back to the alt
text.** Nothing is wrong with the document in those cases: the library has no HTTP
client and will not invent a download.

### Where a document's files come from

The theme a document names is looked for on disk — beside the document, beside the
working directory, and among the themes that ship with the package — and its pictures
and the template it names are read from disk as well. **The theme's files come from
somewhere the caller chooses**, so a program that keeps its own files somewhere else
— in a database, say — says where they are with a **finder** and hands it over
wherever a document's files are wanted:

```php
use MarkdownWord\MarkdownToWord;
use MarkdownWord\Template\MarkdownTemplate;

$finder = new MyFinder();   // a MarkdownWord\Files\FileFinder

(new MarkdownToWord($markdown, files: $finder))->save('report.docx');

(new MarkdownTemplate('report.dotx', files: $finder))
    ->insert($markdown)
    ->save('report.docx');
```

A finder is four methods: `read()` and `path()` answer for one file, `themes()` lists
the themes that resolve and `files()` the files of one theme. The names it is asked
for are the library's own — `house/theme.yml` for a theme's manifest, `logo.png` for a
picture — and the role says which world a name is resolved in: `theme`, `picture` or
`document`.

**A finder answers; it does not refuse.** A file that is not there is `null`, and a
file that is out of reach is answered like any other: the question of what a render
may read belongs to the library, which holds the path a finder answered to its own
scope and refuses what is out of reach in its own words.

`Files\DiskFinder` answers out of directories on this machine, one list of bases per
role, tried in the order they are given. A program that brings no finder of its own
gets one for its **themes** — the directories of themes beside the document, beside
the working directory and the ones that ship with the package. A picture falls back
to `imageBasePath` or the working directory, and a template to the document's own
directory and the roots the caller allowed. The directories of themes on disk remain
the fallback, so a program with a finder of its own does not lose the themes that
ship with the package.

### Styles

Each slot holds a styleId, an inline style array, or `null`. The default is the
[built-in look](#the-built-in-look): an array, with the styleId named inside it.

| Slot | Default | Controls |
| --- | --- | --- |
| `heading.1` … `heading.6` | blue bold 16…11pt, `styleName: Heading1`… | headings |
| `paragraph` | 6pt after, 1.15 lines | body text |
| `blockQuote` | italic `#404040`, indented, `styleName: IntenseQuote` | `>` blocks |
| `codeBlock` | indented, no space above or below | fenced code paragraphs |
| `thematicBreak` | `null`, which draws a rule | `---` |
| `listParagraph` | 3pt after | list item paragraphs |
| `htmlFallback` | `null` | raw HTML |
| `codeFont` | Consolas 9pt, dark red | `` `code` `` |
| `linkFont` | blue, underlined | hyperlink text |
| `bulletList` | `MarkdownWord-Bullet` | bullet numbering style name |
| `orderedList` | `MarkdownWord-Ordered` | ordered numbering style name |
| `table` | `null` | table style name, or an array of table properties |
| `tableHeaderRow` | `null` | header row properties |
| `tableCell` | `null` | cell properties, and the font inside them |

> **The three table slots are not font and paragraph slots.** A heading's array is
> split between a `Font` and a `Paragraph`; `table` is a `Table`, `tableCell` a
> `Cell` and `tableHeaderRow` a `Row`, and each has names the others do not have.
> `borderColor` and `cellMargin` are table properties; `vAlign` is a cell one; a
> row has three properties and no appearance at all, so `tableHeaderRow` can repeat
> the header or stop it splitting across a page but cannot shade it.

> **StyleIds, not display names.** Word looks a style up by its *identifier*, so
> the built-in headings are `Heading1`, not `Heading 1`, and `IntenseQuote`, not
> `Intense Quote`. Use the identifier your template defines.

A `Styles` object is the table above with your changes in it, and can be used on
its own — through `Configuration::withStyles()`, or wherever the renderer asks a
configuration for a slot:

```php
use MarkdownWord\Configuration\Styles;

$styles = new Styles();                       // every slot at its default
$styles = $styles->with(Styles::HEADING_1, 'CorpTitle');   // a new instance
$styles->get(Styles::HEADING_1);              // 'CorpTitle', the original untouched
$styles->heading(2);                          // the style for a heading level
Styles::defaults();                           // the default table as an array
$styles->toArray();                           // the whole table as an array
```

`heading()` clamps: a level past the sixth answers with the sixth, and a level
below the first with the first, since there is no other heading style to give.

### Options

| Option | Default | Values |
| --- | --- | --- |
| `softBreak` | `space` | `space`, `lineBreak`, `paragraph` |
| `hardBreak` | `line` | `line`, `paragraph`, `remove` |
| `html` | `strip` | how raw HTML is handled: `strip` rebuilds it as Word, `preserve` shows it as monospaced text, `drop` discards it |
| `images` | `embed` | `embed`, `placeholder`, `skip` |
| `imageBasePath` | `null` | directory relative image paths resolve against |
| `imageMaxWidth` | `15.0` | centimetres, the width a picture that names no size of its own is scaled to; `0` disables scaling |
| `maxHeadingLevel` | `6` | deeper headings become paragraphs |
| `orderedListFormat` | `decimal` | any OOXML `w:numFmt`, e.g. `lowerRoman` |
| `orderedListSuffix` | `tab` | `tab`, `space`, `nothing` |
| `tableBorders` | `true` | draw cell borders |
| `tableHeaderBold` | `true` | bold the header row |
| `tableWidth` | `5000` | fiftieths of a percent of the text column; `0` leaves sizing to Word |
| `codeBlockShading` | `true` | shade code blocks |
| `linkTarget` | `_blank` | `_blank` or `_self` |
| `thematicBreak` | `border` | `border` or `text` |
| `deferredHyperlinks` | `false` | resolve links while writing the file |

## Advanced use

Keep the `PhpWord` document and add your own content — a cover page, a
`TOC`, metadata:

```php
$phpWord = new PhpWord();
$converter = new MarkdownToWord(null, $config);

$markdown = "# Chapter one\n\n…";

$section = $phpWord->addSection();
$section->addTitle('Annual Report', 1);

$phpWord->getDocInfo()->setTitle('Annual Report');

// toDocx() takes that same document, renders the Markdown into the section that is
// already there, and hands back the finished bytes.
file_put_contents('report.docx', $converter->toDocx($markdown, $phpWord));
```

`toDocx()` renders the Markdown it is given into the document it is handed, so
that document already has your own content in it. `renderIntoContainer()` is the
same render into a container you name — a table cell, a header, a footer — for
when you are writing the document out yourself:

```php
$converter->renderIntoContainer($markdown, $section, $phpWord);
```

Recover the plain text of a rendered document, for indexing or an accessibility
fallback:

```php
use MarkdownWord\Text\TextExtractor;

TextExtractor::fromPhpWord($phpWord);
```

A form field contributes the default it was given — the text the author wrote for
it — and a field with no default contributes nothing. Its name is the form's
identifier rather than the document's wording, so it is not in the text.

Use a different Markdown dialect:

```php
use MarkdownWord\Parser\CommonMarkParser;

new MarkdownToWord(null, $config, CommonMarkParser::commonMarkOnly());   // no directives
new MarkdownToWord(null, $config, CommonMarkParser::extended());      // + footnotes
new MarkdownToWord(null, $config, CommonMarkParser::directives());    // the default
new MarkdownToWord(null, $config, CommonMarkParser::withAllExtensions());
```

Or implement `MarkdownParserInterface` for anything else.

`parse()` is the parser on its own, for a caller that wants the syntax tree rather
than a document — a linter, a table of contents, a search index:

```php
$tree = (new MarkdownToWord())->parse("# Hello\n\nBody.");   // a CommonMark Document
```

`pendingHyperlinks()` is the other half of the way into Word. A link whose label
carries emphasis cannot be expressed by PHPWord's own element, so the renderer
leaves a placeholder behind and rewrites the file while writing it; this is what
is waiting to be rewritten, and the text of a document is only complete once it
has been handed over:

```php
$converter = new MarkdownToWord();

$phpWord = $converter->toPhpWord('A [**bold** link](https://example.com).');

TextExtractor::fromPhpWord($phpWord, TextExtractor::LINE_BREAK, $converter->pendingHyperlinks());

$converter->pendingHyperlinks();
// [['placeholder' => '⁣MDWL⁣0⁣MDWL⁣', 'url' => 'https://example.com', 'title' => null, 'runs' => [...]]]
```

The `placeholder` is the token the writer looks for, and it is
`LinkPlaceholder::MARKER` on *both* sides of the index — the string above is
`"\u{2063}MDWL\u{2063}" . '0' . "\u{2063}MDWL\u{2063}"`, which is why the
invisible separator appears twice. It is there so that the token can never
collide with anything an author typed, and so that a run carrying it renders as
nothing at all if the writer pass never runs.

## Requirements

PHP 8.2+ with `ext-zip`, `ext-dom`, `ext-mbstring` and `ext-gd`.

`ext-imagick` is optional and enables [SVG](#images). Word holds an SVG only beside a
raster of it, so something has to draw the raster; without the extension an SVG raises
rather than going in silently flattened.

`symfony/yaml` is a requirement rather than a suggestion. It is what reads the
[frontmatter](#frontmatter) block, and a document whose block nobody reads is a
document that ignores its own configuration.

Working on the library needs PHP 8.4+, because Pest 5 does. That is a floor for
the *test suite* only: the published package installs on 8.2, and the library is
checked on 8.2 by the `minimum` job, which runs `smoke.php` and `stress.php`
there.

## Notes

- **Output escaping is turned on while writing**, and the previous setting is
  restored afterwards. PHPWord has escaping off by default, which is correct for
  content that is already escaped and wrong for everything else: a document
  containing a lone `<` or `&` — `a < b`, `AT&T` — otherwise gets raw markup in
  its XML and Word refuses to open it.
- **Tables span the text column, and so do their columns.** Given no width, PHPWord
  writes no `w:tblW` at all — its writer emits the element only when a width has
  been set — and a table with no width is one every viewer shrinks to its
  narrowest content. This library writes `<w:tblW w:w="5000" w:type="pct"/>` instead,
  as a percentage of the column, so it follows the page size and the margins.
  `tableWidth` changes it, and `0` hands the sizing back to Word.

  The columns inside it are measured too, because a Markdown table has no widths
  to carry and Word does not guess well without help: given an empty
  `<w:gridCol/>` and cells with no `<w:tcW>` it narrows every column to one or
  two characters. Each column is given a width in twips summing to exactly the
  text column, measured from the page the table lands on — its size, orientation,
  margins and column count. The width is shared out in proportion to the widest
  cell in each column, with two limits that matter more than the proportion: a
  column is never left below half an inch, and a column is widened no further
  than about forty characters' worth, so one cell holding a paragraph cannot take
  the table away from its neighbours.

  A `tableCell` style naming its own `unit` keeps its own widths and gets none of
  these — `w:tcW` in twips labelled as a percentage is not a narrower table but an
  unreadable one, and a caller who has measured itself is not overridden.
- **Cells let their text wrap.** PHPWord's cell style defaults `noWrap` to true, so
  a cell that says nothing about wrapping is written as `<w:noWrap/>` — Word's
  "Wrap text" option, with the box ticked off. Word honours it: the cell is laid
  out on one line and the column widened to suit, so a table holding a sentence
  runs off the page with nowhere for the line to break. LibreOffice reads it as a
  hint it may ignore, so the same file looks right there and wrong in Word.

  Every cell is therefore rendered with `['noWrap' => false]`, and a `tableCell`
  style of `['noWrap' => true]` gets the old behaviour back. It is a cell style
  and not an `Options` entry because `noWrap` is a cell property, and the
  `tableCell` slot already takes a PHPWord cell style array — a new option would
  have been a second way to say one thing.

  This was worth fixing on its own account, and it also exposed the missing
  column widths above: `noWrap` had been making Word size columns to their
  content, which papered over their being absent rather than filling them in.
- **PHPWord 1.4 emits a deprecation on PHP 8.1+** (`Using null as an array
  offset`). It comes from `PhpWord\Style::getStyle()` being called with a null
  name while writing a paragraph that carries no numbering of its own, which
  every list item does. The output is correct and nothing reachable from this
  library's API can avoid it, so the library does nothing about it: a program that
  embeds this package sees it, as it should. The command line silences that one
  message from that one file rather than switching deprecation reporting off, and
  so does this repository's test suite.
- **Hyperlinks around images** are rendered as the image without the link.
  PHPWord's `Link` element holds only a string, so there is nowhere to put it.
- **An image in a format Word cannot embed is re-encoded as PNG.** PHPWord takes
  JPEG, GIF, PNG, BMP and TIFF and refuses the rest, so a `.webp` is decoded with GD
  on the way in — losslessly for the pixels, and several times larger for the file.
  It is never done quietly: `pendingImageConversions()` has it afterwards.
- **Image alt text and link titles are written by a pass over the finished
  file.** PHPWord has nowhere to put either through its API — it emits
  `o:title` as a literal empty string — so they are filled in while the archive
  is written. That is the same reason links whose label carries emphasis are
  resolved then rather than by PHPWord's own `Link` element.
- **A picture that carries an SVG is rewritten from VML to DrawingML.** PHPWord
  writes images as `w:pict`/`v:imagedata`, and VML has no extension list, so
  `svgBlip` would have nowhere to go. Only pictures that have a vector are
  touched; every other image keeps the markup PHPWord wrote. The description
  crosses with it, from `o:title` to `wp:docPr/@descr`, because moving between
  the two without carrying it would delete the alt text.
- **A raster beside an SVG needs `ext-imagick`, which is optional.** Without a
  usable one the vector is written alone and `pendingLosses()` says what that
  costs, rather than the conversion being refused over a picture.
- **A document in memory is written to the system temp directory to be read
  back**, because `ext-zip` only opens files. It is removed once the archive is
  closed. The library does not write anywhere near its own install directory, so
  nothing appears in your `vendor/`.
- **Raw HTML never becomes markup in the document.** This is worth stating
  precisely, because the default does more than remove tags: a fragment of HTML
  is parsed and *rebuilt* as Word content. `<b>`, `<i>`, `<s>` and `<code>`
  become the corresponding formatting, `<br>` becomes a line break, comments are
  dropped, and `<img>`, `<hr>` and friends contribute nothing. The words they
  wrapped are kept — which is what makes a document with embedded HTML compare
  equal to the same document rendered as HTML, and is why `strip` is the default
  rather than `drop`.

  What cannot happen is the HTML contributing structure of its own: no attribute,
  event handler, script URL or frame reaches the document, because the tags are
  never copied into the XML — only text is. An attribute carrying what would
  close an element and open a run has nothing to escape into, and an external
  entity is not resolved. A raw `<a href>` does not even become a live link,
  though a Markdown `[link](url)` does. `preserve` keeps the markup as literal
  monospaced text, and `drop` discards the fragment outright.

  One thing this cannot do: style text that sits *outside* the element. CommonMark
  makes `a <b>bold</b> b` five sibling nodes rather than a `b` wrapping a word, so
  the bold is not applied — the text survives unformatted. Write `**bold**` for
  text you want emphasised, and reserve raw HTML for content that brings its own
  markup.

## Tools

A document that has been built has not been looked at, and a document that has been looked at
in a renderer that disagrees with Word is a document that has not really been looked at.

| Tool | What it is |
| --- | --- |
| `tools/render-document.php` | Renders a document with the most truthful renderer the machine has — Microsoft Word where it can be used, WordCraft where Word cannot, LibreOffice last — and says so when it had to fall back, because a renderer that cannot be trusted is worse than none |
| `tools/render-with-word.applescript` | Opens a document in Microsoft Word on macOS and exports it as PDF. The export is written inside Word's own sandbox container, because Word writes nowhere else |
| `tools/render-with-word.ps1` | The same on Windows, through Word's COM automation rather than through AppleScript |
| `tools/package-as-document.sh` | Turns a Word template into a Word document, which is the one rewrite that makes a template a file Word will open |
| `tools/pdf-is-complete.sh` | Whether a PDF has been written whole, which is what the Word tool waits for before it copies one out. The one part of that tool the test suite can check, because it needs no Word to answer |

The choice between the three renderers is tested without any of them: the dispatcher takes the
platform and the renderer from the environment, so the test suite can ask what it would choose
on a machine that has none of them. The one test that needs Word is skipped where Word is not.

A **template** is rendered as well as a document. Word's AppleScript opens a package typed as a
template by opening nothing at all, and raises no error when it does so, so the template is
staged as a document first — which is the one rewrite that turns a template into a file Word
will open.

## Licence

MIT — see [LICENSE](LICENSE). PHPWord, which this library builds on, is
LGPL-3.0.

[commonmark]: https://github.com/thephpleague/commonmark
[command-line]: https://markdown-office.github.io/command-line/basic/
[issues]: https://github.com/markdown-office/markdown-word/issues
[composer-patches]: https://github.com/cweagans/composer-patches
[mdoffice]: https://github.com/markdown-office/mdoffice
[sonarcloud]: https://sonarcloud.io/summary/new_code?id=markdown-office_markdown-word
[skill]: https://markdown-office.github.io/skills/
[sonar-token]: https://sonarcloud.io/account/security
[pest]: https://pestphp.com
[phpword]: https://github.com/PHPOffice/PHPWord
[cm-spec]: https://spec.commonmark.org/0.31.2/
[gfm-spec]: https://github.github.com/gfm/
