<?php

namespace Tests\Feature\BoqImport;

use App\Models\BoqItem;
use App\Models\CrmTenderBoqItem;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Tests\Feature\Crm\BuildsCrm;

/**
 * Rows that look like a total, a carried amount, an adjustment or a section by their words, but are items:
 * a lump sum with its own number or unit, a specification that names a percentage, a numbered trade heading,
 * a total of quantities, a lettered provisional sum. And the cells around a sheet that are not values: an
 * item's text merged down beside a typed serial number, a space, a border or a formula far below the table.
 */
class SheetRowGuardsTest extends BoqImportTestCase
{
    use BuildsCrm;

    private const HEADING = ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'];

    public function test_a_lump_sum_item_with_its_own_number_or_unit_is_a_line_whatever_its_words(): void
    {
        $rows = [
            self::HEADING,
            ['1', 'Sample excavation in soft soil', 'Cum', 80, 260, 20800],
            ['2', 'Sample PCC 1:4:8', 'Cum', 12, 5400, 64800],
            ['3', 'Disposal of surplus earth taken out from excavation, lump sum', 'LS', null, null, 15000],
            ['4', 'Provisional sum for shifting of poles to be carried out by the board', null, null, null, 50000],
            ['5', 'Testing of samples brought to site', 'LS', null, null, 8000],
            [null, 'Total', null, null, null, 158600],
        ];
        $read = $this->classified($rows);

        $this->assertSame(['1', '2', '3', '4', '5'], array_column($read['lines'], 'code'));
        $this->assertSame(['158600.00', [7 => true]], [$read['lines_total'], array_column($read['totals'], 'matches', 'row')]);

        $read = $this->classified([
            self::HEADING,
            ['1', 'Sample excavation in soft soil', 'Cum', 80, 260, 20800],
            ['2', 'Sample PCC 1:4:8', 'Cum', 12, 5400, 64800],
            ['3', 'Lump sum provision for electrification including all taxes and duties', null, null, null, 250000],
            ['4', 'Provision for third party inspection charges inclusive of GST', null, null, null, 40000],
            [null, 'Total', null, null, null, 375600],
        ]);

        $this->assertSame(['1', '2', '3', '4'], array_column($read['lines'], 'code'));
        $this->assertSame([[], '375600.00'], [$read['adjustments'], $read['lines_total']]);

        // With no number column, the words decide: work carried out or brought to site is not an amount carried to another page.
        $read = $this->classified([
            ['Description', 'Unit', 'Qty', 'Rate', 'Amount'],
            ['Sample excavation in soft soil', 'Cum', 80, 260, 20800],
            ['Provisional sum for shifting of poles to be carried out by the board', null, null, null, 50000],
            ['Lump sum provision for electrification including all taxes and duties', null, null, null, 250000],
            ['Testing of samples brought to site', null, null, null, 8000],
            ['Carried to next page', null, null, null, 328800],
        ]);

        $this->assertCount(4, $read['lines']);
        $this->assertSame(['328800.00', 'carried'], [$read['lines_total'], $this->kinds($read)[6]]);

        // The tender import, which has no page to check on, takes every line.
        $this->actingAs($this->owner);
        $tender = $this->makeTender($this->org);
        $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet($rows)])->assertSessionHasNoErrors();
        $this->assertSame(['1', '2', '3', '4', '5'], CrmTenderBoqItem::where('tender_id', $tender->id)->orderBy('sort')->pluck('code')->all());
    }

    public function test_a_specification_that_names_a_percentage_stays_a_specification(): void
    {
        $read = $this->classified([
            self::HEADING,
            ['1', 'Sample excavation in soft soil', 'Cum', 80, 260, 20800],
            ['2', 'Providing cement plaster in CM 1:4 mixed with waterproofing compound @ 2% by weight of cement:'],
            [null, '(a) 12 mm thick on inside walls', 'Sqm', 100, 250, 25000],
            [null, '(b) 20 mm thick on outside walls', 'Sqm', 80, 300, 24000],
            ['3', 'Cement plaster with 2% waterproofing compound below plinth:'],
            [null, '(a) 15 mm thick', 'Sqm', 40, 260, 10400],
        ]);

        $this->assertSame([], $read['adjustments']);
        $this->assertSame('spec', $this->kinds($read)[3]);
        $this->assertSame('spec', $this->kinds($read)[6]);
        $this->assertSame(['1', '2a', '2b', '3a'], array_column($read['lines'], 'code'));
        $this->assertStringContainsString('@ 2% by weight of cement', $read['lines'][4]['details'][0]);
        $this->assertSame('80200.00', $read['with_adjustments']);

        // With no number column, lettered parts below say it is not an adjustment either.
        $read = $this->classified([
            ['Description', 'Unit', 'Qty', 'Rate', 'Amount'],
            ['Sample excavation in soft soil', 'Cum', 80, 260, 20800],
            ['Plaster with 2% compound below plinth'],
            ['(a) 15 mm thick', 'Sqm', 40, 260, 10400],
        ]);
        $this->assertSame([[], '31200.00'], [$read['adjustments'], $read['lines_total']]);

        // A row that is about the percentage still is one.
        $read = $this->classified([self::HEADING, ['1', 'Sample excavation', 'Cum', 80, 260, 20800], [null, 'Add 6% contingencies', null, null, null, 1248]]);
        $this->assertSame(['6', '1248.00'], [$read['adjustments'][0]['percent'], $read['adjustments'][0]['effect']]);
    }

    public function test_a_numbered_trade_heading_with_no_parts_names_the_lines_under_it(): void
    {
        $read = $this->classified([
            self::HEADING,
            ['1', 'EARTH WORK'],
            ['1.1', 'Excavation', 'Cum', 80, 260, 20800],
            ['1.2', 'Filling with excavated earth in layers, watered and rammed', 'Cum', 40, 150, 6000],
            ['1.3', 'Dewatering', 'LS', 1, 2000, 2000],
            ['2', 'CONCRETE WORK'],
            ['2.1', 'Providing PCC 1:4:8 in foundation and plinth', 'Cum', 12, 5400, 64800],
            [null, 'Total', null, null, null, 93600],
        ]);

        $kinds = $this->kinds($read);
        $this->assertSame(['subheading', 'subheading'], [$kinds[2], $kinds[6]]);
        $this->assertSame(['1.1', '1.2', '1.3', '2.1'], array_column($read['lines'], 'code'));
        $this->assertSame(['EARTH WORK', 'EARTH WORK', 'EARTH WORK', 'CONCRETE WORK'], array_column($read['lines'], 'section'));
        $this->assertSame(['Excavation', 'Dewatering'], [$read['lines'][3]['description'], $read['lines'][5]['description']]);
        $this->assertSame([8 => true], array_column($read['totals'], 'matches', 'row'));

        // Items numbered afresh under each heading stay under it; an item numbered past a heading's own items does not.
        $read = $this->classified([
            self::HEADING,
            ['1', 'EARTH WORK'],
            ['1', 'Excavation in foundation trenches in all kinds of soil', 'Cum', 80, 260, 20800],
            ['2', 'Filling with excavated earth in layers', 'Cum', 40, 150, 6000],
            ['2', 'CONCRETE WORK'],
            ['1', 'PCC 1:4:8 in foundation and plinth', 'Cum', 12, 5400, 64800],
        ]);
        $this->assertSame(['EARTH WORK', 'EARTH WORK', 'CONCRETE WORK'], array_column($read['lines'], 'section'));

        $read = $this->classified([
            self::HEADING,
            ['1', 'EARTH WORK'],
            ['1.1', 'Excavation', 'Cum', 80, 260, 20800],
            ['1.2', 'Cement plaster in CM 1:6'],
            ['1.3', 'Sample painting', 'Sqm', 900, 120, 108000],
            ['2', 'Sample lump sum for site clearance', 'LS', 1, 5000, 5000],
        ]);
        $this->assertSame(['1.1', '1.3', '2'], array_column($read['lines'], 'code'));
        $this->assertSame(['Sample painting', 'Sample lump sum for site clearance'], [$read['lines'][5]['description'], $read['lines'][6]['description']]);
        // 1.2 has nothing under it (1.3 follows it), so it heads nothing and is left out as a note.
        $this->assertSame(['EARTH WORK', 'EARTH WORK', null], array_column($read['lines'], 'section'));
        $this->assertSame('note', $this->kinds($read)[4]);
    }

    public function test_an_item_merged_down_beside_a_serial_typed_in_its_first_row(): void
    {
        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, $this->sheet([
            self::HEADING,
            ['1', 'Providing and laying sample vitrified tiles of approved make on floor, complete'],
            [],
            [null, null, 'Sqm', 150, 1200, 180000],
            ['2', 'Sample skirting tiles 100 mm high', 'Rmt', 200, 300, 60000],
            ['3', 'Sample granite sill'],
            [null, null, 'Rmt', 40, 1500, 60000],
            [null, 'Total', null, null, null, 300000],
        ], merges: ['B2:B4', 'B6:B7']));

        $this->assertSame([], $preview['errors']);
        $this->assertSame([3, '300000.00', true], [$preview['line_count'], $preview['lines_total'], $preview['subtotal_matches']]);
        $this->assertSame(['1', '2', '3'], array_column($preview['lines'], 'code'));
        $this->assertSame('Sample granite sill', $preview['lines'][2]['description']);
    }

    public function test_totals_of_quantities_part_totals_and_a_lettered_provisional_sum(): void
    {
        $read = $this->classified([
            self::HEADING,
            ['1', 'Sample excavation', 'Cum', 80, 260, 20800],
            ['2', 'Sample excavation in hard soil', 'Cum', 40, 300, 12000],
            [null, 'Total Qty', 'Cum', 120, null, null],
            [null, 'Total quantity of excavation', 'Cum', 120, null, null],
        ]);
        $this->assertSame(['1', '2'], array_column($read['lines'], 'code'));
        $this->assertSame(['subtotal', 'subtotal'], [$this->kinds($read)[4], $this->kinds($read)[5]]);

        $rows = [
            self::HEADING,
            ['A', 'EARTH WORK'],
            ['1', 'Sample excavation', 'Cum', 80, 260, 20800],
            ['2', 'Sample PCC', 'Cum', 12, 5400, 64800],
            ['A', 'Sub Total (A)', null, null, null, 85600],
            ['B', 'MASONRY'],
            ['3', 'Sample brickwork', 'Cum', 45, 6100, 274500],
            ['B', 'Sub Total (B)', null, null, null, 274500],
            ['C', 'Provisional sum for electrical works', null, null, null, 500000],
            [null, 'Grand Total', null, null, null, 860100],
        ];
        $read = $this->classified($rows);
        $kinds = $this->kinds($read);

        $this->assertSame(['subtotal', 'subtotal', 'line'], [$kinds[5], $kinds[8], $kinds[9]]);
        $this->assertSame(['A.1', 'A.2', 'B.3', 'C'], array_column($read['lines'], 'code'));
        $this->assertSame(['EARTH WORK', 'EARTH WORK', 'MASONRY'], array_slice(array_column($read['lines'], 'section'), 0, 3));
        $this->assertSame(['LS', '1', '500000'], [$read['lines'][9]['unit'], $read['lines'][9]['qty'], $read['lines'][9]['rate']]);
        $this->assertSame([5 => true, 8 => true, 10 => true], array_column($read['totals'], 'matches', 'row'));
        $this->assertSame('860100.00', $read['lines_total']);

        $this->actingAs($this->owner);
        $tender = $this->makeTender($this->org);
        $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet($rows)])->assertSessionHasNoErrors();
        $this->assertSame(['A.1', 'A.2', 'B.3', 'C'], CrmTenderBoqItem::where('tender_id', $tender->id)->orderBy('sort')->pluck('code')->all());
    }

    public function test_spaces_borders_and_formulas_far_below_or_beside_the_table_are_not_values(): void
    {
        $project = $this->makeProject($this->org);
        $made = [
            'a formula dragged down' => fn ($sheet) => $sheet->setCellValue('F6000', '=IF(D6000="","",D6000*E6000)'),
            'a space' => fn ($sheet) => $sheet->setCellValueExplicit('B6000', ' ', 's'),
            'a border' => fn ($sheet) => $sheet->getStyle('C6000')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN),
            'a space far to the right' => fn ($sheet) => $sheet->setCellValueExplicit('BB2', ' ', 's'),
            'a border far to the right' => fn ($sheet) => $sheet->getStyle('BB2')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN),
        ];

        foreach (['Xls', 'Xlsx'] as $writer) {
            foreach ($made as $case => $make) {
                $preview = $this->staged($project, $this->withExtra($make, $writer));
                $this->assertSame([], $preview['errors'], $writer.': '.$case);
                $this->assertSame([], array_filter($preview['notes'], fn (array $note) => str_contains($note['message'], 'past column')), $writer.': '.$case);
                $this->assertSame(1, $preview['line_count'], $writer.': '.$case);
            }
        }

        $this->actingAs($this->owner);
        $tender = $this->makeTender($this->org);
        $this->post(route('crm.boq.import', $tender), ['file' => $this->withExtra($made['a border far to the right'], 'Xls')])->assertSessionHasNoErrors();
        $this->assertSame(1, CrmTenderBoqItem::where('tender_id', $tender->id)->count());

        // A real value down there is still found.
        $this->upload($project, $this->withExtra(fn ($sheet) => $sheet->setCellValue('B6000', 'Sample item far down'), 'Xls'))->assertRedirect(route('boq.sheet.preview', $project));
        $this->assertStringContainsString('more than 5000 rows', $this->preview($project)['errors'][0]['message']);
    }

    public function test_lines_deleted_from_the_project_do_not_count_as_items_already_there(): void
    {
        $project = $this->makeProject($this->org);
        $this->staged($project, $this->sheet([self::HEADING, [1, 'Sample excavation', 'Cum', 80, 260, 20800], [2, 'Sample PCC', 'Cum', 12, 5400, 64800]]));
        $this->confirm($project)->assertSessionHas('success');
        BoqItem::where('project_id', $project->id)->get()->each->delete();

        $preview = $this->staged($project, $this->sheet([self::HEADING, [1, 'Sample excavation in hard rock', 'Cum', 80, 460, 36800], [2, 'Sample PCC 1:3:6', 'Cum', 12, 6400, 76800]]));

        $this->assertSame([[], []], [$preview['renumbered'], $preview['updates']]);
        $this->confirm($project)->assertSessionHas('success');
        $this->assertSame([1, 2], array_keys($this->lines($project)));
    }

    public function test_a_second_amount_column_does_not_stop_the_import(): void
    {
        $rows = [
            ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount (Rs.)', 'Amount (Rs. in lakhs)'],
            [1, 'Sample excavation', 'Cum', 80, 260, 20800, 0.208],
            [2, 'Sample PCC', 'Cum', 12, 5400, 64800, 0.648],
        ];
        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, $this->sheet($rows));

        $this->assertSame([], $preview['errors']);
        $this->assertSame('F', $this->columnOf($preview, 'amount'));
        $this->assertNotSame([], array_filter($preview['notes'], fn (array $note) => str_contains($note['message'], 'Column G')));

        $this->actingAs($this->owner);
        $tender = $this->makeTender($this->org);
        $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet($rows)])->assertSessionHasNoErrors();
        $this->assertSame(['260.00', '5400.00'], CrmTenderBoqItem::where('tender_id', $tender->id)->orderBy('sort')->pluck('rate')->all());

        // A government schedule's amount with and without taxes: the one that is quantity times rate is read.
        $rows = [
            ['Sl. No.', 'Item Description', 'Quantity', 'Units', 'BASIC RATE In Figures To be entered by the Bidder in Rs. P', 'TOTAL AMOUNT With Taxes', 'TOTAL AMOUNT Without Taxes', 'TOTAL AMOUNT In Words'],
            [1, 'Sample earthwork in excavation by mechanical means', 1200, 'Cum', 180, 254880, 216000, 'INR Two Lakh Sixteen Thousand Only'],
            [2, 'Sample cement concrete 1:2:4 in drain bed', 80, 'Cum', 6450, 608880, 516000, 'INR Five Lakh Sixteen Thousand Only'],
        ];
        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, $this->sheet($rows));

        $this->assertSame([], $preview['errors']);
        $this->assertSame(['E', 'G', []], [$this->columnOf($preview, 'rate'), $this->columnOf($preview, 'amount'), $preview['mismatches']]);

        $other = $this->makeTender($this->org);
        $this->post(route('crm.boq.import', $other), ['file' => $this->sheet($rows)])->assertSessionHasNoErrors();
        $this->assertSame(['180.00', '6450.00'], CrmTenderBoqItem::where('tender_id', $other->id)->orderBy('sort')->pluck('rate')->all());
    }

    /** A one-line BOQ with something more put on the sheet by $make. */
    private function withExtra(callable $make, string $writer): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([self::HEADING, [1, 'Sample excavation', 'Cum', 80, 260, 20800]]);
        $make($sheet);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.'.strtolower($writer);
        IOFactory::createWriter($book, $writer)->save($path);
        $book->disconnectWorksheets();

        return new UploadedFile($path, 'boq.'.strtolower($writer), null, null, true);
    }
}
