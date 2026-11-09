<?php

namespace Tests\Feature\Drawings;

use App\Models\AuditLog;
use App\Models\Drawing;
use App\Models\DrawingFolder;
use App\Models\DrawingIssuePin;
use App\Models\DrawingSheet;
use App\Models\DrawingVersion;
use App\Models\Issue;
use App\Models\User;
use App\Services\Drawings\DrawingRegisterService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\BuildsTenant;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use BuildsDrawings, BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake();
    }

    private function pdf(string $name = 'plan.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 200, 'application/pdf');
    }

    private function upload(User $user, $project, array $overrides = []): TestResponse
    {
        return $this->actingAs($user)->post(route('drawings.register.store', $project), [
            'kind' => 'drawing',
            'number' => 'A-101',
            'title' => 'Ground floor plan',
            'revision' => 'R0',
            'status' => 'for_construction',
            'file' => $this->pdf(),
            ...$overrides,
        ]);
    }

    public function test_upload_adds_an_entry_with_its_first_revision(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);

        $this->upload($this->member($organization, 'pm'), $project)->assertRedirect();

        $drawing = Drawing::firstOrFail();
        $version = $drawing->currentVersion;
        $this->assertSame('R0', $version->revision);
        $this->assertSame(1, $version->version_no);
        $this->assertSame('plan.pdf', $version->original_name);
        Storage::assertExists($version->path);
        $this->assertStringStartsWith('org-'.$organization->id.'/drawings/', $version->path);
    }

    public function test_repeating_an_upload_keeps_one_entry_and_no_stray_file(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');
        $uuid = (string) Str::uuid();

        $this->upload($user, $project, ['client_uuid' => $uuid])->assertRedirect();
        $this->upload($user, $project, ['client_uuid' => $uuid])->assertRedirect();

        $this->assertSame(1, Drawing::count());
        $this->assertCount(1, Storage::allFiles());
    }

    public function test_a_duplicate_number_is_refused_and_its_file_is_not_kept(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');
        $this->upload($user, $project)->assertRedirect();

        $this->upload($user, $project)->assertSessionHasErrors('number');

        $this->assertCount(1, Storage::allFiles());
    }

    public function test_a_new_revision_becomes_current(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');
        $this->upload($user, $project);
        $drawing = Drawing::firstOrFail();

        $this->actingAs($user)->post(route('drawings.register.versions.store', $drawing), ['revision' => 'R1', 'status' => 'approved', 'file' => $this->pdf('plan-r1.pdf')])->assertRedirect();

        $versions = DrawingVersion::orderBy('version_no')->get();
        $this->assertSame([false, true], $versions->pluck('is_current')->all());
        $this->assertSame('R1', $drawing->fresh()->currentVersion->revision);
    }

    public function test_a_revision_cannot_be_reused(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');
        $this->upload($user, $project);

        $this->actingAs($user)->post(route('drawings.register.versions.store', Drawing::firstOrFail()), ['revision' => 'R0', 'status' => 'approved', 'file' => $this->pdf()])->assertSessionHasErrors('revision');

        $this->assertCount(1, Storage::allFiles());
    }

    public function test_web_page_and_script_files_are_refused(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');

        foreach ([['page.html', 'text/html'], ['logo.svg', 'image/svg+xml'], ['run.php', 'application/x-php'], ['page.pdf', 'text/html']] as [$name, $mime]) {
            $this->upload($user, $project, ['number' => $name, 'file' => UploadedFile::fake()->create($name, 10, $mime)])->assertSessionHasErrors('file');
        }

        $this->assertSame(0, Drawing::count());
    }

    public function test_a_real_pdf_passes_the_content_check_and_a_disguised_page_does_not(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');

        $pdfPath = tempnam(sys_get_temp_dir(), 'drawing').'.pdf';
        file_put_contents($pdfPath, Pdf::loadHTML('<p>Plan</p>')->output());
        $this->upload($user, $project, ['file' => new UploadedFile($pdfPath, 'real.pdf', null, null, true)])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('application/pdf', DrawingVersion::firstOrFail()->mime);

        $htmlPath = tempnam(sys_get_temp_dir(), 'drawing').'.pdf';
        file_put_contents($htmlPath, '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>');
        $this->upload($user, $project, ['number' => 'B-1', 'file' => new UploadedFile($htmlPath, 'fake.pdf', null, null, true)])->assertSessionHasErrors('file');

        unlink($pdfPath);
        unlink($htmlPath);
    }

    public function test_a_cad_or_office_file_is_stored_but_not_given_sheets(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');
        $this->upload($user, $project, ['kind' => 'document', 'file' => UploadedFile::fake()->create('boq.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')])->assertRedirect();
        $version = DrawingVersion::firstOrFail();

        $this->actingAs($user)->postJson(route('drawings.register.sheets.store', $version), ['pages' => [['page' => 1, 'width' => 100, 'height' => 100]]])
            ->assertUnprocessable();

        $response = $this->actingAs($user)->get(route('drawings.register.file', $version))->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_a_pdf_opens_inline_or_downloads_on_request(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'viewer');
        $this->upload($this->member($organization, 'pm'), $project);
        $version = DrawingVersion::firstOrFail();

        $inline = $this->actingAs($user)->get(route('drawings.register.file', $version))->assertOk();
        $this->assertStringContainsString('inline', (string) $inline->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $inline->headers->get('X-Content-Type-Options'));

        $download = $this->actingAs($user)->get(route('drawings.register.file', [$version, 'download' => 1]))->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
    }

    public function test_a_removed_entry_stops_serving_its_file_and_sheets(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');
        $drawing = $this->makeDrawing($project);
        $version = $drawing->currentVersion;
        $sheet = $this->makeSheet($version);
        Storage::put($version->path, 'x');

        $this->actingAs($user)->delete(route('drawings.register.destroy', $drawing))->assertRedirect();

        $this->assertSoftDeleted($drawing);
        $this->actingAs($user)->get(route('drawings.register.file', $version))->assertNotFound();
        $this->actingAs($user)->get(route('drawings.register.sheet', $sheet))->assertNotFound();
    }

    public function test_an_entry_with_a_takeoff_cannot_be_removed(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');
        $sheet = $this->scaledSheet($project);

        $this->actingAs($user)->postJson(route('drawings.takeoff.measurements.store', $sheet), ['type' => 'count', 'takeoff_layer_id' => $this->makeLayer($project)->id, 'points' => [[1, 1]]])->assertCreated();

        $this->actingAs($user)->delete(route('drawings.register.destroy', $sheet->version->drawing))->assertSessionHasErrors('drawing');
        $this->assertNotSoftDeleted($sheet->version->drawing);
    }

    public function test_sheets_are_recorded_once_with_one_audit_row(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');
        $version = $this->makeDrawing($project)->currentVersion;
        AuditLog::query()->delete();
        $pages = [['page' => 1, 'width' => 842, 'height' => 595.28], ['page' => 2, 'width' => 842, 'height' => 595.28]];

        $this->actingAs($user)->postJson(route('drawings.register.sheets.store', $version), ['pages' => $pages])->assertOk()->assertJsonPath('created', 2);
        $this->actingAs($user)->postJson(route('drawings.register.sheets.store', $version), ['pages' => $pages])->assertOk()->assertJsonPath('created', 0);

        $this->assertSame(2, DrawingSheet::count());
        $this->assertSame('595.280', DrawingSheet::firstOrFail()->height);
        $this->assertSame(1, AuditLog::where('action', 'sheets_registered')->count());
        $this->assertSame(0, AuditLog::where('auditable_type', DrawingSheet::class)->count());
    }

    public function test_page_numbers_cannot_repeat(): void
    {
        $organization = $this->tenant();
        $version = $this->makeDrawing($this->makeProject($organization))->currentVersion;

        $this->actingAs($this->member($organization, 'pm'))
            ->postJson(route('drawings.register.sheets.store', $version), ['pages' => [['page' => 1, 'width' => 10, 'height' => 10], ['page' => 1, 'width' => 10, 'height' => 10]]])
            ->assertUnprocessable();
    }

    public function test_pins_move_to_the_new_revision_only_when_the_page_size_is_the_same(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $drawing = $this->makeDrawing($project);
        $old = $this->makeSheet($drawing->currentVersion, 1, '1000', '800');
        $oldSecond = $this->makeSheet($drawing->currentVersion, 2, '1000', '800');

        foreach ([$old, $oldSecond] as $index => $sheet) {
            $issue = Issue::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'title' => 'Crack '.$index, 'priority' => 'high', 'status' => 'open']);
            DrawingIssuePin::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'drawing_sheet_id' => $sheet->id, 'issue_id' => $issue->id, 'x' => 10, 'y' => 10]);
        }

        $next = $this->makeVersion($drawing, 'R1');
        app(DrawingRegisterService::class)->registerSheets($next, [
            ['page' => 1, 'width' => '1000.4', 'height' => '800'],
            ['page' => 2, 'width' => '2000', 'height' => '800'],
        ]);

        $sheets = DrawingSheet::where('drawing_version_id', $next->id)->orderBy('page_no')->get();
        $this->assertSame(1, $sheets[0]->pins()->count());
        $this->assertSame(0, $sheets[1]->pins()->count());
        $this->assertSame(1, $oldSecond->pins()->count());
    }

    public function test_folders_nest_three_deep_and_only_empty_ones_go(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');
        $store = route('drawings.register.folders.store', $project);

        $this->actingAs($user)->post($store, ['name' => 'Structural'])->assertRedirect();
        $first = DrawingFolder::firstOrFail();
        $this->actingAs($user)->post($store, ['name' => 'Footings', 'parent_id' => $first->id])->assertRedirect();
        $second = DrawingFolder::where('name', 'Footings')->firstOrFail();
        $this->actingAs($user)->post($store, ['name' => 'Block A', 'parent_id' => $second->id])->assertRedirect();
        $third = DrawingFolder::where('name', 'Block A')->firstOrFail();

        $this->actingAs($user)->post($store, ['name' => 'Too deep', 'parent_id' => $third->id])->assertSessionHasErrors('parent_id');
        $this->actingAs($user)->delete(route('drawings.register.folders.destroy', $second))->assertSessionHasErrors('folder');
        $this->actingAs($user)->delete(route('drawings.register.folders.destroy', $third))->assertRedirect();

        $this->assertDatabaseMissing('drawing_folders', ['id' => $third->id]);
    }

    public function test_adding_a_folder_twice_keeps_one(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'pm');

        $this->actingAs($user)->post(route('drawings.register.folders.store', $project), ['name' => 'Structural']);
        $this->actingAs($user)->post(route('drawings.register.folders.store', $project), ['name' => 'Structural']);

        $this->assertSame(1, DrawingFolder::count());
    }

    public function test_a_folder_from_another_project_is_refused(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $other = $this->makeProject($organization);
        $folder = DrawingFolder::create(['organization_id' => $organization->id, 'project_id' => $other->id, 'name' => 'Theirs']);

        $this->upload($this->member($organization, 'pm'), $project, ['drawing_folder_id' => $folder->id])->assertSessionHasErrors('drawing_folder_id');
    }

    public function test_the_register_filters_by_folder_type_and_search(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'viewer');
        $folder = DrawingFolder::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'name' => 'Structural']);
        $inFolder = $this->makeDrawing($project, 'S-200');
        $inFolder->update(['drawing_folder_id' => $folder->id]);
        $this->makeDrawing($project, 'A-100')->update(['kind' => 'document']);

        $this->actingAs($user)->get(route('drawings.register.index', [$project, 'folder' => $folder->id]))->assertOk()->assertSee('S-200')->assertDontSee('A-100');
        $this->actingAs($user)->get(route('drawings.register.index', [$project, 'kind' => 'document']))->assertOk()->assertSee('A-100')->assertDontSee('S-200');
        $this->actingAs($user)->get(route('drawings.register.index', [$project, 'q' => 's-2']))->assertOk()->assertSee('S-200')->assertDontSee('A-100');
    }

    public function test_every_screen_renders(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'owner');
        $sheet = $this->scaledSheet($project);
        $this->makeSheet($sheet->version, 2);
        $this->makeLayer($project);
        $drawing = $sheet->version->drawing;

        $this->actingAs($user)->get(route('drawings.register.index', $project))->assertOk()->assertSee('A-101');
        $this->actingAs($user)->get(route('drawings.register.index', [$project, 'view' => 'sheets']))->assertOk()->assertSee('Open');
        $this->actingAs($user)->get(route('drawings.register.show', $drawing))->assertOk()->assertSee('Open viewer');
        $this->actingAs($user)->get(route('drawings.register.sheet', $sheet))->assertOk()->assertSee('drawing-viewer-data', false)->assertSee('Next sheet');
        $this->actingAs($user)->get(route('drawings.takeoff.index', $project))->assertOk();
        $this->actingAs($user)->get(route('drawings.transmittals.index', $project))->assertOk();
        $this->actingAs($user)->get(route('drawings.transmittals.create', $project))->assertOk()->assertSee('A-101');
        $this->actingAs($user)->get(route('drawings.bbs.index', $project))->assertOk();
    }

    public function test_a_viewable_file_with_no_sheets_starts_the_sheet_reader_for_managers_only(): void
    {
        $organization = $this->tenant();
        $drawing = $this->makeDrawing($this->makeProject($organization));

        $this->actingAs($this->member($organization, 'pm'))->get(route('drawings.register.show', $drawing))->assertOk()->assertSee('drawing-sheet-register', false);
        $this->actingAs($this->member($organization, 'viewer'))->get(route('drawings.register.show', $drawing))->assertOk()->assertDontSee('drawing-sheet-register', false);
    }

    public function test_the_viewer_hands_the_canvas_only_what_this_user_may_do(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $sheet = $this->scaledSheet($project);

        $viewer = $this->actingAs($this->member($organization, 'viewer'))->get(route('drawings.register.sheet', $sheet))->assertOk();
        $viewer->assertDontSee('data-tool="area"', false)->assertDontSee('data-tool="pin"', false);

        $this->actingAs($this->member($organization, 'qs'))->get(route('drawings.register.sheet', $sheet))->assertOk()->assertSee('data-tool="area"', false)->assertDontSee('data-tool="pin"', false);
        $this->actingAs($this->member($organization, 'pm'))->get(route('drawings.register.sheet', $sheet))->assertOk()->assertSee('data-tool="area"', false)->assertSee('data-tool="pin"', false);
    }

    public function test_plans_without_the_module_are_blocked_and_get_no_menu(): void
    {
        $contractor = $this->tenant('Small Co', 'contractor');
        $project = $this->makeProject($contractor);
        $owner = $this->member($contractor, 'owner');

        $this->actingAs($owner)->get(route('drawings.register.index', $project))->assertForbidden();
        $this->actingAs($owner)->get(route('drawings.bbs.index', $project))->assertForbidden();
        $this->actingAs($owner)->get(route('projects.show', $project))->assertOk()->assertDontSee(route('drawings.register.index', $project));

        foreach (['business', 'enterprise'] as $plan) {
            $organization = $this->tenant('Co '.$plan, $plan);
            $this->actingAs($this->member($organization, 'owner'))->get(route('drawings.register.index', $this->makeProject($organization)))->assertOk();
        }
    }

    public function test_the_menu_links_to_the_module_on_a_plan_that_has_it(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);

        $this->actingAs($this->member($organization, 'viewer'))->get(route('projects.show', $project))->assertOk()
            ->assertSee(route('drawings.register.index', $project))
            ->assertSee(route('drawings.bbs.index', $project));
    }

    public function test_roles_need_the_manage_permission_to_change_the_register(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $drawing = $this->makeDrawing($project);

        foreach (['viewer', 'engineer', 'accounts', 'store'] as $role) {
            $user = $this->member($organization, $role);
            $this->actingAs($user)->get(route('drawings.register.index', $project))->assertOk();
            $this->upload($user, $project, ['number' => 'NEW'])->assertForbidden();
            $this->actingAs($user)->delete(route('drawings.register.destroy', $drawing))->assertForbidden();
            $this->actingAs($user)->post(route('drawings.register.folders.store', $project), ['name' => 'X'])->assertForbidden();
        }
    }

    public function test_other_companies_cannot_reach_the_register(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $drawing = $this->makeDrawing($project);
        $sheet = $this->makeSheet($drawing->currentVersion);
        $folder = DrawingFolder::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'name' => 'Ours']);
        $outsider = $this->member($this->tenant('Other Co'), 'owner');

        $this->actingAs($outsider)->get(route('drawings.register.index', $project))->assertNotFound();
        $this->actingAs($outsider)->get(route('drawings.register.show', $drawing))->assertNotFound();
        $this->actingAs($outsider)->get(route('drawings.register.file', $drawing->currentVersion))->assertNotFound();
        $this->actingAs($outsider)->get(route('drawings.register.sheet', $sheet))->assertNotFound();
        $this->actingAs($outsider)->post(route('drawings.register.versions.store', $drawing), ['revision' => 'R9', 'status' => 'approved', 'file' => $this->pdf()])->assertNotFound();
        $this->actingAs($outsider)->delete(route('drawings.register.folders.destroy', $folder))->assertNotFound();
        $this->actingAs($outsider)->patch(route('drawings.register.sheets.update', $sheet), ['title' => 'x'])->assertNotFound();
    }

    public function test_changes_are_audited(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->upload($this->member($organization, 'pm'), $project);

        $this->assertDatabaseHas('audit_logs', ['auditable_type' => Drawing::class, 'action' => 'created']);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => DrawingVersion::class, 'action' => 'created']);
    }

    public function test_migrations_roll_back_cleanly(): void
    {
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_12_09_100003_create_bbs_tables.php'])->assertSuccessful();
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_12_09_100002_create_takeoff_and_pin_tables.php'])->assertSuccessful();
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_12_09_100001_create_drawing_register_tables.php'])->assertSuccessful();

        foreach (['bbs_bars', 'bbs_schedules', 'drawing_issue_pins', 'takeoff_measurements', 'takeoff_layers', 'drawing_transmittal_items', 'drawing_transmittals', 'drawing_sheets', 'drawing_versions', 'drawings', 'drawing_folders'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
    }
}
