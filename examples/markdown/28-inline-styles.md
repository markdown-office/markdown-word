---
theme: corporate
---

# A word in a style of its own

The theme this document names — `corporate`, which ships with the library —
brings two named styles with it, `brand` and `muted`. A word written in one of
them is set in that style, in the middle of a sentence.

The foundry's own name, in the brand style: :brand[Orrery] Type Foundry.

A line of small print, in the muted one: :muted[Cast by hand since 1974.]

## The raw form

`style` is a reserved name. It asks for no named style at all, and the
properties beside it are the whole of the style:

A word in it: :style[Orrery]{color=8B0000}

Dark red, and nothing else, because there is no named style behind it to
inherit a weight from.

## An attribute over a named style

An attribute overrides the style it is written against, property by property,
and leaves the rest of that style standing: :brand[Orrery]{color=8B0000} is
dark red and still bold, because the theme set the weight and the attribute
said nothing about it.

## A name nothing draws

:ember[Orrery] — this library has no rendering for that name, so the directive
is written as the characters the author typed. The sentence around it is
untouched.

## A label holding Markdown

:brand[**Orrery**] — a label is plain text, so the asterisks reach the document
as they were typed, and the directive comes back as its own source form rather
than as a word in a style.

## What the reader is given

A word in a style is written as direct formatting — a colour, a weight, a size
— so a reader finds the word and not the name it was written under.
