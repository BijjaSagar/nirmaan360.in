<?php

namespace Tests\Feature\PriceWatch;

use App\Models\MaterialPrice;
use App\Models\PriceWatchMaterial;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class PriceSheetTest extends PriceWatchTestCase
{
    private PriceWatchMaterial $cement;

    private PriceWatchMaterial $sand;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->cement = $this->watch('Cement', 'bag', 'cement');
        $this->sand = $this->watch('Sand', 'brass', 'sand');
    }

    private function workbook(array $rows): UploadedFile
    {
        $book = new Spreadsheet;

        foreach ($rows as $r => $cells) {
            foreach (array_values($cells) as $c => $value) {
                $book->getActiveSheet()->setCellValue([$c + 1, $r + 1], $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'prices').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        return new UploadedFile($path, 'prices.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function upload(UploadedFile $file, array $extra = [])
    {
        return $this->actingAs($this->pm)->post(route('price-watch.upload'), ['file' => $file, ...$extra]);
    }

    public function test_the_template_is_the_board_with_blank_price_cells(): void
    {
        $response = $this->actingAs($this->pm)->get(route('price-watch.template'));
        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, $response->getContent());
        $sheet = IOFactory::load($path)->getActiveSheet()->toArray();

        $this->assertSame(['Material', 'Rate (₹)', 'Unit', 'Date', 'Source'], $sheet[0]);
        $this->assertSame(['Cement', null, 'bag', '2026-11-10', null], $sheet[1]);
        $this->assertSame(['Sand', null, 'brass', '2026-11-10', null], $sheet[2]);

        $this->actingAs($this->qs)->get(route('price-watch.template'))->assertForbidden();
        $this->actingAs($this->engineer)->get(route('price-watch.template'))->assertForbidden();
    }

    public function test_a_good_sheet_adds_each_price_with_its_date_and_source(): void
    {
        $this->price($this->cement, '380', '2026-11-01');

        $file = $this->workbook([
            ['Material', 'Rate (₹)', 'Unit', 'Date', 'Source'],
            ['Cement', 395, 'bag', '2026-11-08', 'Birla dealer'],
            // An Excel date, a rate with a comma, a unit in another case, a blank unit, a date written the Indian way.
            ['sand', '4,700.50', 'BRASS', ExcelDate::PHPToExcel(new \DateTime('2026-11-09')), 'Pune market'],
            ['Cement', 380, '', '01/11/2026', ''],
            [null, null, null, null, null],
        ]);

        $this->upload($file)->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', '2 prices added, 1 already on the list.');

        $cement = $this->cement->prices()->first();
        $this->assertSame(['395.00', 'bag', '2026-11-08', 'Birla dealer', 'excel', $this->pm->id], [$cement->rate, $cement->unit, $cement->priced_on->toDateString(), $cement->source, $cement->source_kind, $cement->noted_by]);
        $sand = $this->sand->prices()->first();
        $this->assertSame(['4700.50', 'brass', '2026-11-09', 'Pune market'], [$sand->rate, $sand->unit, $sand->priced_on->toDateString(), $sand->source]);
        $this->assertSame(3, MaterialPrice::count());
    }

    public function test_unknown_materials_are_refused_unless_the_sheet_may_add_them(): void
    {
        $file = $this->workbook([
            ['material', 'rate', 'unit', 'date', 'source'],
            ['Cement', 395, 'bag', '2026-11-08', ''],
            ['Vitrified tiles', 55, 'sqm', '2026-11-08', 'Morbi'],
        ]);

        $this->upload($file)->assertSessionHasErrors(['file' => 'Row 3: "Vitrified tiles" is not on the board. Add it first, or tick "add new materials from the sheet".']);
        $this->assertSame(0, MaterialPrice::count());

        $this->upload($this->workbook([
            ['material', 'rate', 'unit', 'date', 'source'],
            ['Cement', 395, 'bag', '2026-11-08', ''],
            ['Vitrified tiles', 55, 'sqm', '2026-11-08', 'Morbi'],
        ]), ['add_missing' => '1'])->assertSessionHasNoErrors()->assertSessionHas('success', '2 prices added, 0 already on the list. 1 new materials put on the board.');

        $tiles = PriceWatchMaterial::where('name', 'Vitrified tiles')->sole();
        $this->assertSame(['sqm', '55.00'], [$tiles->unit, $tiles->prices()->first()->rate]);
    }

    public function test_every_wrong_row_is_named_and_nothing_is_saved(): void
    {
        $file = $this->workbook([
            ['material', 'rate', 'unit', 'date', 'source'],
            ['Cement', 395, 'bag', '2026-11-08', 'Fine'],
            ['', 10, 'bag', '2026-11-08', 'No material'],
            ['Cement', 'abc', 'bag', '2026-11-08', 'Bad rate'],
            ['Cement', -5, 'bag', '2026-11-08', 'Negative'],
            ['Cement', 400, 'kg', '2026-11-08', 'Wrong unit'],
            ['Cement', 400, 'bag', 'someday', 'Bad date'],
            ['Cement', 400, 'bag', '2027-01-01', 'Future'],
            ['Cement', 400, 'bag', '', 'No date'],
        ]);

        $response = $this->upload($file);
        $response->assertSessionHasErrors('file');
        $errors = session('errors')->get('file');

        $this->assertSame([
            'Row 3: the material is missing.',
            'Row 4: the rate must be a number of rupees, zero or more.',
            'Row 5: the rate must be a number of rupees, zero or more.',
            'Row 6: Cement is priced per bag on the board, not per kg.',
            'Row 7: the date is missing or unreadable. Write it as 2026-11-05 or 05/11/2026.',
            'Row 8: the date is in the future.',
            'Row 9: the date is missing or unreadable. Write it as 2026-11-05 or 05/11/2026.',
        ], $errors);
        $this->assertSame(0, MaterialPrice::count());
    }

    /** The board's columns are varchar(160), varchar(20) and decimal(15,2): a sheet past them is refused on every database, not only where the column enforces it. */
    public function test_values_the_board_cannot_hold_are_refused_before_anything_is_saved(): void
    {
        $file = $this->workbook([
            ['material', 'rate', 'unit', 'date', 'source'],
            ['Cement', '1e400', 'bag', '2026-11-08', 'Rate beyond any column'],
            ['Cement', '99999999999999', 'bag', '2026-11-08', 'One digit too many'],
            [str_repeat('x', 161), 10, 'bag', '2026-11-08', 'Name too long'],
            ['Fly ash', 10, str_repeat('k', 21), '2026-11-08', 'Unit too long'],
            ['Cement', '9999999999999', 'bag', '2026-11-08', 'The largest rate the column holds'],
        ]);

        $this->upload($file, ['add_missing' => '1'])->assertSessionHasErrors('file');

        $this->assertSame([
            'Row 2: the rate is larger than '.inr('9999999999999').'.',
            'Row 3: the rate is larger than '.inr('9999999999999').'.',
            'Row 4: the material name is longer than 160 characters.',
            'Row 5: the unit is longer than 20 characters.',
        ], session('errors')->get('file'));
        $this->assertSame(0, MaterialPrice::count());
        $this->assertSame(2, PriceWatchMaterial::count());
    }

    public function test_a_sheet_without_the_headings_or_rows_and_a_file_that_is_not_a_workbook_are_refused(): void
    {
        $this->upload($this->workbook([['Cement', 395, 'bag', '2026-11-08']]))->assertSessionHasErrors(['file' => 'The sheet needs a heading row with material, rate, unit, date and source. Download the template to see the layout.']);
        $this->upload($this->workbook([['material', 'rate', 'unit', 'date', 'source']]))->assertSessionHasErrors(['file' => 'The sheet has no price rows under the heading row.']);

        $this->actingAs($this->pm)->post(route('price-watch.upload'), [])->assertSessionHasErrors('file');
        $this->actingAs($this->pm)->post(route('price-watch.upload'), ['file' => UploadedFile::fake()->createWithContent('prices.csv', "material,rate\nCement,1")])->assertSessionHasErrors('file');

        $fake = UploadedFile::fake()->createWithContent('prices.xlsx', 'not a zip at all');
        $this->upload($fake)->assertSessionHasErrors(['file' => 'This is not a valid .xlsx workbook. Save it from Excel as "Excel Workbook (.xlsx)" and try again.']);

        config(['modules.price_watch.upload_max_rows' => 1]);
        $this->upload($this->workbook([
            ['material', 'rate', 'unit', 'date', 'source'],
            ['Cement', 395, 'bag', '2026-11-08', ''],
            ['Sand', 4700, 'brass', '2026-11-08', ''],
        ]))->assertSessionHasErrors(['file' => 'A sheet may hold up to 1 price rows.']);

        $this->assertSame(0, MaterialPrice::count());
    }

    public function test_only_managers_of_the_same_company_upload(): void
    {
        $file = $this->workbook([['material', 'rate', 'unit', 'date', 'source'], ['Cement', 395, 'bag', '2026-11-08', '']]);

        foreach ([$this->qs, $this->engineer, $this->store, $this->viewer] as $user) {
            $this->actingAs($user)->post(route('price-watch.upload'), ['file' => $file])->assertForbidden();
        }

        [, $rivalOwner] = $this->rival();
        $this->actingAs($rivalOwner)->post(route('price-watch.upload'), ['file' => $file])
            ->assertSessionHasErrors(['file' => 'Row 2: "Cement" is not on the board. Add it first, or tick "add new materials from the sheet".']);

        $this->assertSame(0, MaterialPrice::withoutGlobalScopes()->count());
    }
}
