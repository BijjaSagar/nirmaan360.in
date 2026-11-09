<?php

namespace Tests\Feature\Access;

use App\Models\Approval;
use App\Models\ApprovalStep;
use App\Models\Attendance;
use App\Models\BoqItem;
use App\Models\Dpr;
use App\Models\DprPhoto;
use App\Models\Issue;
use App\Models\MeasurementEntry;
use App\Models\Organization;
use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Services\Access\TeamAccessService;
use App\Services\ApprovalService;
use App\Services\MeasurementService;
use App\Services\ProjectService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Api\ApiTestCase;

/**
 * A repeat carrying the client_uuid of a record on a project the person cannot reach
 * must answer as not found, show nothing of that record and write nothing.
 */
class ClientUuidRepeatTest extends ApiTestCase
{
    private Organization $organization;

    private User $owner;

    private User $limited;

    private Project $mine;

    private Project $hidden;

    private BoqItem $mineItem;

    private Worker $worker;

    /** @var array<string, string> Kind of record => the client_uuid it holds in the hidden project. */
    private array $uuids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake();

        $this->organization = $this->tenant();
        $this->owner = $this->member($this->organization, 'owner');
        $this->actingAs($this->owner);

        $this->mine = $this->makeProject($this->organization);
        $this->mineItem = $this->makeBoqItem($this->mine);
        $this->worker = Worker::create(['organization_id' => $this->organization->id, 'name' => 'Mason', 'trade' => 'Mason']);

        $this->hidden = $this->makeProject($this->organization, ['client_uuid' => $this->uuid('project')]);
        $this->fillHiddenProject();

        $this->limited = $this->member($this->organization, 'pm');
        app(TeamAccessService::class)->applyProjects($this->limited, true, [$this->mine->id]);
        $this->limited->refresh();

        $this->signOut();
        $this->signIn($this->limited);
    }

    private function uuid(string $kind): string
    {
        return $this->uuids[$kind] ??= (string) Str::uuid();
    }

    private function signOut(): void
    {
        Auth::forgetUser();
        $this->app['auth']->forgetGuards();
    }

    private function fillHiddenProject(): void
    {
        $base = ['organization_id' => $this->organization->id];
        $inProject = [...$base, 'project_id' => $this->hidden->id];
        $today = now()->toDateString();

        $item = $this->makeBoqItem($this->hidden);
        $item->update(['client_uuid' => $this->uuid('boq')]);

        $this->draftEntry($this->hidden, $item, $this->owner, ['client_uuid' => $this->uuid('measurement')]);

        $dpr = Dpr::create([...$inProject, 'report_date' => $today, 'summary' => 'Secret pour', 'status' => 'DRAFT', 'client_uuid' => $this->uuid('dpr')]);
        DprPhoto::create([...$base, 'dpr_id' => $dpr->id, 'path' => 'x.jpg', 'client_uuid' => $this->uuid('photo')]);
        Issue::create([...$inProject, 'title' => 'Secret crack', 'priority' => 'high', 'status' => 'open', 'client_uuid' => $this->uuid('issue')]);
        Attendance::create([...$inProject, 'worker_id' => $this->worker->id, 'attendance_date' => $today, 'status' => 'present', 'client_uuid' => $this->uuid('attendance')]);

        $wallet = PettyCashWallet::create([...$inProject, 'name' => 'Secret wallet', 'client_uuid' => $this->uuid('wallet')]);
        PettyCashEntry::create([...$base, 'petty_cash_wallet_id' => $wallet->id, 'type' => 'topup', 'amount' => '500.00', 'entry_date' => $today, 'client_uuid' => $this->uuid('entry')]);

        $entry = $this->draftEntry($this->hidden, $item, $this->owner);
        $approval = app(MeasurementService::class)->submit($entry, $this->owner);
        app(ApprovalService::class)->act($approval, $this->owner, 'approve', null, $this->uuid('step'));
    }

    /** @param class-string $model */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScopes()->where('organization_id', $this->organization->id)->count();
    }

    private function assertNotFoundAnswer(TestResponse $response): void
    {
        $response->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
        $this->assertArrayNotHasKey('data', $response->json());
    }

    /** One sync item, refused on its own line with nothing about the hidden record. */
    private function assertPushRefused(array $item): void
    {
        $result = $this->push($item)->json('results.0');

        $this->assertSame('error', $result['status']);
        $this->assertNull($result['id']);
        $this->assertNull($result['server_status']);
        $this->assertArrayHasKey('client_uuid', $result['errors']);
    }

    public function test_dpr_create(): void
    {
        $before = $this->rows(Dpr::class);

        $response = $this->postJson(self::API."/projects/{$this->mine->id}/dprs", $this->dprData(['client_uuid' => $this->uuid('dpr')]));

        $this->assertNotFoundAnswer($response);
        $response->assertDontSee('Secret pour');
        $this->assertSame($before, $this->rows(Dpr::class));
    }

    public function test_dpr_web_form(): void
    {
        $before = $this->rows(Dpr::class);
        $this->actingAs($this->limited);

        $this->post("/projects/{$this->mine->id}/dprs", [...$this->dprData(), 'client_uuid' => $this->uuid('dpr')])
            ->assertSessionHasErrors('client_uuid');

        $this->assertSame($before, $this->rows(Dpr::class));
    }

    public function test_dpr_photo(): void
    {
        $dpr = Dpr::create(['organization_id' => $this->organization->id, 'project_id' => $this->mine->id, 'report_date' => now()->toDateString(), 'status' => 'DRAFT']);
        $before = $this->rows(DprPhoto::class);

        $response = $this->post(self::API."/dprs/{$dpr->id}/photos", [
            'photo' => UploadedFile::fake()->image('a.jpg'),
            'client_uuid' => $this->uuid('photo'),
        ], ['Accept' => 'application/json']);

        $this->assertNotFoundAnswer($response);
        $this->assertSame($before, $this->rows(DprPhoto::class));
        $this->assertSame([], Storage::allFiles());
    }

    public function test_issue_create(): void
    {
        $before = $this->rows(Issue::class);

        $response = $this->postJson(self::API."/projects/{$this->mine->id}/issues", $this->issueData(['client_uuid' => $this->uuid('issue')]));

        $this->assertNotFoundAnswer($response);
        $response->assertDontSee('Secret crack');
        $this->assertSame($before, $this->rows(Issue::class));
    }

    public function test_measurement_create(): void
    {
        $before = $this->rows(MeasurementEntry::class);

        $response = $this->postJson(self::API."/projects/{$this->mine->id}/measurements", $this->measurementData($this->mineItem, ['client_uuid' => $this->uuid('measurement')]));

        $this->assertNotFoundAnswer($response);
        $this->assertSame($before, $this->rows(MeasurementEntry::class));
    }

    public function test_measurement_web_form(): void
    {
        $before = $this->rows(MeasurementEntry::class);
        $this->actingAs($this->limited);

        $this->post("/projects/{$this->mine->id}/measurements", $this->measurementData($this->mineItem, ['client_uuid' => $this->uuid('measurement')]))
            ->assertSessionHasErrors('client_uuid');

        $this->assertSame($before, $this->rows(MeasurementEntry::class));
    }

    public function test_attendance_new_mark(): void
    {
        $before = $this->rows(Attendance::class);

        $response = $this->postJson(self::API."/projects/{$this->mine->id}/attendance", [
            'date' => now()->toDateString(),
            'marks' => [['worker_id' => $this->worker->id, 'status' => 'present', 'client_uuid' => $this->uuid('attendance')]],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
        $this->assertSame($before, $this->rows(Attendance::class));
    }

    public function test_attendance_cannot_take_the_key_onto_an_existing_mark(): void
    {
        $mark = Attendance::create(['organization_id' => $this->organization->id, 'project_id' => $this->mine->id, 'worker_id' => $this->worker->id, 'attendance_date' => now()->toDateString(), 'status' => 'absent']);

        $this->postJson(self::API."/projects/{$this->mine->id}/attendance", [
            'date' => now()->toDateString(),
            'marks' => [['worker_id' => $this->worker->id, 'status' => 'present', 'client_uuid' => $this->uuid('attendance')]],
        ])->assertUnprocessable()->assertJsonValidationErrors('client_uuid');

        $this->assertNull($mark->refresh()->client_uuid);
        $this->assertSame('absent', $mark->status);
    }

    public function test_petty_cash_wallet(): void
    {
        $before = $this->rows(PettyCashWallet::class);

        $response = $this->postJson(self::API."/projects/{$this->mine->id}/wallets", ['name' => 'Site cash', 'client_uuid' => $this->uuid('wallet')]);

        $this->assertNotFoundAnswer($response);
        $response->assertDontSee('Secret wallet');
        $this->assertSame($before, $this->rows(PettyCashWallet::class));
    }

    public function test_petty_cash_entry(): void
    {
        $wallet = PettyCashWallet::create(['organization_id' => $this->organization->id, 'project_id' => $this->mine->id, 'name' => 'Mine']);
        $before = $this->rows(PettyCashEntry::class);

        $response = $this->postJson(self::API."/petty-wallets/{$wallet->id}/entries", [
            'type' => 'topup', 'amount' => 100, 'entry_date' => now()->toDateString(), 'client_uuid' => $this->uuid('entry'),
        ]);

        $this->assertNotFoundAnswer($response);
        $this->assertSame($before, $this->rows(PettyCashEntry::class));
    }

    public function test_project_through_the_api(): void
    {
        $before = $this->rows(Project::class);

        $response = $this->postJson(self::API.'/projects', ['name' => 'Copy', 'client_uuid' => $this->uuid('project')]);

        $this->assertNotFoundAnswer($response);
        $response->assertDontSee($this->hidden->name);
        $this->assertSame($before, $this->rows(Project::class));
    }

    public function test_project_service_directly(): void
    {
        $before = $this->rows(Project::class);

        try {
            app(ProjectService::class)->create(['name' => 'Copy', 'client_uuid' => $this->uuid('project')], $this->limited);
            $this->fail('The repeat should have been refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('client_uuid', $exception->errors());
        }

        $this->assertSame($before, $this->rows(Project::class));
    }

    public function test_boq_item(): void
    {
        $before = $this->rows(BoqItem::class);

        $response = $this->postJson(self::API."/projects/{$this->mine->id}/boq-items", [
            'description' => 'Copy', 'unit' => 'cum', 'qty' => 1, 'rate' => 1, 'client_uuid' => $this->uuid('boq'),
        ]);

        $this->assertNotFoundAnswer($response);
        $this->assertSame($before, $this->rows(BoqItem::class));
    }

    public function test_approval_act(): void
    {
        $qs = $this->member($this->organization, 'qs');
        app(TeamAccessService::class)->applyProjects($qs, true, [$this->mine->id]);
        $this->signOut();
        $this->actingAs($this->owner);
        $entry = $this->draftEntry($this->mine, $this->mineItem, $this->owner);
        $approval = app(MeasurementService::class)->submit($entry, $this->owner);
        $this->signOut();
        $this->signIn($qs->refresh());
        $before = $this->rows(ApprovalStep::class);

        $this->postJson(self::API."/approvals/{$approval->id}/act", ['action' => 'approve', 'client_uuid' => $this->uuid('step')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_uuid');

        $this->assertSame($before, $this->rows(ApprovalStep::class));
        $this->assertSame(1, Approval::withoutGlobalScopes()->findOrFail($approval->id)->current_level);
    }

    public function test_sync_push_measurement(): void
    {
        $before = $this->rows(MeasurementEntry::class);

        $this->assertPushRefused($this->item('measurement', $this->mine, $this->measurementData($this->mineItem), ['client_uuid' => $this->uuid('measurement')]));

        $this->assertSame($before, $this->rows(MeasurementEntry::class));
    }

    public function test_sync_push_dpr(): void
    {
        $before = $this->rows(Dpr::class);

        $this->assertPushRefused($this->item('dpr', $this->mine, $this->dprData(), ['client_uuid' => $this->uuid('dpr')]));

        $this->assertSame($before, $this->rows(Dpr::class));
    }

    public function test_sync_push_issue(): void
    {
        $before = $this->rows(Issue::class);

        $this->assertPushRefused($this->item('issue', $this->mine, $this->issueData(), ['client_uuid' => $this->uuid('issue')]));

        $this->assertSame($before, $this->rows(Issue::class));
    }

    public function test_sync_push_attendance(): void
    {
        $before = $this->rows(Attendance::class);

        $this->assertPushRefused($this->item('attendance', $this->mine, [
            'worker_id' => $this->worker->id, 'status' => 'present', 'attendance_date' => now()->toDateString(),
        ], ['client_uuid' => $this->uuid('attendance')]));

        $this->assertSame($before, $this->rows(Attendance::class));
    }

    public function test_sync_push_petty_cash(): void
    {
        $wallet = PettyCashWallet::create(['organization_id' => $this->organization->id, 'project_id' => $this->mine->id, 'name' => 'Mine']);
        $before = $this->rows(PettyCashEntry::class);

        $this->assertPushRefused($this->item('petty_cash', $this->mine, [
            'wallet_id' => $wallet->id, 'type' => 'topup', 'amount' => 100, 'entry_date' => now()->toDateString(),
        ], ['client_uuid' => $this->uuid('entry')]));

        $this->assertSame($before, $this->rows(PettyCashEntry::class));
    }

    public function test_sync_upload_photo(): void
    {
        $dpr = Dpr::create(['organization_id' => $this->organization->id, 'project_id' => $this->mine->id, 'report_date' => now()->toDateString(), 'status' => 'DRAFT']);
        $before = $this->rows(DprPhoto::class);

        $response = $this->post(self::API.'/sync/upload', [
            'type' => 'dpr_photo',
            'client_uuid' => $this->uuid('photo'),
            'parent_id' => $dpr->id,
            'file' => UploadedFile::fake()->image('a.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
        $this->assertSame($before, $this->rows(DprPhoto::class));
    }

    public function test_a_limited_persons_repeat_inside_their_own_project_still_returns_the_original(): void
    {
        $uuid = (string) Str::uuid();
        $url = self::API."/projects/{$this->mine->id}/dprs";

        $first = $this->postJson($url, $this->dprData(['client_uuid' => $uuid]))->assertCreated()->json('data.id');
        $second = $this->postJson($url, $this->dprData(['client_uuid' => $uuid]))->assertOk()->json('data.id');

        $this->assertSame($first, $second);
    }

    public function test_a_trashed_record_in_a_reachable_project_still_counts_as_a_repeat(): void
    {
        $uuid = (string) Str::uuid();
        $url = self::API."/projects/{$this->mine->id}/issues";
        $id = $this->postJson($url, $this->issueData(['client_uuid' => $uuid]))->assertCreated()->json('data.id');

        Issue::findOrFail($id)->delete();

        $this->assertSame($id, $this->postJson($url, $this->issueData(['client_uuid' => $uuid]))->assertOk()->json('data.id'));
    }

    public function test_someone_with_no_limit_keeps_the_existing_repeat_behaviour(): void
    {
        $this->signOut();
        $this->signIn($this->member($this->organization, 'pm'));

        $hiddenIssue = Issue::withoutGlobalScopes()->where('client_uuid', $this->uuid('issue'))->value('id');

        $this->postJson(self::API."/projects/{$this->mine->id}/issues", $this->issueData(['client_uuid' => $this->uuid('issue')]))
            ->assertOk()
            ->assertJsonPath('data.id', $hiddenIssue);
    }
}
