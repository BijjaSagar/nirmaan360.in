<?php

namespace Tests\Feature\BoqImport;

use App\Models\BoqItem;
use App\Services\Imports\BoqSheetImport;
use Illuminate\Http\UploadedFile;

/**
 * The import box on the BOQ page itself. It used to take only our own CSV, and its file box listed
 * only .csv, so a phone greyed out every Excel sheet and PDF. Now any file can be picked there, and
 * anything that is not our own CSV goes to the import that reads a sheet by its headings.
 */
class BoqPageUploadTest extends BoqImportTestCase
{
    /** A contractor's own layout, with invented text and figures. */
    private function ownLayout(): array
    {
        return [
            ['SAMPLE BUILDERS'], [],
            ['Sl. No.', 'Particulars', 'UOM', 'Qty', 'Unit Rate', 'Total Amount'],
            ['1', 'Clearing the sample site of shrubs', 'Sqm', 400, 12, 4800],
            ['2', 'Earth filling in the sample plinth', 'Cum', 60, 340, 20400],
        ];
    }

    public function test_no_boq_file_box_lists_file_types_so_a_phone_greys_nothing_out(): void
    {
        $project = $this->makeProject($this->org);

        $page = $this->actingAs($this->pm)->get(route('boq.index', $project))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input id="file" name="file" type="file" class="input" required>/', $page);
        $this->assertStringNotContainsString('accept=".csv', $page);
        $this->assertStringContainsString('BOQ file (.xlsx, .xls, .ods, .csv or .pdf)', $page);
        $this->assertStringNotContainsString('Import CSV', $page);

        $excel = $this->get(route('boq.excel.index', $project))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input id="file" name="file" type="file" class="input" required>/', $excel);
        $this->assertStringNotContainsString('accept=".xlsx', $excel);
    }

    public function test_an_excel_sheet_in_its_own_layout_picked_on_the_boq_page_goes_to_the_column_check(): void
    {
        $project = $this->makeProject($this->org);

        $this->actingAs($this->pm)->post(route('boq.import', $project), ['file' => $this->sheet($this->ownLayout(), 'client-boq.xls')])
            ->assertRedirect(route('boq.sheet.preview', $project));

        $this->assertNotNull(session(BoqSheetImport::SESSION));
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());

        $preview = $this->preview($project);
        $this->assertSame('D', $this->columnOf($preview, 'qty'));

        $this->confirm($project)->assertSessionHasNoErrors();
        $this->assertSame(2, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_a_csv_in_another_layout_goes_to_the_column_check_too(): void
    {
        $project = $this->makeProject($this->org);
        $csv = "Sl. No.,Particulars,UOM,Qty,Unit Rate,Total Amount\n1,Clearing the sample site of shrubs,Sqm,400,12,4800\n2,Earth filling in the sample plinth,Cum,60,340,20400\n";

        $this->actingAs($this->pm)->post(route('boq.import', $project), ['file' => $this->csvFile($csv, 'client.csv')])
            ->assertRedirect(route('boq.sheet.preview', $project));
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_our_own_csv_still_imports_at_once_on_the_boq_page(): void
    {
        $project = $this->makeProject($this->org);
        $csv = "section,code,description,unit,qty,rate\nSubstructure,1.1,Sample excavation,cum,25,180\n";

        $this->actingAs($this->pm)->post(route('boq.import', $project), ['file' => $this->csvFile($csv)])
            ->assertSessionHasNoErrors()->assertSessionMissing(BoqSheetImport::SESSION);

        $line = $this->lines($project)['1.1'];
        $this->assertSame(['Sample excavation', 'cum', '25.000', '180.00'], [$line->description, $line->unit, $line->qty, $line->rate]);
    }

    public function test_a_plan_without_the_any_layout_import_keeps_the_csv_box_and_refuses_an_excel_sheet(): void
    {
        $org = $this->tenant('Free Example Co', 'free');
        $pm = $this->member($org, 'pm');
        $project = $this->makeProject($org);

        $page = $this->actingAs($pm)->get(route('boq.index', $project))->assertOk()->getContent();
        $this->assertStringContainsString('Import CSV', $page);
        $this->assertStringNotContainsString('accept=', substr($page, (int) strpos($page, 'Import CSV'), 900));

        $this->post(route('boq.import', $project), ['file' => $this->sheet($this->ownLayout())])->assertSessionHasErrors('file');
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());

        $csv = "section,code,description,unit,qty,rate\n,1,Sample line,cum,2,100\n";
        $this->post(route('boq.import', $project), ['file' => $this->csvFile($csv)])->assertSessionHasNoErrors();
        $this->assertSame(1, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_our_own_csv_saved_without_a_file_ending_still_imports_at_once(): void
    {
        $project = $this->makeProject($this->org);
        $csv = "section,code,description,unit,qty,rate\n,7,Sample line,cum,3,150\n";

        // A real file on disk, so its type is read from what is in it, as for an upload from a browser.
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $csv);

        $this->actingAs($this->pm)->post(route('boq.import', $project), ['file' => new UploadedFile($path, 'boq-export', null, null, true)])
            ->assertSessionHasNoErrors()->assertSessionMissing(BoqSheetImport::SESSION);
        $this->assertSame(1, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_an_upload_that_is_not_one_file_is_refused_with_a_field_error(): void
    {
        $project = $this->makeProject($this->org);

        $this->actingAs($this->pm)->post(route('boq.import', $project), ['file' => [$this->sheet($this->ownLayout()), $this->sheet($this->ownLayout())]])
            ->assertSessionHasErrors('file');
        $this->post(route('boq.import', $project), [])->assertSessionHasErrors('file');
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_a_file_with_one_endless_first_line_is_not_taken_for_our_csv_and_costs_one_short_read(): void
    {
        $project = $this->makeProject($this->org);
        $long = str_repeat('sample,', 200000)."\n";

        $this->actingAs($this->pm)->post(route('boq.import', $project), ['file' => $this->csvFile($long, 'long.csv')])
            ->assertRedirect();
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_the_boq_page_box_has_the_same_limit_on_tries_as_the_import_it_hands_over_to(): void
    {
        $this->assertContains('throttle:20,1', app('router')->getRoutes()->getByName('boq.import')->gatherMiddleware());
    }

    public function test_another_companys_project_is_not_reached_through_the_boq_page_box(): void
    {
        $other = $this->tenant('Other Example Co', 'business');
        $project = $this->makeProject($other);

        $this->actingAs($this->pm)->post(route('boq.import', $project), ['file' => $this->sheet($this->ownLayout())])->assertNotFound();
        $this->assertSame(0, BoqItem::withoutGlobalScopes()->where('project_id', $project->id)->count());
    }

    public function test_someone_who_may_not_change_the_boq_cannot_upload_there(): void
    {
        $project = $this->makeProject($this->org);
        $viewer = $this->member($this->org, 'viewer');

        $this->actingAs($viewer)->post(route('boq.import', $project), ['file' => new UploadedFile($this->sheet($this->ownLayout())->getRealPath(), 'boq.xlsx', null, null, true)])
            ->assertForbidden();
    }
}
