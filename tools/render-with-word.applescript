-- Opens each document in Microsoft Word and exports it to PDF.
--
--   osascript tools/render-with-word.applescript <document.docx> [...] --out <directory>
--
-- Word rather than LibreOffice because Word is the only application that will tell the truth
-- about whether a document opens. LibreOffice is a different implementation of the same
-- format and renders what it believes the format means: a Word document laid out by
-- LibreOffice has its inline pictures in the wrong place often enough that the layout
-- cannot be trusted, and a layout that cannot be trusted is no use to a caller who is
-- checking whether the text fits.
--
-- Word is sandboxed, and the sandbox is the shape of this script:
--
--   * Word will not export a PDF to a path outside its own container. Asked to, it waits
--     for a grant the caller cannot give, and the AppleEvent times out — so the export is
--     written inside the container and copied out afterwards. This is not a preference:
--     asked to write to /tmp, Word hangs until the AppleEvent times out and writes nothing.
--
--   * A PDF is written lazily, so a file that exists is a file that may still be being
--     written. The export is finished when the file has stopped growing and carries the
--     end-of-file marker every PDF ends with, and only then is it copied out. The question
--     has an answer of its own in `tools/pdf-is-complete.sh`, which is a file of its own so
--     that the answer can be checked without Word.
--
--   * Word knows a document by the name of its file, so a document staged under the name of
--     one that is already open is that document. Every document is therefore staged under a
--     name of this run's own, which no open document can have.
--
-- A validator that cannot run must never report success. Every step here either produces
-- the file it promised or raises, and a raised AppleScript error exits non-zero, so a caller
-- reading only the exit status learns the render did not happen.

on run argv
	set documents_ to {}
	set outDirectory to missing value
	set sawOut to false

	repeat with argument in argv
		-- `text` rather than `item`, both because it is the descriptive word here and because
		-- Word's dictionary defines `text` as a class of its own and a variable by that name
		-- would not compile against it.
		set word_ to argument as text

		if word_ is "--out" then
			set sawOut to true
			set outDirectory to missing value
		else if sawOut and outDirectory is missing value then
			set outDirectory to word_
		else
			set end of documents_ to word_
		end if
	end repeat

	if (count of documents_) is 0 then
		error "Nothing to render. Pass one or more .docx or .dotx paths, then --out <directory>." number 1
	end if

	if outDirectory is missing value then
		error "No output directory. Pass --out <directory>." number 1
	end if

	-- The output directory is created before Word is started at all, so a path that cannot be
	-- written costs nothing and starts no application.
	set destination to prepareDirectory(outDirectory)
	set stamp to runStamp()
	set scratch to makeScratchDirectory(stamp)
	set opened to {}
	set stagedIn to missing value

	try
		set stagedIn to workingDirectory(scratch)
		set rendered to {}
		set names to {}
		set place to 0

		repeat with document_ in documents_
			set place to place + 1
			set one to render(document_, place, stamp, stagedIn, opened)
			set end of rendered to one
			set end of names to (one's stem) & ".pdf"
		end repeat

		collect(stagedIn, destination, rendered)
		cleanUp(scratch, stagedIn)

		return names
	on error messageText number errorNumber
		-- The documents this run opened are closed before anything else, whatever went wrong:
		-- a document window left behind is a window the person using the machine finds open on
		-- a document they did not ask for. Only documents this run opened are touched, so a
		-- document somebody had open before is still open afterwards.
		closeAll(opened)
		cleanUp(scratch, stagedIn)

		if errorNumber is -128 then
			error "Cancelled." number 1
		end if

		error messageText number errorNumber
	end try
end run

--
-- One document in, one PDF file name out.
--
-- Raises rather than returning an empty string, because a caller counting files would read a
-- failed conversion as a short document.
--
-- The document is staged under a name of this run's own, because Word knows a document by the
-- name of its file: a document staged under the name of one that is already open is that
-- document, and what is exported is the one that was open before.
--
on render(documentPath, place, stamp, working, opened)
	set fileName to nameOf(documentPath)
	set stem to documentNameOf(fileName)
	set stagedStem to stem & "-" & place & "-" & stamp
	set staged to working & (stagedStem & ".docx")
	set target to working & (stagedStem & ".pdf")

	-- The document is staged through the helper rather than copied, because a template is
	-- not a document Word will open: Word's AppleScript opens a package whose body is
	-- typed as a template by opening nothing at all, and it raises no error when it does
	-- so. The helper rewrites that one content type, and a document is what Word opens.
	try
		do shell script "sh " & quoted form of (helperPath("package-as-document.sh")) & " " & quoted form of documentPath & " " & quoted form of staged
	on error errorMessage
		error "Unable to read " & documentPath & ": " & errorMessage number 1
	end try

	set openedName to openDocument(staged, stagedStem & ".docx")
	set end of opened to openedName

	try
		with timeout of 600 seconds
			tell application "Microsoft Word"
				set d to document openedName

				try
					save as d file name target file format format PDF
				on error errorMessage number errorNumber
					error "Word would not export " & fileName & " as PDF: " & errorMessage number 1
				end try
			end tell
		end timeout
	on error messageText number errorNumber
		if errorNumber is -1712 then
			error "Word timed out exporting " & fileName & " as PDF. Word writes only inside its own container, and this script writes there; a timeout means Word is waiting for something it was not given." number 1
		end if

		error messageText number errorNumber
	end try

	-- Both of these run outside the `tell`: Word's dictionary makes every handler name a term
	-- of its own, so a call to one from inside is sent to the application instead of run.
	-- `settle` in particular has to be outside, because it polls the filesystem while Word is
	-- still writing and a call inside the block would be Word's.
	settle(working, stagedStem)

	-- The document is closed only once the PDF is whole: Word writes it lazily, and a
	-- document closed while the export is still being written is a PDF that stops in the
	-- middle.
	if not pdfIsComplete(target) then
		error "Word reported success and wrote no finished PDF for " & fileName & "." number 1
	end if

	closeOne(openedName)
	set opened to dropFrom(opened, openedName)

	return {stem: stem, container: stagedStem & ".pdf"}
end render

--
-- Opens a document in Word and waits for it to be there.
--
-- `open` returns before Word has finished opening anything, so the document that was asked
-- for is not in the collection yet and `active document` is still the one that was open
-- before. Reading it too early is how a render exports the wrong document — which is why
-- this waits for the new name to appear rather than sleeping and hoping.
--
on openDocument(staged, stagedName)
	tell application "Microsoft Word"
		launch
		set display alerts to none
	end tell

	tell application "Microsoft Word"
		with timeout of 600 seconds
			open file name staged
		end timeout
	end tell

	-- The staged name is this run's own, so waiting for *that* name is exact: no other
	-- document can have it, and there is nothing to diff against and nothing to guess.
	repeat 240 times
		if (stagedName is in namesOfDocuments()) then
			return stagedName
		end if

		do shell script "sleep 0.25"
	end repeat

	error "Word did not open " & stagedName & "." number 1
end openDocument

--
-- The names of the documents Word has open.
--
-- Every answer comes out of a handler of its own, and every handler reads one value and
-- returns it. That is not a style: it is how AppleScript's scopes work.
--
--   * A `tell` block has a scope of its own. A name assigned inside one is the block's, and
--     a name that already exists outside it is *shadowed* rather than changed — so
--     `set found to {}` before a `tell` and `set found to found & x` inside it leaves the
--     outer `found` exactly as it was. A list built inside a `tell` is empty outside it.
--   * A `return` inside a `tell` yields `missing value`, not the value that was returned.
--
-- So each handler assigns a name of its own inside the `tell` and returns it outside, and
-- the list is built by a handler that does not talk to Word at all.
--
-- The count is read the same way, one document at a time: `documents` is an element of the
-- application in Word's dictionary and not a collection that can be walked with
-- `repeat with`, and asking it to be walked is an error the script cannot recover from.
--
on namesOfDocuments()
	set found to {}

	try
		repeat with index_ from 1 to documentCount()
			set end of found to documentNameAt(index_)
		end repeat
	on error
		return {}
	end try

	return found
end namesOfDocuments

--
-- How many documents Word has open.
--
on documentCount()
	tell application "Microsoft Word"
		set total to count of documents
	end tell

	return total
end documentCount

--
-- The name of the document at a position.
--
on documentNameAt(index_)
	tell application "Microsoft Word"
		set oneName to (name of document index_) as text
	end tell

	return oneName
end documentNameAt

--
-- Word's sandbox container, which is the one directory it will always write.
--
on workingDirectory(scratch)
	try
		set home to POSIX path of (path to home folder)
		set base to home & "Library/Containers/com.microsoft.Word/Data/" & scratch

		do shell script "mkdir -p " & quoted form of base

		return base & "/"
	on error errorMessage
		error "Unable to prepare Word's container: " & errorMessage number 1
	end try
end workingDirectory

--
-- The PDF has to be finished before the document is closed: Word writes it lazily, and
-- closing first is a race this script would lose on a large document and win on a small one.
--
-- "Finished" is two questions and not one: the file has stopped growing, and it carries the
-- end-of-file marker every PDF ends with. A file that exists is a file that may still be
-- written, and a copy of that is a copy of half a PDF.
--
-- The wait is `sleep` rather than AppleScript's `delay`, because Word's dictionary defines
-- `delay` as a class of its own and this script compiles against that dictionary.
--
on settle(working, stem)
	set candidate to working & (stem & ".pdf")
	set bytes to -1

	repeat 240 times
		set current to fileSize(candidate)

		if current > 0 and current is bytes and pdfIsComplete(candidate) then
			return
		end if

		set bytes to current

		do shell script "sleep 0.25"
	end repeat
end settle

--
-- The size of a file in bytes, or -1 where there is no file.
--
on fileSize(candidate)
	try
		return (do shell script "stat -f %z " & quoted form of candidate) as integer
	on error
		return -1
	end try
end fileSize

--
-- Whether a PDF has been written whole.
--
-- `tools/pdf-is-complete.sh` answers it, and it is a file of its own because the answer can
-- be checked without Word: the script that asks the question needs the application and the
-- answer does not.
--
on pdfIsComplete(candidate)
	try
		do shell script "sh " & quoted form of (helperPath("pdf-is-complete.sh")) & " " & quoted form of candidate
		return true
	on error
		return false
	end try
end pdfIsComplete

--
-- The helper beside this script, found through the script's own path rather than through the
-- working directory: the tool is run from wherever the caller happens to be.
--
on helperPath(name)
	set here to POSIX path of (path to me)
	set base to nameOf(here)

	return (text 1 thru -(length of base + 1) of here) & name
end helperPath

--
-- What tells this run's files from any other's.
--
-- The epoch second and the shell's own process id, in one shell: two runs a second apart must
-- not stage a document under a name the other is using, because Word knows a document by its
-- name and would hand back the one it already had.
--
on runStamp()
	return do shell script "date +%s-$$"
end runStamp

-- A directory name of our own inside it, so two runs cannot collide on one another's pages.
on makeScratchDirectory(stamp)
	-- The name is the run's own stamp rather than anything read from the environment: a
	-- directory inside another application's container is a good place not to interpolate.
	return "markdown-word-render-" & stamp
end makeScratchDirectory

on prepareDirectory(outDirectory)
	try
		do shell script "mkdir -p " & quoted form of outDirectory
		return outDirectory
	on error errorMessage
		error "Unable to create the output directory " & outDirectory & ": " & errorMessage number 1
	end try
end prepareDirectory

--
-- Moves the PDFs out of the container.
--
-- Copied rather than moved so that a document that rendered and one that did not leave the
-- same trail: an export that Word wrote into its own sandbox and then failed to hand back is
-- the one failure a caller cannot see from the output directory alone.
--
-- Each PDF is the one this run rendered rather than every PDF in the directory, and it is
-- copied under the name of the document it came from: the files in the container carry the
-- names they were staged under, which are not the names the caller asked for.
--
on collect(working, destination, rendered)
	repeat with one in rendered
		set source to working & (one's container)
		set name_ to (one's stem) & ".pdf"
		set copied to destination & "/" & name_

		try
			do shell script "cp -f " & quoted form of source & " " & quoted form of copied
		on error errorMessage
			error "Unable to collect " & name_ & ": " & errorMessage number 1
		end try

		if not fileExists(copied) then
			error "The PDF for " & name_ & " was not written to " & destination & "." number 1
		end if
	end repeat
end collect

--
-- Close a document and stop listing it as one this run opened.
--
on closeOne(documentName)
	try
		tell application "Microsoft Word" to close document documentName saving no
	end try
end closeOne

on dropFrom(opened, documentName)
	set kept to {}

	repeat with one in opened
		if (one as text) is not documentName then
			set end of kept to (one as text)
		end if
	end repeat

	return kept
end dropFrom

--
-- Every document this run opened, in the order they were opened.
--
on closeAll(opened)
	repeat with documentName in (reverse of opened)
		closeOne(documentName)
	end repeat
end closeAll

--
-- Removes the directory this run made inside Word's container.
--
-- The container path rather than the bare name, because `rm -rf markdown-word-render-` in
-- whatever directory the caller happened to be in removes nothing at all and reports
-- success: a scratch directory left inside another application's container is a directory of
-- a stranger's pages, and this is the only place it goes away.
--
on cleanUp(scratch, stagedIn)
	if stagedIn is missing value then
		return
	end if

	try
		do shell script "rm -rf " & quoted form of stagedIn
	end try
end cleanUp

-- The file name of a document, with its extension kept.
on nameOf(documentPath)
	set AppleScript's text item delimiters to "/"
	set parts to text items of (documentPath as text)
	set AppleScript's text item delimiters to ""

	if (count of parts) is 0 then
		error "Not a path: " & documentPath number 1
	end if

	-- Indexed rather than `last part of`: Word's dictionary defines `part` as a class of its
	-- own, and this script is told to compile against that dictionary.
	return item (count of parts) of parts
end nameOf

-- The file name of a document without its extension, which is what Word calls the document
-- it opened.
--
-- Both the extensions a Word document has are stripped, because a template is one of
-- them: `report.dotx` is a document to render, and the PDF it produces is `report.pdf`.
--
-- `$name` rather than `$fileName`, which AppleScript parses as its own `file name` class and
-- then rejects wherever it appears.
on documentNameOf(name)
	set endings to {".docx", ".dotx"}

	repeat with one in endings
		if name ends with (one as text) then
			return text 1 thru -(length of (one as text) + 1) of name
		end if
	end repeat

	return name
end documentNameOf

-- `$candidate` rather than `$path`, which Word's dictionary defines as a class of its own and
-- this script has to compile against that dictionary.
on fileExists(candidate)
	try
		do shell script "test -s " & quoted form of candidate
		return true
	on error
		return false
	end try
end fileExists
