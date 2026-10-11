---
# The three table options on their own, with no style slot anywhere.
#
# This is the counterpart to `16-styled-tables`: there the table carries a definition
# of its own, and here it does not, so `tableBorders` is what draws the lines.

options:
  # No cell borders at all. The columns are still readable — they are still columns —
  # and the page reads as a printed report rather than as a spreadsheet.
  tableBorders: false

  # And no bold first row, for the same reason: the header is a header because it is
  # first, not because it is louder.
  tableHeaderBold: false

  # 0 hands the width back to Word, which sizes the table to its contents instead of
  # to the text column. It is the other end of `tableWidth` from the default.
  tableWidth: 0
---

# Tables without decoration

Three options, three things you can see. Open `02-tables.docx` next to this one: the
Markdown there is the first table below, and the difference is entirely in the block.

## No borders

| Plan | Monthly | Yearly | Notes |
|:-----|--------:|-------:|:-----:|
| Solo | 9 | 90 | one seat |
| Studio | 24 | 240 | up to ten seats |
| Company | 60 | 600 | unlimited, invoiced |

## Sized to its contents

A table narrower than the text column, which is what `tableWidth: 0` asks Word for.

| Key | Value |
| --- | --- |
| `styles` | the slots from `Styles::defaults()` |
| `options` | the values from `Options` |

## Long cells still wrap

Borderless is not unstructured: the columns are still measured, so a long cell wraps
inside its column rather than running across the page.

| Construct | Renders as | Notes |
|-----------|:----------:|-------|
| **bold** | bold | `**bold**` |
| *italic* | italic | `*italic*` |
| `code` | code | backticks |
| ~~strike~~ | struck | `~~strike~~` |