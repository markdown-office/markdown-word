# Directives: a table of contents, footnotes, and what happens to the rest

The two constructs this library draws are below :footnote[A footnote is a real
Word footnote: a superscript mark where the directive stood, the body at the foot
of the page, and a number Word maintains itself.] and above. Everything else on
this page is a directive **nothing draws**, and it is here so the page can be
used to see what that looks like.

## What is drawn

A table of contents, as a field Word builds rather than as a list of lines
written down :footnote[Nothing is cached. Word fills the field in when the
document opens; until it does, the field says what to press.]:

::toc{title="Contents" depth=3}

Depth 2 instead of 3:

::toc{title="This one stops at Heading 2" depth=2}

No page numbers, for a document read on screen:

::toc{title="No page numbers" numbers=false}

## What is not drawn

A directive is a word the author chose, so a name this library has no rendering
for is drawn as the author wrote it rather than dropped.

An inline one, mid-sentence: the sentence keeps :badge[PRODUCTION] the word
that was there, with its braces and its attributes.

A leaf one, standing between paragraphs:

::image[chart]{src="assets/example-logo-meridian.png"}

A container one, whose body is ordinary Markdown and whose fence is not drawn:

:::callout{type=info}
Everything inside a container reaches the renderer exactly as if the fences were
not there: a heading, a list, a table.
:::

## What a format cannot carry

An `.odt` or an `.rtf` has no writer for a footnote, so this page rendered to
either says so through the usual channel and draws the source form instead. The
same is true of a table of contents, which needs a field.

## And through a template

A `.dotx` may carry its own header and footer, and neither of the two constructs
above reaches a document rendered into one: the template renderer copies its
elements into the template's own document, and the parts this library writes
beside a body do not travel with them. Render this file without `--template` to
see them.

## Closing

A construct that is drawn and a construct that is not are different things, and
the difference is visible: one becomes a Word field the reader can update, the
other becomes the characters the author typed. Neither is silently missing, which
is the part that matters.