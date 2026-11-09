<?php

namespace Tests\Feature\Reports;

use App\Models\BoqItem;
use App\Models\BoqSection;
use App\Services\Reports\BoqExcelService;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

class BoqExcelTest extends ReportsTestCase
{
    public function test_export_gives_a_workbook_in_the_import_columns(): void
    {
        $project = $this->makeProject($this->org, ['code' => 'RT1']);
        $section = BoqSection::create(['organization_id' => $this->org->id, 'project_id' => $project->id, 'name' => 'Substructure']);
        BoqItem::create(['organization_id' => $this->org->id, 'project_id' => $project->id, 'boq_section_id' => $section->id, 'code' => '1.1', 'description' => 'Excavation', 'unit' => 'cum', 'qty' => '250.500', 'rate' => '185.25', 'sort' => 1]);

        $response = $this->actingAs($this->pm)->get(route('boq.excel.export', $project));

        $this->assertWorkbook($response);
        $this->assertStringContainsString('boq-RT1.xlsx', $response->headers->get('Content-Disposition'));

        $rows = $this->readWorkbook($response);
        $this->assertSame(['section', 'code', 'description', 'unit', 'qty', 'rate', 'amount'], $rows[0]);
        $this->assertSame(['Substructure', '1.1', 'Excavation', 'cum'], array_slice($rows[1], 0, 4));
        $this->assertEquals(250.5, $rows[1][4]);
        $this->assertEquals(185.25, $rows[1][5]);
        // 250.500 x 185.25 = 46,405.125, kept as money to two places.
        $this->assertEquals(46405.13, $rows[1][6]);
    }

    public function test_export_writes_text_that_looks_like_a_formula_as_plain_text(): void
    {
        $project = $this->makeProject($this->org);
        BoqItem::create(['organization_id' => $this->org->id, 'project_id' => $project->id, 'code' => '9', 'description' => '=HYPERLINK("http://x.test","click")', 'unit' => 'nos', 'qty' => '1', 'rate' => '1', 'sort' => 1]);

        $response = $this->actingAs($this->pm)->get(route('boq.excel.export', $project));

        $path = tempnam(sys_get_temp_dir(), 'f').'.xlsx';
        file_put_contents($path, $response->getContent());
        $cell = IOFactory::load($path)->getSheet(0)->getCell('C2');

        $this->assertFalse($cell->isFormula());
        $this->assertSame('=HYPERLINK("http://x.test","click")', $cell->getValue());
    }

    public function test_template_download_has_headings_and_sample_rows(): void
    {
        $rows = $this->readWorkbook($this->actingAs($this->pm)->get(route('boq.excel.template')));

        $this->assertSame($this->boqHeading(), array_slice($rows[0], 0, 6));
        $this->assertCount(3, array_filter($rows, fn ($row) => $row[1] !== null));
    }

    public function test_import_adds_new_lines_and_updates_existing_ones(): void
    {
        $project = $this->makeProject($this->org);
        $existing = BoqItem::create(['organization_id' => $this->org->id, 'project_id' => $project->id, 'code' => '1.1', 'description' => 'Old text', 'unit' => 'cum', 'qty' => '10', 'rate' => '100', 'sort' => 5]);
        $same = BoqItem::create(['organization_id' => $this->org->id, 'project_id' => $project->id, 'code' => '1.2', 'description' => 'Same', 'unit' => 'cum', 'qty' => '10.000', 'rate' => '100.00', 'sort' => 6]);

        $file = $this->workbook([
            $this->boqHeading(),
            ['Substructure', '1.1', 'Excavation in all soils', 'cum', 250.5, 185.25],
            ['', '1.2', 'Same', 'cum', 10, 100],
            ['Superstructure', '2.1', 'RCC M25', 'cum', 120, '7,450.00'],
        ]);

        $this->actingAs($this->pm)->post(route('boq.excel.import', $project), ['file' => $file])
            ->assertRedirect(route('boq.excel.index', $project))
            ->assertSessionHas('success');

        $existing->refresh();
        $this->assertSame('Excavation in all soils', $existing->description);
        $this->assertSame('250.500', $existing->qty);
        $this->assertSame('185.25', $existing->rate);
        $this->assertSame(5, (int) $existing->sort);

        $new = BoqItem::where('project_id', $project->id)->where('code', '2.1')->first();
        $this->assertSame('7450.00', $new->rate);
        $this->assertSame(7, (int) $new->sort);
        $this->assertSame('Superstructure', $new->section->name);
        $this->assertSame(3, BoqItem::where('project_id', $project->id)->count());

        $this->assertEquals(['created' => 1, 'updated' => 1, 'unchanged' => 1], collect(session('boq_import'))->only(['created', 'updated', 'unchanged'])->all());
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => $new->getMorphClass(), 'auditable_id' => $new->id, 'action' => 'created']);
    }

    public function test_import_lists_every_problem_by_row_and_imports_nothing(): void
    {
        $project = $this->makeProject($this->org);
        $existing = BoqItem::create(['organization_id' => $this->org->id, 'project_id' => $project->id, 'code' => '1.1', 'description' => 'Keep me', 'unit' => 'cum', 'qty' => '10', 'rate' => '100', 'sort' => 1]);

        $file = $this->workbook([
            ['Title row that is not a heading'],
            $this->boqHeading(),
            ['A', '1.1', 'Would change the existing line', 'cum', 5, 5],
            ['A', '', 'No code', 'cum', 5, 5],
            ['A', '2.1', 'Bad quantity', 'cum', 'lots', 5],
            ['A', '2.2', 'Negative rate', 'cum', 5, -5],
            ['A', '2.3', 'No unit', '', 5, 5],
            ['A', '2.4', str_repeat('x', 2001), 'cum', 5, 5],
            ['A', '2.5', 'Huge', 'cum', 1.0E+15, 5],
        ]);

        $this->actingAs($this->pm)->post(route('boq.excel.import', $project), ['file' => $file])->assertRedirect(route('boq.excel.index', $project));

        $errors = collect(session('boq_import.errors'));

        $this->assertSame([4, 5, 6, 7, 8, 9], $errors->pluck('row')->unique()->values()->all());
        $this->assertSame('code', $errors->firstWhere('row', 4)['field']);
        $this->assertSame('qty', $errors->firstWhere('row', 5)['field']);
        $this->assertSame('rate', $errors->firstWhere('row', 6)['field']);
        $this->assertSame('unit', $errors->firstWhere('row', 7)['field']);
        $this->assertSame('description', $errors->firstWhere('row', 8)['field']);
        $this->assertSame('qty', $errors->firstWhere('row', 9)['field']);

        // Nothing was written: the existing line is untouched and no new line was added.
        $this->assertSame('Keep me', $existing->fresh()->description);
        $this->assertSame(1, BoqItem::where('project_id', $project->id)->count());
        $this->assertSame(0, BoqSection::where('project_id', $project->id)->count());

        $this->actingAs($this->pm)->get(route('boq.excel.index', $project))
            ->assertOk()->assertSee('Nothing was imported')->assertSee('Quantity must be a number')->assertDontSee('Import finished');
    }

    public function test_import_refuses_a_quantity_below_what_is_already_approved(): void
    {
        $project = $this->makeProject($this->org);
        $item = $this->makeBoqItem($project, '100.000', '5000.00');
        $this->approveEntry($this->draftEntry($project, $item, $this->pm, ['nos' => 1, 'length' => 10, 'breadth' => 2, 'depth' => 1]), $this->pm, $this->owner);

        $file = $this->workbook([$this->boqHeading(), ['', $item->code, 'RCC', 'cum', 15, 5000]]);
        $this->actingAs($this->pm)->post(route('boq.excel.import', $project), ['file' => $file]);

        $this->assertStringContainsString('approved quantity', session('boq_import.errors.0.message'));
        $this->assertSame('100.000', $item->fresh()->qty);

        $file = $this->workbook([$this->boqHeading(), ['', $item->code, 'RCC', 'cum', 20, 5000]]);
        $this->actingAs($this->pm)->post(route('boq.excel.import', $project), ['file' => $file]);
        $this->assertSame('20.000', $item->fresh()->qty);
    }

    public function test_import_flags_an_item_code_repeated_in_the_file(): void
    {
        $project = $this->makeProject($this->org);
        $file = $this->workbook([$this->boqHeading(), ['', 'A1', 'First', 'nos', 1, 1], ['', 'A1', 'Second', 'nos', 2, 2]]);

        $this->actingAs($this->pm)->post(route('boq.excel.import', $project), ['file' => $file]);

        $this->assertSame(3, session('boq_import.errors.0.row'));
        $this->assertStringContainsString('row 2', session('boq_import.errors.0.message'));
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_import_reads_the_saved_result_of_a_formula_and_never_runs_one(): void
    {
        $project = $this->makeProject($this->org);

        $file = $this->workbook([$this->boqHeading(), ['', 'F1', 'Formula quantity', 'cum', '=10*2.5', 100]]);
        $this->actingAs($this->pm)->post(route('boq.excel.import', $project), ['file' => $file]);
        $this->assertSame('25.000', BoqItem::where('project_id', $project->id)->where('code', 'F1')->first()->qty);

        // A formula whose saved result is missing is refused. It is not calculated here.
        $unsaved = $this->workbook([$this->boqHeading(), ['', 'F2', 'No saved value', 'cum', '=WEBSERVICE("http://127.0.0.1:9/x")', 100]], saveFormulaResults: false);
        $this->actingAs($this->pm)->post(route('boq.excel.import', $project), ['file' => $unsaved]);

        $this->assertStringContainsString('formula', session('boq_import.errors.0.message'));
        $this->assertNull(BoqItem::where('project_id', $project->id)->where('code', 'F2')->first());
    }

    public function test_import_finds_the_heading_row_and_common_heading_names(): void
    {
        $project = $this->makeProject($this->org);
        $file = $this->workbook([
            ['Bill of quantities'],
            [],
            ['Item No.', 'Item Description', 'UOM', 'Quantity', 'Rate (₹)'],
            ['3.1', 'Brickwork', 'cum', '1,250.5', '₹ 6,100'],
        ]);

        $this->actingAs($this->pm)->post(route('boq.excel.import', $project), ['file' => $file])->assertSessionHas('success');

        $item = BoqItem::where('project_id', $project->id)->first();
        $this->assertSame(['3.1', 'Brickwork', 'cum', '1250.500', '6100.00'], [$item->code, $item->description, $item->unit, $item->qty, $item->rate]);
        $this->assertNull($item->boq_section_id);
    }

    public function test_import_picks_the_sheet_named_boq(): void
    {
        $project = $this->makeProject($this->org);
        $file = $this->workbook([$this->boqHeading(), ['', 'S1', 'On the BOQ sheet', 'nos', 1, 1]], sheet: 'BOQ');

        $this->actingAs($this->pm)->post(route('boq.excel.import', $project), ['file' => $file])->assertSessionHas('success');
        $this->assertSame(1, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_import_rejects_files_that_are_not_workbooks(): void
    {
        $project = $this->makeProject($this->org);
        $fake = UploadedFile::fake()->createWithContent('boq.xlsx', 'this is plain text, not a zip');

        $this->actingAs($this->pm)->post(route('boq.excel.import', $project), ['file' => $fake]);
        $this->assertSame(0, session('boq_import.errors.0.row'));
        $this->assertStringContainsString('not a valid .xlsx', session('boq_import.errors.0.message'));

        $this->actingAs($this->pm)->post(route('boq.excel.import', $project), ['file' => UploadedFile::fake()->create('boq.csv', 1, 'text/csv')])
            ->assertSessionHasErrors('file');
    }

    public function test_import_of_an_empty_sheet_says_so(): void
    {
        $project = $this->makeProject($this->org);
        $this->actingAs($this->pm)->post(route('boq.excel.import', $project), ['file' => $this->workbook([$this->boqHeading()])]);

        $this->assertStringContainsString('no rows', session('boq_import.errors.0.message'));
    }

    public function test_service_import_is_all_or_nothing_inside_a_transaction(): void
    {
        $project = $this->makeProject($this->org);
        $file = $this->workbook([$this->boqHeading(), ['', 'T1', 'Good', 'nos', 1, 1], ['', 'T2', 'Bad', 'nos', 'x', 1]]);

        $result = app(BoqExcelService::class)->import($project, $file->getRealPath());

        $this->assertFalse($result->ok());
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_permissions_plan_and_tenant_isolation(): void
    {
        $project = $this->makeProject($this->org);
        $engineer = $this->member($this->org, 'engineer');
        $file = fn () => $this->workbook([$this->boqHeading(), ['', 'P1', 'Item', 'nos', 1, 1]]);

        // An engineer can look at the BOQ and download it, but not change it.
        $this->actingAs($engineer)->get(route('boq.excel.export', $project))->assertOk();
        $this->actingAs($engineer)->post(route('boq.excel.import', $project), ['file' => $file()])->assertForbidden();
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());

        // A role that can only view the BOQ cannot import either.
        $this->actingAs($this->member($this->org, 'viewer'))->post(route('boq.excel.import', $project), ['file' => $file()])->assertForbidden();

        // The free plan has no reports module.
        $free = $this->tenant('Small Co', 'free');
        $freeOwner = $this->member($free, 'owner');
        $freeProject = $this->makeProject($free);
        $this->actingAs($freeOwner)->get(route('boq.excel.export', $freeProject))->assertForbidden();
        $this->actingAs($freeOwner)->get(route('boq.excel.index', $freeProject))->assertForbidden();
        $this->actingAs($freeOwner)->post(route('boq.excel.import', $freeProject), ['file' => $file()])->assertForbidden();

        // Another company's project is not found.
        $other = $this->tenant('Other Co', 'business');
        $otherProject = $this->makeProject($other);
        $this->actingAs($this->owner)->get(route('boq.excel.export', $otherProject))->assertNotFound();
        $this->actingAs($this->owner)->post(route('boq.excel.import', $otherProject), ['file' => $file()])->assertNotFound();
        $this->assertSame(0, BoqItem::withoutGlobalScopes()->where('project_id', $otherProject->id)->count());
    }

    public function test_boq_page_shows_the_excel_buttons_only_where_the_plan_has_them(): void
    {
        $project = $this->makeProject($this->org);
        $this->actingAs($this->pm)->get(route('boq.index', $project))->assertOk()->assertSee('Export Excel')->assertSee('Import Excel')->assertSee('Export CSV');

        $free = $this->tenant('Small Co', 'free');
        $freeProject = $this->makeProject($free);
        $this->actingAs($this->member($free, 'owner'))->get(route('boq.index', $freeProject))->assertOk()->assertSee('Export CSV')->assertDontSee('Export Excel');
    }
}
