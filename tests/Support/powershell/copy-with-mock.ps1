# Writes a copy of a tool with a stand-in for Word loaded into it.
#
#   pwsh -File copy-with-mock.ps1 -Tool <tool.ps1> -Mock <mock.ps1> -Target <copy.ps1>
#
# The copy is the tool with one line added: the line that loads the stand-in. The line
# goes after the tool's `param` block, because PowerShell requires the `param` block to be
# the first thing in a script — a file that loads something before it declares its
# parameters does not run at all.
#
# The place the line goes is found by reading the tool's syntax tree rather than by
# looking for a line that looks like the end of a `param` block: a tool whose `param`
# block is written differently is still a tool, and a copy made by guessing would be a
# copy of something else.
#
# The copy is what the test runs, so that the tool's own argument handling is exercised
# the way a caller exercises it. A stand-in loaded from a wrapper script instead would
# change how the arguments arrive, and a test that changes the thing it is testing is
# not a test of it.

param(
    [Parameter(Mandatory = $true)]
    [string] $Tool,

    [Parameter(Mandatory = $true)]
    [string] $Mock,

    [Parameter(Mandatory = $true)]
    [string] $Target
)

$errors = $null
$tokens = $null

$ast = [System.Management.Automation.Language.Parser]::ParseFile($Tool, [ref] $tokens, [ref] $errors)

if ($errors.Count -gt 0) {
    foreach ($error in $errors) {
        [Console]::Error.WriteLine("line {0}: {1}" -f $error.Extent.StartLineNumber, $error.Message)
    }

    exit 1
}

$source = [System.IO.File]::ReadAllText($Tool)

# A single-quoted string, with the quote inside it doubled, because a path may hold one.
$quoted = "'" + $Mock.Replace("'", "''") + "'"

$at = $ast.ParamBlock.Extent.EndOffset

# The line is added without a newline of its own, so that taking it out again gives
# back the file that went in: the copy is the tool, and nothing else.
[System.IO.File]::WriteAllText(
    $Target,
    $source.Substring(0, $at) + "`n. " + $quoted + $source.Substring($at)
)

exit 0
