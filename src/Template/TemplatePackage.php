<?php

declare(strict_types=1);

namespace MarkdownWord\Template;

use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Exception\UnreadableTemplate;
use MarkdownWord\WordPackage;
use ZipArchive;

/**
 * Settles what kind of package a template is, before anything is rendered into it.
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
 * So the type is settled on the way in, where one rewrite answers both. A package
 * that already is a document is passed straight through — untouched and uncopied.
 */
final class TemplatePackage
{
    /**
     * @param string $template The caller's own file, or a normalised copy of it.
     * @param string|null $temporary The copy to delete, or null when the caller's own
     *        file was passed through and there is nothing of ours to remove.
     */
    private function __construct(
        private readonly string $template,
        private readonly ?string $temporary,
    ) {
    }

    /**
     * The copy outlives a single render, because {@see MarkdownTemplate} is rendered
     * into repeatedly and every one of those renders needs it.
     *
     * @throws FileNotWritable when the template cannot be opened or the normalised
     *         copy cannot be staged or written.
     * @throws UnreadableTemplate when the template holds more than it may.
     */
    public static function of(string $template, TemplateOptions $options = new TemplateOptions()): self
    {
        $zip = new ZipArchive();

        // `CHECKCONS` refuses an archive whose central directory disagrees with its
        // own contents. The read path opens a document the same way.
        if ($zip->open($template, ZipArchive::CHECKCONS) !== true) {
            throw new FileNotWritable(sprintf(
                'The template "%s" could not be opened. A Word template is a .docx or .dotx file, which is a zip archive.',
                $template,
            ));
        }

        try {
            self::check($zip, $template, $options);

            $isTemplate = WordPackage::isTemplate($zip);
            $declared = WordPackage::contentTypesOf($zip);
        } finally {
            $zip->close();
        }

        if (!$isTemplate) {
            return new self($template, null);
        }

        return new self($rewritten = self::rewrite($template, $declared), $rewritten);
    }

    public function path(): string
    {
        return $this->template;
    }

    /** Whether a copy was made, which is to say the template given was a `.dotx`. */
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
     * What the archive may hold, before a part of it is read.
     *
     * The sizes are the ones the central directory declares, which cost one lookup
     * each and no decompression. Every entry is measured, and not only the ones this
     * library reads: the template is opened again by PHPWord's own processor, and a
     * part that is read there is read whole.
     *
     * @throws UnreadableTemplate When the archive holds more parts than are allowed,
     *         or a part that declares to be larger than is allowed.
     */
    private static function check(ZipArchive $zip, string $template, TemplateOptions $options): void
    {
        if ($zip->numFiles > $options->maxEntries) {
            throw new UnreadableTemplate(sprintf(
                'The template "%s" has %d parts in it, which is more entries than the %d allowed.',
                $template,
                $zip->numFiles,
                $options->maxEntries,
            ));
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;

            if ($size > $options->maxPartBytes) {
                throw new UnreadableTemplate(sprintf(
                    'The template "%s" holds a part of %d bytes, which is larger than the %d allowed.',
                    $template,
                    $size,
                    $options->maxPartBytes,
                ));
            }
        }
    }

    /**
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

        // Each step that can fail removes the file it made before saying so.
        if (!copy($template, $temporary)) {
            @unlink($temporary);

            throw new FileNotWritable(sprintf('Unable to copy the template "%s".', $template));
        }

        $zip = new ZipArchive();

        if ($zip->open($temporary, ZipArchive::CHECKCONS) !== true) {
            @unlink($temporary);

            throw new FileNotWritable(sprintf(
                'The template "%s" could not be opened. A Word template is a .docx or .dotx file, which is a zip archive.',
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

        return $temporary;
    }
}