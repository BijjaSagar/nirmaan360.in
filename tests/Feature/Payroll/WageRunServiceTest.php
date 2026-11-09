<?php

namespace Tests\Feature\Payroll;

use App\Exceptions\ImmutableRecordException;
use App\Models\AdvanceRecovery;
use App\Models\AuditLog;
use App\Models\WageRun;
use App\Models\WageRunLine;
use App\Services\Payroll\AdvanceService;
use App\Services\Payroll\WageRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\BuildsTenant;
use Tests\BuildsWorkforce;
use Tests\TestCase;

class WageRunServiceTest extends TestCase
{
    use BuildsTenant, BuildsWorkforce, RefreshDatabase;

    private WageRunService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WageRunService::class);
    }

    private function request(array $overrides = []): array
    {
        return ['period_from' => '2026-11-01', 'period_to' => '2026-11-07', ...$overrides];
    }

    /** The mason from the calculator example: ₹800 a day, 5.5 payable days, 3 overtime hours -> ₹5,000. */
    private function mason(object $site)
    {
        $worker = $this->makeWorker($site->organization, 'Ramesh Mason', '800.00');
        $this->markDays($site->project, $worker, '2026-11-01', [
            ['present', 2], ['present'], ['present'], ['overtime', 1], ['present'], ['half'], ['absent'],
        ]);

        return $worker;
    }

    private function site(): object
    {
        $organization = $this->tenant();
        $accountant = $this->member($organization, 'accounts');
        $owner = $this->member($organization, 'owner');

        return (object) ['organization' => $organization, 'accountant' => $accountant, 'owner' => $owner, 'project' => $this->makeProject($organization)];
    }

    public function test_run_is_calculated_from_attendance(): void
    {
        $site = $this->site();
        $this->mason($site);

        $run = $this->service->create($this->request(), $site->accountant);

        $this->assertSame('DRAFT', $run->status);
        $this->assertSame('WR/2026-27/0001', $run->number);
        $this->assertSame('5.500', $run->total_days);
        $this->assertSame('5000.00', $run->total_gross);
        $this->assertSame('0.00', $run->total_recovery);
        $this->assertSame('5000.00', $run->total_net);

        $line = $run->lines->first();
        $this->assertSame('Ramesh Mason', $line->worker_name);
        $this->assertSame('800.00', $line->daily_wage);
        $this->assertSame('5.000', $line->present_days);
        $this->assertSame('1.000', $line->half_days);
        $this->assertSame('1.000', $line->absent_days);
        $this->assertSame('4400.00', $line->day_pay);
        $this->assertSame('600.00', $line->ot_pay);
    }

    public function test_only_the_chosen_period_project_and_worker_kind_count(): void
    {
        $site = $this->site();
        $other = $this->makeProject($site->organization);
        $own = $this->makeWorker($site->organization, 'Own Hand', '500.00');
        $hired = $this->makeWorker($site->organization, 'Hired Hand', '400.00', ['type' => 'contractor']);

        $this->mark($site->project, $own, '2026-11-02');
        $this->mark($other, $own, '2026-11-03');
        $this->mark($site->project, $own, '2026-11-20');
        $this->mark($site->project, $hired, '2026-11-02');

        $run = $this->service->create($this->request(['project_id' => $site->project->id, 'worker_scope' => 'own']), $site->accountant);

        $this->assertSame(['Own Hand'], $run->lines->pluck('worker_name')->all());
        $this->assertSame('500.00', $run->total_gross);
    }

    public function test_numbers_follow_the_financial_year(): void
    {
        $site = $this->site();
        $worker = $this->makeWorker($site->organization);
        $this->mark($site->project, $worker, '2026-03-30');
        $this->mark($site->project, $worker, '2026-04-02');

        $march = $this->service->create(['period_from' => '2026-03-01', 'period_to' => '2026-03-31'], $site->accountant);
        $april = $this->service->create(['period_from' => '2026-04-01', 'period_to' => '2026-04-30'], $site->accountant);

        $this->assertSame('WR/2025-26/0001', $march->number);
        $this->assertSame('WR/2026-27/0001', $april->number);
    }

    public function test_a_period_with_nothing_to_pay_is_refused_and_leaves_no_run(): void
    {
        $site = $this->site();

        try {
            $this->service->create($this->request(), $site->accountant);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('period_from', $exception->errors());
        }

        $this->assertSame(0, WageRun::withoutGlobalScopes()->count());
    }

    public function test_overlapping_runs_are_refused_so_no_day_is_paid_twice(): void
    {
        $site = $this->site();
        $this->mason($site);
        $this->service->create($this->request(), $site->accountant);

        $this->expectException(ValidationException::class);
        $this->service->create($this->request(['period_from' => '2026-11-05', 'period_to' => '2026-11-12']), $site->accountant);
    }

    public function test_runs_for_different_projects_or_worker_kinds_may_share_dates(): void
    {
        $site = $this->site();
        $other = $this->makeProject($site->organization);
        $worker = $this->makeWorker($site->organization);
        $this->mark($site->project, $worker, '2026-11-02');
        $this->mark($other, $worker, '2026-11-03');

        $first = $this->service->create($this->request(['project_id' => $site->project->id]), $site->accountant);
        $second = $this->service->create($this->request(['project_id' => $other->id]), $site->accountant);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('800.00', $first->total_gross);
        $this->assertSame('800.00', $second->total_gross);

        $this->expectException(ValidationException::class);
        $this->service->create($this->request(), $site->accountant);
    }

    public function test_repeating_a_client_uuid_returns_the_same_run(): void
    {
        $site = $this->site();
        $this->mason($site);
        $uuid = '11111111-1111-4111-8111-111111111111';

        $first = $this->service->create($this->request(['client_uuid' => $uuid]), $site->accountant);
        $again = $this->service->create($this->request(['client_uuid' => $uuid]), $site->accountant);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, WageRun::withoutGlobalScopes()->count());
    }

    public function test_advance_is_taken_back_in_instalments_across_runs(): void
    {
        $site = $this->site();
        $worker = $this->mason($site);
        $advance = app(AdvanceService::class)->give([
            'worker_id' => $worker->id, 'amount' => '2500.00', 'given_on' => '2026-10-30', 'recovery_per_run' => '1000.00',
        ], $site->accountant);

        // Run 1: gross ₹5,000, takes ₹1,000, net ₹4,000.
        $first = $this->service->create($this->request(), $site->accountant);
        $this->assertSame('1000.00', $first->total_recovery);
        $this->assertSame('4000.00', $first->total_net);
        $this->assertSame('1500.00', $advance->refresh()->outstanding());

        // Run 2 and 3 take ₹1,000 and then the last ₹500.
        $this->markDays($site->project, $worker, '2026-11-08', [['present'], ['present']]);
        $second = $this->service->create(['period_from' => '2026-11-08', 'period_to' => '2026-11-09'], $site->accountant);
        $this->assertSame('1000.00', $second->total_recovery);
        $this->assertSame('600.00', $second->total_net);

        $this->markDays($site->project, $worker, '2026-11-10', [['present'], ['present']]);
        $third = $this->service->create(['period_from' => '2026-11-10', 'period_to' => '2026-11-11'], $site->accountant);
        $this->assertSame('500.00', $third->total_recovery);
        $this->assertSame('1100.00', $third->total_net);
        $this->assertSame('0.00', $advance->refresh()->outstanding());
    }

    public function test_recovery_never_takes_more_than_the_wages_and_the_rest_waits(): void
    {
        $site = $this->site();
        $worker = $this->makeWorker($site->organization, 'Short Pay', '500.00');
        $this->mark($site->project, $worker, '2026-11-02');
        $advance = app(AdvanceService::class)->give(['worker_id' => $worker->id, 'amount' => '2000.00', 'given_on' => '2026-10-30'], $site->accountant);

        $run = $this->service->create($this->request(), $site->accountant);

        $this->assertSame('500.00', $run->total_recovery);
        $this->assertSame('0.00', $run->total_net);
        $this->assertSame('1500.00', $advance->refresh()->outstanding());
    }

    public function test_older_advances_are_taken_first_and_future_ones_are_left_alone(): void
    {
        $site = $this->site();
        $worker = $this->makeWorker($site->organization, 'Two Advances', '1000.00');
        $this->mark($site->project, $worker, '2026-11-02');
        $service = app(AdvanceService::class);
        $older = $service->give(['worker_id' => $worker->id, 'amount' => '600.00', 'given_on' => '2026-10-01'], $site->accountant);
        $newer = $service->give(['worker_id' => $worker->id, 'amount' => '600.00', 'given_on' => '2026-10-15'], $site->accountant);
        $later = $service->give(['worker_id' => $worker->id, 'amount' => '600.00', 'given_on' => '2026-11-05'], $site->accountant);

        $run = $this->service->create($this->request(), $site->accountant);

        $this->assertSame('1000.00', $run->total_recovery);
        $this->assertSame('0.00', $older->refresh()->outstanding());
        $this->assertSame('200.00', $newer->refresh()->outstanding());
        $this->assertSame('600.00', $later->refresh()->outstanding());
    }

    public function test_recalculating_a_draft_frees_and_retakes_the_advance_without_doubling_it(): void
    {
        $site = $this->site();
        $worker = $this->mason($site);
        $advance = app(AdvanceService::class)->give(['worker_id' => $worker->id, 'amount' => '1000.00', 'given_on' => '2026-10-30'], $site->accountant);
        $run = $this->service->create($this->request(['period_to' => '2026-11-10']), $site->accountant);

        $this->travel(5)->seconds();
        $this->mark($site->project, $worker, '2026-11-09', 'present');
        $this->assertTrue($this->service->isStale($run));

        $run = $this->service->recalculate($run);

        $this->assertFalse($this->service->isStale($run));
        $this->assertSame('5800.00', $run->total_gross);
        $this->assertSame('1000.00', $run->total_recovery);
        $this->assertSame(1, AdvanceRecovery::withoutGlobalScopes()->count());
        $this->assertSame('0.00', $advance->refresh()->outstanding());
    }

    public function test_deleting_a_draft_releases_its_advance_recovery(): void
    {
        $site = $this->site();
        $worker = $this->mason($site);
        $advance = app(AdvanceService::class)->give(['worker_id' => $worker->id, 'amount' => '1000.00', 'given_on' => '2026-10-30'], $site->accountant);
        $run = $this->service->create($this->request(), $site->accountant);
        $this->assertSame('0.00', $advance->refresh()->outstanding());

        $this->service->delete($run);

        $this->assertSame('1000.00', $advance->refresh()->outstanding());
        $this->assertSame(0, WageRunLine::withoutGlobalScopes()->count());
    }

    public function test_approval_needs_a_second_person_and_fresh_attendance(): void
    {
        $site = $this->site();
        $worker = $this->mason($site);
        $run = $this->service->create($this->request(['period_to' => '2026-11-10']), $site->accountant);

        try {
            $this->service->approve($run, $site->accountant);
            $this->fail('The person who prepared the run must not approve it.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->travel(5)->seconds();
        $this->mark($site->project, $worker, '2026-11-09', 'present');

        try {
            $this->service->approve($run, $site->owner);
            $this->fail('A stale run must not be approved.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Recalculate', $exception->errors()['status'][0]);
        }

        $run = $this->service->approve($this->service->recalculate($run), $site->owner);

        $this->assertSame('APPROVED', $run->status);
        $this->assertSame($site->owner->id, $run->approved_by);
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'wage_run.approve')->exists());
    }

    public function test_an_owner_may_approve_their_own_run(): void
    {
        $site = $this->site();
        $this->mason($site);
        $run = $this->service->create($this->request(), $site->owner);

        $this->assertSame('APPROVED', $this->service->approve($run, $site->owner)->status);
    }

    public function test_an_approved_run_is_locked_in_every_way(): void
    {
        $site = $this->site();
        $worker = $this->mason($site);
        app(AdvanceService::class)->give(['worker_id' => $worker->id, 'amount' => '500.00', 'given_on' => '2026-10-30'], $site->accountant);
        $run = $this->service->approve($this->service->create($this->request(), $site->accountant), $site->owner);

        $this->assertThrowsLocked(fn () => $run->update(['total_net' => '1.00']));
        $this->assertThrowsLocked(fn () => $run->delete());
        $this->assertThrowsLocked(fn () => $this->service->delete($run));
        $this->assertThrowsLocked(fn () => $this->service->recalculate($run));
        $this->assertThrowsLocked(fn () => $run->lines->first()->update(['net' => '1.00']));
        $this->assertThrowsLocked(fn () => $run->lines->first()->delete());
        $this->assertThrowsLocked(fn () => $run->recoveries->first()->delete());

        $this->assertSame('4500.00', $run->refresh()->total_net);
    }

    public function test_an_approved_run_can_only_move_on_to_paid(): void
    {
        $site = $this->site();
        $this->mason($site);
        $run = $this->service->approve($this->service->create($this->request(), $site->accountant), $site->owner);

        $paid = $this->service->markPaid($run, $site->accountant, 'Bank transfer');

        $this->assertSame('PAID', $paid->status);
        $this->assertSame('Bank transfer', $paid->payment_note);
        $this->assertNotNull($paid->paid_at);

        $this->expectException(ValidationException::class);
        $this->service->markPaid($paid, $site->accountant);
    }

    public function test_a_draft_cannot_be_marked_paid(): void
    {
        $site = $this->site();
        $this->mason($site);
        $run = $this->service->create($this->request(), $site->accountant);

        $this->expectException(ValidationException::class);
        $this->service->markPaid($run, $site->accountant);
    }

    public function test_other_companies_attendance_and_workers_are_never_included(): void
    {
        $site = $this->site();
        $this->mason($site);
        $rival = $this->tenant('Rival Co');
        $rivalProject = $this->makeProject($rival);
        $this->mark($rivalProject, $this->makeWorker($rival, 'Rival Hand', '9999.00'), '2026-11-02');

        $run = $this->service->create($this->request(), $site->accountant);

        $this->assertSame(['Ramesh Mason'], $run->lines->pluck('worker_name')->all());
        $this->assertSame('5000.00', $run->total_gross);
    }

    public function test_saved_terms_apply_to_new_runs_only(): void
    {
        $site = $this->site();
        $worker = $this->makeWorker($site->organization, 'OT Man', '800.00');
        $this->mark($site->project, $worker, '2026-11-02', 'present', 4);
        $first = $this->service->create($this->request(['period_to' => '2026-11-05']), $site->accountant);
        $this->assertSame('800.00', $first->lines->first()->day_pay);
        $this->assertSame('800.00', $first->lines->first()->ot_pay);

        $this->service->saveTerms($site->organization, ['shift_hours' => '10', 'ot_multiplier' => '1.5']);
        $this->mark($site->project, $worker, '2026-11-12', 'present', 4);
        $second = $this->service->create(['period_from' => '2026-11-10', 'period_to' => '2026-11-14'], $site->accountant);

        // ₹800 / 10 hours = ₹80 an hour, 4 hours at 1.5x = ₹480.
        $this->assertSame('480.00', $second->lines->first()->ot_pay);
        $this->assertSame(['shift_hours' => '10', 'ot_multiplier' => '1.5'], $second->terms);
        $this->assertSame('2', $first->refresh()->terms['ot_multiplier']);
    }

    private function assertThrowsLocked(callable $action): void
    {
        try {
            $action();
        } catch (ImmutableRecordException $exception) {
            $this->assertSame(409, $exception->getStatusCode());

            return;
        }

        $this->fail('Expected the approved wage run to refuse the change.');
    }
}
