<?php

namespace Tests\Feature\BoqImport;

use App\Models\AuditLog;
use App\Models\BoqImportLayout;
use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Services\Imports\BoqSheetImport;
use App\Services\Reports\BoqExcelService;
use Illuminate\Http\UploadedFile;

/** Uploading a BOQ in a contractor's own layout: read by its headings, checked, then imported. */
class BoqSheetImportTest extends BoqImportTestCase
{
    /** An engineer's estimate on DSR rates: Unit after Rate, a specification with lettered parts, and a footer. */
    private function estimate(): array
    {
        return [
            [null, 'ESTIMATE OF SAMPLE WORKS'],
            [null, 'Work: sample two-room house, Example Colony'],
            [],
            ['Sr. No.', 'DSR Code', 'Description of Item', 'Qty', 'Rate', 'Unit', 'Amount (Rs.)'],
            ['A', null, 'EARTH WORK:'],
            [null, 'X-1 2', 'Digging trenches for the sample footings in ordinary soil, with the spoil spread on site'],
            [null, null, 'a) Dug by hand', 20, 210, 'Cum', 4200],
            [null, null, 'b) Dug by excavator', 30, 90, 'Cum', 2700],
            ['B', null, 'CONCRETE WORK:'],
            [1, 'X-5 1', 'Reinforced concrete of grade M-20 cast in the sample building, steel paid separately, in'],
            [2, 'X-5 3', 'b) Footings', 10, 5000, 'Cum', 50000],
            [3, 'X-5 4', 'c) Columns', 4, 6500, 'Cum', 26000],
            [1, null, 'Steel bars for the sample frame, cut, bent and tied', 1500, 60, 'Kg', 90000],
            [],
            [null, null, 'Subtotal', null, null, 'Rs.', 172900],
            [null, null, 'Less 5% on schedule rates', null, null, null, 164255],
            [null, null, 'Electrical 3%', null, null, 'Rs.', 5187],
            [null, null, 'Plumbing 3%', null, null, 'Rs.', 5187],
            [null, null, 'Subtotal', null, null, 'Rs.', 174629],
            [null, null, 'Total cost of the work', null, 'Say', 'Rs.', 174629],
            ['Rupees one lakh seventy four thousand six hundred twenty nine only'],
            [null, null, null, null, 'Prepared by: Sample Engineer'],
        ];
    }

    public function test_the_template_and_our_own_export_still_import_straight_away(): void
    {
        $project = $this->makeProject($this->org);

        $this->upload($project, $this->workbook([$this->boqHeading(), ['Substructure', '1.1', 'Excavation in all soils', 'cum', 250.5, 185.25]]))
            ->assertRedirect(route('boq.excel.index', $project))->assertSessionHas('success');
        $this->assertNull(session(BoqSheetImport::SESSION));

        $export = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($export, app(BoqExcelService::class)->export($project));
        $this->upload($project, new UploadedFile($export, 'boq-export.xlsx', null, null, true))
            ->assertRedirect(route('boq.excel.index', $project))->assertSessionHas('success');

        $line = $this->lines($project)['1.1'];
        $this->assertSame(['Excavation in all soils', 'cum', '250.500', '185.25', 'Substructure'], [$line->description, $line->unit, $line->qty, $line->rate, $line->section->name]);
        $this->assertSame(1, BoqItem::where('project_id', $project->id)->count());
        $this->assertSame(['excel_import'], BoqRevision::where('project_id', $project->id)->pluck('source')->unique()->values()->all());
    }

    public function test_a_heading_under_a_letterhead_is_found_and_nothing_is_written_until_confirmed(): void
    {
        $project = $this->makeProject($this->org);
        $rows = [
            ['SAMPLE BUILDERS AND DEVELOPERS'], ['Plot 4, Example Road, Sample Town 400001'], ['Phone: 00000 00000'], [],
            ['BILL OF QUANTITIES'], ['Name of work: Example warehouse'], ['Client: Example Trust'], ['Date: 01-04-2026'],
            [], ['Tender No. EX/01'], ['Part I - Civil'], [],
            ['Sl. No.', 'Particulars', 'UOM', 'Qty', 'Unit Rate', 'Total Amount'],
            ['1.1', 'Clearing the site of shrubs', 'Sqm', 400, 12, 4800],
            ['1.2', 'Earth filling in plinth with approved soil', 'Cum', 60, 340, 20400],
        ];

        $preview = $this->staged($project, $this->sheet($rows, 'warehouse.xlsx'));

        $this->assertSame(13, $preview['heading_row']);
        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F'], [$this->columnOf($preview, 'serial'), $this->columnOf($preview, 'description'), $this->columnOf($preview, 'unit'), $this->columnOf($preview, 'qty'), $this->columnOf($preview, 'rate'), $this->columnOf($preview, 'amount')]);
        $this->assertSame(2, $preview['line_count']);
        $this->assertSame([], $preview['errors']);
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());

        $this->get(route('boq.sheet.preview', $project))->assertSee('Check the columns before importing')->assertSee('Import these 2 lines')->assertSee('Clearing the site of shrubs');

        $this->confirm($project)->assertRedirect(route('boq.excel.index', $project))->assertSessionHas('success');

        $lines = $this->lines($project);
        $this->assertSame(['1.1', '1.2'], array_map('strval', array_keys($lines)));
        $this->assertSame(['sqm', '400.000', '12.00'], [$lines['1.1']->unit, $lines['1.1']->qty, $lines['1.1']->rate]);
        $this->assertNull(session(BoqSheetImport::SESSION));
        $this->assertDatabaseHas('audit_logs', ['action' => 'boq.sheet_import_confirmed', 'auditable_id' => $project->id]);
        $this->assertSame('Imported from warehouse.xlsx after checking its columns', BoqRevision::where('project_id', $project->id)->value('reason'));
    }

    public function test_two_row_headings_in_merged_cells(): void
    {
        $project = $this->makeProject($this->org);
        $rows = [
            ['S.No.', 'Description of Work', 'Unit', 'Quantity', 'Rate', 'Amount'],
            [null, null, null, null, '(Rs.)', 'in Rs.'],
            [1, 'Brick masonry in cement mortar 1:6', 'cum', 12.5, 5200, 65000],
            [2, 'Plaster 12 mm thick in cement mortar 1:4', 'sqm', 300, 240, 72000],
        ];

        $preview = $this->staged($project, $this->sheet($rows, merges: ['A1:A2', 'B1:B2', 'C1:C2', 'D1:D2']));

        $this->assertSame([1, 2], [$preview['heading_row'], $preview['span']]);
        $this->assertSame('E', $this->columnOf($preview, 'rate'));
        $this->assertSame('F', $this->columnOf($preview, 'amount'));
        $this->assertSame(2, $preview['line_count']);
        $this->assertSame([], $preview['errors']);
        $this->assertSame('137000.00', $preview['lines_total']);
    }

    public function test_marathi_headings_and_devanagari_digits(): void
    {
        $project = $this->makeProject($this->org);
        $rows = [
            ['नमुना घर - अंदाजपत्रक'],
            [],
            ['अ.क्र.', 'तपशील', 'एकक', 'प्रमाण', 'दर', 'रक्कम'],
            ['१', 'पायासाठी खोदकाम', 'घ.मी.', '१०', '३५०', '३,५००'],
            ['२', 'विटांचे बांधकाम', 'घ.मी.', '१२.५', '५,२००', '६५,०००'],
            ['३', 'गिलावा', 'चौ.मी.', '३००', '२४०', '७२,०००'],
        ];

        $preview = $this->staged($project, $this->sheet($rows, 'marathi.xlsx'));

        $this->assertSame(3, $preview['heading_row']);
        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F'], [$this->columnOf($preview, 'serial'), $this->columnOf($preview, 'description'), $this->columnOf($preview, 'unit'), $this->columnOf($preview, 'qty'), $this->columnOf($preview, 'rate'), $this->columnOf($preview, 'amount')]);
        $this->assertSame([], $preview['mismatches']);

        $this->confirm($project)->assertSessionHas('success');

        $lines = $this->lines($project);
        $this->assertSame(['1', '2', '3'], array_map('strval', array_keys($lines)));
        $this->assertSame(['पायासाठी खोदकाम', 'cum', '10.000', '350.00'], [$lines['1']->description, $lines['1']->unit, $lines['1']->qty, $lines['1']->rate]);
        $this->assertSame(['sqm', '5200.00'], [$lines['3']->unit, $lines['2']->rate]);
    }

    public function test_hindi_headings(): void
    {
        $project = $this->makeProject($this->org);
        $rows = [
            ['क्र.सं.', 'कार्य का विवरण', 'इकाई', 'मात्रा', 'दर', 'राशि'],
            ['1', 'नींव की खुदाई', 'घ.मी.', '20', '300', '6,000'],
            ['2', 'सीमेंट कंक्रीट', 'घ.मी.', '5', '4,500', '22,500'],
        ];

        $preview = $this->staged($project, $this->sheet($rows, 'hindi.xlsx'));

        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F'], [$this->columnOf($preview, 'serial'), $this->columnOf($preview, 'description'), $this->columnOf($preview, 'unit'), $this->columnOf($preview, 'qty'), $this->columnOf($preview, 'rate'), $this->columnOf($preview, 'amount')]);

        $this->confirm($project)->assertSessionHas('success');
        $this->assertSame('22500.00', $this->lines($project)['2']->amount());
    }

    public function test_a_dsr_estimate_with_specifications_parts_adjustments_and_words(): void
    {
        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, $this->sheet($this->estimate(), 'estimate.xlsx'));

        $this->assertSame(4, $preview['heading_row']);
        $this->assertSame('E', $this->columnOf($preview, 'rate'));
        $this->assertSame('F', $this->columnOf($preview, 'unit'));
        $this->assertSame(5, $preview['line_count']);
        $this->assertSame(['-5', '3', '3'], array_column($preview['adjustments'], 'percent'));
        $this->assertSame(['-8645.00', '5187.00', '5187.00'], array_column($preview['adjustments'], 'effect'));
        $this->assertSame('total', $preview['adjustments'][0]['shows']);
        $this->assertSame(['172900.00', '172900', true], [$preview['lines_total'], $preview['file_subtotal'], $preview['subtotal_matches']]);
        $this->assertSame(['174629.00', '174629', true], [$preview['with_adjustments'], $preview['file_total'], $preview['total_matches']]);

        $kinds = array_column($preview['others'], 'kind', 'row');
        $this->assertSame(['section', 'spec', 'section', 'spec', 'subtotal', 'adjustment', 'adjustment', 'adjustment', 'subtotal', 'total', 'words', 'after_total'], array_values($kinds));
        $this->assertSame('words', $kinds[21]);

        $this->confirm($project)->assertSessionHas('success');

        $lines = $this->lines($project);
        $this->assertSame(['X-1 2a', 'X-1 2b', 'X-5 3', 'X-5 4', 'B.1'], array_map('strval', array_keys($lines)));
        $this->assertSame('Dug by hand - Digging trenches for the sample footings in ordinary soil, with the spoil spread on site', $lines['X-1 2a']->description);
        $this->assertStringContainsString('Specification: Digging trenches for the sample footings', $lines['X-1 2a']->details);
        $this->assertSame(['cum', '20.000', '210.00', 'EARTH WORK'], [$lines['X-1 2a']->unit, $lines['X-1 2a']->qty, $lines['X-1 2a']->rate, $lines['X-1 2a']->section->name]);
        $this->assertStringStartsWith('Footings - Reinforced concrete of grade M-20', $lines['X-5 3']->description);
        $this->assertSame(['kg', 'CONCRETE WORK'], [$lines['B.1']->unit, $lines['B.1']->section->name]);
        $this->assertSame('excavation', $lines['X-1 2b']->category);
        // Nothing of the footer became a line.
        $this->assertSame(5, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_rate_only_lines_and_subtotals(): void
    {
        $project = $this->makeProject($this->org);
        $rows = [
            ['Item No.', 'Description', 'Unit', 'Qty', 'Rate (Rs.)', 'Amount (Rs.)'],
            ['1', 'Supply of sample pipes 110 mm', 'Rmt', 120, 450, 54000],
            ['2', 'Extra over for bends', 'Nos', 'QRO', 300, null],
            ['3', 'Dismantling old fittings', 'Job', 'Rate only', 1500, null],
            ['4', 'Testing charges', 'LS', null, 2500, null],
            [null, 'Sub Total', null, null, null, 54000],
            ['5', 'Providing sample valves', 'Nos', 10, 1200, 12000],
            [null, 'Total c/f', null, null, null, 66000],
            [null, 'Grand Total', null, null, null, 66000],
        ];

        $preview = $this->staged($project, $this->sheet($rows));

        $this->assertSame(5, $preview['line_count']);
        $this->assertSame([3, 4, 5], array_column($preview['rate_only'], 'row'));
        $this->assertSame(['subtotal', 'carried', 'total'], array_column($preview['others'], 'kind'));
        $this->assertSame('66000.00', $preview['lines_total']);
        $this->get(route('boq.sheet.preview', $project))->assertSee('Rate-only lines')->assertSee('no quantity is made up');

        $this->confirm($project)->assertSessionHas('success');

        $lines = $this->lines($project);
        $this->assertSame(['0.000', '300.00', 'nos'], [$lines['2']->qty, $lines['2']->rate, $lines['2']->unit]);
        $this->assertSame(['0.000', 'LS'], [$lines['3']->qty, $lines['3']->unit]);
        $this->assertStringContainsString('Rate only', $lines['4']->details);
        $this->assertSame('120.000', $lines['1']->qty);
    }

    public function test_a_labour_rate_card_with_no_quantity_column(): void
    {
        $project = $this->makeProject($this->org);
        $rows = [
            ['नमुना बांधकाम - मजुरी दरपत्रक'],
            [],
            ['अ.क्र.', 'कामाचा तपशील', 'एकक', 'दर'],
            ['१', 'वीट बांधकाम मजुरी', 'घ.मी.', '१,२५०'],
            ['२', 'गिलावा मजुरी', 'चौ.मी.', '९०'],
            ['३', 'फरशी बसवणे मजुरी', 'चौ.फुट', '२५'],
        ];

        $preview = $this->staged($project, $this->sheet($rows, 'rate-card.xlsx'));

        $this->assertNull($this->columnOf($preview, 'qty'));
        $this->assertSame(3, count($preview['rate_only']));

        $this->confirm($project)->assertSessionHas('success');

        $lines = $this->lines($project);
        $this->assertSame(['cum', 'sqm', 'sqft'], [$lines['1']->unit, $lines['2']->unit, $lines['3']->unit]);
        $this->assertSame(['0.000', '1250.00'], [$lines['1']->qty, $lines['1']->rate]);
    }

    public function test_no_code_column_and_the_unit_only_in_the_quantity_heading(): void
    {
        $project = $this->makeProject($this->org);
        $rows = [
            ['Description', 'Qty (Sqm)', 'Rate per Sqm', 'Amount'],
            ['Vitrified tile flooring 600 x 600', 120, 1100, 132000],
            ['Ceramic wall tiles in toilets', 80, 900, 72000],
        ];

        $preview = $this->staged($project, $this->sheet($rows));

        $this->assertSame('sqm', $preview['heading_unit']);
        $this->assertFalse($preview['needs_unit']);

        $this->confirm($project)->assertSessionHas('success');

        $lines = $this->lines($project);
        $this->assertSame(['1', '2'], array_map('strval', array_keys($lines)));
        $this->assertSame(['sqm', 'sqm'], [$lines['1']->unit, $lines['2']->unit]);
    }

    public function test_a_sheet_with_no_unit_anywhere_asks_for_one(): void
    {
        $project = $this->makeProject($this->org);
        $rows = [['Sr No', 'Description', 'Qty', 'Rate'], [1, 'Sample item one', 5, 100], [2, 'Sample item two', 6, 200]];

        $preview = $this->staged($project, $this->sheet($rows));

        $this->assertTrue($preview['needs_unit']);
        $this->assertSame([['row' => 0, 'field' => 'unit', 'message' => 'This sheet has no unit column. Type the unit for its lines above.']], $preview['errors']);
        $this->get(route('boq.sheet.preview', $project))->assertDontSee('Import these');
        $this->post(route('boq.sheet.confirm', $project), ['version' => $preview['version']])->assertRedirect(route('boq.sheet.preview', $project));
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());

        $this->choose($project, ['unit' => 'Nos'])->assertRedirect(route('boq.sheet.preview', $project));
        $this->confirm($project)->assertSessionHas('success');
        $this->assertSame(['nos', 'nos'], array_values(array_map(fn (BoqItem $line) => $line->unit, $this->lines($project))));
    }

    public function test_the_file_kind_is_read_from_its_content_not_its_name(): void
    {
        $rows = [['Item No', 'Particulars of work', 'Unit', 'Qty', 'Rate'], ['1', 'Sample work A', 'cum', 2, 100], ['2', 'Sample work B', 'sqm', 3, 50]];

        foreach ([
            'xlsx saved as .xls' => $this->sheet([...$rows, ['3', 'Sample work C', 'nos', 1, 10]], 'estimate.xls', 'Xlsx'),
            'a real BIFF .xls' => $this->sheet([...$rows, ['3', 'Sample work C', 'nos', 1, 10]], 'estimate.xls', 'Xls'),
            'an OpenDocument sheet' => $this->sheet([...$rows, ['3', 'Sample work C', 'nos', 1, 10]], 'estimate.ods', 'Ods'),
            'a CSV with Indian numbers' => $this->csvFile("Item No,Particulars of work,Unit,Qty,Rate\n1,Sample work A,cum,2,100\n2,Sample work B,sqm,3,50\n3,\"Sample work C, extra\",nos,\"1,00,000.5\",\"₹ 1,250/-\"\n"),
        ] as $label => $file) {
            $project = $this->makeProject($this->org);
            $preview = $this->staged($project, $file);

            $this->assertSame([], $preview['errors'], $label);
            $this->confirm($project)->assertSessionHas('success');
            $this->assertSame(['1', '2', '3'], array_map('strval', array_keys($this->lines($project))), $label);
        }

        $this->assertSame(['100000.500', '1250.00'], [$this->lines($project)['3']->qty, $this->lines($project)['3']->rate]);
    }

    public function test_the_sheet_with_the_most_priced_rows_is_picked_and_can_be_switched(): void
    {
        $project = $this->makeProject($this->org);
        $file = $this->book([
            'Measurement' => [['Description', 'Nos', 'L', 'B', 'D', 'Qty'], ['Footing F1', 4, 1.5, 1.5, 1, 9], ['Footing F2', 2, 1.2, 1.2, 1, 2.88]],
            'Abstract' => [['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'], [1, 'Excavation for footings', 'cum', 11.88, 250, 2970], [2, 'PCC below footings', 'cum', 1.2, 4500, 5400]],
        ]);

        $preview = $this->staged($project, $file);

        $this->assertSame(1, $preview['sheet']);
        $this->assertSame('priced', $preview['picked']);
        $this->assertSame(['Measurement', 'Abstract'], array_column($preview['sheets'], 'name'));
        $this->assertSame(2, $preview['line_count']);

        $this->choose($project, ['sheet' => 0])->assertRedirect(route('boq.sheet.preview', $project));
        $this->assertSame(0, $this->preview($project)['sheet']);

        $this->choose($project, ['sheet' => 1]);
        $this->confirm($project)->assertSessionHas('success');
        $this->assertSame('PCC below footings', $this->lines($project)['2']->description);
    }

    public function test_fifteen_hundred_rows(): void
    {
        $project = $this->makeProject($this->org);
        $rows = [['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount']];

        for ($i = 1; $i <= 1500; $i++) {
            $rows[] = [$i, 'Sample work item number '.$i, 'nos', $i % 7 + 1, 10 + $i % 13, ($i % 7 + 1) * (10 + $i % 13)];
        }

        $preview = $this->staged($project, $this->sheet($rows));

        $this->assertSame(1500, $preview['line_count']);
        $this->assertCount(BoqSheetImport::SHOWN_LINES, $preview['lines']);
        $this->assertSame([], $preview['errors']);

        $this->confirm($project)->assertSessionHas('success');
        $this->assertSame(1500, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_amounts_that_do_not_match_are_listed_and_never_corrected(): void
    {
        $project = $this->makeProject($this->org);
        $rows = [
            ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
            [1, 'Sample line that adds up', 'cum', 10, 100, 1000],
            [2, 'Sample line whose amount is wrong', 'cum', 10, 100, 1500],
            [3, 'Sample line rounded to the rupee', 'cum', 3.333, 100, 333],
        ];

        $preview = $this->staged($project, $this->sheet($rows));

        $this->assertSame([3], array_column($preview['mismatches'], 'row'));
        $this->assertSame(['1000.00', '1500'], [$preview['mismatches'][0]['computed'], $preview['mismatches'][0]['amount']]);
        $this->get(route('boq.sheet.preview', $project))->assertSee('Amounts that are not quantity times rate')->assertSee('Nothing here was corrected.');

        $this->confirm($project)->assertSessionHas('success');
        $this->assertSame(['10.000', '100.00'], [$this->lines($project)['2']->qty, $this->lines($project)['2']->rate]);
    }

    public function test_a_column_can_be_changed_or_left_out_and_the_heading_row_chosen(): void
    {
        $project = $this->makeProject($this->org);
        $rows = [
            ['Abstract of cost'],
            ['No.', 'Work', 'Ref', 'Unit', 'Nos', 'Rate', 'Amt'],
            ['1', 'Sample door frames in hard wood', 'D-14', 'nos', 6, 3200, 19200],
            ['2', 'Sample window grills', 'W-03', 'nos', 4, 1800, 7200],
        ];

        $preview = $this->staged($project, $this->sheet($rows));
        $this->assertNull($this->columnOf($preview, 'qty'));
        $this->assertContains('No quantity in the file. It was worked out as amount divided by rate.', array_column($preview['notes'], 'message'));

        // Column E holds the numbers of doors: read it as the quantity, and keep the reference in the details.
        $this->choose($project, ['fields' => [1 => 'serial', 2 => 'description', 3 => 'reference', 4 => 'unit', 5 => 'qty', 6 => 'rate', 7 => 'amount']]);
        $preview = $this->preview($project);
        $this->assertSame('E', $this->columnOf($preview, 'qty'));
        $this->assertSame('chosen', collect($preview['columns'])->firstWhere('letter', 'E')['how']);
        $this->assertNotContains('No quantity in the file. It was worked out as amount divided by rate.', array_column($preview['notes'], 'message'));

        // A heading row picked by hand is read afresh.
        $this->choose($project, ['row' => 1, 'span' => 1]);
        $this->assertFalse($this->preview($project)['found']);
        $this->get(route('boq.sheet.preview', $project))->assertSee('Choose the row that holds the headings');
        $this->choose($project, ['row' => 2, 'span' => 1]);
        $this->choose($project, ['fields' => [3 => 'reference', 5 => 'qty']]);

        $this->confirm($project)->assertSessionHas('success');

        $line = $this->lines($project)['1'];
        $this->assertSame(['6.000', '3200.00'], [$line->qty, $line->rate]);
        $this->assertStringContainsString('Reference: D-14', $line->details);
    }

    public function test_a_saved_layout_maps_the_next_file_and_another_company_never_sees_it(): void
    {
        $project = $this->makeProject($this->org);
        $heading = ['No.', 'Work', 'Ref', 'Unit', 'Nos', 'Rate', 'Amt'];

        $this->staged($project, $this->sheet([$heading, ['1', 'Sample door frames', 'D-14', 'nos', 6, 3200, 19200]], 'first.xlsx'));
        $this->choose($project, ['fields' => [3 => 'reference', 5 => 'qty']]);
        $this->confirm($project)->assertSessionHas('success');

        $this->assertDatabaseHas('boq_import_layouts', ['organization_id' => $this->org->id, 'file_name' => 'first.xlsx', 'uses' => 1]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'created', 'auditable_type' => (new BoqImportLayout)->getMorphClass()]);

        // The next file in the same layout maps itself: one click to confirm.
        $second = $this->makeProject($this->org);
        $preview = $this->staged($second, $this->sheet([['Estimate two'], $heading, ['1', 'Sample shutters', 'S-02', 'nos', 3, 2500, 7500]], 'second.xlsx'));
        $this->assertSame('saved', $preview['picked']);
        $this->assertSame('first.xlsx', $preview['saved']['file']);
        $this->assertSame('E', $this->columnOf($preview, 'qty'));
        $this->get(route('boq.sheet.preview', $second))->assertSee('laid out like one imported before');
        $this->confirm($second)->assertSessionHas('success');
        $this->assertSame('3.000', $this->lines($second)['1']->qty);
        $this->assertDatabaseHas('boq_import_layouts', ['organization_id' => $this->org->id, 'uses' => 2]);

        // Another company with the same layout starts from detection, not from this company's choices.
        $other = $this->tenant('Other Co', 'business');
        $otherPm = $this->member($other, 'pm');
        $otherProject = $this->makeProject($other);
        $preview = $this->staged($otherProject, $this->sheet([$heading, ['1', 'Sample frames', 'D-1', 'nos', 2, 100, 200]]), $otherPm);
        $this->assertNull($preview['saved']);
        $this->assertNull($this->columnOf($preview, 'qty'));
    }

    public function test_saved_layouts_are_listed_and_removed_by_whoever_can_import_the_boq(): void
    {
        $project = $this->makeProject($this->org);
        $this->staged($project, $this->sheet([['Sr', 'Particulars', 'UOM', 'Qty', 'Rate'], [1, 'Sample item', 'nos', 1, 5]], 'layout.xlsx'));
        $this->confirm($project)->assertSessionHas('success');
        $layout = BoqImportLayout::firstOrFail();

        $this->actingAs($this->pm)->get(route('boq.excel.index', $project))->assertOk()->assertSee('Saved file layouts')->assertSee('layout.xlsx');
        $this->actingAs($this->member($this->org, 'engineer'))->get(route('boq.excel.index', $project))->assertOk()->assertDontSee('Saved file layouts');

        // An engineer cannot remove it, another company cannot see it, and the free plan has no such screen.
        $this->actingAs($this->member($this->org, 'engineer'))->delete(route('boq.layouts.destroy', $layout))->assertForbidden();
        $other = $this->tenant('Other Co', 'business');
        $this->actingAs($this->member($other, 'pm'))->delete(route('boq.layouts.destroy', $layout))->assertNotFound();
        $this->assertDatabaseHas('boq_import_layouts', ['id' => $layout->id]);

        $this->actingAs($this->pm)->delete(route('boq.layouts.destroy', $layout))->assertSessionHas('success');
        $this->assertDatabaseMissing('boq_import_layouts', ['id' => $layout->id]);
        $this->assertTrue(AuditLog::where('action', 'deleted')->where('auditable_id', $layout->id)->exists());
    }

    public function test_permissions_and_tenant_isolation_of_the_column_check(): void
    {
        $project = $this->makeProject($this->org);
        $file = fn () => $this->sheet([['Sr', 'Particulars', 'UOM', 'Qty', 'Rate'], [1, 'Sample item', 'nos', 1, 5]]);
        $this->staged($project, $file());
        $token = session(BoqSheetImport::SESSION);

        // An engineer and a viewer may not use any of it.
        foreach (['engineer', 'viewer'] as $role) {
            $user = $this->member($this->org, $role);
            $this->actingAs($user)->get(route('boq.sheet.preview', $project))->assertForbidden();
            $this->actingAs($user)->post(route('boq.sheet.choose', $project), ['token' => $token, 'unit' => 'nos'])->assertForbidden();
            $this->actingAs($user)->post(route('boq.sheet.confirm', $project), ['token' => $token, 'version' => 1])->assertForbidden();
            $this->actingAs($user)->post(route('boq.sheet.discard', $project), ['token' => $token])->assertForbidden();
        }

        // Someone else in the company cannot use this person's file, nor can it go to another project.
        $owner = $this->owner;
        $this->actingAs($owner)->post(route('boq.sheet.confirm', $project), ['token' => $token, 'version' => 1])->assertRedirect(route('boq.excel.index', $project));
        $otherProject = $this->makeProject($this->org);
        $this->actingAs($this->pm)->post(route('boq.sheet.confirm', $otherProject), ['token' => $token, 'version' => 1])->assertRedirect(route('boq.excel.index', $otherProject));
        $this->assertSame(0, BoqItem::withoutGlobalScopes()->count());

        // Another company's project is not found, and the free plan has no reports module.
        $other = $this->tenant('Other Co', 'business');
        $foreign = $this->makeProject($other);
        $this->actingAs($this->pm)->get(route('boq.sheet.preview', $foreign))->assertNotFound();
        $this->actingAs($this->pm)->post(route('boq.sheet.confirm', $foreign), ['token' => $token, 'version' => 1])->assertNotFound();
        $this->actingAs($this->pm)->post(route('boq.sheet.choose', $foreign), ['token' => $token, 'unit' => 'nos'])->assertNotFound();
        $this->actingAs($this->pm)->post(route('boq.sheet.discard', $foreign), ['token' => $token])->assertNotFound();
        $free = $this->tenant('Small Co', 'free');
        $freeProject = $this->makeProject($free);
        $this->actingAs($this->member($free, 'owner'))->get(route('boq.sheet.preview', $freeProject))->assertForbidden();

        // A stale page cannot confirm what it did not show.
        $this->actingAs($this->pm)->post(route('boq.sheet.confirm', $project), ['token' => $token, 'version' => 999])->assertRedirect(route('boq.sheet.preview', $project))->assertSessionHasErrors('version');
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());

        // Discarding forgets the file.
        $this->actingAs($this->pm)->post(route('boq.sheet.discard', $project), ['token' => $token])->assertSessionHas('success');
        $this->actingAs($this->pm)->get(route('boq.sheet.preview', $project))->assertRedirect(route('boq.excel.index', $project));
    }

    public function test_a_template_shaped_file_with_problems_offers_to_be_read_by_its_own_headings(): void
    {
        $project = $this->makeProject($this->org);
        $rows = [
            $this->boqHeading(),
            ['', 'A', 'EARTH WORK', '', '', ''],
            ['', '1', 'Sample excavation', 'cum', 10, 200],
        ];

        $this->upload($project, $this->workbook($rows))->assertRedirect(route('boq.excel.index', $project));
        $this->assertNotEmpty(session('boq_import.errors'));
        $this->assertTrue(session('boq_import.sheet_preview'));
        $this->get(route('boq.excel.index', $project))->assertSee('Read it by its own headings');
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_the_preview_reads_in_hindi_and_marathi(): void
    {
        $project = $this->makeProject($this->org);
        $this->staged($project, $this->sheet([['Sr', 'Particulars', 'UOM', 'Qty', 'Rate'], [1, 'Sample item', 'nos', 1, 5]]));

        $this->pm->update(['locale' => 'hi']);
        $this->actingAs($this->pm->fresh())->get(route('boq.sheet.preview', $project))->assertOk()->assertSee('इम्पोर्ट से पहले कॉलम जाँचें')->assertSee('ये 1 लाइनें इम्पोर्ट करें');

        $this->pm->update(['locale' => 'mr']);
        $this->actingAs($this->pm->fresh())->get(route('boq.sheet.preview', $project))->assertOk()->assertSee('इम्पोर्ट करण्यापूर्वी स्तंभ तपासा');
    }
}
