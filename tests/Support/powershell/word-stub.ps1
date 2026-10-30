# A stand-in for the tool that drives Word, used to see what the dispatcher asks for.
#
#   MARKDOWN_OFFICE_WORD_STUB_LOG — where the arguments are written, one per line.
#
# The dispatcher runs this instead of the real tool, so that a test can read the
# arguments the tool would have been given — on a machine where Word is not installed,
# and on a machine where it is, without starting an application either way.
#
# It writes a PDF the way the tool does, so that the dispatcher believes the render
# happened and reports a file rather than a failure.

[CmdletBinding(PositionalBinding = $false)]
param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $Arguments,

    [Parameter()]
    [string] $Out
)

if (-not [string]::IsNullOrWhiteSpace($env:MARKDOWN_OFFICE_WORD_STUB_LOG)) {
    $lines = @($Arguments)

    if (-not [string]::IsNullOrWhiteSpace($Out)) {
        $lines += '--out=' + $Out
    }

    [System.IO.File]::WriteAllLines($env:MARKDOWN_OFFICE_WORD_STUB_LOG, [string[]] $lines)
}

if ([string]::IsNullOrWhiteSpace($Out)) {
    [Console]::Error.WriteLine('No output directory.')
    exit 1
}

foreach ($argument in $Arguments) {
    $stem = [System.IO.Path]::GetFileNameWithoutExtension($argument)
    $target = Join-Path $Out ($stem + '.pdf')

    [System.IO.File]::WriteAllText($target, "%PDF-1.4`ntrailer`n%%EOF`n")
}

exit 0
