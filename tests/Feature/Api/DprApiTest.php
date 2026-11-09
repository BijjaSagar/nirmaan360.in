<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Dpr;
use App\Models\DprPhoto;
use App\Models\Project;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\DprService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DprApiTest extends ApiTestCase
{
    private function draft(Project $project, User $user, array $overrides = []): Dpr
    {
        return app(DprService::class)->create($project, $this->dprData($overrides), $user);
    }

    private function approve(Dpr $dpr, User $submitter, User $approver): void
    {
        app(ApprovalService::class)->act(app(DprService::class)->submit($dpr, $submitter), $approver, 'approve');
    }

    public function test_create_is_idempotent_on_client_uuid(): void
    {
        $site = $this->site();
        $payload = $this->dprData(['client_uuid' => (string) Str::uuid()]);

        $this->postJson(self::API."/projects/{$site->project->id}/dprs", $payload)->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.report_date', '2026-11-01')
            ->assertJsonPath('data.created_by_name', $site->user->name)
            ->assertJsonPath('data.manpower.0', ['trade' => 'Mason', 'count' => 12])
            ->assertJsonPath('data.machinery.0', ['name' => 'Crane', 'hours' => '2.0'])
            ->assertJsonPath('data.work_items.0', ['boq_item_id' => null, 'description' => 'Formwork', 'qty' => '3.500']);

        $this->postJson(self::API."/projects/{$site->project->id}/dprs", $payload)->assertOk();

        $this->assertSame(1, Dpr::count());
    }

    public function test_list_is_paginated_newest_first(): void
    {
        $site = $this->site();
        $this->draft($site->project, $site->user, ['report_date' => '2026-11-01']);
        $newest = $this->draft($site->project, $site->user, ['report_date' => '2026-11-05']);

        $this->getJson(self::API."/projects/{$site->project->id}/dprs")->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $newest->id);
    }

    public function test_show_has_children_and_the_approval_trail(): void
    {
        $site = $this->site();
        $pm = $this->member($site->organization, 'pm');
        $dpr = $this->draft($site->project, $site->user);
        app(ApprovalService::class)->act(app(DprService::class)->submit($dpr, $site->user), $pm, 'return', 'Add crane hours');

        $this->getJson(self::API."/dprs/{$dpr->id}")->assertOk()
            ->assertJsonPath('data.status', 'RETURNED')
            ->assertJsonPath('data.return_remark', 'Add crane hours')
            ->assertJsonPath('data.manpower.0.trade', 'Mason')
            ->assertJsonPath('data.steps.0.action', 'submit')
            ->assertJsonPath('data.steps.1.action', 'return')
            ->assertJsonPath('data.steps.1.actor', $pm->name)
            ->assertJsonPath('data.steps.1.remark', 'Add crane hours');
    }

    public function test_patch_replaces_children_and_moves_updated_at(): void
    {
        $site = $this->site();
        $dpr = $this->draft($site->project, $site->user);
        $this->travel(5)->minutes();

        $this->patchJson(self::API."/dprs/{$dpr->id}", $this->dprData(['manpower' => [['trade' => 'Helper', 'count' => 4]]]))->assertOk()
            ->assertJsonCount(1, 'data.manpower')
            ->assertJsonPath('data.manpower.0.trade', 'Helper')
            ->assertJsonPath('data.machinery.0.name', 'Crane');

        $this->assertSame(1, $dpr->manpower()->count());
        $this->assertTrue($dpr->fresh()->updated_at->isAfter($dpr->created_at));
    }

    public function test_replacing_children_writes_an_audit_row(): void
    {
        $site = $this->site();
        $dpr = $this->draft($site->project, $site->user);

        $this->patchJson(self::API."/dprs/{$dpr->id}", $this->dprData(['manpower' => [['trade' => 'Helper', 'count' => 4]]]))->assertOk();
        $this->patchJson(self::API."/dprs/{$dpr->id}", $this->dprData(['manpower' => [['trade' => 'Helper', 'count' => 4]]]))->assertOk();

        $audit = AuditLog::where('action', 'dpr.children_replaced')->sole();
        $this->assertSame('Mason', $audit->old_values['manpower'][0]['trade']);
        $this->assertSame('Helper', $audit->new_values['manpower'][0]['trade']);
    }

    public function test_patch_is_refused_once_the_dpr_has_left_the_engineer(): void
    {
        $site = $this->site();
        $pm = $this->member($site->organization, 'pm');
        $inReview = $this->draft($site->project, $site->user);
        app(DprService::class)->submit($inReview, $site->user);
        $approved = $this->draft($site->project, $site->user);
        $this->approve($approved, $site->user, $pm);

        $this->patchJson(self::API."/dprs/{$inReview->id}", $this->dprData(['summary' => 'Changed']))->assertStatus(409);
        $this->patchJson(self::API."/dprs/{$approved->id}", $this->dprData(['summary' => 'Changed']))->assertStatus(409);

        $this->assertSame('Slab cast', $approved->fresh()->summary);
    }

    public function test_submit_goes_through_approvals(): void
    {
        $site = $this->site();
        $dpr = $this->draft($site->project, $site->user);

        $this->postJson(self::API."/dprs/{$dpr->id}/submit")->assertOk()->assertJsonPath('data.status', 'IN_REVIEW');

        $this->assertSame('pm', $dpr->currentApproval->currentRole());
        $this->assertSame(1, AuditLog::where('action', 'approval.submit')->count());
    }

    public function test_photo_upload_is_idempotent_and_serves_the_bytes(): void
    {
        Storage::fake('local');
        $site = $this->site();
        $dpr = $this->draft($site->project, $site->user);
        $uuid = (string) Str::uuid();
        $payload = ['client_uuid' => $uuid, 'caption' => 'Panel S1', 'taken_at' => '2026-11-09T04:30:00Z'];

        $id = $this->post(self::API."/dprs/{$dpr->id}/photos", [...$payload, 'photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.caption', 'Panel S1')->json('data.id');
        $this->post(self::API."/dprs/{$dpr->id}/photos", [...$payload, 'photo' => UploadedFile::fake()->image('b.jpg')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.id', $id);

        $photo = DprPhoto::sole();
        $this->assertSame('2026-11-09 10:00:00', $photo->taken_at->format('Y-m-d H:i:s'));
        $this->assertCount(1, Storage::disk('local')->allFiles());

        $this->get(self::API."/dpr-photos/{$photo->id}")->assertOk();
    }

    public function test_a_photo_cannot_be_added_to_an_approved_dpr(): void
    {
        Storage::fake('local');
        $site = $this->site();
        $dpr = $this->draft($site->project, $site->user);
        $this->approve($dpr, $site->user, $this->member($site->organization, 'pm'));

        $this->post(self::API."/dprs/{$dpr->id}/photos", ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertStatus(409);

        $this->assertSame(0, DprPhoto::count());
    }

    public function test_cross_company_site_and_boq_ids_are_rejected(): void
    {
        $site = $this->site();
        $theirs = $this->makeBoqItem($this->makeProject($site->organization));

        $this->postJson(self::API."/projects/{$site->project->id}/dprs", $this->dprData(['work' => [['boq_item_id' => $theirs->id, 'qty' => 1]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('work.0.boq_item_id');
    }

    public function test_routes_need_their_permission(): void
    {
        Storage::fake('local');
        $site = $this->site();
        $dpr = $this->draft($site->project, $site->user);
        $photo = app(DprService::class)->addPhoto($dpr, UploadedFile::fake()->image('a.jpg'));
        $projectDprs = self::API."/projects/{$site->project->id}/dprs";

        $this->signIn($this->member($site->organization, 'accounts'));
        $this->getJson($projectDprs)->assertForbidden();
        $this->getJson(self::API."/dprs/{$dpr->id}")->assertForbidden();
        $this->getJson(self::API."/dpr-photos/{$photo->id}")->assertForbidden();

        $this->signIn($this->member($site->organization, 'viewer'));
        $this->postJson($projectDprs, $this->dprData())->assertForbidden();
        $this->patchJson(self::API."/dprs/{$dpr->id}", $this->dprData())->assertForbidden();
        $this->postJson(self::API."/dprs/{$dpr->id}/submit")->assertForbidden();
        $this->postJson(self::API."/dprs/{$dpr->id}/photos")->assertForbidden();
    }

    public function test_routes_respect_the_plan_feature(): void
    {
        $site = $this->site('owner');
        config(['buildos.plans.business.features' => ['measurement']]);

        $this->getJson(self::API."/projects/{$site->project->id}/dprs")->assertForbidden();
    }

    public function test_another_companys_dprs_are_a_404(): void
    {
        Storage::fake('local');
        $site = $this->site();
        $dpr = $this->draft($site->project, $site->user);
        $photo = app(DprService::class)->addPhoto($dpr, UploadedFile::fake()->image('a.jpg'));
        $this->signInElsewhere();

        $this->getJson(self::API."/projects/{$site->project->id}/dprs")->assertNotFound();
        $this->postJson(self::API."/projects/{$site->project->id}/dprs", $this->dprData())->assertNotFound();
        $this->getJson(self::API."/dprs/{$dpr->id}")->assertNotFound();
        $this->patchJson(self::API."/dprs/{$dpr->id}", $this->dprData())->assertNotFound();
        $this->postJson(self::API."/dprs/{$dpr->id}/submit")->assertNotFound();
        $this->postJson(self::API."/dprs/{$dpr->id}/photos")->assertNotFound();
        $this->getJson(self::API."/dpr-photos/{$photo->id}")->assertNotFound();
    }

    public function test_the_web_form_still_saves_children_through_the_service(): void
    {
        $site = $this->site();
        $this->withoutVite();
        $this->actingAs($site->user);

        $this->post("/projects/{$site->project->id}/dprs", $this->dprData(['client_uuid' => (string) Str::uuid()]))->assertRedirect();

        $dpr = Dpr::sole();
        $this->assertSame('12', (string) $dpr->manpower()->sole()->count);
        $this->assertSame('3.500', $dpr->workItems()->sole()->qty);
    }
}
