<?php

namespace Tests\Feature\BoqImport;

use App\Models\BoqItem;
use App\Models\CrmTenderBoqItem;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Crm\BuildsCrm;

/**
 * A sheet in its own layout often leaves a figure out: no rate column, a blank quantity, a rate-only line. The reader
 * reads it as 0, which is right for a new line. A line that updates an item already there keeps the item's figure
 * instead, as the CSV import keeps the rates when its file has no rate column, and a quantity is held to the work
 * measured and billed as it is when the line is edited by hand. The tender BOQ, which has no preview, does the same.
 */
class KeptFiguresTest extends BoqImportTestCase
{
    use BuildsCrm;

    private function item(Project $project, string $code, string $description, string $unit, string $qty, string $rate): BoqItem
    {
        return BoqItem::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'code' => $code, 'description' => $description, 'unit' => $unit, 'qty' => $qty, 'rate' => $rate,
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> Item code => quantity and rate, by code. */
    private function figures(Project $project): array
    {
        $figures = array_map(fn (BoqItem $item) => [$item->qty, $item->rate], $this->lines($project));
        ksort($figures);

        return $figures;
    }

    public function test_a_sheet_with_no_rate_column_keeps_the_rates_of_the_items_it_updates(): void
    {
        $project = $this->makeProject($this->org);
        $this->item($project, 'C1', 'Sample plaster on walls', 'sqm', '400.000', '12.00');
        $this->item($project, 'C2', 'Sample PCC 1:4:8', 'cum', '60.000', '340.00');

        $preview = $this->staged($project, $this->sheet([
            ['Item Code', 'Description', 'Unit', 'Qty'],
            ['C1', 'Sample plaster on walls', 'sqm', 450],
            ['C2', 'Sample PCC 1:4:8', 'cum', 70],
            ['C3', 'Sample skirting in kota stone', 'sqm', 30],
        ]));

        $this->assertSame(['update' => 2, 'add' => 1], $preview['plan']);
        $this->assertSame([], $preview['errors']);
        $this->assertSame(['12.00', '340.00', '0'], array_column($preview['lines'], 'rate'));
        $messages = array_column($preview['notes'], 'message');
        $this->assertContains('2 lines update an item already in the project, and the file gives no rate for them (rows 2, 3). They keep the rate the item has.', $messages);
        // Only the line that is added comes in at rate 0, and the note says so of it alone.
        $this->assertContains('1 lines have a quantity but no rate (rows 4). They are read at rate 0; fill in the rates later.', $messages);
        $this->assertSame([], array_values(array_filter($messages, fn (string $message) => str_contains($message, 'rows 2, 3, 4'))));

        $this->confirm($project)->assertSessionHas('success');
        $this->assertSame(['C1' => ['450.000', '12.00'], 'C2' => ['70.000', '340.00'], 'C3' => ['30.000', '0.00']], $this->figures($project));
    }

    public function test_the_csv_a_role_without_money_downloads_keeps_the_rates_when_it_comes_back(): void
    {
        $project = $this->makeProject($this->org);
        $this->item($project, 'C1', 'Sample plaster on walls', 'sqm', '400.000', '12.00');

        // The BOQ page's CSV for site staff has no rate column.
        $csv = $this->actingAs($this->member($this->org, 'engineer'))->get(route('boq.export', $project))->assertOk()->streamedContent();
        $this->assertStringStartsWith('section,code,description,unit,qty,', $csv);

        $this->staged($project, $this->csvFile(str_replace(',400.000,', ',500,', $csv)));
        $this->confirm($project)->assertSessionHas('success');

        $this->assertSame(['C1' => ['500.000', '12.00']], $this->figures($project));
    }

    public function test_a_blank_quantity_keeps_the_items_quantity_and_a_cut_is_held_to_the_work_measured_and_billed(): void
    {
        $project = $this->makeProject($this->org);
        $item = $this->item($project, 'C1', 'Sample RCC M25 in slab', 'cum', '100.000', '12.00');
        // 50 cum measured and not yet approved: a hand edit cannot cut the line under it.
        $this->draftEntry($project, $item, $this->member($this->org, 'engineer'));

        // The template's columns with the quantity left blank: refused there, then read on the column check.
        $this->upload($project, $this->workbook([['Item Code', 'Description', 'Unit', 'Qty', 'Rate'], ['C1', 'Sample RCC M25 in slab', 'cum', null, 13]]))
            ->assertRedirect(route('boq.excel.index', $project));
        $this->assertSame('Quantity is missing.', session('boq_import.errors.0.message'));

        $preview = $this->preview($project);
        $this->assertSame([], $preview['errors']);
        $this->assertSame([], $preview['rate_only']);
        $this->assertSame('100.000', $preview['lines'][0]['qty']);
        $messages = array_column($preview['notes'], 'message');
        $this->assertContains('1 lines update an item already in the project, and the file gives no quantity for them (rows 2). They keep the quantity the item has.', $messages);
        $this->assertSame([], array_values(array_filter($messages, fn (string $message) => str_contains($message, 'quantity 0'))));

        $this->confirm($project)->assertSessionHas('success');
        $this->assertSame(['C1' => ['100.000', '13.00']], $this->figures($project));

        // A quantity under the work measured is refused, in the template's columns and in any other layout alike.
        $this->upload($project, $this->workbook([$this->boqHeading(), ['', 'C1', 'Sample RCC M25 in slab', 'cum', 40, 13]]));
        $this->assertSame('Quantity cannot be lower than the quantity already measured (50.000).', session('boq_import.errors.0.message'));
        $preview = $this->staged($project, $this->sheet([['Item Code', 'Description', 'Unit', 'Qty'], ['C1', 'Sample RCC M25 in slab', 'cum', 40]]));
        $this->assertSame(['Quantity cannot be lower than the quantity already measured (50.000).'], array_column($preview['errors'], 'message'));
        $this->confirm($project)->assertRedirect(route('boq.sheet.preview', $project));
        $this->post(route('boq.sheet.discard', $project));

        // So is one under what is billed to the client.
        $bill = DB::table('ra_bills')->insertGetId(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'type' => 'client', 'bill_no' => 1, 'number' => 'RA-'.Str::random(6), 'period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ra_bill_items')->insert(['organization_id' => $project->organization_id, 'ra_bill_id' => $bill, 'boq_item_id' => $item->id, 'code' => 'C1', 'description' => $item->description, 'unit' => 'cum', 'rate' => '12.00', 'current_qty' => '60.000', 'cumulative_qty' => '60.000', 'created_at' => now(), 'updated_at' => now()]);
        $this->upload($project, $this->workbook([$this->boqHeading(), ['', 'C1', 'Sample RCC M25 in slab', 'cum', 55, 13]]));
        $this->assertSame('Quantity cannot be lower than the quantity already billed (60.000).', session('boq_import.errors.0.message'));
        $this->assertSame(['C1' => ['100.000', '13.00']], $this->figures($project));

        // A rise is not a cut, and a cut that leaves room for the work goes through.
        $this->upload($project, $this->workbook([$this->boqHeading(), ['', 'C1', 'Sample RCC M25 in slab', 'cum', 60, 13]]))->assertSessionHas('success');
        $this->assertSame(['C1' => ['60.000', '13.00']], $this->figures($project));
    }

    public function test_a_tender_corrigendum_without_rates_keeps_the_rates_quoted_on_the_tender(): void
    {
        $this->actingAs($this->owner);
        $tender = $this->makeTender($this->org);
        $heading = ['S.No.', 'Item Code', 'Description of Work', 'Qty', 'Unit', 'Rate (Rs.)', 'Amount (Rs.)'];

        $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet([
            $heading,
            [1, 'R-2.1', 'Sample granular sub-base with close graded material', '1,250', 'Cum', 950, 1187500],
            [2, 'R-9.9', 'Sample road marking with hot thermoplastic paint', 20, 'Sqm', 45, 900],
        ], 'priced.xlsx')])->assertSessionHasNoErrors();

        // The department's corrigendum: new quantities and no rates, a line now quoted at a rate only, and a new line.
        $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet([
            $heading,
            [1, 'R-2.1', 'Sample granular sub-base with close graded material', '1,300', 'Cum', null, null],
            [2, 'R-9.9', 'Sample road marking with hot thermoplastic paint', 'QRO', 'Sqm', 50, null],
            [3, 'R-4.2', 'Sample precast kerb stones', 120, 'Sqm', null, null],
        ], 'corrigendum.xlsx')])->assertSessionHasNoErrors();

        $lines = CrmTenderBoqItem::where('tender_id', $tender->id)->orderBy('sort')->get()->keyBy('code');
        $this->assertSame(['R-2.1', 'R-9.9', 'R-4.2'], $lines->keys()->all());
        $this->assertSame(['1300.000', '950.00'], [$lines['R-2.1']->qty, $lines['R-2.1']->rate]);
        $this->assertSame(['20.000', '50.00'], [$lines['R-9.9']->qty, $lines['R-9.9']->rate]);
        $this->assertSame(['120.000', '0.00'], [$lines['R-4.2']->qty, $lines['R-4.2']->rate]);
    }

    public function test_a_corrigendum_numbered_by_serial_never_gives_a_tender_line_the_rate_quoted_for_another(): void
    {
        $this->actingAs($this->owner);
        $tender = $this->makeTender($this->org);
        $heading = ['S.No.', 'Description of Work', 'Unit', 'Qty', 'Rate', 'Amount'];

        $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet([
            $heading,
            [1, 'Sample granular sub-base with close graded material', 'Cum', '1,250', 950, 1187500],
            [2, 'Sample road marking with hot thermoplastic paint', 'Sqm', 20, 45, 900],
        ], 'priced.xlsx')])->assertSessionHasNoErrors()->assertSessionHas('success', '2 BOQ rows imported.');

        // The corrigendum adds an item at S.No. 2, so road marking moves to 3; it gives no rates.
        $response = $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet([
            $heading,
            [1, 'Sample granular sub-base with close graded material', 'Cum', '1,300', null, null],
            [2, 'Sample precast kerb stones new item', 'Rmt', 120, null, null],
            [3, 'Sample road marking with hot thermoplastic paint', 'Sqm', 25, null, null],
        ], 'corrigendum.xlsx')])->assertSessionHasNoErrors();

        $lines = CrmTenderBoqItem::where('tender_id', $tender->id)->orderBy('sort')->get()->keyBy('code');
        $this->assertSame(['1', '2', '2-2', '3'], $lines->keys()->map(fn ($code) => (string) $code)->all());
        // The same item by its wording keeps its quoted rate.
        $this->assertSame(['1300.000', '950.00'], [$lines['1']->qty, $lines['1']->rate]);
        // The line numbered 2 on the tender is another item: it is left as it was, and the kerb stones come in at rate 0.
        $this->assertSame(['Sample road marking with hot thermoplastic paint', '20.000', '45.00'], [$lines['2']->description, $lines['2']->qty, $lines['2']->rate]);
        $this->assertSame(['Sample precast kerb stones new item', 'rmt', '120.000', '0.00'], [$lines['2-2']->description, $lines['2-2']->unit, $lines['2-2']->qty, $lines['2-2']->rate]);
        $this->assertSame(['25.000', '0.00'], [$lines['3']->qty, $lines['3']->rate]);
        $response->assertSessionHas('success', '3 BOQ rows imported. 1 lines already on the tender kept their own quantity or rate, because the file gives none or only an amount to work it out from (1). 1 lines carry the number of a different line already on the tender, so they were added as new lines (2 → 2-2). The lines already there were left as they were: check them, and delete any line the file replaces.');

        // A file with the sheet's own item codes updates the line with that code, its wording changed or not, as it always has.
        $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet([
            ['Item Code', 'Description of Work', 'Unit', 'Qty', 'Rate'],
            ['2', 'Sample road marking with cold plastic paint', 'Sqm', 22, null],
        ], 'codes.xlsx')])->assertSessionHasNoErrors()->assertSessionHas('success', '1 BOQ rows imported. 1 lines already on the tender kept their own quantity or rate, because the file gives none or only an amount to work it out from (2).');
        $line = CrmTenderBoqItem::where('tender_id', $tender->id)->where('code', '2')->firstOrFail();
        $this->assertSame(['Sample road marking with cold plastic paint', '22.000', '45.00'], [$line->description, $line->qty, $line->rate]);
    }

    public function test_a_line_that_keeps_the_items_quantity_does_not_say_the_file_gives_none(): void
    {
        $project = $this->makeProject($this->org);
        $this->item($project, '1', 'Sample plaster on walls', 'sqm', '400.000', '12.00');
        $this->item($project, '2', 'Sample PCC 1:4:8', 'cum', '60.000', '340.00');

        $preview = $this->staged($project, $this->sheet([
            ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
            [1, 'Sample plaster on walls', 'sqm', null, 13, null],
            [2, 'Sample PCC 1:4:8', 'cum', null, null, null],
            [3, 'Sample skirting in kota stone', 'sqm', null, 55, null],
        ]));

        $this->assertSame(['update' => 2, 'add' => 1], $preview['plan']);
        $this->assertSame([], $preview['errors']);
        $details = array_map(fn (array $line) => $line['details'], $preview['lines']);
        $this->assertSame([[], [], ['Rate only: the file gives no quantity.']], $details);

        $this->confirm($project)->assertSessionHas('success');
        $lines = $this->lines($project);
        $this->assertSame(['400.000', '13.00', null], [$lines['1']->qty, $lines['1']->rate, $lines['1']->details]);
        $this->assertSame(['60.000', '340.00', null], [$lines['2']->qty, $lines['2']->rate, $lines['2']->details]);
        // A line that is added still says it: it comes in at quantity 0.
        $this->assertSame(['0.000', '55.00', 'Rate only: the file gives no quantity.'], [$lines['3']->qty, $lines['3']->rate, $lines['3']->details]);
    }

    public function test_a_tender_line_keeps_its_quoted_rate_where_the_sheet_works_one_out_from_the_estimated_amount(): void
    {
        $this->actingAs($this->owner);
        $tender = $this->makeTender($this->org);
        $this->makeBoqLine($tender, ['section' => null, 'code' => 'R-2.1', 'description' => 'Sample granular sub-base with close graded material', 'unit' => 'cum', 'qty' => '1250.000', 'rate' => '950.00']);

        // The department's schedule: its own estimated amount and no quoted rate. Amount over quantity is its rate, not ours.
        $response = $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet([
            ['S.No.', 'Item Code', 'Description of Work', 'Qty', 'Unit', 'Quoted Rate (Rs.)', 'Estimated Amount (Rs.)'],
            [1, 'R-2.1', 'Sample granular sub-base with close graded material', '1,300', 'Cum', null, '11,70,000'],
            [2, 'R-4.2', 'Sample precast kerb stones', 120, 'Rmt', null, '54,000'],
        ], 'schedule.xlsx')])->assertSessionHasNoErrors();

        $lines = CrmTenderBoqItem::where('tender_id', $tender->id)->orderBy('sort')->get()->keyBy('code');
        $this->assertSame(['1300.000', '950.00'], [$lines['R-2.1']->qty, $lines['R-2.1']->rate]);
        // A line that is added takes the rate worked out, as on the column check.
        $this->assertSame(['120.000', '450.00'], [$lines['R-4.2']->qty, $lines['R-4.2']->rate]);
        $response->assertSessionHas('success', '2 BOQ rows imported. 1 lines already on the tender kept their own quantity or rate, because the file gives none or only an amount to work it out from (R-2.1).');
    }

    public function test_a_tender_line_given_only_an_amount_keeps_its_quantity_rate_and_unit(): void
    {
        $this->actingAs($this->owner);
        $tender = $this->makeTender($this->org);
        $this->makeBoqLine($tender, ['section' => null, 'code' => 'R-2.1', 'description' => 'Sample granular sub-base with close graded material', 'unit' => 'cum', 'qty' => '1250.000', 'rate' => '950.00', 'sort' => 1]);
        $this->makeBoqLine($tender, ['section' => null, 'code' => 'R-9.9', 'description' => 'Sample road marking with hot thermoplastic paint', 'unit' => 'sqm', 'qty' => '20.000', 'rate' => '45.00', 'sort' => 2]);

        // Only amounts: read as 1 lump sum at the amount, and with no unit as LS.
        $response = $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet([
            ['S.No.', 'Item Code', 'Description of Work', 'Qty', 'Unit', 'Quoted Rate (Rs.)', 'Estimated Amount (Rs.)'],
            [1, 'R-2.1', 'Sample granular sub-base with close graded material', null, 'Cum', null, '12,00,000'],
            [2, 'R-9.9', 'Sample road marking with hot thermoplastic paint', null, null, null, 1000],
            [3, 'R-7.1', 'Sample shifting of service lines', null, null, null, '2,50,000'],
        ], 'amounts.xlsx')])->assertSessionHasNoErrors();

        $lines = CrmTenderBoqItem::where('tender_id', $tender->id)->orderBy('sort')->get()->keyBy('code');
        $this->assertSame(['cum', '1250.000', '950.00'], [$lines['R-2.1']->unit, $lines['R-2.1']->qty, $lines['R-2.1']->rate]);
        $this->assertSame(['sqm', '20.000', '45.00'], [$lines['R-9.9']->unit, $lines['R-9.9']->qty, $lines['R-9.9']->rate]);
        // A line that is added is 1 lump sum at the amount.
        $this->assertSame(['LS', '1.000', '250000.00'], [$lines['R-7.1']->unit, $lines['R-7.1']->qty, $lines['R-7.1']->rate]);
        $response->assertSessionHas('success', '3 BOQ rows imported. 2 lines already on the tender kept their own quantity or rate, because the file gives none or only an amount to work it out from (R-2.1, R-9.9).');
    }

    public function test_a_sheet_with_no_category_or_details_column_keeps_an_items_category_and_details(): void
    {
        $project = $this->makeProject($this->org);
        BoqItem::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'code' => 'S-5.1', 'description' => 'Sample bar bending and fixing in slabs',
            'unit' => 'kg', 'qty' => '800.000', 'rate' => '72.00', 'category' => 'steel', 'details' => 'Hand note: 12 mm, 1:4 mix',
        ]);
        BoqItem::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'code' => 'S-6.2', 'description' => 'Sample walling in fly ash blocks',
            'unit' => 'cum', 'qty' => '45.000', 'rate' => '5100.00', 'category' => 'brick_work', 'details' => 'Hand note: blocks from the sample yard',
        ]);

        $preview = $this->staged($project, $this->sheet([
            ['S.No.', 'Item Code', 'Description of Work', 'Unit', 'Quantity', 'Rate (Rs.)', 'Amount (Rs.)'],
            [1, 'S-5.1', 'Sample bar bending and fixing in slabs', 'kg', 850, 72, 61200],
            // A rate-only line: the reader's "Rate only" never replaces the item's details.
            [2, 'S-6.2', 'Sample walling in fly ash blocks', 'cum', null, 5200, null],
            [3, 'S-7.4', 'Sample cement plaster 12 mm thick', 'sqm', null, 210, null],
        ]));

        $this->assertSame(['update' => 2, 'add' => 1], $preview['plan']);
        $this->assertSame([], $preview['errors']);
        $this->assertSame([[], [], ['Rate only: the file gives no quantity.']], array_map(fn (array $line) => $line['details'], $preview['lines']));

        $this->confirm($project)->assertSessionHas('success');
        $lines = $this->lines($project);
        $this->assertSame(['850.000', 'steel', 'Hand note: 12 mm, 1:4 mix'], [$lines['S-5.1']->qty, $lines['S-5.1']->category, $lines['S-5.1']->details]);
        $this->assertSame(['45.000', '5200.00', 'brick_work', 'Hand note: blocks from the sample yard'], [$lines['S-6.2']->qty, $lines['S-6.2']->rate, $lines['S-6.2']->category, $lines['S-6.2']->details]);
        // A new item is filed by its words and says what the reader made of it, as before.
        $this->assertSame(['plaster', 'Rate only: the file gives no quantity.'], [$lines['S-7.4']->category, $lines['S-7.4']->details]);
    }

    public function test_a_sheet_with_a_category_and_a_remarks_column_still_updates_them(): void
    {
        $project = $this->makeProject($this->org);
        BoqItem::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'code' => 'S-5.1', 'description' => 'Sample bar bending and fixing in slabs',
            'unit' => 'kg', 'qty' => '800.000', 'rate' => '72.00', 'category' => 'steel', 'details' => 'Hand note: 12 mm, 1:4 mix',
        ]);
        BoqItem::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'code' => 'S-6.2', 'description' => 'Sample walling in fly ash blocks',
            'unit' => 'cum', 'qty' => '45.000', 'rate' => '5100.00', 'category' => 'brick_work', 'details' => 'Hand note: blocks from the sample yard',
        ]);

        $preview = $this->staged($project, $this->sheet([
            ['S.No.', 'Item Code', 'Description of Work', 'Unit', 'Quantity', 'Rate (Rs.)', 'Amount (Rs.)', 'Category', 'Remarks'],
            [1, 'S-5.1', 'Sample bar bending and fixing in slabs', 'kg', 850, 72, 61200, 'RCC', 'Revised in sample corrigendum 2'],
            // Blank cells say nothing about the item: it keeps its category and its details.
            [2, 'S-6.2', 'Sample walling in fly ash blocks', 'cum', 50, 5100, 255000, null, null],
        ]));

        $this->assertSame('H', $this->columnOf($preview, 'category'));
        $this->assertSame('I', $this->columnOf($preview, 'remark'));
        $this->assertSame(['update' => 2, 'add' => 0], $preview['plan']);
        $this->assertSame([], $preview['errors']);

        $this->confirm($project)->assertSessionHas('success');
        $lines = $this->lines($project);
        $this->assertSame(['rcc', 'Remark: Revised in sample corrigendum 2'], [$lines['S-5.1']->category, $lines['S-5.1']->details]);
        $this->assertSame(['50.000', 'brick_work', 'Hand note: blocks from the sample yard'], [$lines['S-6.2']->qty, $lines['S-6.2']->category, $lines['S-6.2']->details]);
    }
}
