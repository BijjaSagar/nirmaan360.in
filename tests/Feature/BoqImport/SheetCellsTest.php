<?php

namespace Tests\Feature\BoqImport;

use App\Models\BoqItem;
use App\Models\CrmTenderBoqItem;
use Tests\Feature\Crm\BuildsCrm;

/**
 * What a quantity or rate cell of a contractor's sheet can hold besides a plain number: the number with
 * its unit, a lump sum, a rate-only mark, words, a spreadsheet error, or a formula with no saved value.
 * A quantity is never made up and never quietly read as 0.
 */
class SheetCellsTest extends BoqImportTestCase
{
    use BuildsCrm;

    public function test_a_quantity_written_with_its_unit_keeps_the_quantity_and_the_unit(): void
    {
        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, $this->sheet([
            ['Sr No', 'Description', 'Quantity', 'Rate', 'Amount'],
            [1, 'Sample brickwork', '12.5 Cum', 6100, 76250],
            [2, 'Sample plaster', '400 Sqm', 230, null],
            [3, 'Sample doors', '6 Nos', '8,000', null],
            [4, 'Sample grill', '250 Kg', 95, 23750],
            [5, 'Sample lump work', 'L.S.', 25000, null],
            [6, 'Sample fixing', '1 Job', 4000, null],
        ]));

        // Every line has its unit from its own quantity cell, so none is asked for and none is rate only.
        $this->assertSame([], $preview['rate_only']);
        $this->assertFalse($preview['needs_unit']);
        $this->assertSame([], $preview['errors']);

        $this->confirm($project)->assertSessionHas('success');

        $this->assertSame([
            1 => ['12.500', 'cum', '6100.00'], 2 => ['400.000', 'sqm', '230.00'], 3 => ['6.000', 'nos', '8000.00'],
            4 => ['250.000', 'kg', '95.00'], 5 => ['1.000', 'LS', '25000.00'], 6 => ['1.000', 'LS', '4000.00'],
        ], array_map(fn (BoqItem $line) => [$line->qty, $line->unit, $line->rate], $this->lines($project)));
    }

    public function test_an_unpriced_schedule_with_the_quantity_and_unit_in_one_cell(): void
    {
        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, $this->sheet([
            ['Sr No', 'Description', 'Qty', 'Amount'],
            [1, 'Sample doors', '12 Nos', null],
            [2, 'Sample flooring', '150 Sqm', null],
        ]));

        $this->assertSame([], $preview['errors']);
        $this->assertSame(2, $preview['line_count']);

        $this->confirm($project)->assertSessionHas('success');

        $lines = $this->lines($project);
        $this->assertSame(['12.000', 'nos', '0.00'], [$lines[1]->qty, $lines[1]->unit, $lines[1]->rate]);
        $this->assertSame(['150.000', 'sqm'], [$lines[2]->qty, $lines[2]->unit]);
    }

    public function test_the_tender_import_reads_a_quantity_written_with_its_unit(): void
    {
        $this->actingAs($this->owner);
        $tender = $this->makeTender($this->org);

        $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet([
            ['Sr No', 'Description', 'Unit', 'Quantity', 'Rate'],
            [1, 'Sample plaster', null, '400 Sqm', 230],
            [2, 'Sample shelving', 'nos', '10 Nos', 1500],
        ])])->assertSessionHasNoErrors();

        $lines = CrmTenderBoqItem::where('tender_id', $tender->id)->orderBy('sort')->get()->keyBy('code');
        $this->assertSame(['400.000', 'sqm', '230.00'], [$lines['1']->qty, $lines['1']->unit, $lines['1']->rate]);
        $this->assertSame('10.000', $lines['2']->qty);
    }

    public function test_words_or_an_error_in_a_quantity_cell_stop_that_row_until_it_is_left_out(): void
    {
        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, $this->sheet([
            ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
            [1, 'Sample work A', 'cum', 'approx 10', 100, 1000],
            [2, 'Sample work B', 'cum', '#REF!', 500, null],
            [3, 'Sample work C', 'cum', 5, 100, 500],
        ]));

        // The file gives these rows something that is not a quantity. They are not read as 0, or worked out from the amount.
        $this->assertSame([[2, 'qty'], [3, 'qty']], array_map(fn (array $error) => [$error['row'], $error['field']], $preview['errors']));
        $this->assertStringContainsString('approx 10', $preview['errors'][0]['message']);
        $this->assertStringContainsString('#REF!', $preview['errors'][1]['message']);
        $this->get(route('boq.sheet.preview', $project))->assertDontSee('Import these')->assertSee('Leave this row out');

        // Each row can be left out on the page, and put back.
        $this->choose($project, ['skip' => 2])->assertRedirect(route('boq.sheet.preview', $project));
        $this->choose($project, ['skip' => 3]);
        $preview = $this->preview($project);
        $this->assertSame([], $preview['errors']);
        $this->assertSame(['excluded', 'excluded'], [array_column($preview['others'], 'kind', 'row')[2], array_column($preview['others'], 'kind', 'row')[3]]);

        $this->choose($project, ['keep' => 3]);
        $this->assertCount(1, $this->preview($project)['errors']);
        $this->choose($project, ['skip' => 3]);

        $this->confirm($project)->assertSessionHas('success');
        $this->assertSame([3], array_keys($this->lines($project)));
    }

    public function test_a_rate_only_mark_beside_a_quantity_keeps_the_quantity(): void
    {
        $read = $this->classified([
            ['Sr. No.', 'Description of Item', 'Unit', 'Qty', 'Rate', 'Amount'],
            [1, 'Sample door fitting', 'nos', 10, 500, 'Rate only'],
            [2, 'Sample hinge', 'nos', 'QRO', 40, null],
        ]);

        $this->assertSame(['line', '10', '500'], [$read['lines'][2]['kind'], $read['lines'][2]['qty'], $read['lines'][2]['rate']]);
        $this->assertSame(['rate_only', '0'], [$read['lines'][3]['kind'], $read['lines'][3]['qty']]);
    }

    public function test_words_in_a_rate_cell_and_lines_with_no_rate_are_named_by_their_rows(): void
    {
        $read = $this->classified([
            ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
            [1, 'Sample work A', 'cum', 10, 100, 1000],
            [2, 'Sample work B', 'cum', 5, 'as per market', null],
            [3, 'Sample work C', 'cum', 4, null, null],
        ]);

        $notes = array_map(fn (array $note) => $note['row'].': '.$note['message'], $read['notes']);
        $this->assertContains('3: The rate cell says "as per market", which is not a number. The line comes in at rate 0; fill in the rate later.', $notes);
        $this->assertContains('0: 2 lines have a quantity but no rate (rows 3, 4). They are read at rate 0; fill in the rates later.', $notes);
    }

    public function test_a_formula_with_no_saved_value_is_refused_not_read_as_zero(): void
    {
        $rows = [
            ['Sr. No.', 'Description of Item', 'Unit', 'Qty', 'Rate', 'Amount'],
            [1, 'Sample brick masonry', 'cum', '=4*10', 5000, '=D2*E2'],
            [2, 'Sample plaster', 'sqm', 120, '=200+50', '=D3*E3'],
        ];
        $project = $this->makeProject($this->org);

        $preview = $this->staged($project, $this->workbook($rows, saveFormulaResults: false));

        $this->assertSame([[2, 'qty'], [3, 'rate']], array_map(fn (array $error) => [$error['row'], $error['field']], $preview['errors']));
        $this->assertSame('Quantity is a formula with no saved value. Paste it as a value and try again.', $preview['errors'][0]['message']);
        $this->post(route('boq.sheet.confirm', $project), ['version' => $preview['version']]);
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());

        // The tender import, which has no column check, refuses the same file.
        $this->actingAs($this->owner);
        $tender = $this->makeTender($this->org);
        $this->post(route('crm.boq.import', $tender), ['file' => $this->workbook($rows, saveFormulaResults: false)])->assertSessionHasErrors('file');
        $this->assertSame(0, CrmTenderBoqItem::count());
    }
}
