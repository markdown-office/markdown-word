# Bleeding off the edge of the page

A text box that hangs off the left margin is a thing a document may want: the rule that runs
past the margin, the note in a box that is meant to be cut off by the edge of the paper.

It is also a thing a document may get wrong, and the two are told apart by whether the author
said so. `bleed=true` on the box is the permission, and the refusal names it:

> The text box is placed at a left of -2, which is off the edge of the page. A box lies within
> the page unless it is written to bleed, so write left=0 rather than a negative one — or
> write bleed=true if the box is meant to hang off the edge.

The slides half has the same rule for the same thing, so a document and a deck are written the
same way.

::textbox[A note in a box that hangs off the left margin]{width=9 height=2 left=-2 top=3 border=2E5395 bleed=true}
