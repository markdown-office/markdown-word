#!/bin/sh
#
# Whether a PDF has been written whole. Exit 0 when it has, 1 when it has not.
#
# A PDF is written from its first byte to its last, and the marker it ends with is written
# last of all, so a file that carries the marker is a file that has been written whole and a
# file that does not is one that is still being written or one that was cut short. The
# question is not the same as "does the file exist": a PDF being written is a file that
# exists and is not empty, and a copy of that is a copy of half a PDF.
#
# A file of its own rather than a line inside the AppleScript that asks the question,
# because the answer is one the test suite can check without Word: the script itself needs
# the application, and this does not.
#
# The same question has the same answer in the slides half, which has a file of its own for
# it. The two are copies rather than one shared file because the two packages are siblings
# and neither depends on the other: a file one of them could import from the other would put
# a dependency between two packages the suite deliberately keeps apart.
#
# Usage: pdf-is-complete.sh <file>

set -eu

if [ "$#" -ne 1 ]; then
	echo "Usage: pdf-is-complete.sh <file>" >&2
	exit 2
fi

file="$1"

if [ ! -f "$file" ] || [ ! -s "$file" ]; then
	exit 1
fi

# The last bytes rather than the whole file: the marker is at the end of a PDF, and a PDF
# is large enough that reading all of it to look at its last few bytes is waste.
if tail -c 64 "$file" | grep -qF '%%EOF'; then
	exit 0
fi

exit 1
