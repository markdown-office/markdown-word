---
# A document this library refuses to convert.
#
# `styles.blockquote` is not a style slot. The real one is `blockQuote`, camelCase,
# and the typo below used to be discarded without a word: the document came out
# finished, correct, and configured as though the quote style had never been asked
# for. Nothing looked wrong and nothing warned.
#
# Converting this file now raises:
#
#   Unknown style "blockquote". Did you mean "blockQuote"? Known styles:
#   blockQuote, bulletList, codeBlock, codeFont, heading.1, heading.2, heading.3, ...
#
# It is not built by `examples/build.php`, because building it is the failure.

styles:
  blockquote: Quote
  code_font: Courier New

# Three mistakes at once, all reported together so that one run finds all of them:
options:
  maxheadinglevel: 2
  imagesz: embed

---

# You will not see this file

Every key under `styles:` and `options:` above names nothing. The block is checked
before it is merged, so nothing is rendered and no document is written.

## If you want to see what happens instead

Remove the typos and the file converts. The names that work are `blockQuote` and
`codeFont`; for options, `maxHeadingLevel` and `images`.