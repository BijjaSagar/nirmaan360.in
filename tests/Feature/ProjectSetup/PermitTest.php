<?php

namespace Tests\Feature\ProjectSetup;

use App\Models\ProjectPermit;
use App\Models\ProjectPermitFile;
use App\Services\Access\TeamAccessService;
use App\Services\ProjectSetup\PermitService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PermitTest extends ProjectSetupTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
    }

    private function permit(array $attributes = [], $project = null): ProjectPermit
    {
        $project ??= $this->project;

        return ProjectPermit::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'name' => 'Fire NOC',
            'status' => 'not_started',
            ...$attributes,
        ]);
    }

    public function test_a_permission_is_added_with_everything_the_checklist_holds(): void
    {
        $this->actingAs($this->pm)->post(route('project-setup.permits.store', $this->project), [
            'name' => 'Building plan sanction',
            'authority' => 'Municipal corporation',
            'documents_required' => 'Site plan, ownership papers',
            'status' => 'applied',
            'applied_on' => '2026-11-02',
            'fee' => '125000.50',
            'remarks' => 'Follow up on Monday',
        ])->assertSessionHasNoErrors();

        $permit = ProjectPermit::sole();

        $this->assertSame('Building plan sanction', $permit->name);
        $this->assertSame('Municipal corporation', $permit->authority);
        $this->assertSame('Site plan, ownership papers', $permit->documents_required);
        $this->assertSame('applied', $permit->status);
        $this->assertSame('2026-11-02', $permit->applied_on->toDateString());
        $this->assertSame('125000.50', $permit->fee);
        $this->assertSame($this->project->id, $permit->project_id);
        $this->assertSame($this->org->id, $permit->organization_id);
    }

    public function test_pressing_save_twice_adds_one_permission_and_a_repeat_cannot_cross_projects(): void
    {
        $uuid = (string) Str::uuid();
        $fields = ['name' => 'Fire NOC', 'status' => 'applied', 'client_uuid' => $uuid];

        $this->actingAs($this->pm)->post(route('project-setup.permits.store', $this->project), $fields)->assertSessionHasNoErrors();
        $this->actingAs($this->pm)->post(route('project-setup.permits.store', $this->project), $fields)->assertSessionHasNoErrors();

        $this->assertSame(1, ProjectPermit::count());
        $this->assertSame($uuid, ProjectPermit::sole()->client_uuid);

        $elsewhere = $this->makeProject($this->org, ['code' => 'ELSE']);
        $this->actingAs($this->pm)->post(route('project-setup.permits.store', $elsewhere), $fields)->assertSessionHasErrors('client_uuid');
        $this->actingAs($this->pm)->post(route('project-setup.permits.store', $this->project), [...$fields, 'client_uuid' => 'not-a-uuid'])->assertSessionHasErrors('client_uuid');

        $this->assertSame(1, ProjectPermit::count());
        $this->get(route('project-setup.permits.index', $this->project))->assertSee('name="client_uuid"', false);
    }

    public function test_an_update_cannot_change_the_request_id(): void
    {
        $permit = $this->permit(['client_uuid' => '11111111-1111-4111-8111-111111111111']);

        $this->actingAs($this->pm)->patch(route('project-setup.permits.update', $permit), [
            'name' => 'Fire NOC', 'status' => 'applied', 'client_uuid' => '22222222-2222-4222-8222-222222222222',
        ])->assertSessionHasNoErrors();

        $this->assertSame('11111111-1111-4111-8111-111111111111', $permit->fresh()->client_uuid);
    }

    public function test_only_the_four_statuses_are_accepted_and_approval_cannot_precede_the_application(): void
    {
        $this->actingAs($this->pm)->post(route('project-setup.permits.store', $this->project), ['name' => 'x', 'status' => 'maybe'])->assertSessionHasErrors('status');
        $this->actingAs($this->pm)->post(route('project-setup.permits.store', $this->project), [
            'name' => 'x', 'status' => 'approved', 'applied_on' => '2026-11-10', 'approved_on' => '2026-11-01',
        ])->assertSessionHasErrors('approved_on');
        $this->actingAs($this->pm)->post(route('project-setup.permits.store', $this->project), ['name' => '', 'status' => 'applied'])->assertSessionHasErrors('name');

        $this->assertSame(0, ProjectPermit::count());
    }

    public function test_marking_a_permission_applied_or_approved_fills_in_todays_date(): void
    {
        $this->travelTo('2026-12-05 10:00:00');

        $this->actingAs($this->pm)->post(route('project-setup.permits.store', $this->project), ['name' => 'Fire NOC', 'status' => 'approved'])->assertSessionHasNoErrors();

        $permit = ProjectPermit::sole();
        $this->assertSame('2026-12-05', $permit->applied_on->toDateString());
        $this->assertSame('2026-12-05', $permit->approved_on->toDateString());
    }

    public function test_a_permission_is_updated_and_removed(): void
    {
        $permit = $this->permit();

        $this->actingAs($this->pm)->patch(route('project-setup.permits.update', $permit), ['name' => 'Fire NOC', 'status' => 'rejected', 'remarks' => 'Re-apply with new plan'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $permit->fresh()->status);

        $this->actingAs($this->pm)->delete(route('project-setup.permits.destroy', $permit))->assertSessionHasNoErrors();
        $this->assertSame(0, ProjectPermit::count());
    }

    public function test_the_total_fee_is_added_exactly_and_is_shown_as_a_separate_head_to_money_overview_only(): void
    {
        $this->permit(['name' => 'A', 'fee' => '0.10']);
        $this->permit(['name' => 'B', 'fee' => '0.20']);
        $this->permit(['name' => 'C', 'fee' => '125000.00']);
        $this->permit(['name' => 'D', 'fee' => null]);

        $this->assertSame('125000.30', app(PermitService::class)->totalFee($this->project));

        $this->actingAs($this->pm)->get(route('project-setup.permits.index', $this->project))
            ->assertOk()->assertSee('Total fees')->assertSee('₹1,25,000.30', false)->assertSee('A separate head, not part of the BOQ');
    }

    public function test_fees_are_hidden_from_everyone_without_money_overview(): void
    {
        $this->permit(['name' => 'Fire NOC', 'fee' => '98765.00']);

        foreach ([$this->engineer, $this->qs, $this->holding(['permit.view', 'permit.manage', 'project.view', 'money.view'])] as $user) {
            $this->actingAs($user)->get(route('project-setup.permits.index', $this->project))
                ->assertOk()->assertSee('Fire NOC')->assertDontSee('98,765')->assertDontSee('Total fees')->assertDontSee('Fee (₹)');
        }
    }

    public function test_someone_without_money_overview_cannot_set_or_wipe_a_fee(): void
    {
        $permit = $this->permit(['fee' => '500.00']);
        $clerk = $this->holding(['permit.view', 'permit.manage', 'project.view']);

        $this->actingAs($clerk)->patch(route('project-setup.permits.update', $permit), ['name' => 'Fire NOC renamed', 'status' => 'applied', 'fee' => '9.00'])->assertSessionHasNoErrors();

        $permit->refresh();
        $this->assertSame('Fire NOC renamed', $permit->name);
        $this->assertSame('500.00', $permit->fee);

        $this->actingAs($clerk)->post(route('project-setup.permits.store', $this->project), ['name' => 'New', 'status' => 'not_started', 'fee' => '77'])->assertSessionHasNoErrors();
        $this->assertNull(ProjectPermit::where('name', 'New')->sole()->fee);
    }

    public function test_the_checklist_is_open_to_view_for_site_roles_but_only_managers_change_it(): void
    {
        $permit = $this->permit();

        $this->actingAs($this->engineer)->get(route('project-setup.permits.index', $this->project))->assertOk()->assertDontSee('Add permission')->assertDontSee('Save changes');
        $this->actingAs($this->engineer)->post(route('project-setup.permits.store', $this->project), ['name' => 'x', 'status' => 'applied'])->assertForbidden();
        $this->actingAs($this->engineer)->patch(route('project-setup.permits.update', $permit), ['name' => 'x', 'status' => 'applied'])->assertForbidden();
        $this->actingAs($this->engineer)->delete(route('project-setup.permits.destroy', $permit))->assertForbidden();
        $this->actingAs($this->qs)->post(route('project-setup.permits.files.store', $permit), ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertForbidden();

        $this->actingAs($this->holding(['project.view']))->get(route('project-setup.permits.index', $this->project))->assertForbidden();
        $this->assertSame('not_started', $permit->fresh()->status);
    }

    public function test_a_document_is_stored_privately_and_opened_through_a_signed_in_route(): void
    {
        $permit = $this->permit();

        $this->actingAs($this->pm)->post(route('project-setup.permits.files.store', $permit), ['file' => UploadedFile::fake()->createWithContent('sanction.pdf', "%PDF-1.4\nsanction")])->assertSessionHasNoErrors();

        $file = ProjectPermitFile::sole();
        $this->assertSame('sanction.pdf', $file->original_name);
        $this->assertSame($permit->id, $file->project_permit_id);
        $this->assertStringStartsWith('org-'.$this->org->id.'/permits/'.$permit->id.'/', $file->path);
        Storage::assertExists($file->path);

        $response = $this->actingAs($this->engineer)->get(route('project-setup.permits.files.show', $file))->assertOk();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sanction.pdf', $response->headers->get('Content-Disposition'));

        auth()->logout();
        $this->get(route('project-setup.permits.files.show', $file))->assertRedirect(route('login'));
    }

    public function test_only_documents_of_the_right_kind_and_size_are_accepted(): void
    {
        $permit = $this->permit();

        foreach ([
            UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream'),
            UploadedFile::fake()->create('page.html', 10, 'text/html'),
            UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf'),
        ] as $file) {
            $this->actingAs($this->pm)->post(route('project-setup.permits.files.store', $permit), ['file' => $file])->assertSessionHasErrors('file');
        }

        $this->actingAs($this->pm)->post(route('project-setup.permits.files.store', $permit), [])->assertSessionHasErrors('file');
        $this->assertSame(0, ProjectPermitFile::count());
    }

    public function test_removing_a_document_or_a_permission_removes_the_stored_file(): void
    {
        $permit = $this->permit();
        $service = app(PermitService::class);
        $this->actingAs($this->pm);

        $first = $service->addFile($permit, UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'), $this->pm);
        $second = $service->addFile($permit, UploadedFile::fake()->create('b.pdf', 5, 'application/pdf'), $this->pm);

        $this->delete(route('project-setup.permits.files.destroy', $first))->assertSessionHasNoErrors();
        Storage::assertMissing($first->path);
        Storage::assertExists($second->path);

        $this->delete(route('project-setup.permits.destroy', $permit))->assertSessionHasNoErrors();
        Storage::assertMissing($second->path);
        $this->assertSame(0, ProjectPermitFile::count());
    }

    public function test_one_company_cannot_see_or_touch_anothers_permissions_or_documents(): void
    {
        $permit = $this->permit();
        $file = app(PermitService::class)->addFile($permit, UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'), $this->pm);
        [, $rivalOwner, $rivalProject] = $this->rival();
        $this->permit(['name' => 'Rival NOC'], $rivalProject);

        $this->actingAs($rivalOwner);
        $this->get(route('project-setup.permits.index', $this->project))->assertNotFound();
        $this->get(route('project-setup.permits.index', $rivalProject))->assertOk()->assertSee('Rival NOC')->assertDontSee('Fire NOC');
        $this->get(route('project-setup.permits.files.show', $file))->assertNotFound();
        $this->post(route('project-setup.permits.store', $this->project), ['name' => 'x', 'status' => 'applied'])->assertNotFound();
        $this->patch(route('project-setup.permits.update', $permit), ['name' => 'x', 'status' => 'applied'])->assertNotFound();
        $this->delete(route('project-setup.permits.destroy', $permit))->assertNotFound();
        $this->post(route('project-setup.permits.files.store', $permit), ['file' => UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')])->assertNotFound();
        $this->delete(route('project-setup.permits.files.destroy', $file))->assertNotFound();

        $this->assertSame('Fire NOC', $permit->fresh()->name);
    }

    public function test_someone_limited_to_other_projects_cannot_open_this_projects_permissions(): void
    {
        $permit = $this->permit();
        $file = app(PermitService::class)->addFile($permit, UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'), $this->pm);
        $elsewhere = $this->makeProject($this->org, ['code' => 'OTH']);
        $limited = $this->member($this->org, 'pm');
        app(TeamAccessService::class)->applyProjects($limited, true, [$elsewhere->id]);
        $limited->refresh();

        $this->actingAs($limited)->get(route('project-setup.permits.index', $this->project))->assertNotFound();
        $this->actingAs($limited)->get(route('project-setup.permits.files.show', $file))->assertNotFound();
        $this->actingAs($limited)->patch(route('project-setup.permits.update', $permit), ['name' => 'x', 'status' => 'applied'])->assertNotFound();
        $this->actingAs($limited)->get(route('project-setup.permits.index', $elsewhere))->assertOk();
    }

    public function test_the_checklist_needs_a_plan_with_site_control(): void
    {
        $free = $this->tenant('Free Co', 'free');
        $owner = $this->member($free, 'owner');
        $project = $this->makeProject($free);

        $this->actingAs($owner)->get(route('project-setup.permits.index', $project))->assertForbidden();
    }
}
