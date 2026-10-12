<?php

declare(strict_types=1);

use MarkdownWord\Exception\FileNotWritable;
use MarkdownWord\Exception\MalformedDocument;
use MarkdownWord\Template\WordFields;
use MarkdownWord\Tests\Support\Scratch;
use MarkdownWord\Tests\Support\TemplateFactory;

/*
 * Filling a `MERGEFIELD`, and taking it out again.
 *
 * A `MERGEFIELD` is a Word construct rather than one of this library's: a person
 * presses Ctrl+F9 and Word stores a field element. Word writes it two ways and both
 * are in the wild — one element carrying its own cached result, or five runs with the
 * value between `separate` and `end` — so both are here, because a template that came
 * out of Word will mostly carry the second.
 *
 * Every test asserts the *result*: that the value is in the document, that the field
 * is not, and that the document is still a valid one. A field that was filled and
 * left behind is a placeholder somebody can still type into, and it reads as a
 * finished document.
 */

/** A package holding one field, written as Word would. */
function fieldsFixture(string $body, ?string $header = null): string
{
    $path = TemplateFactory::rawPackage('fields', [
        'word/document.xml' => TemplateFactory::part('document', '<w:body>' . $body . '</w:body>'),
    ]);

    if ($header !== null) {
        TemplateFactory::addRawPart($path, 'word/header1.xml', TemplateFactory::part('hdr', $header));
    }

    return $path;
}

/** The simple form: one element with its own cached result. */
function simpleField(string $name, string $result = '«client»'): string
{
    return sprintf(
        '<w:p><w:fldSimple w:instr=" MERGEFIELD %s \\* MERGEFORMAT ">'
        . '<w:r><w:rPr><w:b/></w:rPr><w:t>%s</w:t></w:r></w:fldSimple></w:p>',
        $name,
        $result,
    );
}

/** The five-run form, which is what Word writes most often. */
function complexField(string $name, string $result = '«client»'): string
{
    return sprintf(
        '<w:p>'
        . '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
        . '<w:r><w:instrText xml:space="preserve"> MERGEFIELD %s \\* MERGEFORMAT </w:instrText></w:r>'
        . '<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
        . '<w:r><w:rPr><w:i/></w:rPr><w:t>%s</w:t></w:r>'
        . '<w:r><w:fldChar w:fldCharType="end"/></w:r>'
        . '</w:p>',
        $name,
        $result,
    );
}

it('fills a field Word stores as one element', function () {
    $path = fieldsFixture(simpleField('client'));

    (new WordFields(['client' => 'Contoso Industrial']))->applyTo($path);

    $document = TemplateFactory::xmlOf($path, 'word/document.xml');

    expect($document)->toContain('Contoso Industrial');
});

it('fills a field Word stores as five runs', function () {
    $path = fieldsFixture(complexField('client'));

    (new WordFields(['client' => 'Contoso Industrial']))->applyTo($path);

    $document = TemplateFactory::xmlOf($path, 'word/document.xml');

    expect($document)->toContain('Contoso Industrial');
});

it('takes the field out, so nothing is left to refresh', function () {
    $path = fieldsFixture(simpleField('client') . complexField('client'));

    (new WordFields(['client' => 'Contoso Industrial']))->applyTo($path);

    $document = TemplateFactory::xmlOf($path, 'word/document.xml');

    // Both shapes, gone. A field left behind is a placeholder somebody can type into.
    expect($document)->not->toContain('fldSimple')
        ->and($document)->not->toContain('fldChar')
        ->and($document)->not->toContain('MERGEFIELD');
});

it('leaves no guillemet behind, which is what an unfilled field shows', function () {
    $path = fieldsFixture(simpleField('client'));

    (new WordFields(['client' => 'Contoso Industrial']))->applyTo($path);

    expect(TemplateFactory::xmlOf($path, 'word/document.xml'))->not->toContain('«');
});

it('keeps the formatting the field’s own result carried', function () {
    $path = fieldsFixture(simpleField('client') . complexField('client'));

    (new WordFields(['client' => 'Contoso Industrial']))->applyTo($path);

    $document = TemplateFactory::xmlOf($path, 'word/document.xml');

    // The simple form's result is bold and the complex one's is italic, and a value
    // that came out in neither face would read as a defect nobody was looking for.
    expect(substr_count($document, '<w:b/>'))->toBe(1)
        ->and(substr_count($document, '<w:i/>'))->toBe(1);
});

it('leaves the document valid XML', function () {
    $path = fieldsFixture(simpleField('client') . complexField('client'));

    (new WordFields(['client' => 'Bell & Roper <Ltd>']))->applyTo($path);

    expect(TemplateFactory::xmlIsValid($path))->toBeTrue();
});

it('escapes a value holding markup characters', function () {
    $path = fieldsFixture(simpleField('client'));

    (new WordFields(['client' => 'Bell & Roper <Ltd>']))->applyTo($path);

    $document = TemplateFactory::xmlOf($path, 'word/document.xml');

    // Written as markup this would be an instruction, not a value.
    expect($document)->toContain('Bell &amp; Roper &lt;Ltd&gt;');
});

it('matches a name whatever case Word stored it in', function () {
    // Word folds field names to upper case when it saves, so `client` and `CLIENT`
    // are the same field and one of them has to win rather than both.
    $path = fieldsFixture(simpleField('CLIENT'));

    (new WordFields(['client' => 'Contoso Industrial']))->applyTo($path);

    expect(TemplateFactory::xmlOf($path, 'word/document.xml'))->toContain('Contoso Industrial');
});

it('leaves a field nobody supplied a value for', function () {
    $path = fieldsFixture(simpleField('missing') . complexField('also-missing'));

    (new WordFields(['client' => 'Contoso']))->applyTo($path);

    $document = TemplateFactory::xmlOf($path, 'word/document.xml');

    // Both shapes survive, in both storage forms. A document may carry values for a
    // template it is not always rendered into, and a value that goes unused is not an
    // error — it is the field still being a field.
    expect(substr_count($document, 'MERGEFIELD'))->toBe(2)
        ->and($document)->toContain('missing')
        ->and($document)->toContain('also-missing');
});

it('leaves the document’s own fields alone', function () {
    // A document is full of PAGE, TOC and DATE. Filling PAGE from a frontmatter
    // block would produce a document whose page numbers were wrong, and nothing about
    // that would look like a mistake.
    $path = fieldsFixture(
        simpleField('client')
        . '<w:p><w:fldSimple w:instr=" PAGE "><w:r><w:t>1</w:t></w:r></w:fldSimple></w:p>'
        . '<w:p><w:fldSimple w:instr=" DATE \\@ &quot;d MMMM yyyy&quot; "><w:r><w:t>14 April</w:t></w:r></w:fldSimple></w:p>'
        . '<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r>'
        . '<w:r><w:instrText> TOC \\o "1-3" </w:instrText></w:r>'
        . '<w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>Contents</w:t></w:r>'
        . '<w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>',
    );

    (new WordFields([
        'client' => 'Contoso',
        'page' => '999',
        'date' => '1 January 1970',
        'toc' => 'nope',
    ]))->applyTo($path);

    $document = TemplateFactory::xmlOf($path, 'word/document.xml');

    expect($document)->toContain('Contoso')
        ->and($document)->toContain('PAGE')
        ->and($document)->toContain('TOC')
        ->not->toContain('999')
        ->not->toContain('1 January 1970');
});

it('fills a field in a header', function () {
    // A letterhead puts the client’s name in a header, and a value the template put
    // there is as much this library’s business as one in the body.
    $path = fieldsFixture('<w:p>body</w:p>', simpleField('client'));

    (new WordFields(['client' => 'Contoso Industrial']))->applyTo($path);

    expect(TemplateFactory::xmlOf($path, 'word/header1.xml'))->toContain('Contoso Industrial');
});

it('fills two fields of the same name in one part', function () {
    $path = fieldsFixture(simpleField('client') . '<w:p>between</w:p>' . complexField('client'));

    (new WordFields(['client' => 'Contoso Industrial']))->applyTo($path);

    $document = TemplateFactory::xmlOf($path, 'word/document.xml');

    expect(substr_count($document, 'Contoso Industrial'))->toBe(2);
});

it('leaves a field whose instruction is split across runs', function () {
    // Word does that when an instruction is long, and a lookup that reads only the
    // first `w:instrText` finds a name cut in half.
    $path = fieldsFixture(
        '<w:p>'
        . '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
        . '<w:r><w:instrText xml:space="preserve"> MERGE</w:instrText></w:r>'
        . '<w:r><w:instrText xml:space="preserve">FIELD client </w:instrText></w:r>'
        . '<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
        . '<w:r><w:t>«client»</w:t></w:r>'
        . '<w:r><w:fldChar w:fldCharType="end"/></w:r>'
        . '</w:p>',
    );

    (new WordFields(['client' => 'Contoso Industrial']))->applyTo($path);

    expect(TemplateFactory::xmlOf($path, 'word/document.xml'))->toContain('Contoso Industrial');
});

it('leaves a complex field whose result was never updated', function () {
    // Before anybody presses F9 there is nothing between `separate` and `end`, which
    // is what a template looks like. The value still belongs there.
    $path = fieldsFixture(
        '<w:p>'
        . '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
        . '<w:r><w:instrText xml:space="preserve"> MERGEFIELD client </w:instrText></w:r>'
        . '<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
        . '<w:r><w:fldChar w:fldCharType="end"/></w:r>'
        . '</w:p>',
    );

    (new WordFields(['client' => 'Contoso Industrial']))->applyTo($path);

    $document = TemplateFactory::xmlOf($path, 'word/document.xml');

    expect($document)->toContain('Contoso Industrial')->and($document)->not->toContain('fldChar');
});

it('reads a name stored in quotes', function () {
    // A field whose name has a space in it is stored quoted, and the quotes are part
    // of the storage rather than of the name.
    $path = fieldsFixture(simpleField('&quot;client name&quot;'));

    (new WordFields(['client name' => 'Contoso Industrial']))->applyTo($path);

    expect(TemplateFactory::xmlOf($path, 'word/document.xml'))->toContain('Contoso Industrial');
});

it('does nothing at all without values', function () {
    $path = fieldsFixture(simpleField('client'));
    $before = TemplateFactory::xmlOf($path, 'word/document.xml');

    (new WordFields([]))->applyTo($path);

    expect(TemplateFactory::xmlOf($path, 'word/document.xml'))->toBe($before);
});

it('says a document it cannot open is a file it could not write', function () {
    $path = Scratch::path('not-a-package', '.docx');
    file_put_contents($path, "# Markdown wearing the wrong extension\n");

    expect(fn () => (new WordFields(['client' => 'X']))->applyTo($path))
        ->toThrow(FileNotWritable::class, 'Unable to open');
});

it('says a part that is not XML is a broken document', function () {
    $path = fieldsFixture(simpleField('client'));
    TemplateFactory::addRawPart($path, 'word/document.xml', '<w:document><w:body>');

    expect(fn () => (new WordFields(['client' => 'X']))->applyTo($path))
        ->toThrow(MalformedDocument::class, 'word/document.xml');
});

it('carries a non-ASCII value through', function () {
    $path = fieldsFixture(simpleField('client'));

    (new WordFields(['client' => 'Ünïcödé Ltd · München']))->applyTo($path);

    expect(TemplateFactory::xmlOf($path, 'word/document.xml'))->toContain('Ünïcödé Ltd · München');
});

it('fills a value of zero rather than reading it as absent', function () {
    // An empty or absent value and a value that is `0` are different answers, and
    // `isset()` on the second would skip it.
    $path = fieldsFixture(simpleField('client'));

    (new WordFields(['client' => 0]))->applyTo($path);

    expect(TemplateFactory::xmlOf($path, 'word/document.xml'))->not->toContain('«client»');
});