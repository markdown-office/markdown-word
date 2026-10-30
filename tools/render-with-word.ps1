# Opens each document in Microsoft Word and exports it to PDF.
#
#   powershell -File tools/render-with-word.ps1 <document.docx> [...] --out <directory>
#
# Word rather than LibreOffice because Word is the only application that will tell the truth
# about whether a document opens. LibreOffice is a different implementation of the same
# format and renders what it believes the format means: a Word document laid out by
# LibreOffice has its inline pictures in the wrong place often enough that the layout
# cannot be trusted, and a layout that cannot be trusted is no use to a caller who is
# checking whether the text fits.
#
# This is the Windows half of a pair. The macOS half is `tools/render-with-word.applescript`
# and the two are the same tool on two platforms: the same arguments, the same output
# names, and the same promise — a run that produced no file says so and exits non-zero, so
# a caller reading only the exit status learns the render did not happen.
#
# Windows Word is driven through COM rather than through AppleScript, because Windows Word
# has no AppleScript. `ExportAsFixedFormat` with `wdExportFormatPDF` is the export that
# writes a PDF without changing the document the caller opened — `SaveAs` would leave the
# document pointing at the PDF instead of at itself.
#
# The document is opened where it is, rather than staged under a name of this run's own:
# Word's COM automation opens a document read-only and writes the PDF wherever it is told
# to, so there is no sandbox to stay inside and no name to keep unique.
#
# A validator that cannot run must never report success. Every step here either produces
# the file it promised or raises, and a raised error exits non-zero.

[CmdletBinding(PositionalBinding = $false)]
param(
    # The documents to render.
    #
    # Positional binding is off, so that the first document cannot be mistaken for
    # something else. Everything that is not a named parameter arrives here.
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $Arguments,

    # Where the PDFs are written.
    #
    # A parameter of its own rather than a flag looked for in the argument list,
    # because PowerShell reads a leading dash as the beginning of a parameter name:
    # `--out` is the parameter `out`, which is ambiguous between `-OutBuffer` and
    # `-OutVariable`, and a script asked for `-out` refuses to start at all before it
    # has read a single argument. Naming it here makes the match exact, and an exact
    # match beats a prefix.
    [Parameter()]
    [string] $Out
)

$ErrorActionPreference = 'Stop'

# The export format Word calls a PDF. `wdExportFormatPDF` in the Word object model.
$WdExportFormatPDF = 17

# How the document is closed: `wdDoNotSaveChanges`.
$WdDoNotSaveChanges = 0

# How long to wait for a PDF that is being written, in seconds.
$SecondsToSettle = 60

$documents = @()
$outDirectory = $Out
$expectingDirectory = $false

# The documents are what is left once the flags are taken out.
#
# `--out` is looked for here as well as in the parameter above, because the two ways a
# script can be given its arguments read them differently: a script started with
# `powershell -File` has `--out` bound to a parameter by PowerShell itself, and a script
# run from inside PowerShell has it as an ordinary word in the argument list. The
# parameter is the one that works in both, and this is the one that works when the
# argument list is read by hand.
foreach ($argument in $Arguments) {
    if ($expectingDirectory) {
        $outDirectory = $argument
        $expectingDirectory = $false
        continue
    }

    if ($argument -eq '--out') {
        $outDirectory = $null
        $expectingDirectory = $true
        continue
    }

    $documents += $argument
}

if ($documents.Count -eq 0) {
    Write-Error 'Nothing to render. Pass one or more .docx or .dotx paths, then --out <directory>.'
    exit 1
}

if ([string]::IsNullOrWhiteSpace($outDirectory)) {
    Write-Error 'No output directory. Pass --out <directory>.'
    exit 1
}

# The output directory is created before Word is started at all, so a path that cannot be
# written costs nothing and starts no application.
if (-not (Test-Path -LiteralPath $outDirectory)) {
    New-Item -ItemType Directory -Path $outDirectory -Force | Out-Null
}

$word = $null
$opened = @()

# Whether a PDF has been written whole.
#
# The marker is at the end of a PDF, and a PDF is large enough that reading all of it to look
# at its last few bytes is waste.
#
# Defined here rather than at the end of the file because PowerShell runs a script from the
# top down and a function is not defined until the line that defines it is reached: a
# function used above its own definition is not a function, it is a name the script does
# not know, and the run fails with a message about the name rather than about the PDF.
function Test-PdfComplete {
    param([string] $Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        return $false
    }

    $length = (Get-Item -LiteralPath $Path).Length

    if ($length -eq 0) {
        return $false
    }

    $stream = [System.IO.File]::OpenRead($Path)

    try {
        $read = [Math]::Min(64, $length)
        $stream.Seek(-$read, [System.IO.SeekOrigin]::End) | Out-Null

        $buffer = New-Object byte[] $read
        $stream.Read($buffer, 0, $read) | Out-Null

        return [System.Text.Encoding]::ASCII.GetString($buffer).Contains('%%EOF')
    }
    finally {
        $stream.Dispose()
    }
}

try {
    $word = New-Object -ComObject Word.Application
    $word.Visible = $false
    $word.DisplayAlerts = 0

    foreach ($path in $documents) {
        $name = [System.IO.Path]::GetFileName($path)
        $stem = [System.IO.Path]::GetFileNameWithoutExtension($path)
        $target = Join-Path $outDirectory ($stem + '.pdf')

        $document = $word.Documents.Open($path, $false, $true)
        $opened += $document

        try {
            $document.ExportAsFixedFormat($target, $WdExportFormatPDF)
        }
        catch {
            throw "Word would not export $name as PDF: $($_.Exception.Message)"
        }

        # A PDF is written lazily, so a file that exists is a file that may still be being
        # written. The export is finished when the file carries the end-of-file marker every
        # PDF ends with, and only then is it copied out.
        if (-not (Test-Path -LiteralPath $target)) {
            throw "Word reported success and wrote no PDF for $name."
        }

        if (-not (Test-PdfComplete -Path $target)) {
            throw "Word reported success and wrote no finished PDF for $name."
        }
    }
}
catch {
    # The documents this run opened are closed before anything else, whatever went wrong: a
    # document window left behind is a window the person using the machine finds open on a
    # document they did not ask for. Only documents this run opened are touched, so a
    # document somebody had open before is still open afterwards.
    foreach ($document in $opened) {
        try {
            $document.Close($WdDoNotSaveChanges)
        }
        catch {
            # A document that will not close is not a reason to leave the rest open, and the
            # failure is said rather than swallowed.
            Write-Verbose "A document did not close: $($_.Exception.Message)"
        }
    }

    if ($null -ne $word) {
        try {
            $word.Quit()
        }
        catch {
            Write-Verbose "Word did not quit: $($_.Exception.Message)"
        }
    }

    Write-Error $_.Exception.Message
    exit 1
}

foreach ($document in $opened) {
    try {
        $document.Close($WdDoNotSaveChanges)
    }
    catch {
        Write-Verbose "A document did not close: $($_.Exception.Message)"
    }
}

if ($null -ne $word) {
    $word.Quit()
}

foreach ($path in $documents) {
    [System.IO.Path]::GetFileNameWithoutExtension($path) + '.pdf'
}

exit 0
