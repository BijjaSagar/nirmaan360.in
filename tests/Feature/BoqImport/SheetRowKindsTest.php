<?php

namespace Tests\Feature\BoqImport;

/**
 * How the rows of a contractor's sheet are told apart: part totals, numbered heads and wrapped text, the
 * heading and its column numbers printed again at a page break, carried totals in their many wordings,
 * section rows that carry their own total, round-off and tax rows, percentages inside an item's text,
 * items whose text starts with "Total", and text held in merged cells.
 */
class SheetRowKindsTest extends BoqImportTestCase
{
    private const HEADING = ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'];

    public function test_a_part_total_in_the_middle_is_not_the_end_of_the_sheet(): void
    {
        $read = $this->classified([
            self::HEADING,
            ['A', 'CIVIL WORKS'],
            ['1', 'Sample excavation', 'Cum', 80, 260, 20800],
            ['2', 'Sample PCC 1:4:8', 'Cum', 12, 5400, 64800],
            [null, 'Total Amount of Part A', null, null, null, 85600],
            ['B', 'FINISHING WORKS'],
            ['3', 'Providing and applying sample plaster in cement mortar 1:4 on walls of the sample building, complete'],
            [null, '(a) 12 mm thick on inside walls', 'Sqm', 600, 230, 138000],
            [null, '(b) 20 mm thick on outside walls', 'Sqm', 300, 280, 84000],
            ['4', 'Sample painting', 'Sqm', 900, 120, 108000],
            [null, 'Total Amount of Part B', null, null, null, 330000],
            [null, 'Grand Total (A+B)', null, null, null, 415600],
        ]);

        $kinds = $this->kinds($read);
        $this->assertSame(['subtotal', 'section', 'spec'], [$kinds[5], $kinds[6], $kinds[7]]);
        $this->assertSame(['A.1', 'A.2', 'B.3a', 'B.3b', 'B.4'], array_column($read['lines'], 'code'));
        $this->assertSame(['CIVIL WORKS', 'CIVIL WORKS', 'FINISHING WORKS', 'FINISHING WORKS', 'FINISHING WORKS'], array_column($read['lines'], 'section'));
        $this->assertStringContainsString('sample plaster', $read['lines'][8]['description']);
        $this->assertSame('Sample painting', $read['lines'][10]['description']);
        $this->assertSame([true, true, true], array_column($read['totals'], 'matches'));

        $read = $this->classified([
            self::HEADING,
            ['I', 'BUILDING WORK'],
            [1, 'Sample footing concrete', 'Cum', 10, 5000, 50000],
            [null, 'Estimated cost of Building Work', null, null, null, 50000],
            ['II', 'COMPOUND WALL'],
            [1, 'Sample wall masonry', 'Cum', 20, 4000, 80000],
            [null, 'Grand Total', null, null, null, 130000],
            [null, 'Prepared by: Sample Engineer'],
        ]);

        $this->assertSame(['I.1', 'II.1'], array_column($read['lines'], 'code'));
        $this->assertSame(['BUILDING WORK', 'COMPOUND WALL'], array_column($read['lines'], 'section'));
        $this->assertSame('after_total', $this->kinds($read)[8]);
    }

    public function test_a_short_numbered_head_and_text_wrapped_over_rows(): void
    {
        $read = $this->classified([
            self::HEADING,
            ['1.1', 'Sample excavation in soft soil', 'Cum', 80, 260, 20800],
            ['1.2', 'Sample PCC 1:4:8', 'Cum', 12, 5400, 64800],
            ['1.3', 'Cement plaster in CM 1:6 :'],
            [null, '(a) 12 mm thick', 'Sqm', 600, 230, 138000],
            [null, '(b) 20 mm thick', 'Sqm', 300, 280, 84000],
            ['1.4', 'Sample painting', 'Sqm', 900, 120, 108000],
        ]);

        $this->assertSame(['1.1', '1.2', '1.3a', '1.3b', '1.4'], array_column($read['lines'], 'code'));
        $this->assertSame([null, null, null, null, null], array_column($read['lines'], 'section'));
        $this->assertStringContainsString('Cement plaster in CM 1:6', $read['lines'][5]['description']);
        $this->assertSame('Sample painting', $read['lines'][7]['description']);

        $read = $this->classified([
            self::HEADING,
            ['1', 'Cement concrete 1:4:8 of sample'],
            [null, 'aggregate in foundation including'],
            [null, 'curing complete.', 'Cum', 12, 5400, 64800],
            ['2', 'Brick masonry in cement mortar'],
            [null, '1:6 in superstructure complete.', 'Cum', 45, 6100, 274500],
        ]);

        $this->assertSame(['1', '2'], array_column($read['lines'], 'code'));
        $this->assertSame([
            'Cement concrete 1:4:8 of sample aggregate in foundation including curing complete.',
            'Brick masonry in cement mortar 1:6 in superstructure complete.',
        ], array_column($read['lines'], 'description'));
        $this->assertSame([null, null], array_column($read['lines'], 'section'));
    }

    public function test_the_heading_and_its_column_numbers_printed_again_at_each_page(): void
    {
        $heading = ['Sr. No.', 'Description of Item', 'Unit', 'Qty', 'Rate', 'Amount'];
        $rows = [$heading, [1, 2, 3, 4, 5, 6]];
        $sum = 0;

        for ($i = 1; $i <= 100; $i++) {
            if ($i === 39 || $i === 77) {
                array_push($rows, [null, 'Carried over', null, null, null, $sum], $heading, [1, 2, 3, 4, 5, 6], [null, 'Brought forward', null, null, null, $sum]);
            }

            $rows[] = [$i, 'Sample item '.$i, 'nos', $i % 5 + 1, 100 + $i, ($i % 5 + 1) * (100 + $i)];
            $sum += ($i % 5 + 1) * (100 + $i);
        }

        $rows[] = [null, 'Total', null, null, null, $sum];
        $read = $this->classified($rows);

        $this->assertCount(100, $read['lines']);
        $this->assertSame(3, count(array_keys($this->kinds($read), 'column_numbers')));
        $this->assertSame(2, count(array_keys($this->kinds($read), 'heading_repeat')));

        // A heading on two rows, printed again: both rows are the heading, and no line is put in a section called "of Item".
        $heading = [['Sr.', 'Description', 'Unit', 'Quantity', 'Rate', 'Amount'], ['No.', 'of Item', null, null, '(Rs.)', '(Rs.)']];
        $rows = [['Sample Builders'], ['Example Road'], [], ...$heading];

        for ($i = 1; $i <= 60; $i++) {
            if ($i === 25 || $i === 50) {
                array_push($rows, ...$heading);
            }

            $rows[] = [$i, 'Invented item number '.$i.' of the work', 'Cum', $i, 100 + $i, $i * (100 + $i)];
        }

        $read = $this->classified($rows);
        $this->assertCount(60, $read['lines']);
        $this->assertSame(4, count(array_keys($this->kinds($read), 'heading_repeat')));
        $this->assertSame([null], array_values(array_unique(array_column($read['lines'], 'section'))));
    }

    public function test_carried_totals_in_their_many_wordings_are_never_lines(): void
    {
        $read = $this->classified([
            self::HEADING,
            [1, 'Sample excavation', 'Cum', 80, 260, 20800],
            [2, 'Sample PCC', 'Cum', 12, 5400, 64800],
            [null, 'Carried to next page', null, null, null, 85600],
            [null, 'Brought from previous page', null, null, null, 85600],
            [null, 'Sum of this page', null, null, null, 85600],
            [3, 'Sample brickwork', 'Cum', 45, 6100, 274500],
            [null, 'पुढील पानावर नेलेली रक्कम', null, null, null, 360100],
            [null, 'अगले पृष्ठ पर ले जाया गया', null, null, null, 360100],
            [null, 'Amount carried to summary', null, null, null, 360100],
            [null, 'Carried to abstract', null, null, null, 360100],
            [null, 'Carried to collection', null, null, null, 360100],
            [null, 'Amount transferred to abstract', null, null, null, 360100],
            [null, 'Amount c/o to next page', null, null, null, 360100],
        ]);

        $this->assertSame(['1', '2', '3'], array_column($read['lines'], 'code'));
        $this->assertSame('360100.00', $read['lines_total']);
        $kinds = $this->kinds($read);

        foreach ([4, 5, 8, 9, 10, 11, 12, 13, 14] as $row) {
            $this->assertSame('carried', $kinds[$row], (string) $row);
        }

        // An amount alone that is what the lines above come to is a total, checked, not a lump-sum line.
        $this->assertSame('subtotal', $kinds[6]);
    }

    public function test_a_section_row_with_its_own_total_and_round_off_or_tax_rows(): void
    {
        $read = $this->classified([
            self::HEADING,
            ['A', 'EARTH WORK', null, null, null, 85600],
            ['1', 'Sample excavation', 'Cum', 80, 260, 20800],
            ['2', 'Sample PCC', 'Cum', 12, 5400, 64800],
            ['B', 'MASONRY WORK', null, null, null, 274500],
            ['3', 'Sample brickwork', 'Cum', 45, 6100, 274500],
            [null, 'Grand Total', null, null, null, 360100],
        ]);

        $this->assertSame(['A.1', 'A.2', 'B.3'], array_column($read['lines'], 'code'));
        $this->assertSame(['EARTH WORK', 'EARTH WORK', 'MASONRY WORK'], array_column($read['lines'], 'section'));
        $this->assertSame('360100.00', $read['lines_total']);
        $this->assertSame([2 => true, 5 => true, 7 => true], array_column($read['totals'], 'matches', 'row'));

        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, $this->sheet([
            self::HEADING,
            [1, 'Sample excavation', 'Cum', 80, 260, 20800],
            [2, 'Sample PCC', 'Cum', 4, 16207, 64828],
            [null, 'Sub Total', null, null, null, 85628],
            [null, 'Add GST', null, null, null, 15413.04],
            [null, 'Round off', null, null, null, -0.04],
            [null, 'Labour welfare cess', null, null, null, 856.28],
            [null, 'Grand Total', null, null, null, 101897.28],
        ]));

        $this->assertSame([], $preview['errors']);
        $this->assertSame(2, $preview['line_count']);
        $this->assertSame(['Add GST', 'Round off', 'Labour welfare cess'], array_column($preview['adjustments'], 'label'));
        $this->assertSame(['15413.04', '-0.04', '856.28'], array_column($preview['adjustments'], 'effect'));
        $this->assertSame(['101897.28', true], [$preview['with_adjustments'], $preview['total_matches']]);

        $read = $this->classified([self::HEADING, [1, 'Sample excavation', 'Cum', 80, 260, 20800], [null, 'Less: Special discount', null, null, null, 2000]]);
        $this->assertSame([['Less: Special discount', '-2000.00']], array_map(fn (array $adjustment) => [$adjustment['label'], $adjustment['effect']], $read['adjustments']));
        $this->assertCount(1, $read['lines']);
    }

    public function test_a_percentage_inside_an_item_is_not_an_adjustment(): void
    {
        $read = $this->classified([
            self::HEADING,
            [1, 'Earth work sample', 'cum', 120, null, null],
            [2, 'Cement concrete with 5% waterproofing compound', 'cum', 12, null, null],
            [3, 'Brick masonry sample', 'cum', 40, null, null],
        ]);
        $this->assertSame(['1', '2', '3'], array_column($read['lines'], 'code'));
        $this->assertSame([], $read['adjustments']);

        $read = $this->classified([self::HEADING, [1, 'Plaster with 2% integral waterproofing', 'sqm', 100, null, 25000]]);
        $this->assertSame(['100', '250.00'], [$read['lines'][2]['qty'], $read['lines'][2]['rate']]);

        $read = $this->classified([
            self::HEADING,
            [1, 'Brick masonry sample work', 'cum', 40, 5000, 200000],
            [2, 'Plaster with 2% waterproof compound:'],
            [null, 'a) Inside walls', 'sqm', 100, 250, 25000],
            [null, 'b) Outside walls', 'sqm', 80, 300, 24000],
            [null, 'Total', null, null, null, 249000],
        ]);
        $this->assertSame('spec', $this->kinds($read)[3]);
        $this->assertSame(['1', '2a', '2b'], array_column($read['lines'], 'code'));
        $this->assertStringContainsString('waterproof compound', $read['lines'][4]['description']);
        $this->assertSame(['249000.00', []], [$read['with_adjustments'], $read['adjustments']]);
    }

    public function test_items_whose_text_starts_with_total_are_lines(): void
    {
        $read = $this->classified([
            self::HEADING,
            [1, 'Brick masonry sample', 'cum', 40, 5000, 200000],
            [2, 'Total station survey of the sample plot', 'day', 'QRO', 4500, null],
            [3, 'Total station survey, second visit', 'day', 2, null, null],
            [4, 'Totalizer flow meter supply', 'nos', 1, 3000, 3000],
            [null, 'Total', null, null, null, 203000],
        ]);

        $this->assertSame(['1', '2', '3', '4'], array_column($read['lines'], 'code'));
        $this->assertSame([6 => true], array_column($read['totals'], 'matches', 'row'));
    }

    public function test_text_in_merged_cells_belongs_to_the_row_with_the_figures(): void
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
        ], merges: ['A2:A4', 'B2:B4', 'A6:A7', 'B6:B7']));

        $this->assertSame([], $preview['errors']);
        $this->assertSame(['300000.00', true], [$preview['lines_total'], $preview['subtotal_matches']]);
        $this->confirm($project)->assertSessionHas('success');
        $lines = $this->lines($project);
        $this->assertSame([1, 2, 3], array_keys($lines));
        $this->assertSame(['Providing and laying sample vitrified tiles of approved make on floor, complete', '150.000'], [$lines[1]->description, $lines[1]->qty]);
        $this->assertSame('Sample granite sill', $lines[3]->description);

        // "Description" merged over two columns: the item in the first, its lettered parts in the second.
        $project = $this->makeProject($this->org);
        $preview = $this->staged($project, $this->sheet([
            ['Sr No', 'Description', null, 'Unit', 'Qty', 'Rate', 'Amount'],
            ['1', 'Providing sample cement plaster in CM 1:4 on walls'],
            [null, null, '(a) 12 mm thick inside', 'Sqm', 600, 230, 138000],
            [null, null, '(b) 20 mm thick outside', 'Sqm', 300, 280, 84000],
            ['2', 'Sample painting with acrylic emulsion', null, 'Sqm', 900, 120, 108000],
        ], merges: ['B1:C1']));

        $this->assertSame([], $preview['errors']);
        $this->assertSame([3, '330000.00'], [$preview['line_count'], $preview['lines_total']]);
        $this->assertSame(['1a', '1b', '2'], array_column($preview['lines'], 'code'));
        $this->assertStringContainsString('12 mm thick inside', $preview['lines'][0]['description']);
    }
}
