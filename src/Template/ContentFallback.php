<?php

declare(strict_types=1);

namespace MarkdownWord\Template;

/**
 * Where the rendered content goes when the template named no place for it.
 *
 * A template may or may not carry the control. The ones written for this library do,
 * because its generator puts one there; the ones a company made in Word to be filled
 * in by a person do not, because nobody making a letterhead inserts a content control.
 * Refusing the second kind would leave the library able to use only the templates it
 * wrote itself, so the question has to have an answer for every template.
 *
 * No other tool has one to copy. Every templating library surveyed — docxtemplater,
 * python-docx-template, docx-templates, and Word's own mail merge — leaves the
 * template exactly as it found it and inserts nothing, because in that model the
 * template's body *is* the output's body and only the marked region changes. The
 * result opens cleanly and contains none of the caller's content, with no signal at
 * all, which is the worst outcome available and not one worth copying.
 */
enum ContentFallback: string
{
    /**
     * Put the content at the end of the body, before the final `w:sectPr`.
     *
     * Before the `w:sectPr` rather than after it, and that is the whole of the
     * decision: a `w:sectPr` has to be the last child of a `w:body` or Word rejects
     * the file, and content placed after it is outside every section — so it would
     * carry none of the margins, header, footer or page numbering the template's last
     * section defines. Which is to say it would not look like the template at all,
     * which is the one thing rendering into it was for.
     */
    case Append = 'append';

    /**
     * Refuse, naming the controls the template does have.
     *
     * For a pipeline that depends on a real placeholder — one that would rather fail
     * loudly than emit a document with the body below the signature block.
     */
    case Error = 'error';
}