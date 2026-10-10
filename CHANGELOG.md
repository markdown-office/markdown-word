# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **A built-in Look & Feel, written as direct formatting.** Every style slot now
  has a concrete formatting before anybody configures it, and it is written onto
  the runs and the paragraph rather than pointed at a style in Word's catalogue.
  `Configuration\LookAndFeel` holds the values and `Styles::defaults()` is them.

  **This changes what a default `.docx` looks like.** Before, a default
  conversion wrote `Heading1` … `Heading6` and `IntenseQuote` into
  `word/styles.xml` with the definitions this library chose, and the appearance
  came from those definitions. After, the same definitions are still written —
  `w:styleId="Heading1"` with its size, weight and colour — and the same
  properties are *also* written onto the text. Headings, quotes, code blocks and
  body spacing now come out of Word's own copy of the stylesheet, or out of a
  copy the reader does not have at all, identically. Paragraphs also carry a
  space after (6pt) and a 1.15 line height they did not carry before, and a code
  block is indented a quarter inch.

  The reason is that a named style is not a thing the other two writers can
  resolve. An `.odt` or an `.rtf` of a perfectly good Markdown file came out as an
  undifferentiated wall of body text: no heading hierarchy, no quote styling, no
  code shading. Both writers do carry direct formatting, and the renderer already
  had the machinery to write some — `InlineStyle`'s forced font. The default
  simply never used it.

  **What it does not change.** A slot configured with a style *name* is used
  verbatim: the Look & Feel is not layered over a template's own `Heading1`, and
  that rule is asserted rather than assumed. `Configuration::withBuiltInHeadingStyles()`
  is now what its name says — it hands every heading, the quote and the lists to
  Word's own styles and clears the body, list and code block spacing, so the
  pre-Look-&-Feel behaviour is one call away and documented.
  `Configuration::withoutDecoration()` — and the `--plain` flag that reads it —
  now clears the body and list spacing too, or "no decoration" would have meant
  something narrower than its name.

  **What it does not fix.** A `.docx` heading still carries `w:pStyle Heading1`
  and that is the conventional hook, but it is not an outline level: PHPWord
  writes a style's `w:name` from the same string as its `w:styleId`, and
  `Heading1` is not the canonical `heading 1`. LibreOffice reads the document as
  a style of its own with no `text:outline-level`, and PHPWord's paragraph
  writer only emits `w:outlineLvl` for a numbered paragraph, so there is no
  setting that would change it. This is unchanged from before.

  **What it closes and what it does not**, measured against rendered pages rather
  than against markup: headings, block quotes and paragraph spacing are now
  carried in all three formats; the `.rtf` keeps a run's colour when the same
  colour is already in a registered style, which is where the heading colour
  comes from, and its typeface is still dropped. Table borders, column
  alignment, header-row boldness in `.odt`, ordered numbering in `.odt`, lists
  and rule-under-a-break in both, and any paragraph background in both, are
  PHPWord writer limits this does not reach. The README's capability table says
  which is which.

- **The reader no longer mistakes a style's own weight for emphasis.**
  `Reverse\StyleTable` resolves a paragraph style's `w:rPr` as well as its
  indentation and alignment, and `DocumentReader` subtracts it from every run in
  the paragraph. Before, a heading written by this library came back as
  `# **Heading**` and a block quote as `> *quoted*`, because the runs repeat the
  formatting their style already implies. It also stopped resolving anything at
  all: `StyleTable` used `DOMElement` without a leading backslash in a namespaced
  file, so every `instanceof` in it was false and `indentOf()` and `alignmentOf()`
  always returned the default.

### Added

- **OpenDocument Text and Rich Text output.** `MarkdownToWord` grows `toOdt()`,
  `toRtf()` and a format-taking `to()` beside `toDocx()`, and `convertTo()` for
  the path-writing side the constructor's `source` already implies. `.docx` stays
  the default everywhere: `toDocx()` and `convert()` keep their signatures and
  their behaviour, and `toDocx()` is now the same call as `convert()` rather than
  a second way of doing it, so the two cannot answer differently. `Format` is the
  value type that names the three.
- **`mdword to-odt` and `mdword to-rtf`.** The same conversion in two more
  formats, with `.docx` the default of all three: a run with nothing else said
  still writes a `.docx` and still derives that name from the input. `ToDocx`
  gives up `final` and grows a `format()`, so the layering of configuration, the
  image base path, the overwrite guards and the reports on standard error are
  written once and cannot drift between the three. Before: `mdword to-docx` was
  the only way out. After: `mdword to-odt`, `mdword to-rtf` and `--to odt` /
  `--to rtf`, with `to-docx` and `--to docx` unchanged.
- **`--to` takes `odt` and `rtf`.** The check that a `--to` contradicts the file
  is by which way the run reads rather than by which command it ends up in,
  because the three word formats are three answers to one question. An unknown
  format now says `Use docx, odt, rtf or markdown.`
- **A link whose label contains emphasis is a real link in all three formats.**
  `OdfHyperlinkPass` and `RtfHyperlinkPass` are the ODF and RTF counterparts of
  `HyperlinkPass`, and they exist because the defect was not Word's alone:
  `Writer\ODText\Element\Link` and `Writer\RTF\Element\Link` each take a single
  plain string, so `[**Release** notes](url)` came out of both as the literal
  text `⁣MDWL⁣0⁣MDWL⁣`. After: a `text:a` with the runs inside it, and a
  `HYPERLINK` field with them.
- **A picture's alternative text reaches an `.odt`.** `OdfImageDescriptionPass`
  writes the `svg:desc` a `draw:frame` takes, where `Writer\ODText\Element\Image`
  writes none. Before: the picture was there and its alt text was not. After: both.
- **`Writer\Staging`, and the atomic-write contract for every format.** Staging
  the document in the temporary directory, patching it there and moving it into
  place in one step was `DocxWriter`'s, because that was the one place a document
  was written. Nothing about it is particular to a `.docx`, so it moved out. No
  behaviour of the `.docx` path changed.
- **`Loss` and `MarkdownToWord::pendingLosses()`.** What a writer cannot carry,
  filtered down to what this document actually used. `Format::drops()` is the
  list; `Writer\Survey` answers whether the document asked for any of it; and
  `pendingLosses()` hands a caller one sentence per loss, written for whoever
  opens the finished document rather than for whoever wrote the code. A `.docx`
  drops nothing and is what the other two are measured against, so a run into one
  never prints a line of this kind.
- **`Application::reportLosses()`**, which prints them on standard error where the
  rest of a run's progress goes, so a piped result stays clean.
- **`examples/markdown/20-formats.md`**, built into a `.docx`, an `.odt` and an
  `.rtf` for opening side by side, and `tests/Readme/examples-formats.php`, which
  asserts every row of the README's capability table against the files.

- **Frontmatter configures the conversion.** The YAML block at the top of a
  document is read as configuration rather than discarded. `options:` and
  `styles:` mean what they mean in a config file; `template_file` and
  `theme_file` are read as data, because neither means anything to a Word
  conversion and both would otherwise become option keys nothing looks at.
  `Document\Frontmatter` reads the block off a parsed document.
- **`Document\ConfigurationMerger`** resolves four sources — command line,
  frontmatter, config file, defaults — each outranking the one below it. A source
  that says nothing is skipped rather than read as "reset everything".
- **`Configuration::withAll()`** merges a batch over an existing configuration,
  reading a missing key as "not mentioned" the way `Options::withAll()` already
  did. Merging preserves what the batch did not mention; a batch naming only
  `options` leaves the styles alone, and the other way round.
- **Values are checked, not only keys.** `Configuration\Validator` reports a value
  that is not what it is used as, with the value itself, what would have worked,
  and the line it is on. `maxHeadingLevel: deep` is cast to 0, the clamp turns 0
  into 1, and the document came out with every heading in it rendered as body
  text; `color: "#8B0000"` reached `w:color` with a `#` in it, which is not a
  colour there, and Word ignored it; a quoted `false` for a boolean option became
  `true`.
- **Every problem in a block says where it is.** `Frontmatter::lineOf()` answers
  the file line for `options/images` or `styles/heading.1/color`, and every
  message is prefixed with it. Multi-problem reporting still holds: keys, values
  and a block that is not a mapping all arrive together.
- **`Exception\InvalidConfiguration`** is the one type to catch, with
  `Exception\UnknownConfigurationKey` for the key half — what callers have been
  catching since keys were checked — and `Exception\InvalidConfigurationValue`
  for the rest.
- **A `.webp` is embedded rather than dropped.** Word has no support for it and
  PHPWord has never added any, so it is decoded with GD and written into the
  document as PNG. `MarkdownToWord::pendingImageConversions()` reports each one
  and `mdword` prints a line on standard error, because the file that comes out
  is several times larger than the one that went in.
- **An SVG is embedded as a vector, not flattened into a picture.** Word has held
  SVG since 2016 and holds it the only way it ever can: a raster beside the vector,
  with the vector referenced from an extension on the same picture. A picture
  carrying that extension and no raster renders nothing at all, so an SVG is
  rasterised with `ext-imagick` and both parts go in. A 2016-or-later reader
  scales it like a vector; an older one draws the raster, which is why it is
  there. `ext-imagick` is optional and detected at runtime — without it an SVG
  raises and says so, rather than going in silently flattened.
- **`.svgz` is unpacked** rather than written into the archive still compressed,
  which would leave a part named `.svg` that no reader could take a vector from.
- **A file named `.svg` that holds no SVG is reported as an unreadable file**
  rather than as an unsupported image format. The two send a reader to opposite
  conclusions: one is a bad file, the other a claim about SVG that Word has not
  been true of since 2016.
- **`Exception\UnsupportedImageFormat`**, raised for a file that is on disk and
  in a format neither Word nor this build of GD can take: an SVG, a truncated
  download. The message names the file, the format and what Word does accept.
  `images: placeholder` and `images: skip` never attempt an embed, so they never
  meet one.
- **`Application::parser()`** and the command line read frontmatter, so a
  document that carries its own configuration gets it from a terminal as well as
  from code.
- **`MarkdownToWord::pendingImageConversions()`** and
  `Application::reportImageConversions()`.

### Fixed

- **A named style loses its character half in an `.odt`.** ODF keeps a style's
  character half and its paragraph half in two families that do not see each
  other, and a paragraph references only the second, so a heading keeps the air
  above it and nothing else: the size, weight and colour a Word style carries on
  one `w:styleId` never reach the spans. Before: this was reported as carried,
  because the style is in the file; a rendered page says otherwise. After: the
  built-in look does not use a named style, so a default document is unaffected,
  and `Format::Odt` reports the loss for the document that does name one.
- **An SVG silently lost its vector outside a `.docx`.** The original is collected
  during the render and only `SvgPass` puts it back, so an `.odt` and an `.rtf`
  were left with the raster and no word about it. Before: flattened, silently.
  After: flattened, and reported.
- **`CommonMarkParser::withAllExtensions()` threw on any document with
  frontmatter.** The method registers `FrontMatterExtension`, which needs a YAML
  parser, but `symfony/yaml` was only a `suggest`. So the one document shape
  anyone would reach for that method to handle raised
  `MissingDependencyException`. `symfony/yaml` is a `require` now, and is
  bundled into the phar.
- **`imageMaxWidth` did nothing.** The width was written as `'8cm'`, and
  `PhpOffice\PhpWord\Style\Frame::setWidth()` puts its argument through
  `setNumericVal()`, which keeps a number and discards anything else — so both
  dimensions went unset and every image came out at its own size, however wide
  the option said it could be. The cap is now a number of points, and it is a
  *maximum*: an image already narrower than it keeps its own size rather than
  being enlarged to reach it.
- **A `.webp` was silently replaced by its alt text.** PHPWord refused the
  format, `ImageResolver` caught the throwable and fell back, and the document
  came out looking finished with the picture simply missing.
- **A frontmatter block that was not a mapping raised a `TypeError`.** `---`
  followed by a scalar reached `array_key_exists()` with a string and died with a
  stack trace in it; a list was ignored without a word.
- **The command line could not set the top of the precedence order.**
  `ToDocx` folded `--images`, `--image-base` and `--table-width` into the
  configuration file's own layer, so a `tableWidth:` in a document's block
  outranked a flag typed beside it. The flags now sit where the documented order
  says they do, and `--plain` goes with them. The `imageBasePath` the command
  line infers from where the file sits is a default and still loses to the block.
- **`MarkdownToWord`'s `$overrides` argument** pinned every default to the top of
  the order when given a `Configuration`, because `toArray()` names every
  setting. It takes an array as well, which is sparse; the array is what the
  command line and `MarkdownTemplate` pass.
- **The reason given for an unusable SVG was wrong.** It read "image/svg+xml is
  not a format this library can put into a Word document. Word takes JPEG, PNG,
  GIF, BMP and TIFF", which is false: Word has taken SVG since 2016. The real
  reason was that this build had no rasteriser for the fallback PNG. The message
  named the wrong thing, and a reader sent to check whether SVG is a Word format
  would find it is.
- **A style property was accepted and then dropped.** `Styles::FONT_KEYS` and
  `PARAGRAPH_KEYS` are the property names of a font and a paragraph style, and
  the validator applied them to every slot: `borderColor` on a `table` and
  `tblHeader` on a header row were both rejected, and `shading` on a header row
  was accepted and dropped by PHPWord's `RowStyle`, which has no `setShading()`.
  Each slot is now checked against the class it is handed.

### Changed

- **`symfony/yaml` is no longer suggested.** It is required. A partial YAML
  parser would be a worse answer for configuration that a person or a language
  model writes.
- **`mdword` reads frontmatter.** A leading `---` in a document converted from a
  terminal is configuration; on the default parser it was a thematic break and
  the rest of the block arrived as paragraphs of text at the top of the document.
- **An image that is in hand and unusable raises instead of falling back.** A
  missing file and a remote URL still fall back to the alt text — nothing is
  wrong with the document in those cases — but a file that is there and cannot be
  put into a Word document now stops the conversion.
- **A document-level problem is reported as itself.** `Exception\InvalidInput`
  and everything under it is printed by `mdword` as its message, without the
  class name and the line in this repository that a genuine defect gets.
- **`14-round-trip.md` is `18-round-trip.md`** in `examples/out`, so that one
  number in the examples is not both a source and a result. `15`, `16` and `17`
  are the image and table examples.
- **A frontmatter block meant different things on different machines.** Given no
  parser of its own, `FrontMatterExtension` takes libyaml wherever `ext-yaml` is
  loaded and symfony/yaml only where it is not — and a CI runner carries the
  first by default where a checkout usually carries neither. The two disagree
  about values and not only about types: `color: 000000` is the string `000000`
  under symfony/yaml and the integer `0` under libyaml, so the same document was
  accepted locally and refused on the runner as a colour that is not one. The
  parser is now named in `CommonMarkParser` rather than discovered, which is
  also what lets the class name and `withAllExtensions()` agree.
- **The package could not be installed on the PHP version it claims.**
  `symfony/yaml` was required at `^8.1`, which needs PHP 8.4.1, while
  `composer.json` has always said `"php": "^8.2"` and the README has always said
  the published package installs on 8.2. `composer install --no-dev` therefore
  failed outright on 8.2 and 8.3, taking the `minimum` job and the phar build
  with it. The constraint is `^7.4 || ^8.1` and the lock carries 7.4, so the
  floor holds; the test framework still needs 8.4, which the README already says.

## [0.1.1] - 2026-10-08

Tables come out wrong in Microsoft Word.

### Fixed

- **Cell text wraps.** Every cell was written as `<w:noWrap/>`, because
  `PhpOffice\PhpWord\Style\Cell` defaults `noWrap` to true and the vendor writer
  emits the element whether or not anybody asked for it. That element is Word's
  "Wrap text" cell option with the box ticked off: Word laid the cell out on a
  single line and widened the column to suit, so a table holding a sentence ran
  off the page with nowhere for the line to break. LibreOffice reads it as a hint
  it may ignore, so the same file looked right there and wrong in Word.
- **Every column has a width.** A Markdown table carries no widths — GFM's
  delimiter row says alignment and nothing else — and the grid was written empty:
  `<w:gridCol/>` with no `w:w`, and no `w:tcW` on any cell. Word narrows every
  column to one or two characters given nothing to lay out from. Widths are now
  measured from the page the table lands on — its size, orientation, margins and
  column count — and sum to exactly the text column. This was hidden by the
  defect above: `noWrap` had been making Word size columns to their content,
  which papered over their being absent rather than filling them in.

  Both are configured through the `tableCell` style rather than as options,
  since both are cell properties and that slot already takes a PHPWord cell style
  array: `['noWrap' => true]` restores the old wrapping, and a style naming its
  own `unit` keeps its own widths.

## [0.1.0] - 2026-09-30

First release.

### Added

- `MarkdownWord\MarkdownToWord` and `MarkdownWord\WordToMarkdown`, converting
  Markdown to Word (`.docx`) and back on `phpoffice/phpword` and
  `league/commonmark`. Full CommonMark, with GitHub-Flavored Markdown available
  through `CommonMarkParser`.
- `MarkdownWord\Converter`, one interface both directions implement, so a caller
  that does not care which way the data has to go does not have to care.
- `Configuration\Options`, `Reverse\Options` and `Configuration\Styles` for the
  rendering, the reading and the styles. Every option has a `withX()` method, and
  each one carries the rest of the configuration forward.
- `Template\MarkdownTemplate`, for rendering into a Word template and inserting
  the result at a named region.
- The `mdword` command line utility, also shipped as a standalone
  `mdword.phar` with no dependencies to install.
- A conformance suite: 654 CommonMark and 646 GitHub-Flavored Markdown examples,
  compared as text against commonmark's own HTML.
- Resource limits on reading a document — `maxPartBytes`, `maxEntries` and
  `maxStyleDepth` — so a hostile archive cannot exhaust memory or recurse
  without end. All three are options, and all three default to something
  reasonable rather than to nothing.

### Known limitations

The round trip preserves what Word was told to keep and loses the rest, in ways
that are documented rather than hidden. The three worth knowing before you rely
on it:

- a fenced code block comes back without its language, and a one-line block comes
  back as an inline span rather than as a block;
- a table is written with a header whether the document marked one or not, and
  reads back with that header emphasised;
- column alignment in a table is not preserved.

`README.md` has the full list, and `Reverse\Options` is where each one is
configurable.

[0.1.1]: https://github.com/markdown-office/markdown-word/releases/tag/v0.1.1
[0.1.0]: https://github.com/markdown-office/markdown-word/releases/tag/v0.1.0
