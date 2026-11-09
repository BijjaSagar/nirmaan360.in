<?php

namespace Tests\Feature\Api;

use App\Models\Dpr;
use App\Models\DprPhoto;
use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\Project;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\DprService;
use App\Services\PettyCashService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

class SyncUploadTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function dpr(Project $project, User $user): Dpr
    {
        return app(DprService::class)->create($project, $this->dprData(['client_uuid' => (string) Str::uuid()]), $user);
    }

    private function entry(Project $project, User $user): PettyCashEntry
    {
        $wallet = PettyCashWallet::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'name' => 'Imprest', 'user_id' => $user->id]);

        return app(PettyCashService::class)->addEntry($wallet, ['type' => 'topup', 'amount' => '10', 'entry_date' => '2026-11-01', 'client_uuid' => (string) Str::uuid()], $user);
    }

    private function upload(array $fields, ?UploadedFile $file = null): TestResponse
    {
        return $this->post(self::API.'/sync/upload', ['client_uuid' => (string) Str::uuid(), 'file' => $file ?? UploadedFile::fake()->image('a.jpg'), ...$fields], ['Accept' => 'application/json']);
    }

    public function test_dpr_photo_upload_is_idempotent(): void
    {
        $site = $this->site();
        $dpr = $this->dpr($site->project, $site->user);
        $fields = ['type' => 'dpr_photo', 'client_uuid' => (string) Str::uuid(), 'parent_client_uuid' => $dpr->client_uuid, 'caption' => 'Panel S1', 'taken_at' => '2026-11-09T10:00:00+05:30'];

        $id = $this->upload($fields)->assertCreated()->assertJsonPath('status', 'created')->json('id');
        $this->upload($fields)->assertOk()->assertJsonPath('status', 'duplicate')->assertJsonPath('id', $id);

        $photo = DprPhoto::sole();
        $this->assertSame($id, $photo->id);
        $this->assertSame('Panel S1', $photo->caption);
        $this->assertSame('2026-11-09 10:00:00', $photo->taken_at->format('Y-m-d H:i:s'));
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_a_dpr_photo_can_reference_its_parent_by_id(): void
    {
        $site = $this->site();
        $dpr = $this->dpr($site->project, $site->user);

        $this->upload(['type' => 'dpr_photo', 'parent_id' => $dpr->id])->assertCreated();

        $this->assertSame($dpr->id, DprPhoto::sole()->dpr_id);
    }

    public function test_petty_bill_upload_is_idempotent(): void
    {
        $site = $this->site();
        $entry = $this->entry($site->project, $site->user);
        $fields = ['type' => 'petty_bill', 'parent_client_uuid' => $entry->client_uuid];

        $this->upload($fields, UploadedFile::fake()->create('bill.pdf', 10, 'application/pdf'))->assertCreated()->assertJsonPath('status', 'created')->assertJsonPath('id', $entry->id);
        $this->upload($fields, UploadedFile::fake()->create('other.pdf', 10, 'application/pdf'))->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertNotNull($entry->fresh()->bill_path);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_an_unknown_parent_is_a_404(): void
    {
        $this->site();

        $this->upload(['type' => 'dpr_photo', 'parent_client_uuid' => (string) Str::uuid()])->assertNotFound();
        $this->upload(['type' => 'petty_bill', 'parent_id' => 999999])->assertNotFound();

        $this->assertSame(0, DprPhoto::count());
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_an_approved_parent_is_a_409(): void
    {
        $site = $this->site();
        $pm = $this->member($site->organization, 'pm');
        $dpr = $this->dpr($site->project, $site->user);
        app(ApprovalService::class)->act(app(DprService::class)->submit($dpr, $site->user), $pm, 'approve');

        $this->upload(['type' => 'dpr_photo', 'parent_client_uuid' => $dpr->client_uuid])->assertStatus(409);

        $this->assertSame(0, DprPhoto::count());
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_a_retry_after_the_parent_was_approved_is_still_a_duplicate(): void
    {
        $site = $this->site();
        $pm = $this->member($site->organization, 'pm');
        $dpr = $this->dpr($site->project, $site->user);
        $fields = ['type' => 'dpr_photo', 'client_uuid' => (string) Str::uuid(), 'parent_client_uuid' => $dpr->client_uuid];
        $this->upload($fields)->assertCreated();
        app(ApprovalService::class)->act(app(DprService::class)->submit($dpr, $site->user), $pm, 'approve');

        $this->upload($fields)->assertOk()->assertJsonPath('status', 'duplicate');
    }

    public function test_a_parent_is_required_and_files_are_checked_by_type(): void
    {
        $site = $this->site();
        $dpr = $this->dpr($site->project, $site->user);

        $this->upload(['type' => 'dpr_photo'])->assertUnprocessable()->assertJsonValidationErrors('parent_client_uuid');
        $this->upload(['type' => 'bad', 'parent_id' => $dpr->id])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->upload(['type' => 'dpr_photo', 'parent_id' => $dpr->id], UploadedFile::fake()->create('bill.pdf', 10, 'application/pdf'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_upload_permission_follows_the_type(): void
    {
        $site = $this->site();
        $dpr = $this->dpr($site->project, $site->user);
        $entry = $this->entry($site->project, $site->user);

        $this->signIn($this->member($site->organization, 'viewer'));
        $this->upload(['type' => 'dpr_photo', 'parent_id' => $dpr->id])->assertForbidden();

        $this->signIn($this->member($site->organization, 'store'));
        $this->upload(['type' => 'petty_bill', 'parent_id' => $entry->id])->assertForbidden();
    }

    public function test_upload_respects_the_plan_feature(): void
    {
        $site = $this->site('owner');
        $dpr = $this->dpr($site->project, $site->user);
        config(['buildos.plans.business.features' => ['measurement']]);

        $this->upload(['type' => 'dpr_photo', 'parent_id' => $dpr->id])->assertForbidden();
    }

    public function test_another_companys_parent_is_a_404(): void
    {
        $site = $this->site();
        $dpr = $this->dpr($site->project, $site->user);
        $entry = $this->entry($site->project, $site->user);
        $this->signInElsewhere();

        $this->upload(['type' => 'dpr_photo', 'parent_client_uuid' => $dpr->client_uuid])->assertNotFound();
        $this->upload(['type' => 'petty_bill', 'parent_id' => $entry->id])->assertNotFound();

        $this->assertSame(0, DprPhoto::withoutGlobalScopes()->count());
    }
}
