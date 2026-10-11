---
# Images: where they come from, how wide they are, and what happens to the three
# that cannot be embedded.

options:
  # Centimetres. The default is 15, which is the width of the text column; 8 makes
  # every picture on this page about half of it, so the difference is visible.
  imageMaxWidth: 8.0
---

# Images

Everything below refers to its file by a path relative to this one. Nothing in the
Markdown says where that resolves from — `imageBasePath` does, and `examples/build.php`
points it at `examples/markdown`, which is what a Markdown renderer in an editor would
do with the same file.

## Embedded

The picture is in the document, not next to it. Open `15-images-embed.docx` and the
image is there; there is nothing beside the file to lose.

![The red square generated for the examples](assets/logo.png)

## WebP

![A photograph, converted on the way in](assets/test_landscape.webp)

Word has no support for WebP and PHPWord has never added any, so this one is decoded
with GD and written into the document as PNG. That is a decision the run made on your
behalf and it is not free — a PNG of a photograph is several times the size of the
WebP it came from — so `mdword` says so on standard error, and
`MarkdownToWord::pendingImageConversions()` has it afterwards:

```
mdword: converted …/assets/test_landscape.webp (image/webp) to PNG for embedding
```

An earlier version of this file embedded neither picture and said nothing about it:
PHPWord refused the format, the refusal was caught, and the document came out looking
finished with the alt text where the photograph should have been.

## An image that is not there

![A picture that was never written](assets/there-is-no-such-file.png)

No file, no picture: the alt text stands in. A remote URL does the same, because the
library has no HTTP client and will not pretend a download happened:

![A picture on somebody else's server](https://example.com/never-fetched.png)

## The other two modes

The same source, built twice more with `images` set to `placeholder` and to `skip`:

| File | What the picture becomes |
| --- | --- |
| `15-images-embed.docx` | the picture, inside the document |
| `15-images-placeholder.docx` | the alt text, then the path it was given |
| `15-images-skip.docx` | nothing at all |

They are one document and three configurations, because `images` is one setting and a
document cannot hold three of it. Open them side by side.