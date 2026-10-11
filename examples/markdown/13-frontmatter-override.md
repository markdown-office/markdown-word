---
# The frontmatter, losing.
#
# Every key below asks for the opposite of what `11-frontmatter.md` asks for. The
# document built from this one is the file-level configuration, because the
# constructor is the higher of the two.

options:
  maxHeadingLevel: 6
  tableBorders: false

styles:
  heading.1:
    name: Arial
    size: 12
    bold: false
    color: 808080
    space:
      before: 0
      after: 0
  codeFont:
    name: Arial
    size: 12
    color: 000000
---

# This heading is not 30pt

`11-frontmatter.md` asks for Georgia 30pt in dark red through its block. This file
asks for Arial 12pt in grey, and the file-level configuration asks for something
else again. The file wins, because that is the order the main README gives:

| Source | Beats |
| --- | --- |
| overrides passed to the converter | the frontmatter |
| the frontmatter | the file-level configuration |
| the file-level configuration | the defaults |

## So this H3 is a heading

`11-frontmatter.md` caps the heading level at 2 and turns this into a paragraph.
This file caps it at 6 in the block, and the override says 6, so it stays a heading.

## And this table has no borders

`tableBorders: false` from the override. Open `11-frontmatter.docx` beside it:
same table, ruled.

## The code is not Courier either

Both files ask for a monospaced face and neither gets it. `11` asks for Courier
New and gets it; this file asks for Arial in the block and gets Arial, because the
block loses.

## Both files are otherwise identical

Same words, same structure, same table, same link. Two different documents out of
two nearly identical files.