<?php

namespace Tests\Feature\Api;

use App\Models\Attendance;
use App\Models\PettyCashWallet;
use App\Models\Site;
use App\Models\Worker;
use App\Services\ApprovalService;
use App\Services\DprService;
use App\Services\IssueService;
use App\Services\MeasurementService;
use App\Services\PettyCashService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

class SyncPullTest extends ApiTestCase
{
    private function pull(?string $since = null): TestResponse
    {
        return $this->getJson(self::API.'/sync/pull'.($since === null ? '' : '?since='.urlencode($since)))->assertOk();
    }

    /** One of everything a device syncs, in a company. */
    private function populate(object $site): object
    {
        $engineer = $site->user;
        $organization = $site->organization;

        $worker = Worker::create(['organization_id' => $organization->id, 'name' => 'Dattu', 'trade' => 'Mason', 'daily_wage' => 950]);
        $attendance = Attendance::create(['organization_id' => $organization->id, 'project_id' => $site->project->id, 'worker_id' => $worker->id, 'attendance_date' => '2026-11-08', 'status' => 'present']);
        $wallet = PettyCashWallet::create(['organization_id' => $organization->id, 'project_id' => $site->project->id, 'name' => 'Imprest', 'user_id' => $engineer->id]);
        $entry = app(PettyCashService::class)->addEntry($wallet, ['type' => 'topup', 'amount' => '1000', 'entry_date' => '2026-11-01'], $engineer);
        $dpr = app(DprService::class)->create($site->project, $this->dprData(), $engineer);
        $issue = app(IssueService::class)->create($site->project, $this->issueData(), $engineer);
        $measurement = $this->draftEntry($site->project, $site->item, $engineer);
        $siteRow = Site::create(['organization_id' => $organization->id, 'project_id' => $site->project->id, 'name' => 'Tower B']);

        return (object) [...compact('worker', 'attendance', 'wallet', 'entry', 'dpr', 'issue', 'measurement'), 'site' => $siteRow];
    }

    public function test_every_top_level_key_is_present(): void
    {
        $this->site();

        $body = $this->pull()->json();

        $this->assertSame([
            'server_time', 'user', 'meta', 'users', 'projects', 'sites', 'boq_items', 'boq_balances', 'workers', 'wallets',
            'dprs', 'issues', 'attendance', 'petty_cash_entries', 'measurements', 'approvals', 'deleted',
        ], array_keys($body));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+05:30$/', $body['server_time']);
        $this->assertSame(['projects', 'boq_items', 'workers', 'issues', 'dprs', 'measurements'], array_keys($body['deleted']));
        $this->assertSame('5', $body['meta']['measurement_tolerance_pct']);
        $this->assertSame(['Clear', 'Cloudy', 'Rain', 'Windy', 'Hot'], $body['meta']['weather']);
    }

    public function test_row_shapes_match_the_contract(): void
    {
        $site = $this->site();
        $data = $this->populate($site);

        $body = $this->pull()->json();

        $this->assertSame(['id', 'name', 'role'], array_keys($body['users'][0]));
        $this->assertSame(['id', 'code', 'name', 'location', 'status', 'start_date', 'end_date', 'measurement_tolerance_pct', 'image_url', 'updated_at'], array_keys($body['projects'][0]));
        $this->assertSame(['id', 'project_id', 'name', 'address', 'updated_at'], array_keys($body['sites'][0]));
        $this->assertSame(['id', 'project_id', 'section', 'code', 'description', 'unit', 'qty', 'rate', 'sort', 'measured_qty', 'approved_qty', 'balance_qty', 'updated_at'], array_keys($body['boq_items'][0]));
        $this->assertSame(['id', 'name', 'phone', 'trade', 'type', 'is_active', 'updated_at'], array_keys($body['workers'][0]));
        $this->assertSame(['id', 'project_id', 'name', 'user_id', 'balance', 'updated_at'], array_keys($body['wallets'][0]));
        $this->assertSame(['id', 'client_uuid', 'project_id', 'site_id', 'report_date', 'weather', 'summary', 'hindrances', 'status', 'created_by', 'created_by_name', 'manpower', 'machinery', 'work_items', 'photos', 'return_remark', 'client_updated_at', 'updated_at'], array_keys($body['dprs'][0]));
        $this->assertSame(['id', 'client_uuid', 'project_id', 'site_id', 'title', 'description', 'priority', 'status', 'location_note', 'assigned_to', 'raised_by', 'due_date', 'resolved_at', 'client_updated_at', 'updated_at'], array_keys($body['issues'][0]));
        $this->assertSame(['id', 'client_uuid', 'project_id', 'worker_id', 'attendance_date', 'status', 'ot_hours', 'marked_by', 'client_updated_at', 'updated_at'], array_keys($body['attendance'][0]));
        $this->assertSame(['id', 'client_uuid', 'petty_cash_wallet_id', 'type', 'amount', 'category', 'description', 'entry_date', 'created_by', 'has_bill', 'updated_at'], array_keys($body['petty_cash_entries'][0]));
        $this->assertSame(['id', 'client_uuid', 'project_id', 'boq_item_id', 'work_order_id', 'measured_on', 'description', 'location', 'nos', 'length', 'breadth', 'depth', 'is_deduction', 'qty', 'status', 'deviation', 'remarks', 'return_remark', 'created_by', 'client_updated_at', 'updated_at'], array_keys($body['measurements'][0]));

        $this->assertSame('1000.00', $body['wallets'][0]['balance']);
        $this->assertSame('1000.00', $body['petty_cash_entries'][0]['amount']);
        $this->assertSame('50.000', $body['measurements'][0]['qty']);
        $this->assertSame('50.000', $body['boq_items'][0]['balance_qty']);
        $this->assertSame('0.0', $body['attendance'][0]['ot_hours']);
        $this->assertSame($data->dpr->id, $body['dprs'][0]['id']);
    }

    public function test_rest_and_pull_emit_identical_shapes(): void
    {
        $site = $this->site();
        $data = $this->populate($site);
        $body = $this->pull()->json();
        $base = self::API."/projects/{$site->project->id}";

        $this->assertSame($body['dprs'][0], $this->getJson($base.'/dprs')->json('data.0'));
        $this->assertSame($body['issues'][0], $this->getJson($base.'/issues')->json('data.0'));
        $this->assertSame($body['measurements'][0], $this->getJson($base.'/measurements')->json('data.0'));
        // The BOQ list adds each line's tender marker, which a delta pull cannot carry: a new quote changes it without touching the line.
        $boq = $this->getJson($base.'/boq')->json('data.0');
        $this->assertArrayHasKey('tenders', $boq);
        unset($boq['tenders']);
        $this->assertSame($body['boq_items'][0], $boq);
        $this->assertSame($body['workers'][0], $this->getJson(self::API.'/workers')->json('data.0'));
        $this->assertSame($body['attendance'][0], $this->getJson($base.'/attendance?date=2026-11-08')->json('data.0'));
        $this->assertSame($body['projects'][0], $this->getJson(self::API.'/projects')->json('data.0'));
    }

    public function test_a_store_role_gets_empty_lists_for_what_it_cannot_view(): void
    {
        $site = $this->site();
        $data = $this->populate($site);
        $this->signIn($this->member($site->organization, 'store'));

        $body = $this->pull()->json();

        $this->assertSame([], $body['wallets']);
        $this->assertSame([], $body['petty_cash_entries']);
        $this->assertSame([], $body['workers']);
        $this->assertSame([], $body['measurements']);
        $this->assertCount(1, $body['dprs']);
        $this->assertCount(1, $body['issues']);
        $this->assertCount(1, $body['attendance']);
        $this->assertCount(1, $body['projects']);
    }

    public function test_tombstones_are_gated_like_the_lists(): void
    {
        $site = $this->site();
        $data = $this->populate($site);
        $since = now()->subMinute()->toIso8601String();
        $data->worker->delete();
        $this->signIn($this->member($site->organization, 'store'));

        $this->assertSame([], $this->pull($since)->json('deleted.workers'));
    }

    public function test_plan_features_empty_the_lists_too(): void
    {
        $site = $this->site('owner');
        $this->populate($site);
        config(['buildos.plans.business.features' => ['measurement']]);

        $body = $this->pull()->json();

        $this->assertSame([], $body['dprs']);
        $this->assertSame([], $body['issues']);
        $this->assertSame([], $body['workers']);
        $this->assertSame([], $body['attendance']);
        $this->assertSame([], $body['wallets']);
        $this->assertCount(1, $body['measurements']);
    }

    public function test_another_companys_rows_never_appear(): void
    {
        $theirs = $this->site('owner');
        $theirData = $this->populate($theirs);

        $mine = (object) ['organization' => $this->tenant('Mine Co')];
        $mine->user = $this->signIn($this->member($mine->organization, 'owner'));
        $mine->project = $this->makeProject($mine->organization);
        $mine->item = $this->makeBoqItem($mine->project);
        $this->populate($mine);

        $body = $this->pull()->json();

        foreach (['projects', 'sites', 'boq_items', 'workers', 'wallets', 'dprs', 'issues', 'attendance', 'petty_cash_entries', 'measurements'] as $key) {
            $this->assertCount(1, $body[$key], $key);
        }
        $this->assertSame([$mine->user->id], array_column($body['users'], 'id'));
        $this->assertSame($mine->project->id, $body['projects'][0]['id']);
        $this->assertNotSame($theirData->dpr->id, $body['dprs'][0]['id']);
    }

    public function test_another_companys_tombstones_never_appear(): void
    {
        $theirs = $this->site('owner');
        $theirData = $this->populate($theirs);
        $theirData->issue->delete();
        $this->signInElsewhere();

        $this->assertSame([], $this->pull(now()->subHour()->toIso8601String())->json('deleted.issues'));
    }

    public function test_deleted_lists_soft_deleted_rows_since_the_last_pull(): void
    {
        $site = $this->site();
        $data = $this->populate($site);
        $since = now()->toIso8601String();
        $this->travel(1)->minutes();

        $data->worker->delete();
        $data->issue->delete();
        $data->dpr->delete();
        $site->item->delete();
        $project = $this->makeProject($site->organization);
        $project->delete();

        $deleted = $this->pull($since)->json('deleted');

        $this->assertSame([$data->worker->id], $deleted['workers']);
        $this->assertSame([$data->issue->id], $deleted['issues']);
        $this->assertSame([$data->dpr->id], $deleted['dprs']);
        $this->assertSame([$site->item->id], $deleted['boq_items']);
        $this->assertSame([$project->id], $deleted['projects']);
        $this->assertSame([], $this->pull()->json('deleted.workers'));
    }

    public function test_a_tombstone_older_than_since_is_not_repeated(): void
    {
        $site = $this->site();
        $data = $this->populate($site);
        $data->worker->delete();
        $this->travel(1)->minutes();

        $this->assertSame([], $this->pull(now()->toIso8601String())->json('deleted.workers'));
    }

    public function test_boq_balances_delta_after_a_measurement_without_touching_boq_items(): void
    {
        $this->travelTo('2026-11-09 10:00:00');
        $site = $this->site();
        $quiet = $this->makeBoqItem($site->project);
        $since = now()->addMinute()->toIso8601String();
        $this->travelTo('2026-11-09 11:00:00');

        $this->draftEntry($site->project, $site->item, $site->user);

        $body = $this->pull($since)->json();

        $this->assertSame([], $body['boq_items']);
        $this->assertSame([['id' => $site->item->id, 'measured_qty' => '50.000', 'approved_qty' => '0.000', 'balance_qty' => '50.000']], $body['boq_balances']);
        $this->assertNotContains($quiet->id, array_column($body['boq_balances'], 'id'));
    }

    public function test_a_first_pull_has_no_balances_because_boq_items_carries_them(): void
    {
        $site = $this->site();
        $this->draftEntry($site->project, $site->item, $site->user);

        $body = $this->pull()->json();

        $this->assertSame([], $body['boq_balances']);
        $this->assertSame('50.000', $body['boq_items'][0]['measured_qty']);
    }

    public function test_a_deleted_draft_moves_the_balance_and_gets_a_tombstone(): void
    {
        $site = $this->site();
        $entry = $this->draftEntry($site->project, $site->item, $site->user);
        $id = $entry->id;
        $since = now()->toIso8601String();
        $this->travel(1)->minutes();

        $entry->delete();

        $body = $this->pull($since)->json();

        $this->assertSame([$id], $body['deleted']['measurements']);
        $this->assertSame('0.000', $body['boq_balances'][0]['measured_qty']);
        $this->assertSame('100.000', $body['boq_balances'][0]['balance_qty']);
    }

    public function test_since_boundary_is_inclusive_for_ist_and_utc_offsets(): void
    {
        $site = $this->site();
        $this->travelTo('2026-11-09 12:00:00');
        $this->draftEntry($site->project, $site->item, $site->user);

        foreach (['2026-11-09T12:00:00+05:30', '2026-11-09T06:30:00+00:00', '2026-11-09T06:30:00Z', '2026-11-09T01:30:00-05:00'] as $since) {
            $this->pull($since)->assertJsonCount(1, 'measurements');
        }

        foreach (['2026-11-09T12:00:01+05:30', '2026-11-09T06:30:01+00:00', '2026-11-09T06:30:01Z'] as $since) {
            $this->pull($since)->assertJsonCount(0, 'measurements');
        }
    }

    public function test_masters_tombstones_and_balances_share_the_inclusive_boundary(): void
    {
        $this->travelTo('2026-11-09 12:00:00');
        $site = $this->site();
        $this->draftEntry($site->project, $site->item, $site->user);
        $gone = $this->makeBoqItem($site->project);
        $gone->delete();
        $worker = Worker::create(['organization_id' => $site->organization->id, 'name' => 'Dattu', 'trade' => 'Mason', 'daily_wage' => 950]);

        $atBoundary = $this->pull('2026-11-09T06:30:00Z')->json();

        $this->assertCount(1, $atBoundary['projects']);
        $this->assertCount(1, $atBoundary['boq_items']);
        $this->assertSame([$worker->id], array_column($atBoundary['workers'], 'id'));
        $this->assertSame([$site->item->id], array_column($atBoundary['boq_balances'], 'id'));
        $this->assertSame([$gone->id], $atBoundary['deleted']['boq_items']);

        $afterBoundary = $this->pull('2026-11-09T06:30:01Z')->json();

        $this->assertSame([], $afterBoundary['projects']);
        $this->assertSame([], $afterBoundary['boq_items']);
        $this->assertSame([], $afterBoundary['workers']);
        $this->assertSame([], $afterBoundary['boq_balances']);
        $this->assertSame([], $afterBoundary['deleted']['boq_items']);
    }

    public function test_an_unencoded_plus_in_since_is_understood(): void
    {
        $site = $this->site();
        $this->travelTo('2026-11-09 12:00:00');
        $this->draftEntry($site->project, $site->item, $site->user);

        $this->getJson(self::API.'/sync/pull?since=2026-11-09T12:00:01+05:30')->assertOk()->assertJsonCount(0, 'measurements');
        $this->getJson(self::API.'/sync/pull?since=2026-11-09T12:00:00+05:30')->assertOk()->assertJsonCount(1, 'measurements');
    }

    public function test_an_invalid_since_is_a_422(): void
    {
        $this->site();

        $this->getJson(self::API.'/sync/pull?since=not-a-date')->assertUnprocessable()->assertJsonValidationErrors('since');
    }

    public function test_server_time_of_one_pull_finds_rows_written_in_that_second(): void
    {
        $site = $this->site();
        $this->travelTo('2026-11-09 12:00:00');

        $serverTime = $this->pull()->json('server_time');
        $this->draftEntry($site->project, $site->item, $site->user);

        $this->assertSame('2026-11-09T12:00:00+05:30', $serverTime);
        $this->pull($serverTime)->assertJsonCount(1, 'measurements');
    }

    public function test_a_first_pull_covers_the_window_plus_anything_still_open(): void
    {
        $site = $this->site();
        $owner = $this->member($site->organization, 'owner');
        $this->travelTo('2026-08-01 12:00:00');

        $oldOpenIssue = app(IssueService::class)->create($site->project, $this->issueData(), $site->user);
        $oldClosedIssue = app(IssueService::class)->create($site->project, $this->issueData(), $site->user);
        app(IssueService::class)->update($oldClosedIssue, ['status' => 'closed']);
        $oldDraft = $this->draftEntry($site->project, $site->item, $site->user);
        $oldApproved = $this->approveEntry($this->draftEntry($site->project, $site->item, $site->user), $site->user, $owner);
        $oldDpr = app(DprService::class)->create($site->project, $this->dprData(), $site->user);

        $this->travelTo('2026-11-09 12:00:00');
        $recentIssue = app(IssueService::class)->create($site->project, $this->issueData(), $site->user);

        $body = $this->pull()->json();
        $issues = array_column($body['issues'], 'id');
        $entries = array_column($body['measurements'], 'id');

        $this->assertContains($oldOpenIssue->id, $issues);
        $this->assertContains($recentIssue->id, $issues);
        $this->assertNotContains($oldClosedIssue->id, $issues);
        $this->assertContains($oldDraft->id, $entries);
        $this->assertNotContains($oldApproved->id, $entries);
        $this->assertContains($oldDpr->id, array_column($body['dprs'], 'id'));
    }

    public function test_the_window_length_comes_from_config(): void
    {
        $site = $this->site();
        $this->travelTo('2026-11-01 12:00:00');
        Attendance::create([
            'organization_id' => $site->organization->id, 'project_id' => $site->project->id,
            'worker_id' => Worker::create(['organization_id' => $site->organization->id, 'name' => 'Dattu', 'trade' => 'Mason', 'daily_wage' => 950])->id,
            'attendance_date' => '2026-11-01', 'status' => 'present',
        ]);
        $this->travelTo('2026-11-09 12:00:00');

        $this->pull()->assertJsonCount(1, 'attendance');

        config(['buildos.sync_window_days' => 7]);
        $this->pull()->assertJsonCount(0, 'attendance');
    }

    public function test_wallets_are_always_the_full_list(): void
    {
        $site = $this->site();
        $this->populate($site);
        $this->travel(1)->hours();

        $this->pull(now()->toIso8601String())->assertJsonCount(1, 'wallets')->assertJsonCount(0, 'petty_cash_entries');
    }

    public function test_masters_are_deltas_after_the_first_pull(): void
    {
        $site = $this->site();
        $this->travel(1)->hours();
        $since = now()->toIso8601String();
        $this->travel(1)->hours();
        $site->project->update(['name' => 'Renamed']);

        $this->pull($since)->assertJsonCount(1, 'projects')->assertJsonPath('projects.0.name', 'Renamed')->assertJsonCount(0, 'boq_items');
    }

    public function test_return_remark_comes_from_the_current_approval_only(): void
    {
        $site = $this->site();
        $pm = $this->member($site->organization, 'pm');
        $dpr = app(DprService::class)->create($site->project, $this->dprData(), $site->user);
        app(ApprovalService::class)->act(app(DprService::class)->submit($dpr, $site->user), $pm, 'return', 'Add crane hours');

        $this->pull()->assertJsonPath('dprs.0.return_remark', 'Add crane hours');

        app(DprService::class)->submit($dpr->fresh(), $site->user);

        $this->pull()->assertJsonPath('dprs.0.return_remark', null);
    }

    public function test_approvals_are_the_inbox_in_the_rest_shape(): void
    {
        $site = $this->site();
        $qs = $this->member($site->organization, 'qs');
        app(MeasurementService::class)->submit($this->draftEntry($site->project, $site->item, $site->user), $site->user);

        $this->pull()->assertJsonCount(0, 'approvals');

        $this->signIn($qs);
        $body = $this->pull()->json();

        $this->assertCount(1, $body['approvals']);
        $this->assertSame($body['approvals'], $this->getJson(self::API.'/approvals')->json('data'));
    }

    public function test_a_new_photo_brings_its_dpr_into_the_next_pull(): void
    {
        Storage::fake('local');
        $site = $this->site();
        $dpr = app(DprService::class)->create($site->project, $this->dprData(), $site->user);
        $this->travel(1)->hours();
        $since = now()->toIso8601String();

        app(DprService::class)->addPhoto($dpr, UploadedFile::fake()->image('a.jpg'));

        $this->pull($since)->assertJsonCount(1, 'dprs')->assertJsonCount(1, 'dprs.0.photos');
    }

    public function test_pull_needs_a_token(): void
    {
        $this->getJson(self::API.'/sync/pull')->assertUnauthorized();
    }
}
