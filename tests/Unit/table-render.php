<?php

declare(strict_types=1);

use MarkdownWord\Configuration;
use MarkdownWord\Configuration\Styles;
use PhpOffice\PhpWord\Element\Table;

const SIMPLE = "| Name | Age |\n"
        . "| :---- | ---: |\n"
        . "| Ann   |    30 |\n"
        . "| Bob   |    40 |";

it('table becomes a single table element', function () {
    $elements = renderElements(SIMPLE . "\n");

    expect($elements)->toHaveCount(1);
    expect($elements[0])->toBeInstanceOf(Table::class);
});

it('rows and cells match the source', function () {
    $table = renderElements(SIMPLE . "\n")[0];

    expect($table->getRows())->toHaveCount(3);
    expect($table->getRows()[0]->getCells())->toHaveCount(2);
    expect($table->getRows()[2]->getCells())->toHaveCount(2);
});

it('cell content is preserved', function () {
    expect(renderText(SIMPLE . "\n"))->toBe("Name\tAge\nAnn\t30\nBob\t40");
});

it('header row is bold by default', function () {
    $runs = headerRuns(0);

    expect($runs[0]['font']['bold'] ?? false)->toBeTrue();
});

it('body cells are not bold', function () {
    $runs = cellRuns(1, 0);

    expect($runs[0]['font'] ?? null)->toBeNull();
});

it('header boldness can be turned off', function () {
    $config = Configuration::create()->withOptions(['tableHeaderBold' => false]);

    expect(headerRuns(0, $config)[0]['font'] ?? null)->toBeNull();
});

it('column alignment comes from the delimiter row', function () {
    expect(cellAlignment(1, 0))->toBe('left');
    expect(cellAlignment(1, 1))->toBe('right');
});

it('centred columns are recognised', function () {
    $table = renderElements("| a | b |\n| :---: | ---: |\n| 1 | 2 |\n")[0];
    $paragraph = $table->getRows()[1]->getCells()[0]->getElements()[0];

    expect($paragraph->getParagraphStyle()->getAlignment())->toBe('center');
});

it('inline formatting works inside cells', function () {
    $table = renderElements("| a | b |\n| --- | --- |\n| **bold** | `code` |\n")[0];
    $runs = renderRuns($table->getRows()[1]->getCells()[0]->getElements()[0]);

    expect($runs[0]['font']['bold'] ?? false)->toBeTrue();
});

it('table with a single body row still renders', function () {
    $table = renderElements("| a | b |\n| --- | --- |\n| 1 | 2 |\n")[0];

    expect($table->getRows())->toHaveCount(2);
});

it('empty cells render', function () {
    $table = renderElements("| a | b |\n| --- | --- |\n| 1 |   |\n")[0];

    expect($table->getRows()[1]->getCells())->toHaveCount(2);
});

it('table borders are drawn by default', function () {
    $table = renderElements(SIMPLE . "\n")[0];

    expect(array_values(array_unique($table->getStyle()->getBorderColor())))->toBe(['000000']);
});

it('table borders can be disabled', function () {
    $config = Configuration::create()->withOptions(['tableBorders' => false]);
    $table = renderElements(SIMPLE . "\n", $config)[0];

    expect(array_values(array_unique($table->getStyle()->getBorderColor())))->toBe([null]);
});

it('table can use a named word table style', function () {
    $config = Configuration::create()->withStyles([
        Styles::TABLE => 'Grid Table 4 - Accent 1',
    ]);

    $table = renderElements(SIMPLE . "\n", $config)[0];

    // A named style is kept as a name so the template's definition is used.
    expect($table->getStyle())->toBe('Grid Table 4 - Accent 1');
});

it('keeps the ruled table when a definition names only the border colour', function () {
    // Found by rendering the shipped themes, not by reading the markup: a theme saying
    // `table: {borderColor: …}` is saying "the usual table, in another colour", and
    // `borderColor` sets the colour of rules that have to be asked for separately. Handed
    // to PHPWord on its own it produced a table with no rules at all, because the
    // definition replaced the default rather than merging over it.
    $config = Configuration::create()->withStyles([
        Styles::TABLE => ['borderColor' => '2E5395'],
    ]);

    $table = renderElements(SIMPLE . "\n", $config)[0];
    $style = $table->getStyle();

    expect($style->getBorderColor())->toBe(array_fill(0, 6, '2E5395'))
        ->and($style->getBorderSize())->toBe(array_fill(0, 6, 6))
        ->and($style->getCellMargin())->toBe(array_fill(0, 4, 80));
});

it('cell style applies to every cell', function () {
    $config = Configuration::create()->withStyles([
        Styles::TABLE_CELL => ['alignment' => ['horizontal' => 'center']],
    ]);

    $table = renderElements("| a |\n| --- |\n| 1 |\n", $config)[0];
    $paragraph = $table->getRows()[1]->getCells()[0]->getElements()[0];

    expect($paragraph->getParagraphStyle()->getAlignment())->toBe('center');
});

it('lets cell text wrap', function () {
    // PHPWord's cell style defaults `noWrap` to true, so a cell that says nothing
    // about wrapping writes `<w:noWrap/>` — Word's "Wrap text" option, unchecked.
    // Word honours it, lays the cell out on one line and widens the column to
    // fit, so the table runs off the page; LibreOffice treats it as a hint and
    // looks fine, which is what made this a Word-only fault.
    $table = renderElements(SIMPLE . "\n")[0];

    foreach ($table->getRows() as $row) {
        foreach ($row->getCells() as $cell) {
            expect($cell->getStyle()->getNoWrap())->toBeFalse();
        }
    }
});

it('a cell style that asks for no wrapping still gets it', function () {
    $config = Configuration::create()->withStyles([
        Styles::TABLE_CELL => ['noWrap' => true],
    ]);

    $table = renderElements(SIMPLE . "\n", $config)[0];

    expect($table->getRows()[1]->getCells()[0]->getStyle()->getNoWrap())->toBeTrue();
});

it('every cell is given a width', function () {
    // An empty `w:gridCol` and a cell with no `w:tcW` is the whole of what a
    // viewer has to work from, and Word's answer without them is a column one or
    // two characters wide — every column, not the wide ones.
    $table = renderElements(SIMPLE . "\n")[0];

    foreach ($table->getRows() as $row) {
        foreach ($row->getCells() as $cell) {
            expect($cell->getWidth())->toBeInt()->toBeGreaterThan(0);
        }
    }
});

it('the column widths fill the text column', function () {
    // A4 with an inch of margin, which is what a `PhpWord` document gets and so
    // what a table with no page setup of its own is measured against.
    expect(array_sum(headerCellWidths(SIMPLE)))->toBe(9026);
});

it('a wider column gets more of the width', function () {
    $widths = headerCellWidths("| Id | Notes |\n| --- | --- |\n| a | A cell holding a whole sentence that has to wrap. |\n");

    expect($widths[1])->toBeGreaterThan($widths[0]);
});

it('a cell style that names its own unit keeps its own widths', function () {
    // `w:tcW` in twips labelled as a percentage is not a narrower table but an
    // unreadable one, so nothing is imposed on a caller who has measured itself.
    $config = Configuration::create()->withStyles([
        Styles::TABLE_CELL => ['unit' => 'pct'],
    ]);

    $table = renderElements(SIMPLE . "\n", $config)[0];

    expect($table->getRows()[0]->getCells()[0]->getWidth())->toBeNull();
});

    /**
     * @return list<array{text: string, font: array<string, mixed>|string|null}>
     */
function headerRuns(int $column, ?Configuration $config = null) : array
{
        return cellRuns(0, $column, $config);
    }

    /**
     * @return list<array{text: string, font: array<string, mixed>|string|null}>
     */
function cellRuns(int $row, int $column, ?Configuration $config = null) : array
{
        $table = renderElements(SIMPLE . "\n", $config)[0];
        $paragraph = $table->getRows()[$row]->getCells()[$column]->getElements()[0];

        return renderRuns($paragraph);
    }

function cellAlignment(int $row, int $column) : ?string
{
        $table = renderElements(SIMPLE . "\n")[0];
        $paragraph = $table->getRows()[$row]->getCells()[$column]->getElements()[0];

        return $paragraph->getParagraphStyle()?->getAlignment();
    }

/**
 * The width of every cell in a table's first row — the row PHPWord takes the
 * column widths from, and the widest one it finds.
 *
 * @return list<int|null>
 */
function headerCellWidths(string $markdown) : array
{
    $table = renderElements($markdown . "\n")[0];

    return array_map(
        static fn ($cell): ?int => $cell->getWidth(),
        $table->getRows()[0]->getCells(),
    );
}
