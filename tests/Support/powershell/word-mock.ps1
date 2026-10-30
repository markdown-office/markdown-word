# A stand-in for Microsoft Word's COM automation.
#
# The tool under test drives Word through COM, and Word is an application that has to be
# installed, started, and driven by somebody sitting in front of it. A test cannot do
# that, so this stands in for it: a fake `New-Object` that hands back an object with the
# shape Word has, and a record of what the tool asked it to do.
#
# A function rather than a module, because a function shadows a cmdlet: the tool asks for
# `New-Object -ComObject Word.Application`, and this answers instead. Every other call
# goes to the real cmdlet, so `New-Object byte[] 64` inside the tool still makes an array
# rather than an application.
#
# The behaviour is chosen by an environment variable, so one file can be the renderer that
# works and the renderer that fails:
#
#   MARKDOWN_OFFICE_WORD_MOCK — `writes` (the default), `writes-nothing`,
#   `writes-half-a-pdf`, `refuses`.
#
# What happened is written to MARKDOWN_OFFICE_WORD_MOCK_LOG, one line per event, so a test
# can ask whether the tool closed what it opened and whether Word was let go.

class WordMock {
    # What the fake Word does when it is asked to export.
    static [string] $Behaviour = 'writes'

    # Where the record of what happened is written. Empty means nowhere.
    static [string] $Log = ''

    static [void] Note([string] $Line) {
        if ([string]::IsNullOrWhiteSpace([WordMock]::Log)) {
            return
        }

        [System.IO.File]::AppendAllText([WordMock]::Log, $Line + [Environment]::NewLine)
    }
}

class WordMockDocument {
    [string] $Path

    WordMockDocument([string] $path) {
        $this.Path = $path
        [WordMock]::Note('opened ' + $path)
    }

    [void] ExportAsFixedFormat([string] $target, [int] $format) {
        [WordMock]::Note('exported ' + $target)

        if ([WordMock]::Behaviour -eq 'refuses') {
            throw 'The mock Word refuses to export.'
        }

        if ([WordMock]::Behaviour -eq 'writes-nothing') {
            return
        }

        $body = "%PDF-1.4`n1 0 obj`n<< /Type /Catalog >>`nendobj`n"

        if ([WordMock]::Behaviour -eq 'writes-half-a-pdf') {
            $body = "%PDF-1.4`n1 0 obj`n"
        }
        else {
            $body = $body + "trailer`n%%EOF`n"
        }

        [System.IO.File]::WriteAllText($target, $body)
    }

    [void] Close([int] $save) {
        [WordMock]::Note('closed ' + $this.Path)
    }
}

class WordMockDocuments {
    [object[]] $Opened = @()

    [object] Open([string] $path, [bool] $confirm, [bool] $readOnly) {
        $document = [WordMockDocument]::new($path)
        $this.Opened += $document

        return $document
    }
}

class WordMockApplication {
    [bool] $Visible = $true
    [int] $DisplayAlerts = -1
    [object] $Documents

    WordMockApplication() {
        $this.Documents = [WordMockDocuments]::new()
    }

    [void] Quit() {
        [WordMock]::Note('quit')
    }
}

if (-not [string]::IsNullOrWhiteSpace($env:MARKDOWN_OFFICE_WORD_MOCK)) {
    [WordMock]::Behaviour = $env:MARKDOWN_OFFICE_WORD_MOCK
}

if (-not [string]::IsNullOrWhiteSpace($env:MARKDOWN_OFFICE_WORD_MOCK_LOG)) {
    [WordMock]::Log = $env:MARKDOWN_OFFICE_WORD_MOCK_LOG
}

# The shadowing of `New-Object`.
#
# An alias rather than a function of the same name: PowerShell resolves an alias before
# a cmdlet, so `New-Object` reaches the mock, and the mock's own name is not a built-in
# that anything else could be using.
#
# Only the COM call is answered here. Everything else is the real cmdlet, because the
# tool uses `New-Object` for something else as well — it makes the byte array it reads
# the end of a PDF into — and a mock that answered that call with an application would
# break a part of the tool that has nothing to do with Word.
function New-MockObject {
    param(
        [Parameter(Position = 0)]
        [string] $TypeName,

        [switch] $ComObject,

        [Parameter(ValueFromRemainingArguments = $true)]
        [object[]] $ArgumentList
    )

    if (-not $ComObject) {
        return Microsoft.PowerShell.Utility\New-Object -TypeName $TypeName -ArgumentList $ArgumentList
    }

    return [WordMockApplication]::new()
}

Set-Alias -Name New-Object -Value New-MockObject
