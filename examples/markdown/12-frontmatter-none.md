# The same Markdown, with no frontmatter

Everything in `11-frontmatter.md` without the block at the top, so the two can be
opened next to each other. The words are the same in both.

## What to compare

| | `11-frontmatter.docx` | this file |
| --- | --- | --- |
| Font | Georgia | Calibri |
| H1 | 30pt, `#8B0000`, air under it | the built-in look: 16pt, `#2F5496` |
| H2 | 17pt, `#B22222`, air above and below | the built-in look: 13pt, `#2F5496` |
| H3 | a paragraph | a heading |
| Code | Courier New 11pt `#006400` | Consolas 9pt `#A31515` |
| Link | `#0000EE` | `#0563C1` |

The headings are the difference you will see first and the spacing the one you
will notice second, once the colour has stopped being surprising. Nothing here
is Word's own `Heading 1`: it is the library's built-in look, written onto the
text itself, and it comes out the same in a `.docx`, an `.odt` and an `.rtf`.

## And this one is a heading here

It is a paragraph in `11-frontmatter.md`, because that file caps the heading level
at 2 and this one does not cap it at all.

## Everything else is identical

Same table, same code block, same link, same words. The block in `11` changes how
the document is written, not what it says.

## And this is not a thematic break

Three dashes on their own cannot be written inside a document that reads frontmatter —
sorry, inside any document that does. Use `***`, which is a thematic break in both
files.