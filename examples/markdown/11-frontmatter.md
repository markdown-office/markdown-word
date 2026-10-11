---
# Frontmatter, read as configuration.
#
# None of this block appears in the document. It is read, merged over whatever the
# converter was given, and the document is written with the result. Open
# `12-frontmatter-none.docx` next to this one: the words are identical, the
# formatting is not.
#
# The keys under `styles:` are the slot names from `Styles::defaults()`, spelled
# exactly: `codeFont`, `linkFont`, `blockQuote`. `code_font` is not a slot, and a
# key that is not one is dropped without a word — which is the one sharp edge here,
# and the reason this file spells them out rather than leaving them to be guessed.

options:
  # Headings past this become paragraphs, so the H3 below is body text.
  maxHeadingLevel: 2
  tableBorders: true
  tableHeaderBold: true
  linkTarget: _blank
  codeBlockShading: true

styles:
  heading.1:
    name: Georgia
    size: 30
    bold: true
    color: 8B0000
    space:
      before: 0
      after: 480
  heading.2:
    name: Georgia
    size: 17
    bold: true
    color: B22222
    space:
      before: 400
      after: 160
  codeFont:
    name: Courier New
    size: 11
    color: 006400
  linkFont:
    color: 0000EE
    underline: single
---

# Frontmatter configures the conversion

Look at this heading. It is Georgia rather than Calibri, 30pt rather than 16, dark
red rather than blue, and there is a hand's width of air under it. All four come
from `styles.heading.1` above, and none of them was asked for in code.

## The same applies to this one

Georgia, 17pt, a lighter red, and air above it as well as below — `space` takes
`before` and `after` in points.

## And this is not a heading at all

`options.maxHeadingLevel` is 2, so an H3 is written as a paragraph. The words
survive; the heading does not. In `12-frontmatter-none.docx` this line is a
blue heading, because nothing capped the level there.

## A table, ruled

`options.tableBorders` is true, so this one has rules around every cell and a bold
header row. In `13` the same table has neither.

| Option           | Effect                                   |
| ---------------- | ---------------------------------------- |
| `maxHeadingLevel`| Headings past the level become paragraphs |
| `tableBorders`   | Ruled cells, or not                       |
| `linkTarget`     | Where a link opens                       |

## Code, in green Courier

    $frontmatter = Frontmatter::fromDocument($document);
    return $configuration->getOptions();

Fenced code gets the same treatment:

```php
$deck = (new DeckParser())->parse($markdown);
```

`codeFont` above asks for Courier New at 11pt in green. Neither file's code is
the built-in Consolas — in `12` it is Consolas, and that difference is the easiest
one to miss and the easiest to confirm in the XML.

## A link, in a different blue

The link colour and underline come from `linkFont`. In `12` it is the built-in
`#0563C1`; here it is `#0000EE`.

## What the block is not

Nothing below is affected by it.

- `---` cannot be written as a thematic break in a document like this one; `***` can.
- A key the configuration does not know is ignored, so a typo costs you the one
  setting rather than the whole document. That is also why `code_font` above would
  have done nothing at all.
- `template_file` and `theme_file` are read as data. Neither means anything to a
  Word conversion, so neither becomes an option.