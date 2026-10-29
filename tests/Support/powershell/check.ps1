# Checks a PowerShell script, and says what is wrong with it.
#
#   pwsh -File check.ps1 -Script <script.ps1> -Check syntax
#   pwsh -File check.ps1 -Script <script.ps1> -Check order
#
# Two checks, because they answer two different questions.
#
# `syntax` asks whether PowerShell can read the script at all. A script that cannot be
# read is a script that does nothing, and the message says where it stopped.
#
# `order` asks whether the script calls a function before it has defined it. PowerShell
# runs a script from the top down, and a function is not defined until the line that
# defines it has been reached — so a function used above its own definition is not a
# function, it is a name the script has not met yet, and the run fails with a message
# about the name rather than about the work the function was for.
#
# Only calls that happen at the top level of the script are checked. A call inside a
# function of the script's own is fine, because that function is not called until the
# script has been read past the definition of the one it needs.
#
# Every problem is written to the error stream, one per line, and the exit status says
# whether there were any.

param(
    [Parameter(Mandatory = $true)]
    [string] $Script,

    [Parameter(Mandatory = $true)]
    [ValidateSet('syntax', 'order')]
    [string] $Check
)

$errors = $null
$tokens = $null

$ast = [System.Management.Automation.Language.Parser]::ParseFile($Script, [ref] $tokens, [ref] $errors)

if ($errors.Count -gt 0) {
    foreach ($error in $errors) {
        [Console]::Error.WriteLine("line {0}: {1}" -f $error.Extent.StartLineNumber, $error.Message)
    }

    exit 1
}

if ($Check -eq 'syntax') {
    exit 0
}

# Where each function of the script's own is defined: the end of its definition.
$definitions = @{}

foreach ($function in $ast.FindAll({ param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] }, $true)) {
    if (-not $definitions.ContainsKey($function.Name)) {
        $definitions[$function.Name] = $function.Extent.EndOffset
        continue
    }

    # A name defined twice: the first definition is the one that matters, because it is
    # the one that is in place first.
    if ($function.Extent.EndOffset -lt $definitions[$function.Name]) {
        $definitions[$function.Name] = $function.Extent.EndOffset
    }
}

$problems = @()

foreach ($statement in $ast.EndBlock.Statements) {
    if ($statement -is [System.Management.Automation.Language.FunctionDefinitionAst]) {
        continue
    }

    foreach ($command in $statement.FindAll({ param($node) $node -is [System.Management.Automation.Language.CommandAst] }, $true)) {
        $name = $command.GetCommandName()

        if ($null -eq $name -or -not $definitions.ContainsKey($name)) {
            continue
        }

        # A call inside a function of the script's own is not a call the script makes
        # while it is being read.
        $nested = $false
        $above = $command

        while ($null -ne $above -and $above -ne $statement) {
            $above = $above.Parent

            if ($above -is [System.Management.Automation.Language.FunctionDefinitionAst]) {
                $nested = $true
                break
            }
        }

        if ($nested) {
            continue
        }

        if ($command.Extent.StartOffset -lt $definitions[$name]) {
            $problems += ("line {0}: '{1}' is called before it is defined" -f $command.Extent.StartLineNumber, $name)
        }
    }
}

foreach ($problem in $problems) {
    [Console]::Error.WriteLine($problem)
}

if ($problems.Count -gt 0) {
    exit 1
}

exit 0
