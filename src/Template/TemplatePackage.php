<?php

declare(strict_types=1);

namespace MarkdownWord\Template;

use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\WordPackage;
use Throwable;
use ZipArchive;

/**
 * The template a document is rendered into, ready to be rendered into.
 *
 * A `.dotx` and a `.docx` are the same archive with one content type in
 * `[Content_Types].xml` different, and that one line decides two things at once.
 *
 * It decides how a reader treats the file. Word opens a package whose body is typed
 * as a template by creating a *new* document from it rather than opening it, so a
 * filled-in template that keeps its own type is not a document, whatever the result
 * happens to be called on disk.
 *
 * It also decides where the body is, because the body is whichever part the
 * declarations name as the main one. PHPWord's lookup recognises a main part typed
 * as a *document*, so on a template it finds nothing and falls back to
 * `word/document.xml` — which is to say it guesses, and the guess is a part nobody
 * reads. A template whose body is `word/document22.xml` would have the rendered
 * content written there while the real body kept its placeholders: the Markdown
 * gone, and nothing saying so.
 *
 * So the type is settled on the way in, where one rewrite answers both. A template
 * is copied to the temporary directory with its body declared as a document's, and
 * that copy is what {@see self::path()} hands to the template processor. A package
 * that already is a document is passed straight through — untouched, uncopied, and
 * with nothing of ours to clean up afterwards.
 */
final class TemplatePackage
{
    /**
     * @param string $template The file to hand to the template processor: the
     *        caller's own file, or a normalised copy of it.
     * @param string $mainPart The part holding the body, as a path inside the archive.
     * @param string|null $temporary The copy to delete, or null when the caller's own
     *        file was passed through and there is nothing of ours to remove.
     */
    private function __construct(
        private readonly string $template,
        private readonly string $mainPart,
        private readonly ?string $temporary,
    ) {
    }

    /**
     * A template ready to be rendered into.
     *
     * The copy outlives a single render, because {@see MarkdownTemplate} is rendered
     * into repeatedly and every one of those renders needs it. It is removed when
     * this object goes.
     *
     * @throws FileNotWritable when the normalised copy cannot be staged or written.
     */
    public static function of(string $template): self
    {
        $zip = new ZipArchive();

        if ($zip->open($template) !== true) {
            throw new FileNotWritable(sprintf(
                'The template "%s" could not be opened. A Word template is a zip archive.',
                $template,
            ));
        }

        try {
            $mainPart = WordPackage::bodyPart($zip);
            $isTemplate = WordPackage::isTemplate($zip);
            $declared = WordPackage::contentTypesOf($zip);
        } finally {
            $zip->close();
        }

        if (!$isTemplate) {
            return new self($template, $mainPart, null);
        }

        return new self($rewritten = self::rewrite($template, $declared), $mainPart, $rewritten);
    }

    /**
     * The part holding the body of a template, without copying it.
     *
     * For the callers that only need to know where to read — the command line
     * checking that a region is in the template — and so must not be handed a copy
     * they would then have to clean up.
     *
     * @see WordPackage::mainPartOf()
     */
    public static function mainPartOf(string $template): string
    {
        return WordPackage::mainPartOf($template);
    }

    /** The file to render into: the caller's own, or a normalised copy of it. */
    public function path(): string
    {
        return $this->template;
    }

    /** The part holding the body, as a path inside the archive. */
    public function mainPart(): string
    {
        return $this->mainPart;
    }

    /** Whether the template given was a `.dotx`, and so was normalised. */
    public function isTemplate(): bool
    {
        return $this->temporary !== null;
    }

    public function __destruct()
    {
        if ($this->temporary !== null) {
            @unlink($this->temporary);
        }
    }

    /**
     * A copy of the template with its body declared as a document's.
     *
     * Copied rather than edited in place, because the template belongs to whoever
     * handed it over and the caller may hold a hard link to it.
     *
     * @throws FileNotWritable when the copy cannot be staged or written.
     */
    private static function rewrite(string $template, string $declared): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'mdword_tpl_');

        if ($temporary === false) {
            throw new FileNotWritable(sprintf(
                'Unable to create a temporary file for the template in "%s".',
                sys_get_temp_dir(),
            ));
        }

        try {
            if (!copy($template, $temporary)) {
                throw new FileNotWritable(sprintf('Unable to copy the template "%s".', $template));
            }

            $zip = new ZipArchive();

            if ($zip->open($temporary) !== true) {
                throw new FileNotWritable(sprintf(
                    'The template "%s" could not be opened. A Word template is a zip archive.',
                    $template,
                ));
            }

            try {
                $zip->deleteName(WordPackage::CONTENT_TYPES);
                $zip->addFromString(
                    WordPackage::CONTENT_TYPES,
                    str_replace(WordPackage::TEMPLATE_MAIN, WordPackage::DOCUMENT_MAIN, $declared),
                );
            } finally {
                $zip->close();
            }
        } catch (Throwable $failure) {
            @unlink($temporary);

            throw $failure;
        }

        return $temporary;
    }
}