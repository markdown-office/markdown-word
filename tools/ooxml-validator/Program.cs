// Validates Office Open XML files against the schema, with Microsoft's own
// validator — the one the Open XML SDK Productivity Tool uses.
//
// The exit code is the answer: 0 when every file is clean, 1 when one of them is
// not, 2 when the tool was asked to do nothing it could do. A gate built on
// anything else would be a gate on this suite's own opinion of the format rather
// than on the format.

using DocumentFormat.OpenXml;
using DocumentFormat.OpenXml.Packaging;
using DocumentFormat.OpenXml.Validation;

if (args.Length == 0)
{
    Console.Error.WriteLine("usage: ooxml-validator [--version <FileFormatVersions>] <file>...");
    return 2;
}

// Office2019 rather than the oldest version the SDK knows: the SDK validates
// against the schema of the version it is given, and a document written for a
// modern Word should be read as a modern Word would read it. Every file this
// suite writes has been checked against every version the SDK knows, and the
// errors are the same at all of them.
var version = FileFormatVersions.Office2019;

for (var i = 0; i < args.Length; i++)
{
    if (args[i] == "--version" && i + 1 < args.Length)
    {
        if (!Enum.TryParse<FileFormatVersions>(args[++i], ignoreCase: true, out version))
        {
            Console.Error.WriteLine($"not a version the SDK knows: {args[i]}");
            return 2;
        }

        continue;
    }

    if (args[i].StartsWith("--"))
    {
        Console.Error.WriteLine($"unknown option: {args[i]}");
        return 2;
    }
}

var files = args.Where(argument => !argument.StartsWith("--")).ToList();

if (files.Count == 0)
{
    Console.Error.WriteLine("no files to validate");
    return 2;
}

var errors = 0;

foreach (var file in files)
{
    if (!File.Exists(file))
    {
        Console.Error.WriteLine($"{file}: no such file");
        errors++;
        continue;
    }

    var extension = Path.GetExtension(file).ToLowerInvariant();

    try
    {
        errors += extension switch
        {
            ".docx" or ".docm" or ".dotx" or ".dotm" => Validate(file, WordprocessingDocument.Open(file, false), version),
            ".pptx" or ".pptm" or ".potx" or ".potm" => Validate(file, PresentationDocument.Open(file, false), version),
            _ => Unknown(file),
        };
    }
    catch (Exception exception)
    {
        // A file the SDK cannot open is a file the gate cannot pass, so it counts
        // as an error rather than as a reason to give up.
        Console.Error.WriteLine($"{file}: could not be opened: {exception.Message}");
        errors++;
    }
}

Console.WriteLine(errors == 0
    ? $"{files.Count} file(s) checked against {version}: no errors"
    : $"{files.Count} file(s) checked against {version}: {errors} error(s)");

return errors == 0 ? 0 : 1;

static int Validate(string file, OpenXmlPackage package, FileFormatVersions version)
{
    var found = new OpenXmlValidator(version).Validate(package).ToList();

    Console.WriteLine($"{file}: {found.Count} error(s)");

    foreach (var error in found)
    {
        Console.WriteLine($"  [{error.ErrorType}] {error.Description}");
        Console.WriteLine($"    part={error.Part?.Uri} path={error.Path?.XPath}");
    }

    return found.Count;
}

static int Unknown(string file)
{
    Console.Error.WriteLine($"{file}: not a Word or PowerPoint file");
    return 1;
}
