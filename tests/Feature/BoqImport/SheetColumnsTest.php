<?php

namespace Tests\Feature\BoqImport;

use App\Models\BoqItem;
use App\Models\CrmTenderBoqItem;
use App\Services\Imports\BoqHeadings;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\Crm\BuildsCrm;

/**
 * The columns of a contractor's sheet: qualified rate and amount headings, an amount in words, rates split
 * into parts, a second table beside the first, columns past Z, a "Per" column, the heading far down the
 * sheet, and item codes the importer makes up.
 */
class SheetColumnsTest extends BoqImportTestCase
{
    use BuildsCrm;

    public function test_qualified_rate_amount_and_quantity_headings_are_read(): void
    {
        $cases = [
            'Estimated Rate' => 'rate', 'SOR Rate' => 'rate', 'Rate including GST (Rs.)' => 'rate', 'Rate (Incl. GST)' => 'rate', 'Rate with GST' => 'rate',
            'BASIC RATE In Figures To be entered by the Bidder Rs. P' => 'rate', 'TOTAL AMOUNT Without Taxes' => 'amount', 'Amount including GST (Rs.)' => 'amount',
            'Net Amount' => 'amount', 'Estimated Amount' => 'amount', 'Approx Qty' => 'qty', 'Revised Qty' => 'qty',
        ];

        foreach ($cases as $heading => $field) {
            $scores = BoqHeadings::scores($heading);
            arsort($scores);
            $this->assertSame($field, array_key_first($scores), $heading);
        }

        // An amount written in words is a remark, never the amount.
        $this->assertSame(['remark'], array_keys(BoqHeadings::scores('TOTAL AMOUNT In Words')));

        // A column whose heading names nothing is the rate when the quantity times it is the amount.
        $layout = (new BoqHeadings)->detect($this->grid([
            ['Sr No', 'Description', 'Unit', 'Qty', 'X', 'Amount'],
            [1, 'Sample work A', 'cum', 2, 100, 200], [2, 'Sample work B', 'cum', 3, 50, 150], [3, 'Sample work C', 'nos', 4, 25, 100],
        ]));
        $this->assertSame(5, $layout->columns()['rate']);

        $rows = [
            ['Sr No', 'Description of item', 'Unit', 'Quantity', 'Rate including GST (Rs.)', 'Amount including GST (Rs.)'],
            ['1', 'Providing and laying sample cement concrete 1:4:8 in foundation, complete', 'Cum', 12, 5400, 64800],
            ['2', 'Providing sample brick masonry in cement mortar 1:6 in superstructure', 'Cum', 45, 6100, 274500],
        ];
        $this->actingAs($this->owner);
        $tender = $this->makeTender($this->org);
        $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet($rows)])->assertSessionHasNoErrors();
        $this->assertSame(['5400.00', '6100.00'], CrmTenderBoqItem::where('tender_id', $tender->id)->orderBy('sort')->pluck('rate')->all());

        // A tender sheet whose figures are in a column no heading names is refused, not imported at rate 0.
        $other = $this->makeTender($this->org);
        $this->post(route('crm.boq.import', $other), ['file' => $this->sheet([['Sr No', 'Description', 'Unit', 'Qty', 'Charges'], [1, 'Sample work', 'nos', 2, 300]])])
            ->assertSessionHasErrors(['file' => 'No rate column was recognised, but column E (Charges) holds figures. Head it Rate, or remove it, and upload the file again.']);
        $this->assertSame(0, CrmTenderBoqItem::where('tender_id', $other->id)->count());
    }

    public function test_a_government_schedule_with_the_amount_in_words(): void
    {
        $rows = [
            ['Name of Work: Sample drain repair'],
            ['Sl. No.', 'Item Description', 'Quantity', 'Units', 'Estimated Rate', 'TOTAL AMOUNT Without Taxes', 'TOTAL AMOUNT In Words'],
            [1.00, 'Sample earthwork in excavation by mechanical means in all kinds of soil', 1200, 'Cum', 180, 216000, 'INR Two Lakh Sixteen Thousand Only'],
            [2.00, 'Sample cement concrete 1:2:4 in drain bed including curing', 80, 'Cum', 6450, 516000, 'INR Five Lakh Sixteen Thousand Only'],
            [3.00, 'Sample stone pitching on drain sides with cement mortar', 600, 'Sqm', 260, 156000, 'INR One Lakh Fifty Six Thousand Only'],
        ];
        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, $this->book(['BoQ1' => $rows], 'schedule.xls', 'Xls'));

        $this->assertSame([], $preview['errors']);
        $this->assertSame(['E', 'F', 'G'], [$this->columnOf($preview, 'rate'), $this->columnOf($preview, 'amount'), $this->columnOf($preview, 'remark')]);
        $this->assertSame([3, '888000.00'], [$preview['line_count'], $preview['lines_total']]);

        $this->actingAs($this->owner);
        $tender = $this->makeTender($this->org);
        $this->post(route('crm.boq.import', $tender), ['file' => $this->book(['BoQ1' => $rows], 'schedule.xls', 'Xls')])->assertSessionHasNoErrors();
        $this->assertSame(['180.00', '6450.00', '260.00'], CrmTenderBoqItem::where('tender_id', $tender->id)->orderBy('sort')->pluck('rate')->all());
    }

    public function test_a_rate_split_into_supply_and_installation_is_not_read_as_one_part(): void
    {
        $rows = [
            ['Sr No', 'Description', 'Unit', 'Qty', 'Rate (Rs.)', null, 'Amount (Rs.)', null, null],
            [null, null, null, null, 'Supply', 'Installation', 'Supply', 'Installation', 'Total'],
            [1, 'Sample 25 mm CPVC pipe', 'Rmt', 100, 180, 60, 18000, 6000, 24000],
            [2, 'Sample ball valve 25 mm', 'Nos', 10, 900, 150, 9000, 1500, 10500],
            [3, 'Sample wash basin', 'Nos', 4, 3500, 800, 14000, 3200, 17200],
            [null, 'Total', null, null, null, null, 41000, 10700, 51700],
        ];
        $merges = ['A1:A2', 'B1:B2', 'C1:C2', 'D1:D2', 'E1:F1', 'G1:I1'];
        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, $this->sheet($rows, merges: $merges));

        // Only one column can be the rate: the person chooses, and nothing is imported until then.
        $this->assertContains('rate', array_column($preview['errors'], 'field'));
        $this->assertStringContainsString('Column F', collect($preview['errors'])->firstWhere('field', 'rate')['message']);
        $this->get(route('boq.sheet.preview', $project))->assertDontSee('Import these');

        // Read through the total amount: the rate is the amount divided by the quantity.
        $this->choose($project, ['fields' => [5 => '', 6 => '', 7 => '', 8 => '', 9 => 'amount']]);
        $preview = $this->preview($project);
        $this->assertSame([], $preview['errors']);
        $this->assertSame(['51700.00', true], [$preview['lines_total'], $preview['subtotal_matches']]);
        $this->confirm($project)->assertSessionHas('success');
        $this->assertSame(['240.00', '1050.00', '4300.00'], BoqItem::where('project_id', $project->id)->orderBy('sort')->pluck('rate')->all());

        $this->actingAs($this->owner);
        $tender = $this->makeTender($this->org);
        $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet($rows, merges: $merges)])->assertSessionHasErrors('file');
        $this->assertSame(0, CrmTenderBoqItem::count());
    }

    public function test_a_second_table_beside_the_first_is_not_dropped_unseen(): void
    {
        $rows = [
            ['CIVIL', null, null, null, null, null, null, 'ELECTRICAL'],
            ['Sr', 'Description', 'Unit', 'Qty', 'Rate', 'Amount', null, 'Sr', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
            [1, 'Sample excavation', 'Cum', 80, 260, 20800, null, 1, 'Sample wiring point', 'Pt', 40, 900, 36000],
            [2, 'Sample PCC', 'Cum', 20, 5500, 110000, null, 2, 'Sample DB 8 way', 'Nos', 2, 6500, 13000],
            [3, 'Sample brickwork', 'Cum', 40, 5730, 229200, null, 3, 'Sample LED fitting', 'Nos', 30, 1200, 36000],
            [null, 'Total', null, null, null, 360000, null, null, 'Total', null, null, null, 85000],
        ];
        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, $this->sheet($rows));

        $this->assertNotSame([], $preview['errors']);
        $this->assertStringContainsString('Column L', collect($preview['errors'])->firstWhere('field', 'rate')['message']);

        $this->actingAs($this->owner);
        $tender = $this->makeTender($this->org);
        $this->post(route('crm.boq.import', $tender), ['file' => $this->sheet($rows)])->assertSessionHasErrors('file');
        $this->assertSame(0, CrmTenderBoqItem::count());
    }

    public function test_columns_past_z_are_read(): void
    {
        $heading = ['Sr No', 'Description', 'Unit'];

        for ($floor = 1; $floor <= 22; $floor++) {
            $heading[] = 'Floor '.$floor;
        }

        $rows = [[...$heading, 'Total Qty', 'Rate', 'Amount']];

        foreach ([1 => 100, 2 => 200, 3 => 300] as $item => $rate) {
            $rows[] = [$item, 'Sample item '.$item, 'cum', ...array_fill(0, 22, $item), 22 * $item, $rate, 22 * $item * $rate];
        }

        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, $this->sheet($rows));

        $this->assertSame(['Z', 'AA', 'AB'], [$this->columnOf($preview, 'qty'), $this->columnOf($preview, 'rate'), $this->columnOf($preview, 'amount')]);
        $this->confirm($project)->assertSessionHas('success');
        $this->assertSame(['100.00', '200.00', '300.00'], BoqItem::where('project_id', $project->id)->orderBy('sort')->pluck('rate')->all());
    }

    public function test_a_rate_given_per_ten_or_a_hundred_units(): void
    {
        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, $this->sheet([
            ['Sr No', 'Description', 'Qty', 'Unit', 'Rate', 'Per', 'Amount'],
            ['1', 'Sample brickwork', 45, 'Cum', 6100, '1 Cum', 274500],
            ['2', 'Sample whitewash', 900, 'Sqm', 450, '100 Sqm', 4050],
            ['3', 'Sample steel', 1500, 'Kg', 6500, 'Qtl', 97500],
            [null, 'Total', null, null, null, null, 376050],
        ]));

        $this->assertSame('F', $this->columnOf($preview, 'per'));
        $this->assertSame([], $preview['mismatches']);
        $this->assertSame(['376050.00', true], [$preview['lines_total'], $preview['subtotal_matches']]);

        $this->confirm($project)->assertSessionHas('success');
        $this->assertSame([['cum', '6100.00'], ['sqm', '4.50'], ['kg', '65.00']], BoqItem::where('project_id', $project->id)->orderBy('sort')->get()->map(fn (BoqItem $line) => [$line->unit, $line->rate])->all());
    }

    public function test_a_serial_typed_as_a_number_that_repeats_is_named(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(['Item No.', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'], null, 'A1');

        foreach ([1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.9, 1.10, 1.11, 1.12] as $index => $serial) {
            $sheet->fromArray([$serial, 'Sample item '.($index + 1), 'nos', 1, 10, 10], null, 'A'.($index + 2));
        }

        $sheet->getStyle('A2:A13')->getNumberFormat()->setFormatCode('0.00');
        $path = tempnam(sys_get_temp_dir(), 'serial').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, new UploadedFile($path, 'serials.xlsx', null, null, true));

        $this->assertContains('Item code 1.1 is used more than once in the file. This line was given 1.1.1.', array_column(array_filter($preview['notes'], fn (array $note) => $note['row'] === 11), 'message'));
    }

    public function test_a_heading_far_down_the_sheet_can_be_found_or_typed(): void
    {
        $rows = [['GENERAL NOTES']];

        for ($note = 1; $note <= 45; $note++) {
            $rows[] = [$note.'. Sample general condition number '.$note.' applies to all items of this schedule.'];
        }

        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, $this->sheet([...$rows, self::heading(), ['1', 'Sample excavation', 'Cum', 80, 260, 20800], ['2', 'Sample PCC', 'Cum', 12, 5400, 64800]]));

        $this->assertTrue($preview['found']);
        $this->assertSame(47, $preview['heading_row']);
        $this->assertArrayHasKey(47, $preview['candidates']);

        // Below more rows of two cells than are looked at, the row can be typed.
        $rows = [];

        for ($note = 1; $note <= 41; $note++) {
            $rows[] = ['Note '.$note, 'Sample condition text '.$note];
        }

        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, $this->sheet([...$rows, self::heading(), ['1', 'Sample excavation', 'Cum', 80, 260, 20800]]));
        $this->assertFalse($preview['found']);

        $this->choose($project, ['row_typed' => 42])->assertRedirect(route('boq.sheet.preview', $project));
        $preview = $this->preview($project);
        $this->assertSame([true, 42, 1], [$preview['found'], $preview['heading_row'], $preview['line_count']]);
        $this->get(route('boq.sheet.preview', $project))->assertSee('Or type the row number');
    }

    public function test_codes_the_importer_makes_up_never_overwrite_other_lines_of_the_project(): void
    {
        $heading = self::heading();
        $file = fn () => $this->book([
            'Civil' => [$heading, [1, 'Sample excavation', 'Cum', 80, 260, 20800], [2, 'Sample PCC', 'Cum', 12, 5400, 64800], [3, 'Sample brick masonry', 'Cum', 20, 5000, 100000]],
            'Electrical' => [$heading, [1, 'Sample wiring point', 'Pt', 40, 900, 36000], [2, 'Sample DB 8 way', 'Nos', 2, 6500, 13000]],
        ]);
        $project = $this->makeProject($this->org);

        $this->staged($project, $file());
        $this->confirm($project)->assertSessionHas('success');

        // The second sheet numbers its lines from 1 too. They are added under new codes, and the civil lines stay.
        $this->staged($project, $file());
        $this->choose($project, ['sheet' => 1]);
        $preview = $this->preview($project);
        $this->assertSame([['1', '1-2', 'Sample excavation'], ['2', '2-2', 'Sample PCC']], array_map(fn (array $line) => [$line['from'], $line['to'], $line['existing']], $preview['renumbered']));
        $this->get(route('boq.sheet.preview', $project))->assertSee('Lines given a new item code');

        $this->confirm($project)->assertSessionHas('success');
        $lines = $this->lines($project);
        $this->assertSame(['1', '2', '3', '1-2', '2-2'], array_map('strval', array_keys($lines)));
        $this->assertSame(['Sample excavation', 'Sample PCC', 'Sample wiring point'], [$lines[1]->description, $lines[2]->description, $lines['1-2']->description]);

        // The same civil sheet again updates its own lines in place.
        $this->staged($project, $file());
        $this->choose($project, ['sheet' => 0]);
        $this->assertSame([], $this->preview($project)['renumbered']);
        $this->confirm($project)->assertSessionHas('success');
        $this->assertSame(5, BoqItem::where('project_id', $project->id)->count());

        // A file with its own item codes still updates the item with that code, and the page lists the change.
        $preview = $this->staged($project, $this->sheet([['Item Code', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'], ['3', 'Sample brick masonry in CM 1:4', 'Cum', 20, 5200, 104000]]));
        $this->assertSame([['3', 'Sample brick masonry', 'Sample brick masonry in CM 1:4']], array_map(fn (array $line) => [$line['code'], $line['now'], $line['after']], $preview['updates']));
    }

    /** @return list<string> */
    private static function heading(): array
    {
        return ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'];
    }
}
