<?php

namespace Tests\Feature\BoqImport;

use App\Models\BoqImportLayout;
use App\Models\CrmTenderBoqItem;
use App\Services\Imports\SheetLayout;
use Tests\Feature\Crm\BuildsCrm;

/** The tender BOQ import reads a department's or client's own schedule by its headings, with the same checks on every row. */
class TenderSheetImportTest extends BoqImportTestCase
{
    use BuildsCrm;

    public function test_a_tender_schedule_in_its_own_layout_is_imported(): void
    {
        $this->actingAs($this->owner);
        $tender = $this->makeTender($this->org);
        $rows = [
            ['OFFICE OF THE EXAMPLE ENGINEER'],
            ['Schedule B - Bill of Quantities'],
            [],
            ['S.No.', 'Item Code', 'Description of Work', 'Qty', 'Unit', 'Rate (Rs.)', 'Amount (Rs.)'],
            ['A', null, 'ROAD WORK'],
            [1, 'R-2.1', 'Sample granular sub-base with close graded material', '1,250', 'Cum', null, null],
            [2, 'R-3.4', 'Sample bituminous macadam 50 mm thick', '3,400.5', 'Sqm', null, null],
            [3, 'R-9.9', 'Sample road marking with hot thermoplastic paint', 'QRO', 'Sqm', null, null],
        ];

        $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet($rows, 'schedule-b.xls')])->assertSessionHasNoErrors();

        $lines = CrmTenderBoqItem::where('tender_id', $tender->id)->orderBy('sort')->get()->keyBy('code');
        $this->assertSame(['R-2.1', 'R-3.4', 'R-9.9'], $lines->keys()->all());
        $this->assertSame(['1250.000', '0.00', 'cum', 'ROAD WORK'], [$lines['R-2.1']->qty, $lines['R-2.1']->rate, $lines['R-2.1']->unit, $lines['R-2.1']->section]);
        $this->assertSame('3400.500', $lines['R-3.4']->qty);
        // A quote-rate-only line keeps a quantity of 0; none is made up.
        $this->assertSame(['0.000', 'sqm'], [$lines['R-9.9']->qty, $lines['R-9.9']->unit]);
    }

    public function test_a_real_xls_tender_schedule_and_a_layout_the_company_saved(): void
    {
        $this->actingAs($this->owner);
        $heading = ['No.', 'Work', 'Ref', 'Unit', 'Nos', 'Rate', 'Amt'];
        BoqImportLayout::create([
            'organization_id' => $this->org->id,
            'signature' => (new SheetLayout(1, 1, headings: array_combine(range(1, 7), $heading)))->signature(),
            'headings' => array_combine(range(1, 7), $heading),
            'fields' => [1 => 'serial', 2 => 'description', 3 => 'reference', 4 => 'unit', 5 => 'qty', 6 => 'rate', 7 => 'amount'],
            'span' => 1,
            'uses' => 1,
        ]);
        $tender = $this->makeTender($this->org);

        $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet([$heading, ['1', 'Sample door frames', 'D-14', 'nos', 6, 3200, 19200]], 'doors.xls', 'Xls')])->assertSessionHasNoErrors();

        $line = CrmTenderBoqItem::where('tender_id', $tender->id)->firstOrFail();
        $this->assertSame(['1', '6.000', '3200.00'], [$line->code, $line->qty, $line->rate]);
    }

    public function test_a_tender_file_with_no_table_or_no_units_is_refused_and_nothing_is_saved(): void
    {
        $this->actingAs($this->owner);
        $tender = $this->makeTender($this->org);

        $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet([['Notes only'], ['No table here']])])->assertSessionHasErrors('file');
        $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet([['Sr No', 'Description', 'Qty', 'Rate'], [1, 'Sample', 2, 3]])])
            ->assertSessionHasErrors(['file' => 'This sheet has no unit column. Add one, with the unit of each line.']);
        $this->assertSame(0, CrmTenderBoqItem::count());

        // Another company's tender is not found.
        $other = $this->makeTender($this->tenant('Other Co', 'business'));
        $this->post(route('crm.boq.import', $other), ['file' => $this->sheet([['Sr No', 'Description', 'Unit', 'Qty', 'Rate'], [1, 'Sample', 'nos', 2, 3]])])->assertNotFound();
        $this->assertSame(0, CrmTenderBoqItem::withoutGlobalScopes()->count());
    }
}
