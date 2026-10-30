# The OOXML validator

A console program that opens a `.docx` or a `.pptx` and reports what Microsoft's
Open XML SDK makes of it. It is the oracle the gate in this repository is measured
against, so it is not a dependency of the library — nothing the library ships
depends on it, and the library's own suite does not need it to run.

## Running it

```bash
dotnet run --project tools/ooxml-validator -- path/to/document.docx
dotnet run --project tools/ooxml-validator -- --version Office2007 path/to/document.docx
```

The exit code is the answer: `0` when every file is clean, `1` when one of them is
not, `2` when there was nothing to check. The errors are printed one per line with
the part they are in and the path to them, which is what a fix needs to be pointed
at.

## Why this and not the schema

The schema in ECMA-376 is the definition of the format, and a document that breaks
it is a document that is not what it claims to be. The SDK reads the same schema,
and it is the reader the Open XML SDK Productivity Tool — the tool Microsoft's own
documentation points at — uses. A gate built on it is a gate on the format rather
than on this suite's opinion of it.

The SDK is also stricter than the schema in a few places, and those places are
named in the pull requests rather than papered over: `w:tblHeader` and
`w:bidiVisual` carry an on/off value the SDK reads as `on` or `off` alone, where
the schema's `ST_OnOff` also allows `1` and `0`.

## Where it runs

The gate that uses it is a job in `.github/workflows/tests.yml`, which installs the
.NET SDK with `actions/setup-dotnet` before the suite runs. A machine without the
.NET SDK can still run the rest of the suite; the test that uses this tool skips
itself there and says why.
