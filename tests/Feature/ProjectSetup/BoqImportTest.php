<?php

namespace Tests\Feature\ProjectSetup;

use App\Models\BoqItem;
use App\Models\BoqRevision;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class BoqImportTest extends ProjectSetupTestCase
{
    private function workbook(array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('BOQ');

        foreach ($rows as $r => $cells) {
            foreach (array_values($cells) as $c => $value) {
                $book->getActiveSheet()->setCellValue([$c + 1, $r + 1], $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        return new UploadedFile($path, 'boq.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('boq.csv', $content);
    }

    public function test_excel_import_reads_category_and_details(): void
    {
        $file = $this->workbook([
            ['section', 'code', 'description', 'unit', 'qty', 'rate', 'category', 'details'],
            ['Substructure', '1.1', 'Excavation for isolated footings', 'cum', 640, 185, 'Excavation', 'In all soils, up to 3 m depth'],
            ['Superstructure', '2.1', 'RCC M25 in footing', 'cum', 185, 7450, 'rcc', "M25 grade.\nWith centering and shuttering."],
            ['Superstructure', '2.2', 'Brick masonry', 'cum', 90, 5200, 'Brick work', null],
        ]);

        $this->actingAs($this->pm)->post(route('boq.excel.import', $this->project), ['file' => $file])->assertSessionHasNoErrors();

        $items = BoqItem::where('project_id', $this->project->id)->orderBy('sort')->get()->keyBy('code');

        $this->assertSame('excavation', $items['1.1']->category);
        $this->assertSame('In all soils, up to 3 m depth', $items['1.1']->details);
        $this->assertSame('rcc', $items['2.1']->category);
        $this->assertSame("M25 grade.\nWith centering and shuttering.", $items['2.1']->details);
        $this->assertSame('brick_work', $items['2.2']->category);
        $this->assertNull($items['2.2']->details);
    }

    public function test_excel_import_still_works_without_the_new_columns_and_leaves_them_alone(): void
    {
        $item = $this->line('10.000', '100.00', ['code' => '1.1', 'category' => 'rcc', 'details' => 'Keep this text']);

        $file = $this->workbook([
            ['section', 'code', 'description', 'unit', 'qty', 'rate'],
            ['', '1.1', 'RCC M25 in slab', 'cum', 12, 150],
            ['', '1.2', 'New line', 'cum', 1, 1],
        ]);

        $this->actingAs($this->pm)->post(route('boq.excel.import', $this->project), ['file' => $file])->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('12.000', $item->qty);
        $this->assertSame('rcc', $item->category);
        $this->assertSame('Keep this text', $item->details);
        $this->assertNull(BoqItem::where('code', '1.2')->firstOrFail()->category);
    }

    public function test_excel_import_rejects_an_unknown_category_and_imports_nothing(): void
    {
        $file = $this->workbook([
            ['code', 'description', 'unit', 'qty', 'rate', 'category'],
            ['1', 'Fine', 'cum', 1, 1, 'rcc'],
            ['2', 'Bad', 'cum', 1, 1, 'Gold plating'],
        ]);

        $this->actingAs($this->pm)->post(route('boq.excel.import', $this->project), ['file' => $file])
            ->assertRedirect()->assertSessionHas('boq_import', fn ($result) => $result['errors'][0]['field'] === 'category' && $result['errors'][0]['row'] === 3);

        $this->assertSame(0, BoqItem::where('project_id', $this->project->id)->count());
    }

    public function test_excel_import_rejects_details_that_are_too_long(): void
    {
        $file = $this->workbook([
            ['code', 'description', 'unit', 'qty', 'rate', 'details'],
            ['1', 'Long', 'cum', 1, 1, str_repeat('a', 10001)],
        ]);

        $this->actingAs($this->pm)->post(route('boq.excel.import', $this->project), ['file' => $file])
            ->assertSessionHas('boq_import', fn ($result) => $result['errors'][0]['field'] === 'details');
        $this->assertSame(0, BoqItem::where('project_id', $this->project->id)->count());
    }

    public function test_excel_import_is_one_batch_in_the_history_with_a_reason(): void
    {
        $existing = $this->line('10.000', '100.00', ['code' => '1']);
        BoqRevision::withoutGlobalScopes()->delete();

        $file = $this->workbook([
            ['code', 'description', 'unit', 'qty', 'rate'],
            ['1', 'RCC M25 in slab', 'cum', 10, 120],
            ['2', 'New', 'cum', 5, 10],
        ]);

        $this->actingAs($this->pm)->post(route('boq.excel.import', $this->project), ['file' => $file])->assertSessionHasNoErrors();

        $revisions = BoqRevision::withoutGlobalScopes()->orderBy('id')->get();

        $this->assertSame(['edited', 'added'], $revisions->pluck('action')->all());
        $this->assertCount(1, $revisions->pluck('batch_uuid')->unique());
        $this->assertSame(['excel_import'], $revisions->pluck('source')->unique()->all());
        $this->assertSame('Imported from an Excel file', $revisions[0]->reason);
        $this->assertSame(['100.00', '120.00'], $revisions[0]->changes['rate']);
        $this->assertSame($this->pm->id, $revisions[0]->user_id);
        // 10 × 100 before; 10 × 120 after the first line, then 5 × 10 more for the second.
        $this->assertSame(['1000.00', '1200.00'], [$revisions[0]->valuation_before, $revisions[0]->valuation_after]);
        $this->assertSame(['1200.00', '1250.00'], [$revisions[1]->valuation_before, $revisions[1]->valuation_after]);
        $this->assertSame($existing->id, $revisions[0]->boq_item_id);
    }

    public function test_the_excel_export_and_template_carry_category_and_details(): void
    {
        $this->line('10.000', '100.00', ['code' => 'E1', 'category' => 'rcc', 'details' => 'M25 grade']);

        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        file_put_contents($path, $this->actingAs($this->pm)->get(route('boq.excel.export', $this->project))->getContent());
        $rows = IOFactory::load($path)->getSheet(0)->toArray(null, false, false, false);

        $this->assertSame(['rcc', 'M25 grade'], [$rows[1][7], $rows[1][8]]);

        file_put_contents($path, $this->actingAs($this->pm)->get(route('boq.excel.template'))->getContent());
        $template = IOFactory::load($path)->getSheet(0)->toArray(null, false, false, false);

        $this->assertSame(['section', 'code', 'description', 'unit', 'qty', 'rate', 'category', 'details'], $template[0]);
        $this->assertSame('excavation', $template[1][6]);
    }

    public function test_an_exported_workbook_can_be_edited_and_imported_back(): void
    {
        $this->line('10.000', '100.00', ['code' => 'E1', 'category' => 'rcc', 'details' => 'M25 grade']);

        $path = tempnam(sys_get_temp_dir(), 'rt').'.xlsx';
        file_put_contents($path, $this->actingAs($this->pm)->get(route('boq.excel.export', $this->project))->getContent());
        $book = IOFactory::load($path);
        $book->getSheet(0)->setCellValue('I2', 'M30 grade, edited');
        IOFactory::createWriter($book, 'Xlsx')->save($path);

        $this->actingAs($this->pm)->post(route('boq.excel.import', $this->project), [
            'file' => new UploadedFile($path, 'boq.xlsx', null, null, true),
        ])->assertSessionHasNoErrors();

        $item = BoqItem::where('code', 'E1')->firstOrFail();
        $this->assertSame('M30 grade, edited', $item->details);
        $this->assertSame('rcc', $item->category);
        $this->assertSame(1, BoqItem::where('project_id', $this->project->id)->count());
    }

    public function test_csv_import_and_export_carry_category_and_details(): void
    {
        $csv = "section,code,description,unit,qty,rate,category,details\n"
            ."Sub,1,Excavation,cum,10,100,Excavation,\"In all soils,\nup to 3 m\"\n"
            ."Sub,2,Bricks,cum,5,50,brick work,\n";

        $this->actingAs($this->pm)->post(route('boq.import', $this->project), ['file' => $this->csv($csv)])->assertSessionHasNoErrors();

        $items = BoqItem::where('project_id', $this->project->id)->orderBy('sort')->get()->keyBy('code');
        $this->assertSame('excavation', $items['1']->category);
        $this->assertSame("In all soils,\nup to 3 m", $items['1']->details);
        $this->assertSame('brick_work', $items['2']->category);
        $this->assertNull($items['2']->details);

        $export = $this->actingAs($this->pm)->get(route('boq.export', $this->project))->streamedContent();
        $this->assertStringContainsString('section,code,description,unit,qty,rate,category,details', $export);
        $this->assertStringContainsString('brick_work', $export);
    }

    public function test_csv_import_without_the_new_columns_leaves_them_and_rejects_an_unknown_category(): void
    {
        $item = $this->line('10.000', '100.00', ['code' => '1', 'category' => 'rcc', 'details' => 'Keep this']);

        $this->actingAs($this->pm)->post(route('boq.import', $this->project), ['file' => $this->csv("section,code,description,unit,qty,rate\n,1,RCC,cum,11,100\n")])->assertSessionHasNoErrors();
        $this->assertSame('rcc', $item->fresh()->category);
        $this->assertSame('Keep this', $item->fresh()->details);

        $this->actingAs($this->pm)->post(route('boq.import', $this->project), ['file' => $this->csv("section,code,description,unit,qty,rate,category\n,9,X,cum,1,1,mystery\n")])->assertSessionHasErrors('file');
        $this->assertNull(BoqItem::where('code', '9')->first());
    }

    public function test_csv_import_goes_into_the_history(): void
    {
        $this->actingAs($this->pm)->post(route('boq.import', $this->project), ['file' => $this->csv("section,code,description,unit,qty,rate\n,1,A,cum,1,100\n,2,B,cum,2,100\n")]);

        $revisions = BoqRevision::withoutGlobalScopes()->orderBy('id')->get();

        $this->assertCount(2, $revisions);
        $this->assertSame('csv_import', $revisions[0]->source);
        $this->assertSame('Imported from a CSV file', $revisions[0]->reason);
        $this->assertSame('300.00', $revisions[1]->valuation_after);
    }
}
