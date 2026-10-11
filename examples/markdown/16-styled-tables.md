---
# A table that looks like a table somebody chose, rather than one Word chose.
#
# The three slots below are the only ones a Markdown table has, and they are not the
# same kind of thing as a heading's. `table` is the table itself, `tableHeaderRow` is
# its first row as a *row*, and `tableCell` is every cell in it. A row in OOXML has
# three properties and no appearance of its own, which is why `tableHeaderRow` below
# repeats the header rather than shading it.

options:
  # 5000 is the full width of the text column. 4000 is four fifths of it, so the
  # table has a margin down each side that the default does not.
  tableWidth: 4000

  # The header row is bold by default. With a cell style setting the size and colour
  # of every cell, bold on the header would be the only difference between it and the
  # rest — so here the header is told apart by repeating instead.
  tableHeaderBold: false

styles:
  table:
    # The borders are this line's, not `options.tableBorders`: that one draws the
    # borders of the *default* table, and a table with a definition of its own brings
    # its own. `02-tables.docx` is what the default looks like.
    borderColor: 2E5F86
    borderSize: 8
    cellMargin: 100

  tableHeaderRow:
    # Repeat this row at the top of every page the table spills onto. This is what a
    # `Row` can be told in OOXML, and it is all it can be told — the slot is a row,
    # not a paragraph, so `shading` and `space` under it are not properties of it and
    # are now reported rather than dropped.
    tblHeader: true

  tableCell:
    size: 10
    color: 1F2933
    vAlign: center
---

# Styled tables

Markdown gives a table three things: the cells, which column is aligned which way,
and which row is the header. Everything else about how it looks is the four settings
above, and all four come out of this block.

## Prices

| Plan | Monthly | Yearly | Notes |
|:-----|--------:|-------:|:-----:|
| Solo | 9 | 90 | one seat |
| Studio | 24 | 240 | up to ten seats |
| Company | 60 | 600 | unlimited, invoiced |

## What each slot does

| Slot | Reaches |
| --- | --- |
| `table` | the table itself: borders, cell padding, alignment |
| `tableHeaderRow` | the first row, as a row — it repeats, or it cannot break |
| `tableCell` | every cell, header included |

## The same table without the block

Take the `---` off the top of this file and the table comes back at the full width of
the text column, with black rules and a bold first row. Nothing else changes, which is
the point: the Markdown was never carrying any of it.

| Plan | Monthly | Yearly | Notes |
|:-----|--------:|-------:|:-----:|
| Solo | 9 | 90 | one seat |
| Studio | 24 | 240 | up to ten seats |
| Company | 60 | 600 | unlimited, invoiced |