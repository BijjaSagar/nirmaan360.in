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
}
