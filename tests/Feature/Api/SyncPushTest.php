<?php

namespace Tests\Feature\Api;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Dpr;
use App\Models\Issue;
use App\Models\MeasurementEntry;
use App\Models\Organization;
use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Services\ApprovalService;
use App\Services\DprService;
use App\Services\IssueService;
use App\Services\MeasurementService;
use App\Services\PettyCashService;
use App\Support\Idempotent;
use Illuminate\Support\Str;

class SyncPushTest extends ApiTestCase
{
    private const T1 = '2026-11-09T09:00:00+05:30';

    private const T2 = '2026-11-09T10:00:00+05:30';

    private const T3 = '2026-11-09T11:00:00+05:30';

    private function worker(Organization $organization): Worker
    {
        return Worker::create(['organization_id' => $organization->id, 'name' => 'Dattu', 'trade' => 'Mason', 'daily_wage' => 950]);
    }

    private function wallet(Project $project, User $holder, string $topUp = '1000'): PettyCashWallet
    {
        $wallet = PettyCashWallet::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'name' => 'Imprest', 'user_id' => $holder->id]);
        app(PettyCashService::class)->addEntry($wallet, ['type' => 'topup', 'amount' => $topUp, 'entry_date' => '2026-11-01'], $holder);

        return $wallet;
    }

    private function attendanceData(Worker $worker, array $overrides = []): array
    {
        return ['worker_id' => $worker->id, 'attendance_date' => '2026-11-08', 'status' => 'present', 'ot_hours' => 0, ...$overrides];
    }

    private function audits(string $action): int
    {
        return AuditLog::where('action', $action)->count();
    }

    // Results

    public function test_every_result_has_the_full_shape_in_request_order(): void
    {
        $site = $this->site();
        $first = $this->item('measurement', $site->project, $this->measurementData($site->item));
        $second = $this->item('issue', $site->project, $this->issueData());

        $results = $this->push($first, $second)->json('results');

        $this->assertSame(['client_uuid', 'status', 'id', 'server_status', 'reason', 'errors'], array_keys($results[0]));
        $this->assertSame($first['client_uuid'], $results[0]['client_uuid']);
        $this->assertSame($second['client_uuid'], $results[1]['client_uuid']);
        $this->assertSame('DRAFT', $results[0]['server_status']);
        $this->assertSame('open', $results[1]['server_status']);
        $this->assertNull($results[0]['errors']);
    }

    public function test_the_batch_is_capped_at_200_items(): void
    {
        $site = $this->site();
        $item = $this->item('issue', $site->project, $this->issueData());

        $this->postJson(self::API.'/sync/push', ['items' => array_fill(0, 201, $item)])->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_one_bad_item_does_not_stop_the_batch(): void
    {
        $site = $this->site();

        $results = $this->push(
            $this->item('measurement', $site->project, $this->measurementData($site->item, ['nos' => 'abc'])),
            $this->item('no_such_type', $site->project, ['x' => 1]),
            ['type' => 'issue', 'project_id' => $site->project->id, 'data' => $this->issueData()],
            $this->item('issue', $site->project, $this->issueData(), ['client_uuid' => 'not-a-uuid']),
            $this->item('issue', $site->project, $this->issueData(), ['project_id' => 999999]),
            $this->item('measurement', $site->project, $this->measurementData($site->item)),
        )->json('results');

        $this->assertSame(['error', 'error', 'error', 'error', 'error', 'created'], array_column($results, 'status'));
        $this->assertArrayHasKey('nos', $results[0]['errors']);
        $this->assertArrayHasKey('type', $results[1]['errors']);
        $this->assertArrayHasKey('client_uuid', $results[2]['errors']);
        $this->assertNull($results[2]['client_uuid']);
        $this->assertArrayHasKey('project_id', $results[4]['errors']);
        $this->assertSame(1, MeasurementEntry::count());
    }

    public function test_a_non_array_item_is_a_per_item_error(): void
    {
        $site = $this->site();

        $results = $this->postJson(self::API.'/sync/push', ['items' => ['junk', $this->item('issue', $site->project, $this->issueData())]])->assertOk()->json('results');

        $this->assertSame(['error', 'created'], array_column($results, 'status'));
    }

    public function test_a_failed_item_leaves_no_partial_children(): void
    {
        $site = $this->site();

        $results = $this->push($this->item('dpr', $site->project, $this->dprData(['manpower' => [['trade' => 'Mason', 'count' => 'many']]])))->json('results');

        $this->assertSame('error', $results[0]['status']);
        $this->assertSame(0, Dpr::count());
    }

    public function test_errors_follow_the_users_locale(): void
    {
        $site = $this->site();
        $site->user->update(['locale' => 'mr']);

        $this->push($this->item('issue', $site->project, $this->issueData(), ['project_id' => 999999]))
            ->assertJsonPath('results.0.errors.project_id.0', 'प्रकल्प सापडला नाही.');
    }

    public function test_a_lost_insert_race_returns_the_winner_and_keeps_the_transaction_usable(): void
    {
        $site = $this->site();
        $uuid = (string) Str::uuid();
        $insert = fn () => Issue::create([...$this->issueData(), 'organization_id' => $site->organization->id, 'project_id' => $site->project->id, 'client_uuid' => $uuid]);
        $winner = $insert();

        // On PostgreSQL the follow-up read fails unless the failed insert was isolated.
        $result = Idempotent::create($insert, fn () => Issue::where('client_uuid', $uuid)->first());

        $this->assertSame($winner->id, $result->id);
        $this->assertFalse($result->wasRecentlyCreated);
        $this->assertSame(1, Issue::count());
    }

    // Permissions and plan features

    public function test_permission_is_checked_per_item(): void
    {
        $site = $this->site('qs');

        $this->push(
            $this->item('measurement', $site->project, $this->measurementData($site->item)),
            $this->item('dpr', $site->project, $this->dprData()),
            $this->item('dpr_submit', $site->project, ['dpr_id' => 1]),
            $this->item('issue', $site->project, $this->issueData()),
            $this->item('issue_status', $site->project, ['issue_id' => 1, 'status' => 'closed']),
            $this->item('attendance', $site->project, ['worker_id' => 1]),
            $this->item('petty_cash', $site->project, ['wallet_id' => 1]),
        )
            ->assertJsonPath('results.0.status', 'created')
            ->assertJsonPath('results.1.status', 'forbidden')
            ->assertJsonPath('results.1.reason', 'permission')
            ->assertJsonPath('results.2.status', 'forbidden')
            ->assertJsonPath('results.3.status', 'forbidden')
            ->assertJsonPath('results.4.status', 'forbidden')
            ->assertJsonPath('results.5.status', 'forbidden')
            ->assertJsonPath('results.6.status', 'forbidden');
    }

    public function test_a_measurement_needs_measurement_create(): void
    {
        $site = $this->site();
        $this->signIn($this->member($site->organization, 'store'));

        $this->push($this->item('measurement', $site->project, $this->measurementData($site->item)))->assertJsonPath('results.0.status', 'forbidden');

        $this->assertSame(0, MeasurementEntry::count());
    }

    public function test_the_plan_feature_is_checked_per_item(): void
    {
        $site = $this->site('owner');
        config(['buildos.plans.business.features' => ['measurement']]);

        $this->push(
            $this->item('issue', $site->project, $this->issueData()),
            $this->item('measurement', $site->project, $this->measurementData($site->item)),
        )
            ->assertJsonPath('results.0.status', 'forbidden')
            ->assertJsonPath('results.0.reason', 'feature')
            ->assertJsonPath('results.1.status', 'created');
    }

    // Tenant isolation

    public function test_another_companys_project_is_an_error(): void
    {
        $site = $this->site();
        $this->signInElsewhere();

        $results = $this->push($this->item('measurement', $site->project, $this->measurementData($site->item)))->json('results');

        $this->assertSame('error', $results[0]['status']);
        $this->assertArrayHasKey('project_id', $results[0]['errors']);
        $this->assertSame(0, MeasurementEntry::withoutGlobalScopes()->count());
    }

    public function test_foreign_ids_inside_data_are_rejected(): void
    {
        $site = $this->site();
        $other = $this->tenant('Other Co');
        $otherProject = $this->makeProject($other);
        $otherBoq = $this->makeBoqItem($otherProject);
        $otherWorker = $this->worker($other);
        $otherWallet = PettyCashWallet::create(['organization_id' => $other->id, 'project_id' => $otherProject->id, 'name' => 'Theirs']);

        $results = $this->push(
            $this->item('measurement', $site->project, $this->measurementData($otherBoq)),
            $this->item('attendance', $site->project, $this->attendanceData($otherWorker)),
            $this->item('petty_cash', $site->project, ['wallet_id' => $otherWallet->id, 'type' => 'topup', 'amount' => '10', 'entry_date' => '2026-11-01']),
        )->json('results');

        $this->assertArrayHasKey('boq_item_id', $results[0]['errors']);
        $this->assertArrayHasKey('worker_id', $results[1]['errors']);
        $this->assertArrayHasKey('wallet_id', $results[2]['errors']);
    }

    public function test_another_companys_server_id_never_touches_their_row(): void
    {
        $site = $this->site();
        $issue = app(IssueService::class)->create($site->project, $this->issueData(['title' => 'Theirs']), $site->user);

        $mine = $this->makeProject($this->signInElsewhere()->organization);

        $this->push($this->item('issue', $mine, $this->issueData(['title' => 'Mine']), ['server_id' => $issue->id, 'client_updated_at' => self::T3]))
            ->assertJsonPath('results.0.status', 'created');

        $this->assertSame('Theirs', Issue::withoutGlobalScopes()->find($issue->id)->title);
    }

    public function test_a_record_pushed_against_the_wrong_project_is_an_error(): void
    {
        $site = $this->site();
        $otherProject = $this->makeProject($site->organization);
        $entry = $this->draftEntry($site->project, $site->item, $site->user);

        $results = $this->push($this->item('measurement', $otherProject, $this->measurementData($this->makeBoqItem($otherProject)), ['server_id' => $entry->id, 'client_updated_at' => self::T3]))->json('results');

        $this->assertSame('error', $results[0]['status']);
        $this->assertArrayHasKey('project_id', $results[0]['errors']);
        $this->assertSame($site->project->id, $entry->fresh()->project_id);
    }

    // measurement

    public function test_measurement_created_then_duplicate_creates_exactly_one_row(): void
    {
        $site = $this->site();
        $item = $this->item('measurement', $site->project, $this->measurementData($site->item));

        $this->push($item)->assertJsonPath('results.0.status', 'created')->assertJsonPath('results.0.server_status', 'DRAFT');
        $this->push($item)->assertJsonPath('results.0.status', 'duplicate');

        $entry = MeasurementEntry::sole();
        $this->assertSame('50.000', $entry->qty);
        $this->assertSame($item['client_uuid'], $entry->client_uuid);
        $this->assertSame('2026-11-09 10:00:00', $entry->client_updated_at->format('Y-m-d H:i:s'));
    }

    public function test_measurement_stale_and_updated_follow_client_updated_at(): void
    {
        $site = $this->site();
        $item = $this->item('measurement', $site->project, $this->measurementData($site->item), ['client_updated_at' => self::T2]);
        $this->push($item);
        $entry = MeasurementEntry::sole();

        $this->push([...$item, 'client_updated_at' => self::T1, 'data' => [...$item['data'], 'nos' => 9]])
            ->assertJsonPath('results.0.status', 'stale')
            ->assertJsonPath('results.0.id', $entry->id);
        $this->assertSame('2.000', $entry->fresh()->nos);
        $this->assertSame(1, $this->audits('sync.stale'));

        $this->push([...$item, 'client_updated_at' => self::T3, 'data' => [...$item['data'], 'nos' => 3]])
            ->assertJsonPath('results.0.status', 'updated');
        $this->assertSame('75.000', $entry->fresh()->qty);
        $this->assertSame('2026-11-09 11:00:00', $entry->fresh()->client_updated_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, AuditLog::where('action', 'updated')->where('auditable_id', $entry->id)->where('auditable_type', MeasurementEntry::class)->count());
    }

    public function test_the_same_instant_in_another_offset_is_a_duplicate(): void
    {
        $site = $this->site();
        $item = $this->item('measurement', $site->project, $this->measurementData($site->item), ['client_updated_at' => self::T2]);
        $this->push($item);

        $this->push([...$item, 'client_updated_at' => '2026-11-09T04:30:00Z', 'data' => [...$item['data'], 'nos' => 9]])
            ->assertJsonPath('results.0.status', 'duplicate');

        $this->assertSame('2.000', MeasurementEntry::sole()->nos);
    }

    public function test_a_server_row_gets_the_client_uuid_on_first_update(): void
    {
        $site = $this->site();
        $entry = $this->draftEntry($site->project, $site->item, $site->user);
        $this->assertNull($entry->client_uuid);
        $this->travel(1)->hours();

        $item = $this->item('measurement', $site->project, $this->measurementData($site->item, ['nos' => 4]), ['server_id' => $entry->id, 'client_updated_at' => now()->addMinute()->toIso8601String()]);

        $this->push($item)->assertJsonPath('results.0.status', 'updated')->assertJsonPath('results.0.id', $entry->id);

        $this->assertSame($item['client_uuid'], $entry->fresh()->client_uuid);
        $this->assertSame(1, MeasurementEntry::count());
    }

    public function test_no_stamp_on_an_existing_editable_row_is_a_duplicate(): void
    {
        $site = $this->site();
        $item = $this->item('measurement', $site->project, $this->measurementData($site->item), ['client_updated_at' => null]);
        $this->push($item)->assertJsonPath('results.0.status', 'created');

        $this->push([...$item, 'data' => [...$item['data'], 'nos' => 9]])->assertJsonPath('results.0.status', 'duplicate');

        $this->assertSame('2.000', MeasurementEntry::sole()->nos);
    }

    public function test_a_returned_measurement_can_be_edited(): void
    {
        $site = $this->site();
        $item = $this->item('measurement', $site->project, $this->measurementData($site->item), ['client_updated_at' => self::T2]);
        $this->push($item);
        $entry = MeasurementEntry::sole();
        $qs = $this->member($site->organization, 'qs');
        app(ApprovalService::class)->act(app(MeasurementService::class)->submit($entry, $site->user), $qs, 'return', 'Check depth');

        $this->push([...$item, 'client_updated_at' => self::T3, 'data' => [...$item['data'], 'depth' => 0.25]])
            ->assertJsonPath('results.0.status', 'updated')
            ->assertJsonPath('results.0.server_status', 'RETURNED');

        $this->assertSame('25.000', $entry->fresh()->qty);
    }

    // conflict

    public function test_conflict_on_an_in_review_measurement_leaves_it_unchanged_and_audits(): void
    {
        $site = $this->site();
        $item = $this->item('measurement', $site->project, $this->measurementData($site->item), ['client_updated_at' => self::T2]);
        $this->push($item);
        $entry = MeasurementEntry::sole();
        app(MeasurementService::class)->submit($entry, $site->user);

        $this->push([...$item, 'client_updated_at' => self::T3, 'data' => [...$item['data'], 'nos' => 9]])
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.reason', 'in_review')
            ->assertJsonPath('results.0.server_status', 'IN_REVIEW');

        $this->assertSame('2.000', $entry->fresh()->nos);
        $audit = AuditLog::where('action', 'sync.conflict')->sole();
        $this->assertSame($entry->id, $audit->auditable_id);
        $this->assertSame(9, $audit->new_values['data']['nos']);
        $this->assertSame(self::T3, $audit->new_values['client_updated_at']);
    }

    public function test_conflict_on_an_approved_measurement_leaves_it_unchanged_and_audits(): void
    {
        $site = $this->site();
        $owner = $this->member($site->organization, 'owner');
        $item = $this->item('measurement', $site->project, $this->measurementData($site->item), ['client_updated_at' => self::T2]);
        $this->push($item);
        $entry = $this->approveEntry(MeasurementEntry::sole(), $site->user, $owner);

        $this->push([...$item, 'client_updated_at' => self::T3, 'data' => [...$item['data'], 'nos' => 9]])
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.reason', 'approved');

        $this->assertSame('2.000', $entry->fresh()->nos);
        $this->assertSame(1, $this->audits('sync.conflict'));
    }

    public function test_conflict_on_a_rejected_dpr(): void
    {
        $site = $this->site();
        $pm = $this->member($site->organization, 'pm');
        $item = $this->item('dpr', $site->project, $this->dprData(), ['client_updated_at' => self::T2]);
        $this->push($item);
        $dpr = Dpr::sole();
        app(ApprovalService::class)->act(app(DprService::class)->submit($dpr, $site->user), $pm, 'reject', 'Duplicate report');

        $this->push([...$item, 'client_updated_at' => self::T3, 'data' => [...$item['data'], 'summary' => 'Edited']])
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.reason', 'rejected');

        $this->assertSame('Slab cast', $dpr->fresh()->summary);
        $this->assertSame(1, $this->audits('sync.conflict'));
    }

    public function test_retrying_a_batch_after_its_response_was_lost_is_all_duplicates(): void
    {
        $site = $this->site();
        $dpr = $this->item('dpr', $site->project, $this->dprData());
        $submit = $this->item('dpr_submit', $site->project, ['dpr_client_uuid' => $dpr['client_uuid']]);
        $this->push($dpr, $submit);

        $this->push($dpr, $submit)
            ->assertJsonPath('results.0.status', 'duplicate')
            ->assertJsonPath('results.1.status', 'duplicate');

        $this->assertSame(0, $this->audits('sync.conflict'));
    }

    // dpr

    public function test_dpr_created_then_duplicate_creates_exactly_one_row(): void
    {
        $site = $this->site();
        $item = $this->item('dpr', $site->project, $this->dprData());

        $this->push($item)->assertJsonPath('results.0.status', 'created')->assertJsonPath('results.0.server_status', 'DRAFT');
        $this->push($item)->assertJsonPath('results.0.status', 'duplicate');

        $dpr = Dpr::sole();
        $this->assertSame(1, $dpr->manpower()->count());
        $this->assertSame(1, $dpr->machinery()->count());
        $this->assertSame(1, $dpr->workItems()->count());
    }

    public function test_dpr_stale_and_updated_replace_children_only_when_newer(): void
    {
        $site = $this->site();
        $item = $this->item('dpr', $site->project, $this->dprData(), ['client_updated_at' => self::T2]);
        $this->push($item);
        $dpr = Dpr::sole();
        $newManpower = [['trade' => 'Helper', 'count' => 7], ['trade' => 'Mason', 'count' => 2]];

        $this->push([...$item, 'client_updated_at' => self::T1, 'data' => [...$item['data'], 'manpower' => $newManpower]])
            ->assertJsonPath('results.0.status', 'stale');
        $this->assertSame(['Mason'], $dpr->manpower()->pluck('trade')->all());
        $this->assertSame(1, $this->audits('sync.stale'));

        $this->push([...$item, 'client_updated_at' => self::T3, 'data' => [...$item['data'], 'manpower' => $newManpower]])
            ->assertJsonPath('results.0.status', 'updated');
        $this->assertSame(2, $dpr->manpower()->count());
    }

    public function test_a_soft_deleted_dpr_is_reported_as_a_duplicate_not_a_crash(): void
    {
        $site = $this->site();
        $item = $this->item('dpr', $site->project, $this->dprData(), ['client_updated_at' => self::T2]);
        $this->push($item);
        $id = Dpr::sole()->id;
        Dpr::sole()->delete();

        $this->push([...$item, 'client_updated_at' => self::T3])->assertJsonPath('results.0.status', 'duplicate')->assertJsonPath('results.0.id', $id);

        $this->assertSame(1, Dpr::withTrashed()->count());
    }

    // *_submit

    public function test_dpr_then_dpr_submit_in_the_same_batch(): void
    {
        $site = $this->site();
        $dpr = $this->item('dpr', $site->project, $this->dprData());
        $submit = $this->item('dpr_submit', $site->project, ['dpr_client_uuid' => $dpr['client_uuid']]);

        $this->push($dpr, $submit)
            ->assertJsonPath('results.0.status', 'created')
            ->assertJsonPath('results.0.server_status', 'DRAFT')
            ->assertJsonPath('results.1.status', 'updated')
            ->assertJsonPath('results.1.server_status', 'IN_REVIEW')
            ->assertJsonPath('results.1.id', Dpr::sole()->id);

        $this->assertSame('pm', Dpr::sole()->currentApproval->currentRole());
    }

    public function test_measurement_then_measurement_submit_in_the_same_batch(): void
    {
        $site = $this->site();
        $entry = $this->item('measurement', $site->project, $this->measurementData($site->item));
        $submit = $this->item('measurement_submit', $site->project, ['entry_client_uuid' => $entry['client_uuid']]);

        $this->push($entry, $submit)->assertJsonPath('results.1.status', 'updated')->assertJsonPath('results.1.server_status', 'IN_REVIEW');

        $this->assertSame('IN_REVIEW', MeasurementEntry::sole()->status);
    }

    public function test_submit_when_already_in_review_or_approved_is_a_duplicate(): void
    {
        $site = $this->site();
        $owner = $this->member($site->organization, 'owner');
        $entry = $this->draftEntry($site->project, $site->item, $site->user);
        app(MeasurementService::class)->submit($entry, $site->user);

        $this->push($this->item('measurement_submit', $site->project, ['entry_id' => $entry->id]))
            ->assertJsonPath('results.0.status', 'duplicate')
            ->assertJsonPath('results.0.server_status', 'IN_REVIEW');

        $approval = $entry->currentApproval;
        app(ApprovalService::class)->act($approval, $owner, 'approve');
        app(ApprovalService::class)->act($approval->fresh(), $owner, 'approve');

        $this->push($this->item('measurement_submit', $site->project, ['entry_id' => $entry->id]))
            ->assertJsonPath('results.0.status', 'duplicate')
            ->assertJsonPath('results.0.server_status', 'APPROVED');
        $this->assertSame(1, $entry->approvals()->count());
    }

    public function test_submit_of_a_rejected_document_is_a_conflict(): void
    {
        $site = $this->site();
        $pm = $this->member($site->organization, 'pm');
        $dpr = app(DprService::class)->create($site->project, $this->dprData(), $site->user);
        app(ApprovalService::class)->act(app(DprService::class)->submit($dpr, $site->user), $pm, 'reject', 'No');

        $this->push($this->item('dpr_submit', $site->project, ['dpr_id' => $dpr->id]))
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.reason', 'rejected');
        $this->assertSame(1, $this->audits('sync.conflict'));
    }

    public function test_a_returned_document_can_be_resubmitted(): void
    {
        $site = $this->site();
        $pm = $this->member($site->organization, 'pm');
        $dpr = app(DprService::class)->create($site->project, $this->dprData(), $site->user);
        app(ApprovalService::class)->act(app(DprService::class)->submit($dpr, $site->user), $pm, 'return', 'Fix');

        $this->push($this->item('dpr_submit', $site->project, ['dpr_id' => $dpr->id]))
            ->assertJsonPath('results.0.status', 'updated')
            ->assertJsonPath('results.0.server_status', 'IN_REVIEW');
        $this->assertSame(2, $dpr->approvals()->count());
    }

    public function test_submit_of_an_unknown_record_is_an_error(): void
    {
        $site = $this->site();

        $results = $this->push($this->item('dpr_submit', $site->project, ['dpr_client_uuid' => (string) Str::uuid()]))->json('results');

        $this->assertSame('error', $results[0]['status']);
        $this->assertArrayHasKey('dpr_id', $results[0]['errors']);
    }

    // issue

    public function test_issue_created_then_duplicate_creates_exactly_one_row(): void
    {
        $site = $this->site();
        $item = $this->item('issue', $site->project, $this->issueData(['assigned_to' => $site->user->id, 'due_date' => '2026-11-20']));

        $this->push($item)->assertJsonPath('results.0.status', 'created')->assertJsonPath('results.0.server_status', 'open');
        $this->push($item)->assertJsonPath('results.0.status', 'duplicate');

        $this->assertSame($site->user->id, Issue::sole()->raised_by);
    }

    public function test_issue_stale_and_updated_follow_client_updated_at(): void
    {
        $site = $this->site();
        $item = $this->item('issue', $site->project, $this->issueData(), ['client_updated_at' => self::T2]);
        $this->push($item);

        $this->push([...$item, 'client_updated_at' => self::T1, 'data' => $this->issueData(['title' => 'Older'])])->assertJsonPath('results.0.status', 'stale');
        $this->assertSame('Honeycombing at C7', Issue::sole()->title);
        $this->assertSame(1, $this->audits('sync.stale'));

        $this->push([...$item, 'client_updated_at' => self::T3, 'data' => $this->issueData(['title' => 'Newer'])])->assertJsonPath('results.0.status', 'updated');
        $this->assertSame('Newer', Issue::sole()->title);
    }

    // issue_status

    public function test_issue_status_raised_offline_and_changed_offline_both_apply(): void
    {
        $site = $this->site();
        $this->travelTo('2026-11-09 14:00:00');
        $issue = $this->item('issue', $site->project, $this->issueData(), ['client_updated_at' => self::T1]);
        $status = $this->item('issue_status', $site->project, ['issue_client_uuid' => $issue['client_uuid'], 'status' => 'resolved'], ['client_updated_at' => self::T2]);

        $this->push($issue, $status)
            ->assertJsonPath('results.0.status', 'created')
            ->assertJsonPath('results.1.status', 'updated')
            ->assertJsonPath('results.1.server_status', 'resolved');

        $this->assertNotNull(Issue::sole()->resolved_at);
    }

    public function test_issue_status_repeat_is_a_duplicate(): void
    {
        $site = $this->site();
        $issue = app(IssueService::class)->create($site->project, $this->issueData(), $site->user);
        $item = $this->item('issue_status', $site->project, ['issue_id' => $issue->id, 'status' => 'in_progress'], ['client_updated_at' => now()->addMinute()->toIso8601String()]);

        $this->push($item)->assertJsonPath('results.0.status', 'updated');
        $this->push($item)->assertJsonPath('results.0.status', 'duplicate');

        $this->assertSame(1, Issue::count());
    }

    public function test_issue_status_older_than_a_web_edit_is_stale(): void
    {
        $site = $this->site();
        $this->travelTo('2026-11-09 12:00:00');
        $issue = app(IssueService::class)->create($site->project, $this->issueData(['client_updated_at' => now()->subHours(3)]), $site->user);
        app(IssueService::class)->update($issue, ['status' => 'closed']);

        $this->push($this->item('issue_status', $site->project, ['issue_id' => $issue->id, 'status' => 'open'], ['client_updated_at' => self::T3]))
            ->assertJsonPath('results.0.status', 'stale')
            ->assertJsonPath('results.0.server_status', 'closed');

        $this->assertSame('closed', $issue->fresh()->status);
        $this->assertSame(1, $this->audits('sync.stale'));
    }

    public function test_issue_status_without_a_stamp_applies_a_real_change(): void
    {
        $site = $this->site();
        $issue = app(IssueService::class)->create($site->project, $this->issueData(), $site->user);
        $item = $this->item('issue_status', $site->project, ['issue_id' => $issue->id, 'status' => 'closed'], ['client_updated_at' => null]);

        $this->push($item)->assertJsonPath('results.0.status', 'updated');
        $this->push($item)->assertJsonPath('results.0.status', 'duplicate');
    }

    // attendance

    public function test_attendance_created_then_duplicate_creates_exactly_one_row(): void
    {
        $site = $this->site();
        $item = $this->item('attendance', $site->project, $this->attendanceData($this->worker($site->organization)));

        $this->push($item)->assertJsonPath('results.0.status', 'created')->assertJsonPath('results.0.server_status', 'present');
        $this->push($item)->assertJsonPath('results.0.status', 'duplicate');

        $row = Attendance::sole();
        $this->assertSame($item['client_uuid'], $row->client_uuid);
        $this->assertSame($site->user->id, $row->marked_by);
    }

    public function test_attendance_last_writer_wins_across_two_client_uuids_with_audit(): void
    {
        $site = $this->site();
        $worker = $this->worker($site->organization);
        $deviceA = $this->item('attendance', $site->project, $this->attendanceData($worker), ['client_updated_at' => self::T2]);
        $deviceB = $this->item('attendance', $site->project, $this->attendanceData($worker, ['status' => 'absent']), ['client_updated_at' => self::T3]);
        $deviceC = $this->item('attendance', $site->project, $this->attendanceData($worker, ['status' => 'half']), ['client_updated_at' => self::T1]);

        $this->push($deviceA)->assertJsonPath('results.0.status', 'created');
        $this->push($deviceB)->assertJsonPath('results.0.status', 'updated')->assertJsonPath('results.0.server_status', 'absent');

        $row = Attendance::sole();
        $this->assertSame('absent', $row->status);
        $this->assertSame($deviceA['client_uuid'], $row->client_uuid);
        $this->assertSame(1, AuditLog::where('action', 'updated')->where('auditable_type', Attendance::class)->where('auditable_id', $row->id)->count());

        $this->push($deviceC)->assertJsonPath('results.0.status', 'stale')->assertJsonPath('results.0.server_status', 'absent');
        $this->assertSame('absent', $row->fresh()->status);
        $this->assertSame(1, $this->audits('sync.stale'));

        // Device B's retry after a lost response changes nothing.
        $this->push($deviceB)->assertJsonPath('results.0.status', 'duplicate');
        $this->assertSame(1, Attendance::count());
    }

    public function test_the_same_device_can_correct_its_own_attendance(): void
    {
        $site = $this->site();
        $item = $this->item('attendance', $site->project, $this->attendanceData($this->worker($site->organization)), ['client_updated_at' => self::T2]);
        $this->push($item);

        $this->push([...$item, 'client_updated_at' => self::T3, 'data' => [...$item['data'], 'status' => 'overtime', 'ot_hours' => 2]])
            ->assertJsonPath('results.0.status', 'updated');

        $this->assertSame('overtime', Attendance::sole()->status);
        $this->assertSame('2.0', Attendance::sole()->ot_hours);
    }

    public function test_attendance_fills_the_client_uuid_of_a_web_mark(): void
    {
        $site = $this->site();
        $worker = $this->worker($site->organization);
        $this->travelTo('2026-11-09 09:30:00');
        Attendance::create(['organization_id' => $site->organization->id, 'project_id' => $site->project->id, 'worker_id' => $worker->id, 'attendance_date' => '2026-11-08', 'status' => 'present']);
        $this->travelTo('2026-11-09 12:00:00');

        $item = $this->item('attendance', $site->project, $this->attendanceData($worker, ['status' => 'half']), ['client_updated_at' => self::T2]);

        $this->push($item)->assertJsonPath('results.0.status', 'updated');

        $this->assertSame($item['client_uuid'], Attendance::sole()->client_uuid);
    }

    // petty_cash

    public function test_petty_cash_created_then_duplicate_creates_exactly_one_row(): void
    {
        $site = $this->site();
        $wallet = $this->wallet($site->project, $site->user);
        $item = $this->item('petty_cash', $site->project, ['wallet_id' => $wallet->id, 'type' => 'expense', 'amount' => '450.50', 'category' => 'Fuel', 'entry_date' => '2026-11-02']);

        $this->push($item)->assertJsonPath('results.0.status', 'created')->assertJsonPath('results.0.server_status', null);
        $this->push($item)->assertJsonPath('results.0.status', 'duplicate');

        $this->assertSame(1, PettyCashEntry::where('type', 'expense')->count());
        $this->assertSame('549.50', $wallet->fresh()->load('entries')->balance());
    }

    public function test_an_expense_over_balance_is_a_per_item_error(): void
    {
        $site = $this->site();
        $wallet = $this->wallet($site->project, $site->user);
        $expense = fn ($amount) => $this->item('petty_cash', $site->project, ['wallet_id' => $wallet->id, 'type' => 'expense', 'amount' => $amount, 'category' => 'Fuel', 'entry_date' => '2026-11-02']);

        $results = $this->push($expense('1000.01'), $expense('1000.00'))->json('results');

        $this->assertSame('error', $results[0]['status']);
        $this->assertArrayHasKey('amount', $results[0]['errors']);
        $this->assertSame('created', $results[1]['status']);
    }

    public function test_a_replayed_expense_is_a_duplicate_even_when_the_wallet_is_now_empty(): void
    {
        $site = $this->site();
        $wallet = $this->wallet($site->project, $site->user);
        $item = $this->item('petty_cash', $site->project, ['wallet_id' => $wallet->id, 'type' => 'expense', 'amount' => '1000', 'category' => 'Fuel', 'entry_date' => '2026-11-02']);

        $this->push($item)->assertJsonPath('results.0.status', 'created');
        $this->push($item)->assertJsonPath('results.0.status', 'duplicate');
    }

    public function test_the_wallet_must_belong_to_the_project(): void
    {
        $site = $this->site();
        $wallet = $this->wallet($this->makeProject($site->organization), $site->user);

        $results = $this->push($this->item('petty_cash', $site->project, ['wallet_id' => $wallet->id, 'type' => 'topup', 'amount' => '10', 'entry_date' => '2026-11-02']))->json('results');

        $this->assertSame('error', $results[0]['status']);
        $this->assertArrayHasKey('wallet_id', $results[0]['errors']);
    }
}
