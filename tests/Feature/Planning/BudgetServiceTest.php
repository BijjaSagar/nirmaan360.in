<?php

namespace Tests\Feature\Planning;

use App\Models\Attendance;
use App\Models\Party;
use App\Models\PettyCashWallet;
use App\Models\PlanCostEntry;
use App\Models\RaBill;
use App\Models\Worker;
use App\Services\Planning\BudgetService;
use App\Support\Dec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BudgetServiceTest extends TestCase
{
    use BuildsPlan, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlan();
    }

    private function budget(): BudgetService
    {
        return app(BudgetService::class);
    }

    private function row(string $head): array
    {
        return collect($this->budget()->report($this->project)['rows'])->firstWhere('head', $head);
    }

    private function worker(string $type, string $wage): Worker
    {
        return Worker::create([
            'organization_id' => $this->organization->id,
            'name' => 'Worker',
            'trade' => 'Mason',
            'type' => $type,
            'daily_wage' => $wage,
        ]);
    }

    private function attendance(Worker $worker, string $date, string $status, string $ot = '0'): void
    {
        Attendance::create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'worker_id' => $worker->id,
            'attendance_date' => $date,
            'status' => $status,
            'ot_hours' => $ot,
        ]);
    }

    private function bill(string $type, string $status, string $gross, int $number): void
    {
        RaBill::create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'type' => $type,
            'bill_no' => $number,
            'number' => 'RA-'.$number.'-'.Str::random(4),
            'period_from' => '2026-11-01',
            'period_to' => '2026-11-30',
            'status' => $status,
            'gross_amount' => $gross,
        ]);
    }

    public function test_saving_a_head_twice_changes_its_budget(): void
    {
        $this->budget()->saveLine($this->project, ['head' => 'material', 'amount' => '100.50']);
        $this->budget()->saveLine($this->project, ['head' => 'material', 'amount' => '200.75']);

        $this->assertSame('200.75', $this->row('material')['budget']);
    }

    public function test_a_repeated_client_uuid_records_one_cost(): void
    {
        $uuid = (string) Str::uuid();
        $data = ['head' => 'material', 'entry_date' => '2026-11-05', 'amount' => '10', 'client_uuid' => $uuid];

        $first = $this->budget()->addCost($this->project, $data, $this->owner);
        $second = $this->budget()->addCost($this->project, $data, $this->owner);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PlanCostEntry::count());
    }

    public function test_actuals_add_up_from_every_source(): void
    {
        $this->budget()->addCost($this->project, ['head' => 'material', 'entry_date' => '2026-11-05', 'amount' => '1000.10'], $this->owner);

        $wallet = PettyCashWallet::create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'name' => 'Site']);

        foreach ([['Materials', '200.20', 'expense'], ['Fuel', '300.30', 'expense'], ['Tea stall', '40.05', 'expense'], ['Labour', '50.00', 'expense'], ['', '9999.00', 'topup']] as [$category, $amount, $type]) {
            $wallet->entries()->create([
                'organization_id' => $this->organization->id,
                'type' => $type,
                'amount' => $amount,
                'category' => $category,
                'entry_date' => '2026-11-05',
            ]);
        }

        $own = $this->worker('own', '800.00');
        $contract = $this->worker('contractor', '700.00');
        $this->attendance($own, '2026-11-01', 'present');
        $this->attendance($own, '2026-11-02', 'half');
        $this->attendance($own, '2026-11-03', 'overtime', '4');
        $this->attendance($own, '2026-11-04', 'absent');
        $this->attendance($contract, '2026-11-01', 'present');

        $this->bill('subcontractor', 'APPROVED', '5000.00', 1);
        $this->bill('subcontractor', 'PAID', '2500.50', 2);
        $this->bill('subcontractor', 'DRAFT', '9000.00', 3);
        $this->bill('client', 'APPROVED', '70000.00', 4);

        $this->assertSame('1200.30', $this->row('material')['actual']);
        $this->assertSame('300.30', $this->row('plant')['actual']);
        $this->assertSame('40.05', $this->row('other')['actual']);
        // 1 + 0.5 + 1.5 days at 800, plus 50 of petty cash.
        $this->assertSame('2450.00', $this->row('labour')['actual']);
        $this->assertSame('7500.50', $this->row('subcontract')['actual']);
    }

    public function test_variance_is_against_the_budget_until_a_baseline_exists(): void
    {
        $this->budget()->saveLine($this->project, ['head' => 'material', 'amount' => '1000']);
        $this->budget()->addCost($this->project, ['head' => 'material', 'entry_date' => '2026-11-05', 'amount' => '1250'], $this->owner);

        $row = $this->row('material');
        $this->assertNull($row['baseline']);
        $this->assertSame('-250.00', $row['variance']);
        $this->assertSame('125.00', $row['used_pct']);
        $this->assertTrue($row['over']);

        $this->schedule()->takeBaseline($this->project, $this->owner);
        $this->budget()->saveLine($this->project, ['head' => 'material', 'amount' => '2000']);

        $row = $this->row('material');
        $this->assertSame('1000.00', $row['baseline']);
        $this->assertSame('2000.00', $row['budget']);
        $this->assertSame('-250.00', $row['variance']);
    }

    public function test_totals_add_every_head_exactly(): void
    {
        $this->budget()->saveLine($this->project, ['head' => 'material', 'amount' => '0.10']);
        $this->budget()->saveLine($this->project, ['head' => 'labour', 'amount' => '0.20']);
        $this->budget()->addCost($this->project, ['head' => 'plant', 'entry_date' => '2026-11-05', 'amount' => '0.05'], $this->owner);

        $totals = $this->budget()->report($this->project)['totals'];

        $this->assertSame('0.30', $totals['budget']);
        $this->assertSame('0.05', $totals['actual']);
        $this->assertSame('0.25', $totals['variance']);
        $this->assertSame('16.67', $totals['used_pct']);
        $this->assertTrue(Dec::of($totals['variance'])->isPositive());
    }

    public function test_a_party_free_project_with_no_budget_reports_zeroes(): void
    {
        $this->assertSame(0, Party::count());

        $report = $this->budget()->report($this->project);

        $this->assertCount(6, $report['rows']);
        $this->assertSame('0.00', $report['totals']['actual']);
        $this->assertNull($report['totals']['used_pct']);
        $this->assertFalse($report['has_baseline']);
    }
}
