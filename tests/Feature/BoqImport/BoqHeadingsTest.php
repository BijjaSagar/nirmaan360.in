<?php

namespace Tests\Feature\BoqImport;

use App\Services\Imports\BoqHeadings;
use App\Services\Imports\CellValues;
use App\Services\Imports\SheetFile;
use App\Services\Reports\PdfTable;
use Tests\TestCase;

/** The shared heading detector and cell readers, on invented headings and values. */
class BoqHeadingsTest extends TestCase
{
    /** @param  list<list<mixed>>  $rows  @return array<int, array<int, mixed>> */
    private function grid(array $rows): array
    {
        $grid = [];

        foreach ($rows as $r => $cells) {
            foreach (array_values($cells) as $c => $value) {
                if ($value !== null && $value !== '') {
                    $grid[$r + 1][$c + 1] = $value;
                }
            }
        }

        return $grid;
    }

    public function test_headings_are_matched_by_words_in_english_hindi_and_marathi(): void
    {
        $cases = [
            'Sr.No' => 'serial', 'S.No.' => 'serial', 'Sl. No.' => 'serial', 'अ.क्र.' => 'serial', 'क्र.सं.' => 'serial', 'अनु. क्र.' => 'serial',
            'Item Code' => 'code', 'DSR Code' => 'code', 'SOR No' => 'code', 'CPWD item no' => 'code',
            'Item Description' => 'description', 'Particulars' => 'description', 'Name of Work/Item' => 'description', 'Details of Work' => 'description',
            'तपशील' => 'description', 'विवरण' => 'description', 'कामाचे नाव' => 'description', 'कार्य का विवरण' => 'description',
            'UOM' => 'unit', 'एकक' => 'unit', 'इकाई' => 'unit',
            'Quantity (Cum)' => 'qty', 'Total Qty' => 'qty', 'BOQ Qty' => 'qty', 'मात्रा' => 'qty', 'नग' => 'qty', 'संख्या' => 'qty',
            'Unit Rate' => 'rate', 'Rate in Rs.' => 'rate', 'Rate per unit' => 'rate', 'दर' => 'rate',
            'Amount in Rs.' => 'amount', 'Total Amount' => 'amount', 'रक्कम' => 'amount', 'राशि' => 'amount', 'एकूण' => 'amount',
            'Remarks' => 'remark',
        ];

        foreach ($cases as $heading => $field) {
            $scores = BoqHeadings::scores($heading);
            arsort($scores);
            $this->assertSame($field, array_key_first($scores), $heading);
        }

        $this->assertSame([], BoqHeadings::scores('Providing and laying cement concrete in foundation and plinth'));
        $this->assertSame('cum', BoqHeadings::unitIn('Quantity (Cum)'));
        $this->assertSame('sqm', BoqHeadings::unitIn('Rate (Rs/Sqm)'));
        $this->assertSame('अ क्र', BoqHeadings::normalise('अ.क्र.'));
        $this->assertSame('rate 12', BoqHeadings::normalise('Rate (१२)'));
    }

    public function test_words_with_two_meanings_are_decided_by_the_values_under_them(): void
    {
        $detector = new BoqHeadings;

        // प्रमाण holding units is the unit; holding numbers it is the quantity.
        $units = $detector->detect($this->grid([['तपशील', 'प्रमाण', 'नग', 'दर'], ['काम एक', 'घ.मी.', 4, 100], ['काम दोन', 'चौ.मी.', 5, 200], ['काम तीन', 'नग', 6, 300]]));
        $this->assertSame(['description' => 1, 'unit' => 2, 'qty' => 3, 'rate' => 4], $units->columns());

        $numbers = $detector->detect($this->grid([['तपशील', 'एकक', 'प्रमाण', 'दर'], ['काम एक', 'घ.मी.', 4, 100], ['काम दोन', 'चौ.मी.', 5, 200], ['काम तीन', 'नग', 6, 300]]));
        $this->assertSame(['description' => 1, 'unit' => 2, 'qty' => 3, 'rate' => 4], $numbers->columns());

        // रुपये that is quantity times rate is the amount; on its own it is the rate.
        $amount = $detector->detect($this->grid([['तपशील', 'एकक', 'नग', 'दर', 'रुपये'], ['काम एक', 'नग', 4, 100, 400], ['काम दोन', 'नग', 5, 200, 1000], ['काम तीन', 'नग', 6, 300, 1800]]));
        $this->assertSame(5, $amount->columns()['amount']);

        $rate = $detector->detect($this->grid([['तपशील', 'एकक', 'नग', 'रुपये'], ['काम एक', 'नग', 4, 100], ['काम दोन', 'नग', 5, 200], ['काम तीन', 'नग', 6, 300]]));
        $this->assertSame(4, $rate->columns()['rate']);
    }

    public function test_the_heading_row_is_found_below_a_letterhead_and_across_two_rows(): void
    {
        $detector = new BoqHeadings;
        $letterhead = array_fill(0, 14, ['Sample Builders, Example Road']);

        $layout = $detector->detect($this->grid([...$letterhead, ['Item', 'Description', 'Qty', 'Rate'], ['1', 'Sample work', 2, 3]]));
        $this->assertSame([15, 1, 2], [$layout->row, $layout->span, $layout->columns()['description']]);
        $this->assertSame(['description', 'qty', 'rate'], array_values(array_intersect(['description', 'qty', 'rate'], array_keys($layout->columns()))));

        // "Rate" over "(Rs.)" and "Quantity" over "Cum": one heading on two rows, and the unit is kept.
        $two = $detector->detect($this->grid([['Sr', 'Description', 'Quantity', 'Rate'], [null, null, 'Cum', '(Rs.)'], [1, 'Sample work', 2, 3]]));
        $this->assertSame([1, 2, 'cum'], [$two->row, $two->span, $two->headingUnit()]);

        // A sheet with no table has no heading.
        $this->assertFalse($detector->detect($this->grid([['Notes'], ['Rates are inclusive of taxes']]))->found());
    }

    public function test_numbers_and_units_written_the_indian_way(): void
    {
        $this->assertSame('123456.5', CellValues::number('1,23,456.50'));
        $this->assertSame('1250', CellValues::number('₹ 1,250/-'));
        $this->assertSame('123.5', CellValues::number('१२३.५०'));
        $this->assertSame('-12345.6', CellValues::number('(12,345.60)'));
        $this->assertNull(CellValues::number('QRO'));
        $this->assertTrue(CellValues::isRateOnly('Q.R.O.'));
        $this->assertTrue(CellValues::isRateOnly('Rate Only'));
        $this->assertTrue(CellValues::isRateOnly('Rate only basis'));
        $this->assertFalse(CellValues::isRateOnly('Rate as per DSR'));
        $this->assertSame([['12.5', 'cum'], ['1', 'LS'], ['1', 'LS'], ['1250', 'sqm'], null, null], array_map(fn (string $text) => CellValues::quantityWithUnit($text), ['12.5 Cum', 'L.S.', '1 Job', '1,250 Sq.m', 'approx 10', '12']));
        $this->assertTrue(CellValues::isError('#REF!'));

        $units = [
            'cum' => ['cum', 'Cu.M', 'm3', 'm³', 'घ.मी.', 'Rs/cu.m'], 'sqm' => ['sqm', 'Sq.m', 'm2', 'चौ.मी.'], 'sqft' => ['sqft', 'Sft', 'चौ.फुट'],
            'rmt' => ['rmt', 'R.M.', 'running metre', 'रनिंग मीटर'], 'nos' => ['Nos', 'No.', 'नग'], 'kg' => ['Kg', 'किलो'], 'MT' => ['M.T.', 'tonne'],
            'LS' => ['L.S.', 'lump sum', 'Job'],
        ];

        foreach ($units as $unit => $spellings) {
            foreach ($spellings as $spelling) {
                $this->assertSame($unit, CellValues::unit($spelling), $spelling);
            }
        }

        $this->assertSame('door', CellValues::unit('Rs/door'));
    }

    public function test_the_file_kind_comes_from_its_content(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sniff');

        file_put_contents($path, "Description,Qty,Rate\nSample,1,2\n");
        $this->assertSame(SheetFile::CSV, SheetFile::sniff($path));

        file_put_contents($path, "\xD0\xCF\x11\xE0 broken");
        $this->assertNull(SheetFile::sniff($path));

        file_put_contents($path, "\x00\x01\x02\x03");
        $this->assertNull(SheetFile::sniff($path));

        unlink($path);
    }

    public function test_the_text_pdf_table_still_finds_its_heading_and_now_reads_marathi_ones(): void
    {
        $page = function (array $lines): array {
            $pieces = [];
            $y = 760;

            foreach ($lines as $line) {
                foreach ($line as $x => $text) {
                    $pieces[] = ['x' => (float) $x, 'x1' => (float) $x + PdfTable::width($text, 9), 'y' => (float) $y, 'size' => 9.0, 'text' => $text];
                }

                $y -= 16;
            }

            return ['pieces' => $pieces, 'h' => [], 'v' => []];
        };

        $english = (new PdfTable([$page([
            [40 => 'Sample Builders'],
            [40 => 'Sr. No.', 90 => 'Description of Work', 320 => 'Unit', 370 => 'Qty', 420 => 'Rate', 480 => 'Amount'],
            [40 => '1', 90 => 'Sample excavation in soil', 320 => 'cum', 370 => '10', 420 => '250', 480 => '2500'],
            [40 => '2', 90 => 'Sample brick work', 320 => 'cum', 370 => '4', 420 => '5200', 480 => '20800'],
        ])]))->grid();

        $this->assertSame(['Sr. No.', 'Description of Work', 'Unit', 'Qty', 'Rate', 'Amount'], array_values($english[1]));
        $this->assertSame('Sample brick work', $english[3][2]);

        $marathi = (new PdfTable([$page([
            [40 => 'अ.क्र.', 90 => 'कामाचा तपशील', 320 => 'एकक', 370 => 'प्रमाण', 420 => 'दर', 480 => 'रक्कम'],
            [40 => '1', 90 => 'नमुना खोदकाम', 320 => 'cum', 370 => '10', 420 => '250', 480 => '2500'],
        ])]))->grid();

        $this->assertSame('कामाचा तपशील', $marathi[1][2]);
        $this->assertSame('नमुना खोदकाम', $marathi[2][2]);
    }

    public function test_a_title_block_above_the_pdf_table_is_not_taken_for_its_heading(): void
    {
        $page = function (array $lines): array {
            $pieces = [];
            $y = 760;

            foreach ($lines as $line) {
                foreach ($line as $x => $text) {
                    $pieces[] = ['x' => (float) $x, 'x1' => (float) $x + PdfTable::width($text, 9), 'y' => (float) $y, 'size' => 9.0, 'text' => $text];
                }

                $y -= 16;
            }

            return ['pieces' => $pieces, 'h' => [], 'v' => []];
        };
        $heading = [40 => 'Item Code', 100 => 'Description of Work', 320 => 'Unit', 370 => 'Qty', 420 => 'Rate'];

        $grid = (new PdfTable([
            $page([
                [40 => 'Name of Work: Sample Hall', 230 => 'Sub Head: Civil', 400 => 'Estimated Cost: Rs. 25,00,000'],
                [],
                $heading,
                [40 => 'S-1', 100 => 'Sample excavation in soil', 320 => 'cum', 370 => '10', 420 => '250'],
                [40 => 'S-2', 100 => 'Sample brick work', 320 => 'cum', 370 => '4', 420 => '5200'],
            ]),
            $page([$heading, [40 => 'S-3', 100 => 'Sample plaster work', 320 => 'sqm', 370 => '40', 420 => '250']]),
        ]))->grid();

        $this->assertSame(['Item Code', 'Description of Work', 'Unit', 'Qty', 'Rate'], array_values($grid[1]));
        $this->assertSame(['S-1', 'S-2', 'S-3'], array_map(fn (array $row) => $row[1] ?? null, array_values(array_slice($grid, 1))));
    }
}
