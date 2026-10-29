#!/bin/sh
#
# Writes a Word document package: a template's own kind rewritten to a document's.
#
#   sh tools/package-as-document.sh <source> <target>
#
# A `.dotx` and a `.docx` are the same archive with one content type different, and that
# one line decides what a reader does with the file. Word opens a package whose body is
# typed as a template by creating a *new* document from it rather than by opening it, and
# the AppleScript that drives Word does not open a template package at all: it opens
# nothing and says nothing.
#
# So the type is rewritten on the way in. It is the rewrite Word itself makes when a
# person chooses *Save As → Document*, and the same one the library makes in
# `MarkdownWord\Template\TemplatePackage`, so the package handed to Word is a document
# the library would have produced too.
#
# **The kind is read out of the package rather than out of the name.** A `.docx` whose
# declarations say it is a template is a template, and a `.dotx` whose declarations say it
# is a document is a document: the declarations are what a reader goes by.
#
# A file of its own rather than a line inside the AppleScript that asks the question,
# because the answer is one the test suite can check without Word: the script itself
# needs the application, and this does not.
#
# The commands are at their own places rather than on the path, because a command looked
# up on the path is a command the path decides, and the path is not this script's to
# decide.
#
# Usage: package-as-document.sh <source> <target>

set -eu

if [ "$#" -ne 2 ]; then
	echo "Usage: package-as-document.sh <source> <target>" >&2
	exit 2
fi

source="$1"
target="$2"

if [ ! -f "$source" ]; then
	echo "There is no file at $source." >&2
	exit 1
fi

# The part that says what the package holds.
declarations='[Content_Types].xml'

# The type of a template's body, as a pattern. The dot and the plus are written as
# character classes, because a pattern would otherwise read them as itself: `main+xml`
# is one or more `n` followed by `xml`, and matches nothing that is there.
template_pattern='wordprocessingml[.]template[.]main[+]xml'

# The type of a document's body, as the text it is replaced with. A replacement is
# literal, so it is spelled the way it is written.
document_type='wordprocessingml.document.main+xml'

# The archive is unpacked rather than read in place, because the name of the part is one
# the shell would read as a pattern: `[Content_Types].xml` is a character class to
# anything that matches names.
work=$(/usr/bin/mktemp -d "${TMPDIR:-/tmp}/mdword-package.XXXXXX")
trap '/bin/rm -rf "$work"' EXIT INT TERM

if ! /usr/bin/unzip -o -q "$source" -d "$work" 2>/dev/null; then
	echo "The package at $source could not be opened. A Word document is a .docx or .dotx file, which is a zip archive." >&2
	exit 1
fi

if [ ! -f "$work/$declarations" ]; then
	echo "The package at $source does not declare what it holds, so it is not a Word document." >&2
	exit 1
fi

if ! /usr/bin/grep -q "$template_pattern" "$work/$declarations"; then
	# Already a document: copied as it is, byte for byte.
	/bin/cp -f "$source" "$target"
	exit 0
fi

/usr/bin/sed "s|$template_pattern|$document_type|g" "$work/$declarations" > "$work/rewritten"
/bin/mv "$work/rewritten" "$work/$declarations"

# The archive is made from the directory's contents, so the parts keep the names they
# had. The target is an absolute path because the command runs inside the directory: a
# relative one would be written into the package being made.
case "$target" in
	*/*) target_directory=${target%/*}; target_name=${target##*/} ;;
	*) target_directory="."; target_name="$target" ;;
esac

if [ ! -d "$target_directory" ]; then
	echo "The directory $target_directory does not exist, so the package could not be written." >&2
	exit 1
fi

target_directory=$(cd "$target_directory" && pwd)

# The parts a macOS machine adds to a folder are not part of a Word document, and a
# package that carries them is a package with rubbish in it.
(
	cd "$work"
	/usr/bin/zip -q -r -X "$target_directory/$target_name" . \
		-x '.DS_Store' -x '*/.DS_Store' -x '__MACOSX/*'
)
