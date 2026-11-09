<?php

namespace Tests\Feature\Payroll;

use App\Exceptions\ImmutableRecordException;
use App\Models\ExpenseClaim;
use App\Models\StaffLeave;
use App\Models\WageRun;
use App\Models\WorkerAdvance;
use App\Services\Payroll\AdvanceService;
use App\Services\Payroll\WageRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsTenant;
use Tests\BuildsWorkforce;
use Tests\TestCase;

class PayrollHttpTest extends TestCase
{
    use BuildsTenant, BuildsWorkforce, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function company(string $plan = 'contractor', string $name = 'Acme Infra'): object
    {
        $organization = $this->tenant($name, $plan);

        return (object) [
            'organization' => $organization,
            'owner' => $this->member($organization, 'owner'),
            'accounts' => $this->member($organization, 'accounts'),
            'pm' => $this->member($organization, 'pm'),
            'engineer' => $this->member($organization, 'engineer'),
            'viewer' => $this->member($organization, 'viewer'),
            'project' => $this->makeProject($organization),
        ];
    }

    private function draftRun(object $company): WageRun
    {
        $worker = $this->makeWorker($company->organization, 'Ramesh Mason', '800.00');
        $this->markDays($company->project, $worker, '2026-11-01', [['present', 2], ['present'], ['half']]);

        return app(WageRunService::class)->create(['period_from' => '2026-11-01', 'period_to' => '2026-11-07'], $company->accounts);
    }

    public function test_payroll_is_a_paid_plan_feature(): void
    {
        $free = $this->company('free', 'Free Co');
        $contractor = $this->company('contractor', 'Paid Co');

        $this->actingAs($free->owner)->get('/payroll/runs')->assertForbidden();
        $this->actingAs($free->owner)->get('/payroll/advances')->assertForbidden();
        $this->actingAs($free->owner)->get('/payroll/staff')->assertForbidden();
        $this->actingAs($contractor->owner)->get('/payroll/runs')->assertOk()->assertSee('Wage runs');
    }

    public function test_menu_lists_payroll_only_where_the_plan_and_role_allow_it(): void
    {
        $paid = $this->company('contractor', 'Paid Co');
        $free = $this->company('free', 'Free Co');

        $this->actingAs($paid->accounts)->get('/dashboard')->assertSee('Wage runs')->assertSee('Advances');
        $this->actingAs($paid->engineer)->get('/dashboard')->assertDontSee('Wage runs')->assertSee('Staff leave and claims');
        $this->actingAs($free->owner)->get('/dashboard')->assertDontSee('Wage runs');
    }

    public function test_roles_without_payroll_permissions_are_turned_away(): void
    {
        $company = $this->company();

        foreach (['engineer', 'viewer'] as $role) {
            $this->actingAs($company->{$role})->get('/payroll/runs')->assertForbidden();
            $this->actingAs($company->{$role})->get('/payroll/advances')->assertForbidden();
            $this->actingAs($company->{$role})->post('/payroll/runs', ['period_from' => '2026-11-01', 'period_to' => '2026-11-07', 'worker_scope' => 'all'])->assertForbidden();
        }
    }

    public function test_a_wage_run_is_made_checked_approved_and_locked_over_http(): void
    {
        $company = $this->company();
        $worker = $this->makeWorker($company->organization, 'Ramesh Mason', '800.00');
        $this->markDays($company->project, $worker, '2026-11-01', [['present', 2], ['present'], ['half']]);

        $this->actingAs($company->accounts)->post('/payroll/runs', [
            'period_from' => '2026-11-01', 'period_to' => '2026-11-07', 'worker_scope' => 'all',
        ])->assertRedirect();

        $run = WageRun::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('2400.00', $run->total_gross);

        $this->actingAs($company->accounts)->get("/payroll/runs/{$run->id}")->assertOk()->assertSee('Ramesh Mason')->assertSee('₹2,400.00');

        // The person who prepared it cannot approve it.
        $this->actingAs($company->accounts)->post("/payroll/runs/{$run->id}/approve")->assertSessionHasErrors('status');
        $this->assertSame('DRAFT', $run->refresh()->status);

        // A project manager cannot approve at all.
        $this->actingAs($company->pm)->post("/payroll/runs/{$run->id}/approve")->assertForbidden();

        // No payslip until it is locked.
        $this->actingAs($company->accounts)->get("/payroll/runs/{$run->id}/payslips")->assertNotFound();

        $this->actingAs($company->owner)->post("/payroll/runs/{$run->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', $run->refresh()->status);

        $line = $run->lines()->firstOrFail();
        $slip = $this->actingAs($company->accounts)->get("/payroll/runs/{$run->id}/lines/{$line->id}/payslip");
        $slip->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $slip->getContent());

        $all = $this->actingAs($company->accounts)->get("/payroll/runs/{$run->id}/payslips");
        $all->assertOk()->assertHeader('content-type', 'application/pdf');

        // Locked: cannot be recalculated or removed.
        $this->actingAs($company->accounts)->post("/payroll/runs/{$run->id}/recalculate")->assertStatus(409);
        $this->actingAs($company->accounts)->delete("/payroll/runs/{$run->id}")->assertStatus(409);
        $this->assertSame(1, WageRun::withoutGlobalScopes()->count());

        $this->actingAs($company->owner)->post("/payroll/runs/{$run->id}/paid", ['payment_note' => 'Bank transfer'])->assertSessionHasNoErrors();
        $this->assertSame('PAID', $run->refresh()->status);
    }

    public function test_the_run_form_validates_dates_and_foreign_ids(): void
    {
        $company = $this->company();
        $other = $this->company('contractor', 'Other Co');

        $this->actingAs($company->accounts)->post('/payroll/runs', ['period_from' => '2026-11-07', 'period_to' => '2026-11-01', 'worker_scope' => 'all'])->assertSessionHasErrors('period_to');
        $this->actingAs($company->accounts)->post('/payroll/runs', ['period_from' => '2026-11-01', 'period_to' => '2026-11-07', 'worker_scope' => 'everyone'])->assertSessionHasErrors('worker_scope');
        $this->actingAs($company->accounts)->post('/payroll/runs', ['period_from' => '2026-11-01', 'period_to' => '2026-11-07', 'worker_scope' => 'all', 'project_id' => $other->project->id])->assertSessionHasErrors('project_id');
    }

    public function test_another_companys_runs_cannot_be_seen_or_changed(): void
    {
        $mine = $this->company('contractor', 'Mine');
        $theirs = $this->company('business', 'Theirs');
        $run = $this->draftRun($theirs);
        $line = $run->lines()->firstOrFail();

        $this->actingAs($mine->owner)->get("/payroll/runs/{$run->id}")->assertNotFound();
        $this->actingAs($mine->owner)->post("/payroll/runs/{$run->id}/approve")->assertNotFound();
        $this->actingAs($mine->owner)->post("/payroll/runs/{$run->id}/recalculate")->assertNotFound();
        $this->actingAs($mine->owner)->delete("/payroll/runs/{$run->id}")->assertNotFound();
        $this->actingAs($mine->owner)->get("/payroll/runs/{$run->id}/lines/{$line->id}/payslip")->assertNotFound();
        $this->actingAs($mine->owner)->get('/payroll/runs')->assertOk()->assertDontSee($run->number);
        $this->assertSame('DRAFT', $run->refresh()->status);
    }

    public function test_advances_are_recorded_listed_and_removed_only_before_recovery(): void
    {
        $company = $this->company();
        $worker = $this->makeWorker($company->organization, 'Ramesh Mason', '800.00');

        $this->actingAs($company->accounts)->post('/payroll/advances', [
            'worker_id' => $worker->id, 'amount' => '1500.50', 'given_on' => '2026-11-01', 'recovery_per_run' => '500', 'reason' => 'Festival',
        ])->assertSessionHasNoErrors();

        $advance = WorkerAdvance::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('1500.50', $advance->amount);
        $this->actingAs($company->accounts)->get('/payroll/advances')->assertOk()->assertSee('Ramesh Mason')->assertSee('₹1,500.50')->assertSee('Festival');

        $this->actingAs($company->accounts)->delete("/payroll/advances/{$advance->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, WorkerAdvance::withoutGlobalScopes()->count());

        // Taken back by a run: now it stays.
        $this->markDays($company->project, $worker, '2026-11-01', [['present']]);
        $kept = app(AdvanceService::class)->give(['worker_id' => $worker->id, 'amount' => '300.00', 'given_on' => '2026-10-30'], $company->accounts);
        app(WageRunService::class)->create(['period_from' => '2026-11-01', 'period_to' => '2026-11-07'], $company->accounts);

        $this->actingAs($company->accounts)->delete("/payroll/advances/{$kept->id}")->assertSessionHasErrors('advance');
        $this->assertSame(1, WorkerAdvance::withoutGlobalScopes()->count());
    }

    public function test_advances_reject_a_worker_from_another_company_and_bad_amounts(): void
    {
        $company = $this->company();
        $other = $this->company('contractor', 'Other Co');
        $stranger = $this->makeWorker($other->organization, 'Stranger');

        $this->actingAs($company->accounts)->post('/payroll/advances', ['worker_id' => $stranger->id, 'amount' => '100', 'given_on' => '2026-11-01'])->assertSessionHasErrors('worker_id');
        $this->actingAs($company->accounts)->post('/payroll/advances', ['worker_id' => $this->makeWorker($company->organization)->id, 'amount' => '-5', 'given_on' => '2026-11-01'])->assertSessionHasErrors('amount');
        $this->assertSame(0, WorkerAdvance::withoutGlobalScopes()->count());
    }

    public function test_advances_cannot_be_deleted_across_companies(): void
    {
        $mine = $this->company('contractor', 'Mine');
        $theirs = $this->company('contractor', 'Theirs');
        $advance = app(AdvanceService::class)->give(['worker_id' => $this->makeWorker($theirs->organization)->id, 'amount' => '100.00', 'given_on' => '2026-11-01'], $theirs->accounts);

        $this->actingAs($mine->accounts)->delete("/payroll/advances/{$advance->id}")->assertNotFound();
        $this->assertSame(1, WorkerAdvance::withoutGlobalScopes()->count());
    }

    public function test_staff_can_ask_for_leave_and_a_manager_decides(): void
    {
        $company = $this->company();

        $this->actingAs($company->engineer)->post('/payroll/staff/leaves', [
            'leave_type' => 'sick', 'from_date' => '2026-11-10', 'to_date' => '2026-11-12', 'reason' => 'Fever',
        ])->assertSessionHasNoErrors();

        $leave = StaffLeave::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('3.000', $leave->days);
        $this->assertSame('PENDING', $leave->status);

        // The requester cannot decide, and neither can a role without the permission.
        $this->actingAs($company->engineer)->patch("/payroll/staff/leaves/{$leave->id}/decide", ['action' => 'approve'])->assertForbidden();

        $this->actingAs($company->pm)->patch("/payroll/staff/leaves/{$leave->id}/decide", ['action' => 'reject'])->assertSessionHasErrors('note');
        $this->actingAs($company->pm)->patch("/payroll/staff/leaves/{$leave->id}/decide", ['action' => 'approve', 'note' => 'Get well soon'])->assertSessionHasNoErrors();

        $leave->refresh();
        $this->assertSame('APPROVED', $leave->status);
        $this->assertSame($company->pm->id, $leave->decided_by);

        // Decided once.
        $this->actingAs($company->pm)->patch("/payroll/staff/leaves/{$leave->id}/decide", ['action' => 'reject', 'note' => 'x'])->assertSessionHasErrors('status');
    }

    public function test_overlapping_leave_and_half_days_are_checked(): void
    {
        $company = $this->company();
        $this->actingAs($company->engineer)->post('/payroll/staff/leaves', ['leave_type' => 'casual', 'from_date' => '2026-11-10', 'to_date' => '2026-11-10', 'half_day' => 1])->assertSessionHasNoErrors();
        $this->assertSame('0.500', StaffLeave::withoutGlobalScopes()->firstOrFail()->days);

        $this->actingAs($company->engineer)->post('/payroll/staff/leaves', ['leave_type' => 'casual', 'from_date' => '2026-11-10', 'to_date' => '2026-11-11'])->assertSessionHasErrors('from_date');
        $this->actingAs($company->engineer)->post('/payroll/staff/leaves', ['leave_type' => 'casual', 'from_date' => '2026-11-20', 'to_date' => '2026-11-22', 'half_day' => 1])->assertSessionHasErrors('half_day');
        $this->assertSame(1, StaffLeave::withoutGlobalScopes()->count());
    }

    public function test_nobody_but_an_owner_decides_their_own_request(): void
    {
        $company = $this->company();
        $this->actingAs($company->pm)->post('/payroll/staff/claims', ['claim_date' => '2026-11-03', 'category' => 'Fuel', 'amount' => '850.00'])->assertSessionHasNoErrors();
        $claim = ExpenseClaim::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($company->pm)->patch("/payroll/staff/claims/{$claim->id}/decide", ['action' => 'approve'])->assertSessionHasErrors('status');
        $this->assertSame('PENDING', $claim->refresh()->status);

        $this->actingAs($company->accounts)->patch("/payroll/staff/claims/{$claim->id}/decide", ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', $claim->refresh()->status);
    }

    public function test_claims_move_from_pending_to_approved_to_paid_and_then_stay_put(): void
    {
        $company = $this->company();
        $this->actingAs($company->engineer)->post('/payroll/staff/claims', [
            'claim_date' => '2026-11-03', 'category' => 'Fuel', 'amount' => '850.00', 'project_id' => $company->project->id, 'description' => 'Site visit',
        ])->assertSessionHasNoErrors();
        $claim = ExpenseClaim::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($company->accounts)->post("/payroll/staff/claims/{$claim->id}/paid")->assertSessionHasErrors('status');
        $this->actingAs($company->accounts)->patch("/payroll/staff/claims/{$claim->id}/decide", ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($company->accounts)->post("/payroll/staff/claims/{$claim->id}/paid")->assertSessionHasNoErrors();
        $this->assertSame('PAID', $claim->refresh()->status);

        // A paid claim cannot be withdrawn, and its figures are locked.
        $this->actingAs($company->engineer)->delete("/payroll/staff/claims/{$claim->id}")->assertSessionHasErrors('status');
        $this->assertSame(1, ExpenseClaim::withoutGlobalScopes()->count());

        $this->expectException(ImmutableRecordException::class);
        $claim->update(['amount' => '1.00']);
    }

    public function test_claims_reject_a_project_from_another_company(): void
    {
        $company = $this->company();
        $other = $this->company('contractor', 'Other Co');

        $this->actingAs($company->engineer)->post('/payroll/staff/claims', ['claim_date' => '2026-11-03', 'category' => 'Fuel', 'amount' => '100', 'project_id' => $other->project->id])->assertSessionHasErrors('project_id');
        $this->assertSame(0, ExpenseClaim::withoutGlobalScopes()->count());
    }

    public function test_staff_requests_stay_inside_the_company(): void
    {
        $mine = $this->company('contractor', 'Mine');
        $theirs = $this->company('contractor', 'Theirs');
        $this->actingAs($theirs->engineer)->post('/payroll/staff/leaves', ['leave_type' => 'casual', 'from_date' => '2026-11-10', 'to_date' => '2026-11-10']);
        $this->actingAs($theirs->engineer)->post('/payroll/staff/claims', ['claim_date' => '2026-11-03', 'category' => 'Fuel', 'amount' => '100', 'description' => 'Secret diesel run']);
        $leave = StaffLeave::withoutGlobalScopes()->firstOrFail();
        $claim = ExpenseClaim::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($mine->pm)->patch("/payroll/staff/leaves/{$leave->id}/decide", ['action' => 'approve'])->assertNotFound();
        $this->actingAs($mine->pm)->patch("/payroll/staff/claims/{$claim->id}/decide", ['action' => 'approve'])->assertNotFound();
        $this->actingAs($mine->pm)->get('/payroll/staff')->assertOk()->assertDontSee('Secret diesel run');
    }

    public function test_repeating_a_form_with_the_same_client_uuid_does_not_double_up(): void
    {
        $company = $this->company();
        $uuid = '22222222-2222-4222-8222-222222222222';
        $payload = ['claim_date' => '2026-11-03', 'category' => 'Fuel', 'amount' => '100', 'client_uuid' => $uuid];

        $this->actingAs($company->engineer)->post('/payroll/staff/claims', $payload);
        $this->actingAs($company->engineer)->post('/payroll/staff/claims', $payload);

        $this->assertSame(1, ExpenseClaim::withoutGlobalScopes()->count());
    }
}
