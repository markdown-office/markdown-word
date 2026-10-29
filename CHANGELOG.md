# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **A word can be written in a style, in the middle of a sentence.** `:brand[Bakery]`
  is the word "Bakery" in the style the theme calls `brand`, and the theme's
  `styles.yml` gains a `textStyles` key for it:

  ```yaml
  textStyles:
    brand:
      color: "0B5CAB"
      bold: true
  ```

  Three forms, one grammar: a name a theme defines, that name with attributes over
  it property by property (`:brand[Bakery]{color=FF0000}`), and the reserved name
  `style` with attributes and no theme style behind it (`:style[teal]{color=00B4D8}`).
  The attributes are eight — `color`, `size`, `font`, `bold`, `italic`, `underline`,
  `strike` and `highlight` — and they are the same in a theme's style and in the
  directive itself. A size is in points, as it is everywhere else.

  The styles are a configuration of their own: `withTextStyles()` adds them and
  `getTextStyles()` reads them back, and `Configuration::toArray()` carries them
  beside the slots and the options.

  A theme that brings no named styles changes nothing: every named form written
  against it is drawn as its own source text, the way a name nothing claims has
  always been drawn. So is everything else this library cannot draw — an attribute
  that does not exist, a value that is not what the attribute takes, and a label
  holding a complete Markdown construct, which a label cannot have honoured in any
  case. The presentation half refuses all of them and names the alternative; this
  half draws them, because a sentence and a parser nobody asked to change should not
  change the syntax of every document converted after it.

  The names this library draws are asked of `Render\Directives::names()` rather than
  read out of the source of `MarkdownToWord`, so a check against them cannot quietly
  find nothing.

- **`CommonMarkParser::withFrontMatter()`.** The default flavour carries the
  directives and not the front matter, and `withAllExtensions()` carries the front
  matter and not the directives, so a document that names a theme in its own `---`
  block and writes a word in one of the theme's styles had no parser that read
  both. The command line reads with that parser already.

- **A theme can be named as its `theme.yml`.** A `theme:` that names a file loads that
  file as the theme's manifest, and the theme's other files — `styles.yml` — are read
  from the directory that holds it. A path is a path: it is written from the document's
  own directory, resolved with `realpath()` and held to the same scope, whether it names
  a directory or a file; only what is read from it differs. A file that is not a
  `theme.yml` is refused, and so is a directory with no `theme.yml` in it — with a
  message that says which of the two it was.

  ```yaml
  ---
  theme: ./themes/house/theme.yml
  ---
  ```

- **`MarkdownWord\Files\FileFinder`: where a document's files come from.** The library
  composes names and asks; a finder answers for the names it is handed. `read()` and
  `path()` answer for one file, `themes()` lists the themes that resolve and
  `files()` the files of one theme. `Files\DiskFinder` is the default and answers out
  of directories on this machine, one list of bases per role — `theme`, `picture` and
  `document` — tried in the order they are given.

  **The theme's files come from somewhere the caller chooses.** A program that keeps its
  themes, pictures and templates in a database — and never had a directory to hand
  over — hands the library a finder of its own, and the library asks it for the names
  it composes:

  ```php
  use MarkdownWord\MarkdownToWord;
  use MarkdownWord\Template\MarkdownTemplate;

  $finder = new MyFinder();   // read(), path(), themes(), files()

  (new MarkdownToWord($markdown, files: $finder))->save('report.docx');

  (new MarkdownTemplate('report.dotx', files: $finder))
      ->insert($markdown)
      ->save('report.docx');
  ```

  A theme is resolved through the finder by name — `house/theme.yml` — and a picture is
  read through it as `logo.png`. The directories of themes on disk remain the fallback
  rather than the only place a theme is looked for, so a program with a source of its
  own does not lose the themes that ship with this package.

  A finder answers; it does not refuse. A file that is not there is `null`, and a file
  that is out of reach is answered like any other, because the question of what a
  render may read belongs to the library and to the messages it already had. That is
  also what lets one adapter serve both halves of this suite: each has its own
  exception classes, and a finder that threw would throw one of them.

  A source that holds a file gives a path, and the file is used where it is. A source
  that holds the bytes has them staged into a file of the library's own: a picture is
  given to PHPWord as bytes, so nothing is left on disk, and a template is staged
  until the template is done with — `Template\TemplateFile::release()` gives it back,
  and a caller who lets the resolver go without saying so has it said for them.

  `MarkdownToWord` takes `roots:` as well: the directories of themes a document may
  name a theme in, for a shared library of themes beside the project. The theme
  resolver has always honoured them; nothing handed it any.

  **What did not change.** The error messages: a theme that is not there is still told
  which themes are, a path out of reach is still refused in the library's own words,
  and a `template_file:` that is not there says what it always did. The four themes
  that ship with the package — `default`, `minimal`, `corporate` and `report` — are
  the same four. The parser's flavours are the same five: `commonMarkOnly()`,
  `extended()`, `directives()`, `withAllExtensions()` and `withFrontMatter()`.

### Fixed

- **A `value` on a dropdown is refused rather than written into the file.** Word
  records a dropdown's choice as the position of the chosen entry in the list, and
  not as its text, so `::field{type=dropdown value="Berlin"}` wrote the text of an
  entry where `w:ddList/w:default` and `w:result` — a zero-based index — belong.

  A `value` is applied to a text field, which is where it means something, and a
  dropdown that carries one is refused with a message that says why.

- **A theme may not name a text style after a directive this library draws.** The
  directive is drawn before the style is, so a style called `footnote` was never
  applied to a word: `:footnote[…]` drew the footnote, and the style was left with
  nothing to reach it. Nothing said so, and the theme was accepted as it stood.

  A style named after one of the names the library draws is refused now, with a
  message that names the collision and says what the name has to be instead. A
  configuration carries the named styles as well, and refuses the same names the
  same way.

- **A table column's alignment is reported as lost in an `.odt`.** The ODF writer never
  descends into a table's cells, so the paragraph style of the runs inside a cell never
  becomes a style the cell could point at — and the alignment of a column went with it.
  The weight of a header row was reported; the alignment was not, so a reader who
  aligned a column and converted to `.odt` lost it in silence.

  The `.docx` and the `.rtf` carry it, and the report says nothing about either of them.

- **A document with a form field in it can be read back.** `TextExtractor` asked a run
  for its text and used it as a string, and a form field has none: a form field is a
  box, and the text in it is the default it was given rather than a run of its own.
  Reading such a document raised a `TypeError` rather than returning its text.

  A form field contributes the default it was given — the text the author wrote for it
  — and a field with no default contributes nothing. Its name is the form's identifier
  rather than the document's wording, so it is not in the text. A checkbox contributes
  nothing either: it is a box.

  A run that holds no text at all — a content control with no value, a list item whose
  text was taken away — contributes nothing rather than raising.

- **A label holds no Markdown that CommonMark does not call emphasis.** The rule refused
  `** text**` and `**Bakery **`, which are not emphasis: a delimiter run that opens an
  emphasis may not be followed by whitespace, and one that closes it may not be preceded
  by whitespace. A label holding one is drawn as the word it holds.

  The rules are CommonMark's own now, and they are the same rules the slides half keeps
  them in: `tools/compare-label-patterns.php` reads both halves' lists out of their own
  sources and runs one corpus of labels through them, and the two halves agree on every
  label in it.

- **A style's name is a word, and a word does not care about case.** A theme that spelled
  a style `Brand` had it under `:Brand[…]` alone, so a document writing `:brand[Bakery]`
  found nothing, and the names this library published were the folded ones rather than
  the spelling the theme wrote — which is the spelling the author has to change. The
  names are folded now, the way the directives package folds the names of the directives
  themselves, and the names this library publishes spell the styles the way the theme
  wrote them.

  A theme that names one style twice, in two spellings, is refused rather than letting
  one of the two be reached at random. The reserved word is folded with the rest of the
  names, so a theme naming a style `Style` is refused as one naming `style`.

- **A document's theme is found beside the document.** The `theme:` a document names was
  resolved against the *process's* working directory, so a document that said
  `theme: corporate` found it only when the run happened to start in the document's own
  directory — and a build script that converts a document from somewhere else got an
  error naming a theme the author had written beside the document. A document travels,
  and the theme beside it travels with it, so a bare name is looked for in
  `./themes/<name>` beside the document first and in the working directory's own
  `./themes/<name>` second. The document's own directory is a root of the scope a
  document-written path is held to, and a relative theme path is written from it — the
  rule a `template_file:` and a relative image path already follow.

  The library has to be told where the document is, because a document handed over as a
  string has no directory of its own:

  ```php
  (new MarkdownToWord($markdown, directory: '/srv/project'))->save('report.docx');
  ```

### Changed

- **`ThemeLoader::load()` and `loadManifest()` take a name and a finder.** The third
  parameter took a `?PathScope` — the scope a theme's own files were held to — and now
  takes a `?FileFinder`, the finder they are read through; the first was a directory
  and is now the name the theme is known by. The scope check stays where it was, with
  the messages it had, and a loader holds the theme's own files to the scope it was
  given — so a `styles.yml` that is a symlink out of the scope is still refused.

- **`ThemeResolver` takes a loader of its own, or none.** Its loader parameter was a
  `ThemeLoader` and is now a `?ThemeLoader`, so a resolver can be built without one.
  It builds a loader with its own scope when it is given none.

- **`ThemeResolver` looks in the roots it is given.** They were read into the scope a
  document-written path is held to and never looked in, so a shared library of themes
  beside the project had to be named by its full path. A name is now looked for in each
  of them directly, the way it is in the themes that ship with this package, and they
  are named in the list of the themes that resolve — so a failure names them too.

- **Three constructors take a finder.** `MarkdownToWord::__construct()`,
  `Template\TemplateFile::__construct()` and `Template\MarkdownTemplate::__construct()`
  gained trailing named parameters, so every existing call site keeps working as it
  was: `files:` on all three, and `roots:` on the converter as well.

  `Template\TemplateFile` gained `release()`, which gives back the files it staged. A
  staged template is the library's own file, and it goes when the template is done with:
  the caller says so, and a caller who lets the resolver go without saying so has it
  said for them.

## [0.2.2] - 2026-10-26

### Changed

- **The generic directives extension is 0.3.0.** Three fixes in it reach this library:
  a container nested inside another one through a blockquote could not be closed, and
  the rest of the document was swallowed as its body; an attribute whose name was all
  digits was renamed; and a value holding a double quote was written back out in a form
  the parser could not read, so a directive this library does not draw came back with a
  value truncated and an attribute invented. The extension also writes an author's
  attributes under the names they gave them now, where it wrote them into the `data-`
  namespace before — which this library does not see, because it draws directives from
  the tree rather than from the HTML.

## [0.2.1] - 2026-10-25

### Fixed

- **The README's link to the command line points at a page that exists.** It named
  `…/documentation/guides/command-line/`, which is a 404: the site is served from the
  root of its host and the command line is a chapter of it, at
  `…/command-line/basic/`. The page that owns the reference is now where the link goes.

- **The test that says a part over the budget is left to the memory limit asserts the
  library's behaviour rather than PHP's wording.** The security releases of 21 October
  2026 made `memory_limit` a soft limit and put a hard limit above it, and a process
  that crosses the hard limit dies with "Hard memory limit of N bytes exhausted" where
  it used to die with "Allowed memory size of N bytes exhausted". The test quoted the
  sentence, so it failed on a security release of the language rather than on a change
  to this library. What it asserts now is that the library did not refuse the part —
  the budget is generous enough that it does not — and that the child died of memory
  rather than of something else.

- **The test that says a document is moved into place rather than copied asks a
  question the filesystem has to be able to answer.** The staged document was in the
  system temp directory and the destination in the repository, and the two are on
  different filesystems on a runner whose `/tmp` is a tmpfs of its own. PHP's
  `rename()` crosses a filesystem by copying the file, so the move succeeded and the
  inode changed — because the document had been copied, by PHP rather than by this
  library. The test failed on every such runner and passed on every other. The
  destination is beside the staged file now, so the question it asks is one the
  filesystem can answer, and a library that copied instead of renaming still fails it.

### Changed

- **The guard that keeps the command line reference on one page covers the examples
  page as well.** The page names the tool in two ways — once to say where the command
  line lives and once to show how the two documents `build.php` cannot make are made —
  and what the page is allowed to name is allowed by name rather than by line: a use
  of the tool that names anything more than the usage it copies is caught, so a third
  invocation is caught even though it begins the way the first two do. The option list
  it checks against gained `--media` and `--help`, which it did not have.

- **The README says the command line is a chapter rather than one page.** There is no
  page that holds every command, every option and every default: the site's own
  summaries split it three ways.

- **The move's documentation says what `rename()` does across a filesystem.** Two
  files described the move, and both said that a rename cannot cross a filesystem
  however the permissions stand, and that the copy fallback creates the destination
  at the umask rather than carrying over the staged file's mode. Both were wrong:
  PHP's `rename()` crosses one by copying the file, giving the copy the source's
  owner and mode and unlinking the source, so a document moved onto another device
  lands with the staged file's permissions — `0600`. The template's copy of the
  move said a second thing that was not true either, that a link is only followed
  when its target is there: a link whose target is not there is a link, and it is
  followed all the same.

- **The quality gate check says what the server said when it could not read the
  gate.** It reads the answer with `--silent --fail`, so a refusal ended the job with
  `curl: (22)` and nothing else, and the reason — an organisation that is not allowed
  the data of a branch that is not the project's main branch — was in a body that was
  thrown away. The log now carries the answer, the branch the analysis is of and the
  branch the project calls its main one.

## [0.2.0] - 2026-10-19

### Added

- **A document can describe its own header and footer**, under `header:` and
  `footer:` in the `---` block. The body of each is Markdown, drawn by the same
  renderer, so a logo, bold text and a link all work:

  ```markdown
  ---
  header:
    body: |
      ![Logo](assets/logo.png)

      **Meridian Analytics**
    align: right
    border: bottom
  footer:
    body: |
      Page {page} of {total}
    align: center
  ---
  ```

  `{page}`, `{total}`, `{date}`, `{chapter}` and `{filename}` become real Word
  fields — `PAGE`, `NUMPAGES`, `DATE`, `STYLEREF`, `FILENAME` — rather than text
  written down at conversion time, which is what makes a page number right on
  the page the document lands on.

  An empty block writes no part at all, rather than an empty band on every page of
  a document that asked for nothing.

  The blank line after the logo is what stacks it above the text. Two lines with
  nothing between them are one paragraph, and an image inside a paragraph is
  inline, so the text runs beside it.

  A `.dotx` may carry a header and footer of its own, and these do not merge with
  them: the template's parts are its own, and a `header:` in the frontmatter is
  ignored on that path. Set the header in the template, or render without one.

  `.odt` carries neither, and says so through `Format::drops()`. `.rtf` carries
  both — the footer is written by `Writer\RTF\Part\Document` rather than by a
  part of its own, which is worth knowing before concluding from a missing
  `Footer.php` that it cannot.

- **A footnote, as `:footnote[…]`.** A real Word footnote — a superscript mark
  where the directive stood, the body at the foot of the page, numbered by Word so
  the notes renumber themselves. The label is text and not Markdown, which is the
  directives package's own decision about a leaf label.

  Two limits of the grammar are worth knowing: the label goes in one pair of
  brackets (`:footnote[n=12]`, not `:footnote:[n=12]`, which parses as an empty
  label), and a mark cannot sit flush against a word, because the grammar leaves a
  colon after a word alone.

- **A table of contents, as `::toc`.** A real Word `TOC` field with no cached
  entries, marked dirty, with `w:updateFields` set so Word builds it on open.
  An `.odt` or an `.rtf` gets the source form back rather than the placeholder a
  field would have been written into.

- **A heading is now the built-in heading style, by name and by level.** Every
  heading style was defined as `w:styleId="Heading1"` with `w:name="Heading1"`,
  which is a style *called* Heading1 — and a `.docx` identifies a built-in style
  by the `w:name` in its definition, which Word spells `heading 1`. Word collects
  a `TOC \o` by that name, so it collected nothing:

  > Es wurden keine Einträge für das Inhaltsverzeichnis gefunden.

  The definitions now carry `w:name="heading 1"` … `heading 6`, and Word builds
  the contents with page numbers. The size, colour and spacing a document set are
  left exactly as they were.

  Each definition also gets the `w:outlineLvl` a heading style is expected to
  carry, zero-based, 0 … 5. Word does not collect by it — `TOC \o` reads the
  style — but every other reader does: LibreOffice reads the level and not the
  name, and the same document is a plain `<text:p>` without one and a real
  `<text:h text:outline-level="1">` with it. These headings were body text to
  anything that was not Word.

  Written after the file is closed, by `Writer\BuiltInHeadingPass`, because
  PHPWord offers no hook for either: `Writer\Word2007\Part\Styles` takes
  `w:name` from the `w:styleId`, and `w:outlineLvl` is written for numbering
  styles only. A `w:styleId` that is not one of Word's nine built-in heading
  ids is not touched, so a slot pointed at a style of the document's own keeps it
  and a template's stylesheet comes through unchanged.

  Verified by opening the built examples in Word 16.113 and reading back what it
  collected, which is also what corrected the second of these two fixes: it was
  written as the cure for a symptom that the name alone had already cured.

- **`*.dotx` templates.** `MarkdownTemplate` and `template_file:` both
  accept a Word template as well as a Word document. Which of the two it is makes no
  difference to the result: **a filled-in template is always a document.** A `.dotx`
  is a `.docx` with one line of `[Content_Types].xml` different, and Word opens a
  package typed as a template by creating a *new* document from it — so leaving that
  line alone produced a file that double-clicked into `Document1.docx` rather than
  opening, and that anything dispatching on MIME type read as a template.

  **Fixes a silent loss of content.** The same line says which part holds the body,
  and it is not always `word/document.xml` — documents from Word Online carry
  `word/document22.xml`, which is why PHPWord looks the name up. Its lookup matches
  the *document* content type, so on a template it matched nothing, fell back to
  `word/document.xml`, and wrote there. The finished file kept
  `${body}${slot}${/body}` in its real body and carried the rendered Markdown in a
  part nothing pointed at: the content was gone, with every word of the template's
  own text in place. `HyperlinkPass` threw `MalformedDocument` on such a template
  rather than losing the links; the region's own check reported "the template has no
  `${body}` region" for a template that had one.

  The type is now settled on the way in, where one rewrite answers both. The template
  on disk is never opened for writing: the copy the renderer works from is ours, and
  is removed when the renderer goes.

- **`template_file:` in a document's frontmatter** now renders into the template it
  names. The key was documented in the README and explained in `Frontmatter`'s
  docblock, and nothing read it: the block was parsed, the key was kept, and a
  document with no template at all came out. A template named by the caller outranks
  it, which is the order the precedence table already gives every other setting.

  It is a path a document writes, so it is held to `PathScope` for the reason a
  `theme:` is: resolved with `realpath()` and landed inside the working directory or
  the directory the Markdown was read from. The boundary is *where a path lands*, not
  where it was written from — a document in `docs/` naming a template one directory
  up is inside the working directory and is a template, not an escape.

- **OpenDocument Text and Rich Text output.** `MarkdownToWord` grows `toOdt()`,
  `toRtf()` and a format-taking `to()` beside `toDocx()`, and `convertTo()` for
  the path-writing side the constructor's `source` already implies. `.docx` stays
  the default everywhere: `toDocx()` and `convert()` keep their signatures and
  their behaviour, and `toDocx()` is now the same call as `convert()` rather than
  a second way of doing it, so the two cannot answer differently. `Format` is the
  value type that names the three.

- **`toOdt()` and `toRtf()`.** The same conversion in two more
  formats, with `.docx` the default of all three: a run with nothing else said
  still writes a `.docx` and still derives that name from the input. The writer
  gives up `final` and grows a `format()`, so the layering of configuration, the
  image base path, the overwrite guards and the reports on standard error are
  written once and cannot drift between the three. The command line that reaches
  them is [`mdoffice`](https://github.com/markdown-office/mdoffice), which owns it
  now.

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

- **`examples/markdown/20-formats.md`**, built into a `.docx`, an `.odt` and an
  `.rtf` for opening side by side, and `tests/Readme/examples-formats.php`, which
  asserts every row of the README's capability table against the files.

- **Frontmatter configures the conversion.** The YAML block at the top of a
  document is read as configuration rather than discarded. `options:` and
  `styles:` mean what they mean in a config file; `template_file` is read as
  data, because it means nothing to a Word conversion and would otherwise become
  an option key nothing looks at. `theme:` is read by the theme resolver rather
  than by the validator.
  `Document\Frontmatter` reads the block off a parsed document.

- **`Document\ConfigurationMerger`** resolves four sources — command line,
  frontmatter, config file, defaults — each outranking the one below it. A source
  that says nothing is skipped rather than read as "reset everything". The command
  line is an array it is given, so the program that owns the flags
  ([`mdoffice`](https://github.com/markdown-office/mdoffice)) reads them and hands
  them over.

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
  document as PNG. `MarkdownToWord::pendingImageConversions()` reports each one,
  because the file that comes out is several times larger than the one that went in.

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

- **A document that carries its own configuration gets it from code as well as
  from a file.** `Document\Frontmatter` reads the block off a parsed document, so
  a document that names its own styles and options does not need the caller to
  repeat them.

- **`MarkdownToWord::pendingImageConversions()`** reports each one, and the file
  that comes out is several times larger than the one that went in.

### Changed

- **BREAKING: directives are parsed by default.** `new CommonMarkParser()` now takes
  the `directives` flavour rather than `gfm`, so `:name[…]`, `::name[…]` and
  `:::name` … `:::` parse in every document without opting in.
  `CommonMarkParser::commonMarkOnly()` and `extended()` still decline them.

  This is a change of behaviour rather than an addition, and the reason the syntax
  used to be opt-in still stands: `Status :green[go]` is a sentence, and a parser
  nobody asked to change should not change the syntax of every document converted
  after it.

  What answers it is that **a directive this library does not draw is drawn as the
  author wrote it** — the node round-trips to its source form, so an unclaimed name
  comes back out as the characters the author typed. Before this, the renderer drew
  a directive's label and discarded its name and attributes, so
  `:badge[PRODUCTION]{type=success}` and `:badge[DRAFT]{type=info}` produced
  identical sentences; a leaf, having no body to fall back on, was dropped entirely
  along with the paragraph it stood between.

  A container stays transparent — its body is the author's Markdown and the fence is
  not — so a `::callout` remains ordinary text in the document rather than a line
  reading `:callout`.

  Only a name with an implementation changes a document. Names live in
  `src/Render/Directives.php` rather than in the parser.

- **A template is filled with Word's own constructs.** A value is a `MERGEFIELD` —
  the field a person inserts with `Ctrl+F9` — and the place for the content is a
  rich-text content control from the Developer tab. `${name}`, `${/name}` and
  `${slot}` are gone, and with them `--define` and `--region`: there is no longer a
  dialect of this library's own for a template to be written in.

  **This is a breaking change.** A template written for the markers stops working,
  and `MarkdownTemplate::insert()` takes the Markdown alone rather than a region name
  and it. Nothing is deprecated first — the markers were never anything but this
  library's, and supporting both would mean two ways to fill a template forever.

  A filled field is **unlinked**: the value stays and the field goes, which is what
  Word's own *Finish & Merge* produces and what a finished document wants. Only
  `MERGEFIELD` is filled — a document is full of `PAGE`, `TOC` and `REF` fields that
  are its own business, and a template that filled `PAGE` from a frontmatter block
  would produce wrong page numbers. Headers and footers are filled too, because a
  letterhead puts the client's name in a header.

  `repeat()` is gone with it. A row-cloning primitive was a second dialect, and a
  list of records is a table in the Markdown, which the template styles like any
  other content.

- **Values come out of the document**, under `fields:` in the block it already had:

  ```markdown
  ---
  fields:
    client: Northwind Ltd
  ---

  # Highlights
  ```

  A document whose client is in a PHP array is a document whose client is in two
  places, and changing one does not change the other. `MarkdownTemplate`'s parser
  carries `FrontMatterExtension` now, which is the opposite of `MarkdownToWord`'s:
  there the block is configuration a caller may not have written, and here it is
  where the values are.

  `MarkdownToWord::renderIntoContainer()` accepts an already-parsed document, so a
  template reads the block off one parse rather than parsing the same text twice.

- **Any `.dotx` now works.** The content control is optional. A template with no `body`
  control takes the content at the end of its body instead of being refused, which is
  what makes the library usable against a template it did not write: nobody making a
  company letterhead inserts a content control, so requiring one left it able to use
  only the templates its own generator produced. Filling `MERGEFIELD`s and placing the
  content are independent — a letterhead with fields and no control gets both.

  The end of the body is before the final `w:sectPr` rather than after it. A
  `w:sectPr` has to be the last child of a `w:body` or Word rejects the file, and
  content after it belongs to no section, so it would carry none of the margins,
  header, footer or page numbering the template's last section defines.

  This is a behaviour change for anyone relying on `NoContentControl`. `ContentFallback`
  chooses between the two: `Append` (the default) or `Error`, which raises as before
  and names the controls the template does have.

  `ContentControl::fill()` returns `bool` where it returned `void` — whether it found a
  control to fill. It no longer throws `NoContentControl` itself; deciding what an
  absent control means is the caller's, and it now has the answer to decide on.

- **`examples/out/` is emptied at the start of every build**, and holds only
  `documents/` and `previews/`. A build that wrote only what it still named left
  behind every name it had stopped naming, which is how a template written in the
  removed `${…}` dialect came to sit in it under a number the numbering had moved
  past. The three generated templates moved to `examples/templates/` as proper
  `.dotx` files, and the intermediates that used to land in `out/` are now built in
  the system temporary directory and removed when the run ends — a scratch directory
  per preview was leaking one directory into `/tmp` on every run, forever.

  `php examples/build.php --write-template` is now `--write-templates` and rebuilds
  all three.

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
  `Configuration::withoutDecoration()`
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

- **A style slot written as a mapping now merges into the built-in look instead of
  replacing it.** `new Styles(['heading.1' => ['color' => '8B0000']])` keeps the
  heading's `Heading1` style name, its 16pt size, its weight, its spacing and its
  `keepNext`, where it used to keep only the colour.

  **This changes what an existing configuration renders.** Naming a slot with a
  mapping — in a `styles:` block or in code — previously discarded every other
  property of that slot, so a heading styled that way stopped being a Word heading
  at all: it came out of the document as large body text, absent from the
  navigation pane and from any table of contents. `space` merges per key too, so
  `space: {before: 0}` moves the gap without deleting the one at the other end.
  The README documented the merging behaviour all along; this makes the code match it.

  A **style id** (`heading.1: Title`) and an explicit **`null`**
  (`blockQuote: null`) are statements about the slot as a whole and still replace it
  outright, as before. `Configuration::withoutDecoration()` is the named way to ask
  for the second across several slots.

- **A `table` slot written as a mapping keeps the table's rules.** Naming
  `table: {borderColor: …}` used to produce a table with no borders at all, because
  the definition replaced the computed default instead of merging over it and
  `borderColor` only sets the colour of rules asked for separately. It now keeps the
  weight and the cell margins. Found by rendering the shipped themes rather than by
  reading the markup.

- **Themes.** A theme is a directory holding a `theme.yml` and usually a
  `styles.yml`, and `styles.yml` takes the slot names from `Styles::defaults()` and
  nothing else.

  ```yaml
  ---
  theme: report
  styles:
    heading.1:
      color: 8B0000   # the report's heading, in dark red
  ---
  ```

  ```php
  (new MarkdownToWord($markdown))->withTheme('corporate');
  ```

  Four ship with the library — `default` (the built-in look, written out, so naming
  it changes nothing), `minimal`, `corporate` and `report`. A theme is a **starting
  point, not a decision**: everything a person wrote for one document outranks it, so
  a `styles:` block, a `-c` file or a flag still wins, and naming one slot on top of
  a theme is the normal case rather than a conflict. Applying a second theme replaces
  the first rather than layering over it.

  A name that does not resolve is an error naming the ones that do, never a silent
  fallback to `default`. A theme works with a template: the template keeps its own
  style definitions and the theme is written over them as direct formatting.

  `styles.yml` is validated as a `styles:` block is — an unknown slot, an unknown
  property inside one, a `#`-prefixed colour and a non-numeric size are all errors at
  load time. Quote a colour YAML would read as something else: `2E5395` is scientific
  notation, and YAML resolves it to infinity before the theme is read.

- **`symfony/yaml` is no longer suggested.** It is required. A partial YAML
  parser would be a worse answer for configuration that a person or a language
  model writes.

- **A document can carry its own configuration.** A leading `---` in a document is
  read as configuration rather than discarded; on the default parser it was a
  thematic break and the rest of the block arrived as paragraphs of text at the top
  of the document.

- **An image that is in hand and unusable raises instead of falling back.** A
  missing file and a remote URL still fall back to the alt text — nothing is
  wrong with the document in those cases — but a file that is there and cannot be
  put into a Word document now stops the conversion.

- **A document-level problem is reported as itself.** `Exception\InvalidInput`
  and everything under it carries a message meant for whoever wrote the document,
  without the class name and the line in this repository that a genuine defect gets.

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

### Removed

- **The command line has moved to [`markdown-office/mdoffice`](https://github.com/markdown-office/mdoffice),
  and `vendor/bin/mdword` is gone.** The classes under `MarkdownWord\Console`,
  the `bin/mdword` entry point and the `composer build:phar` script are all
  removed from this package, which is a library and nothing else.

  A terminal tool is a different thing from a converter: it decides which file is
  read, where the result is written, what a flag means and what exit status to
  return, none of which a caller embedding this package wants decided for it. It
  also wanted to be one program for every office format rather than one per
  library, which is not a shape a library can have — `mdoffice` converts Markdown
  to Word and to PowerPoint side by side and calls this package for the first.

  Installing this package still gives the whole library, and a `composer.json`
  naming `mdword` in `bin` is gone, so nothing is added to `vendor/bin` any more.
  Ask for `mdoffice` as well for the command.

### Fixed

- **`05-links-and-images.docx` shows reference links rather than the words that look
  like them.** The page says its reference links are "defined at the bottom of the
  file" and the file defined none of them, so `05` rendered as literal text —
  `[reference link][one]` and the rest — and a page about links demonstrated the
  opposite. The four definitions are at the bottom now, and the four resolve.

  `[foo][bar]` under "Escaping" is the other half and is left as it is: it exists to
  show what a reference that resolves to nothing looks like, and it still does.

- **A `:footnote[…]` in a running head or foot no longer raises.** It is drawn as
  the directive the author wrote — `:footnote[a note]` — and the conversion
  completes. It previously ended in `BadMethodCallException: Cannot add Footnote
  in TextRun`, which is PHPWord's own exception and not an answer a document
  author can act on.

  A footnote is a mark with a note at the foot of one page, and a running head is
  read on every page and has no such page, so the mark could only ever have
  pointed at a note nothing held. The source form is the whole of the answer, and
  nothing is reported for it: a loss is a property of the *format*, and `.docx`
  writes a footnote everywhere else. `:footnote[…]` in the body of a document is
  unaffected and is still a real footnote.

- **An SVG can be written without a raster beside it**, under `svgFallback` in the
  document's frontmatter:

  ```markdown
  ---
  svgFallback: none
  ---
  ```

  `none` writes the vector alone. `raster` — the default — writes a raster of it as
  well, for the readers that cannot take a vector.

  Word 2016 and later draw a picture carrying only the `asvg:svgBlip` extension, with
  no raster behind it, and so does recent LibreOffice. The raster is there for the
  rest: **Google Docs does not read the extension** and draws such a picture as
  nothing at all, which is why this is an option and not the default.

  `r:embed` is optional on `a:blip`, so a document with the vector alone is one Word
  opens. It is smaller, and it puts `ext-imagick` off the path entirely for a build
  that has no usable rasteriser.

  The option is about `.docx`, because only `.docx` keeps the vector. A raster is what
  an `.odt` or an `.rtf` draws the picture from, so those two want one whatever this
  is set to.

- **Directives parse, under `CommonMarkParser::directives()`.** The
  [Generic Directives RFC](https://talk.commonmark.org/t/generic-directives-plugins-syntax/444)
  — `:name[…]`, `::name[…]` and `:::name` … `:::` — through
  [`markdown-office/commonmark-generic-directives`](https://github.com/markdown-office/commonmark-generic-directives):

  ```php
  new MarkdownToWord(null, $config, CommonMarkParser::directives());
  ```

  It is a parser rather than a default, because a directive is a word the author
  chose: `Status :green[go]` is a sentence today and a node the day it is on by
  default, and nothing should change the syntax of every document converted after
  it.

  **What a directive looks like in Word is not decided yet, and what happens today
  is a loss.** The renderer has no case for either node type. A `:::` container
  contributes its body and is then gone; `:name[label]{attrs}` contributes the
  label and drops the name and the attributes; a `::leaf[…]` contributes nothing
  at all, and a document of nothing but leaves raises `NothingToConvert`. It is
  asserted in `tests/Unit/directive-grammar.php` and it is what the grammar does
  before the rendering catches up.

  Names are not checked. The extension can be given a `DirectiveRegistry` that
  makes it raise `UnknownDirective` for a name it does not hold rather than
  ignoring it, but `CommonMarkParser` builds its extensions from class names and
  cannot pass one in, so strict mode is not reachable through the factory yet.

- **A build that cannot rasterise an SVG no longer says it can.** The capability was
  read from ImageMagick's format registry, which a build with no SVG delegate still
  registers: it answered yes, drew the shapes, dropped every `<text>` in the picture,
  and raised nothing. A reader that does not prefer the vector — an older Word, Google
  Docs — was handed a picture with its words missing. The question is now settled by
  drawing a letter and looking at the result, because that is the part such a build
  loses.

- **An SVG no longer goes in silently when there is no rasteriser.** It is written on
  its own rather than refused, and `pendingLosses()` says what that costs:

  ```
  the vector was written with no raster beside it, so a reader that does not prefer
  the vector draws nothing where an SVG was
  ```

- **A vector was attached to the wrong document.** The pairing between a picture and
  its SVG was by position, and positions were counted across a whole converter rather
  than per document. A second document written through one converter was handed the
  first one's vectors, which were then attached to whatever picture happened to be at
  that index — including a document with no SVG in it at all, which lost a photograph
  and gained a diagram nobody asked for.

- **Two refusals that were accidents are refusals now.** A file holding no SVG whatever
  it is called, and one stating no `width`, `height` or `viewBox`, were both caught by
  the rasteriser declining them. Nothing checked either, so a build without one wrote a
  part that was not a vector into the document, and placed a diagram at an assumed
  288pt.

- **A code span lost its spaces.** `` `a   b` `` came out as `a b`, and `` `foo(  bar)` ``
  as `foo( bar)`. CommonMark normalises exactly two things inside a code span — line
  endings become spaces, and one space comes off each end when both ends have one — and
  the parser has done both by the time the node reaches the renderer. Collapsing what
  was left changed what the code said, which makes the two-space version and the
  one-space version the same line and the difference is usually the bug being looked
  for. Ordinary text is still collapsed, as a browser does; a code block never went
  through that path.

- **The tests wrote scratch files into the system temporary directory**, where
  nothing removes them: 845 empty files and 557 zip-bomb scratch files accumulated,
  and both grew on every run. `tempnam()` creates a real file, so the two calls that
  appended `.php` to it were unlinking a path they had never been given. The scratch
  directories and files now go to `tmp/pest`, which the suite empties after every
  test, and a child process told to be small is given `-d sys_temp_dir` pointing there
  — a PHP fatal skips destructors, so a child that dies of the memory limit leaves
  whatever it had written.

  One SVG test was reading a fixture another test happened to leave in the shared
  scratch directory; it now writes its own.

- **A soft line break in a wrapped paragraph lost its space.** Markdown is habitually
  hard-wrapped by hand, and every wrap point joined two words together:
  `revenue` and `growth` came out as `revenuegrowth`, `in January` as `inJanuary`.
  The cause was the fix above: the writer that folds out a fragment's indentation also
  folded `>`, any whitespace, `<`, and a soft break is written as a run holding exactly
  one space. It now folds only whitespace that has a newline in it, which is the only
  kind that is ever an indent.

  Every document was affected, not only the ones rendered through a template. Nothing
  caught it, because every assertion was on the *words* and every word was right —
  they were merely adjacent to the wrong word.

- **A rendered block written into a template arrived as text.** PHPWord's element
  writer emits a fragment indented for a human to read, and whitespace between a
  `w:t` and its text *is* text to Word: every paragraph in the document opened with
  the literal characters `<w:p>`. No text-level assertion could see it, because the
  words after it were exactly right.

- **A `codeFont` given as a style *name* never reached a code block.** A template
  supplies its typeface as a name — `codeFont: CorpCode` — and PHPWord puts a
  string style on a run as an `rStyle` reference, which is the only way a name
  reaches a run at all. An inline code span took the name straight through and
  worked. A code block merged it with the font half of its own `codeBlock` slot,
  and merging required an array: casting the name gave `['CorpCode']`, whose key
  `0` is not a property, so PHPWord wrote an empty `<w:rPr/>` and dropped it.

  Every code block rendered through a template came out in the body typeface,
  which is the one thing a template is asked to change. Found by rendering
  `examples/out/21-dotx-report.png` and looking at it — no text-level assertion
  could have seen it, because the words were exactly where they should be.

- **The archive carried whatever the building machine had in its working copy.** The
  staging tree is assembled with `cp -R`, so a `.DS_Store` in `src/` went into the
  archive and the artifact stopped being reproducible — Finder rewrites that file
  whenever the directory changes, so two builds of one tree differed. A stray also fed
  the timestamp every entry is stamped with, so one newer than the sources moved every
  timestamp in the archive. `.DS_Store`, `Thumbs.db`, `desktop.ini` and `__MACOSX` are
  now dropped from both.

- **`bulletList` and `orderedList` were silently ignored.** Both were read from the
  converter's own constructor configuration rather than the merged one, so naming them
  in a `styles:` block, a config file or a theme had no effect on the numbering
  written. Pre-existing, and newly reachable from a theme's `styles.yml`.

  The limit that remains is format-specific, and the first version of this entry got it
  wrong by describing the `.docx` case as the only one. An `.odt` writes the name as the
  `text:list-style` its lists refer to. A `.docx` does not: PHPWord's Word writer emits
  `w:abstractNum` and `w:abstractNumId` and never a `w:name`, so the numbering is reached
  by id. In neither format does the name configure the bullet itself — the default is a
  filled bullet, `o`, and a small filled square by nesting depth.

- **A theme's own files were not checked against the scope.** The directory was held to
  the allowed roots and the two files inside it were not, so a `styles.yml` that was a
  symlink pointing out of the scope was read, and its values reached the document as
  direct formatting.

- **`ThemeNotFound` no longer echoes a resolved path.** An error said where a
  document-written name had landed — a path on this machine the document had no
  business asking about — and three distinguishable messages gave it an existence
  oracle over arbitrary directories. The allowed roots are still named, because an
  author who has to move a theme out of them is entitled to know what they are.

- **The filesystem root is no longer accepted as a directory a document may name.**
  `/` is a prefix of every absolute path, so a working directory of `/` — a container
  started with `WORKDIR /`, a service that has chdir'd to `/` — made every path on the
  machine reachable. It is refused rather than kept, so the scope stays a scope.

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
  `Document\ConfigurationMerger` folded the flags `--images`, `--image-base` and
  `--table-width` into the configuration file's own layer, so a `tableWidth:` in a
  document's block outranked a flag typed beside it. The flags now sit where the
  documented order says they do. The `imageBasePath` inferred from where the file
  sits is a default and still loses to the block.

- **`MarkdownToWord`'s `$overrides` argument** pinned every default to the top of
  the order when given a `Configuration`, because `toArray()` names every
  setting. It takes an array as well, which is sparse; the array is what
  `MarkdownTemplate` passes.

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

[0.2.2]: https://github.com/markdown-office/markdown-word/releases/tag/v0.2.2
[0.2.1]: https://github.com/markdown-office/markdown-word/releases/tag/v0.2.1
[0.2.0]: https://github.com/markdown-office/markdown-word/releases/tag/v0.2.0
[0.1.1]: https://github.com/markdown-office/markdown-word/releases/tag/v0.1.1
[0.1.0]: https://github.com/markdown-office/markdown-word/releases/tag/v0.1.0
