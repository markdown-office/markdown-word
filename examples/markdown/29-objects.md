# An order from the Orrery Type Foundry

Seven names place the furniture of a document in it: a picture, a caption, a
page break, a section with columns, a floating box, a shape and a field. This
page carries one of each, and it is converted three times — as a `.docx`, an
`.odt` and an `.rtf` — so that what each of the three can hold can be looked at
rather than read about.

Three of the seven are a Word document's alone. The other two formats have no
writer for them, so they are given the characters the author wrote, and the
loss is reported.

## The picture

::image[The foundry's printer's rule]{src="assets/orrery-rule.png" width=6.35 height=3.81}

::caption[Figure 1: The house rule, the line the foundry prints between the sections of a specimen book.]{}

The size is the size the document asked for: 6.35 cm by 3.81 cm, which is 240
by 144 pixels, and the same size in each of the three formats.

## The shape

::shape{type=rect width=8 height=1.2 fill=EEF1F7 line=2E5395}

A rectangle, drawn in the colour it is given: `fill` for the inside, `line` for
the edge around it, both in six hexadecimal digits without a `#`.

## The fields

::field{name="customer" value="H. Larkspur & Co."}

A field is a form field. In a Word document it is a `FORMTEXT`, and what a
reader sees is a run of thirty spaces — the name and the value are in the file,
and the space is where the answer goes.

::field{name="fount" type=dropdown entries="Caslon,Cheltenham,Plantin"}

A dropdown is the one kind of field a reader can see, and it is the one kind
that changes the document: Word only lets a reader choose from one while the
document is protected for forms, so a document with a dropdown in it is a form.

## The floating box

A text box is a box with text in it. It floats: `left` and `top` place it —
six centimetres from the left margin here, and at the top of the paragraph it
stands in — rather than letting it sit in the flow of the text.

::textbox[Castings travel in kraft boxes; loose sorts are posted separately.]{width=7 height=3 left=6 top=0}

::section{columns=2}

Type is sold by the line, and the price is the same for every size in a fount.
A 6pt sans and a 72pt titling are the same line of metal to cast and to count.

The matrix and the body are cast separately, because a matrix outlasts its body
by a factor of forty and a fount that mixed the two would be useless within a
year.

A broken sort is replaced free of charge. Send the foundry the piece and the
date on the case, and the replacement goes out with the next order.

Cases are made to measure, in grey fibreboard with a brass label. A case for a
fount of five sizes is a different box from a case for one, and the foundry
keeps the drawings.

Orders are dispatched within ten working days of being confirmed. Nothing is
cast before the order is confirmed, so a change of mind costs nothing.

Postage and packing are charged at cost, and are shown on the invoice rather
than added to it.

Orders below fifty pounds are not sent on credit. Terms are thirty days from
the invoice date, and the account is closed when they are paid.

A fount that has been out of production for more than twenty years can be
recast from the drawings, if the drawings survive. They usually do not.

A sort that is not in the fount you ordered is not sold with it. The foundry
will not send a spare, because a spare in the wrong case is a sort that cannot
be found when it is needed.

Proofs are sent as PDF and as a print on the fount's own body. A proof that
has come back from a printer with corrections on it is the same proof with the
corrections on it.

The weight of a line is quoted in the ems of the typeface, and the em is the
size of the type rather than a unit of weight. Two founts of the same nominal
size do not weigh the same, and the foundry will not say that they do.

Worn type is replaced at the same price as new type of the same body, and the
old type is kept for a month in case the replacement is also wanted.

A case of a fount that has been in use for a century is worth more empty than
a new one is full. The foundry buys them back at a price it sets, and it sets
it by the weight of the metal rather than by the age of the case.

::pagebreak

Castings are delivered on a pallet, and a pallet of a heavy body is heavier
than the van that brings it. Give the foundry the number of the lift it will
go to, and the pallet will be the size that fits it.

A collection is cheaper by a good margin, and the foundry will hold an order
for a fortnight without asking again.
