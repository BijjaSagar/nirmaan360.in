<?php

namespace Tests\Feature\Reports;

use App\Models\Attendance;
use App\Models\BoqItem;
use App\Models\Party;
use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\RaBill;
use App\Models\Worker;
use App\Services\DprService;
use App\Services\RaBillService;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\ReportResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ReportBuilderTest extends ReportsTestCase
{
    private ReportBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = app(ReportBuilder::class);
    }

    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    private function row(ReportResult $report, string $key, string $value): array
    {
        return collect($report->rows)->firstWhere($key, $value) ?? $this->fail("No row with {$key} = {$value}");
    }

    public function test_boq_ledger_shows_executed_measured_approved_billed_and_balance(): void
    {
        $project = $this->makeProject($this->org, ['code' => 'RT1', 'name' => 'Riverside Tower']);
        $item = $this->makeBoqItem($project, '100.000', '5000.00');
        $idle = BoqItem::create(['organization_id' => $this->org->id, 'project_id' => $project->id, 'code' => 'IDLE', 'description' => 'Untouched', 'unit' => 'nos', 'qty' => '3', 'rate' => '10.50', 'sort' => 99]);

        // Executed on site: 30.
        app(DprService::class)->create($project, ['report_date' => '2026-11-02', 'work' => [['boq_item_id' => $item->id, 'qty' => '30']]], $this->pm);
        // Measured and approved: 2 x 10 x 5 x 0.5 = 50, then billed. A draft of 10 counts as measured only.
        $approved = $this->approveEntry($this->draftEntry($project, $item, $this->pm), $this->pm, $this->owner);
        $this->draftEntry($project, $item, $this->pm, ['nos' => 1, 'length' => 10, 'breadth' => 1, 'depth' => 1]);
        $rejected = $this->draftEntry($project, $item, $this->pm, ['nos' => 1, 'length' => 99, 'breadth' => 1, 'depth' => 1]);
        $rejected->forceFill(['status' => 'REJECTED'])->save();
        app(RaBillService::class)->generate($project, 'client', '2026-11-01', '2026-11-30', $this->pm);

        $report = $this->builder->build('boq_ledger', $this->org, $project->id);

        $this->assertSame('BOQ ledger', $report->title);
        $this->assertSame('RT1 · Riverside Tower', $report->subtitle);
        $row = $this->row($report, 'code', $item->code);
        $this->assertSame(
            ['rate' => '5000.00', 'qty' => '100.000', 'executed' => '30.000', 'measured' => '60.000', 'approved' => '50.000', 'billed' => '50.000', 'balance' => '40.000', 'amount' => '500000.00', 'approved_amount' => '250000.00', 'billed_amount' => '250000.00'],
            collect($row)->only(['rate', 'qty', 'executed', 'measured', 'approved', 'billed', 'balance', 'amount', 'approved_amount', 'billed_amount'])->all(),
        );
        $this->assertSame('0.000', $this->row($report, 'code', 'IDLE')['approved']);
        $this->assertSame('31.50', $this->row($report, 'code', 'IDLE')['amount']);

        // 500000.00 + 31.50, exact.
        $this->assertSame(['amount' => '500031.50', 'approved_amount' => '250000.00', 'billed_amount' => '250000.00'], $report->totals);
        $this->assertSame($approved->qty, '50.000');
    }

    public function test_boq_ledger_needs_a_project_and_only_reads_this_company(): void
    {
        $project = $this->makeProject($this->org);
        $other = $this->tenant('Other Co', 'business');
        $theirs = $this->makeProject($other);
        $this->makeBoqItem($theirs);

        try {
            $this->builder->build('boq_ledger', $this->org, null);
            $this->fail('A project should be required');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('project_id', $exception->errors());
        }

        $this->expectException(ModelNotFoundException::class);
        $this->builder->build('boq_ledger', $this->org, $theirs->id);
    }

    public function test_boq_ledger_leaves_out_deleted_lines(): void
    {
        $project = $this->makeProject($this->org);
        $keep = $this->makeBoqItem($project);
        $this->makeBoqItem($project)->delete();

        $report = $this->builder->build('boq_ledger', $this->org, $project->id);

        $this->assertSame([$keep->code], array_column($report->rows, 'code'));
    }

    public function test_measurement_register_filters_by_period_project_and_status(): void
    {
        $one = $this->makeProject($this->org, ['code' => 'P1']);
        $two = $this->makeProject($this->org, ['code' => 'P2']);
        $a = $this->makeBoqItem($one);
        $b = $this->makeBoqItem($two);

        $this->draftEntry($one, $a, $this->pm, ['measured_on' => '2026-10-31']);
        $this->draftEntry($one, $a, $this->pm, ['measured_on' => '2026-11-01', 'location' => 'Level 1']);
        $this->approveEntry($this->draftEntry($one, $a, $this->pm, ['measured_on' => '2026-11-30', 'location' => 'Level 2']), $this->pm, $this->owner);
        $this->draftEntry($two, $b, $this->pm, ['measured_on' => '2026-11-15', 'location' => 'Basement']);
        $this->draftEntry($one, $a, $this->pm, ['measured_on' => '2026-12-01']);

        $all = $this->builder->build('measurement_register', $this->org, null, $this->day('2026-11-01'), $this->day('2026-11-30'));
        $this->assertSame(['Level 1', 'Basement', 'Level 2'], array_column($all->rows, 'location'));
        $this->assertContains('project', array_column($all->columns, 'key'));
        $this->assertSame('All projects · 01 Nov 2026 to 30 Nov 2026', $all->subtitle);

        $project = $this->builder->build('measurement_register', $this->org, $one->id, $this->day('2026-11-01'), $this->day('2026-11-30'));
        $this->assertSame(['Level 1', 'Level 2'], array_column($project->rows, 'location'));
        $this->assertNotContains('project', array_column($project->columns, 'key'));

        $approved = $this->builder->build('measurement_register', $this->org, null, $this->day('2026-11-01'), $this->day('2026-11-30'), 'APPROVED');
        $this->assertSame(['Level 2'], array_column($approved->rows, 'location'));
        $this->assertStringContainsString('Approved', $approved->subtitle);
        $this->assertSame('50.000', $approved->rows[0]['qty']);
        $this->assertSame('No', $approved->rows[0]['billed']);

        // A status that does not belong to this report is ignored rather than hiding everything.
        $this->assertCount(3, $this->builder->build('measurement_register', $this->org, null, $this->day('2026-11-01'), $this->day('2026-11-30'), 'PAID')->rows);
    }

    public function test_ra_bill_register_lists_bills_and_totals_exclude_cancelled(): void
    {
        $project = $this->makeProject($this->org, ['code' => 'P1']);
        $party = Party::create(['organization_id' => $this->org->id, 'type' => 'client', 'name' => 'City Authority']);

        $bill = fn (string $number, string $type, string $from, string $to, string $status, array $money) => RaBill::create([
            'organization_id' => $this->org->id, 'project_id' => $project->id, 'party_id' => $party->id, 'type' => $type, 'bill_no' => 1,
            'number' => $number, 'period_from' => $from, 'period_to' => $to, 'status' => $status,
            'gross_amount' => $money[0], 'deduction_total' => $money[1], 'gst_amount' => $money[2], 'net_payable' => $money[3],
            'paid_at' => $status === 'PAID' ? '2026-11-20 10:00:00' : null,
        ]);

        $bill('B-OCT', 'client', '2026-10-01', '2026-10-31', 'APPROVED', ['100000.10', '5000.05', '17100.01', '112100.06']);
        $bill('B-NOV', 'client', '2026-11-01', '2026-11-30', 'PAID', ['200000.20', '10000.02', '34200.04', '224200.22']);
        $bill('B-SUB', 'subcontractor', '2026-11-01', '2026-11-15', 'CANCELLED', ['999.00', '0.00', '0.00', '999.00']);

        $report = $this->builder->build('ra_bill_register', $this->org, null, $this->day('2026-10-15'), $this->day('2026-11-05'));

        // Oldest billing period first.
        $this->assertSame(['B-OCT', 'B-SUB', 'B-NOV'], array_column($report->rows, 'number'));
        $this->assertSame('Client', $report->rows[0]['type']);
        $this->assertSame('Subcontractor', $report->rows[1]['type']);
        $this->assertSame('Cancelled', $report->rows[1]['status']);
        $this->assertSame('City Authority', $report->rows[0]['party']);
        $this->assertSame('2026-11-20', $report->rows[2]['paid_on']);
        // The cancelled bill is listed but not added: 100000.10 + 200000.20 and so on, to the paisa.
        $this->assertSame(['gross' => '300000.30', 'deductions' => '15000.07', 'gst' => '51300.05', 'net' => '336300.28'], $report->totals);

        $november = $this->builder->build('ra_bill_register', $this->org, null, $this->day('2026-11-01'), $this->day('2026-11-30'));
        $this->assertSame(['B-SUB', 'B-NOV'], array_column($november->rows, 'number'));

        $paid = $this->builder->build('ra_bill_register', $this->org, null, $this->day('2026-10-01'), $this->day('2026-11-30'), 'PAID');
        $this->assertSame(['B-NOV'], array_column($paid->rows, 'number'));
    }

    public function test_attendance_summary_counts_days_ot_and_wages(): void
    {
        $project = $this->makeProject($this->org);
        $mason = Worker::create(['organization_id' => $this->org->id, 'name' => 'Ramesh', 'trade' => 'Mason', 'type' => 'own', 'daily_wage' => '650.50']);
        $contractor = Party::create(['organization_id' => $this->org->id, 'type' => 'subcontractor', 'name' => 'Sharma Labour']);
        $helper = Worker::create(['organization_id' => $this->org->id, 'name' => 'Anil', 'trade' => 'Helper', 'type' => 'contract', 'contractor_id' => $contractor->id, 'daily_wage' => '400.00']);

        $mark = fn (Worker $worker, string $date, string $status, string $ot = '0') => Attendance::create([
            'organization_id' => $this->org->id, 'project_id' => $project->id, 'worker_id' => $worker->id, 'attendance_date' => $date, 'status' => $status, 'ot_hours' => $ot,
        ]);

        foreach (['2026-11-02', '2026-11-03', '2026-11-04'] as $date) {
            $mark($mason, $date, 'present');
        }
        $mark($mason, '2026-11-05', 'overtime', '2.5');
        $mark($mason, '2026-11-06', 'half');
        $mark($mason, '2026-11-07', 'half', '1');
        $mark($mason, '2026-11-08', 'absent');
        $mark($mason, '2026-10-31', 'present');   // before the period
        $mark($helper, '2026-11-02', 'present');

        $report = $this->builder->build('attendance_summary', $this->org, $project->id, $this->day('2026-11-01'), $this->day('2026-11-30'));

        $this->assertSame(['Anil', 'Ramesh'], array_column($report->rows, 'worker'));
        $this->assertEquals(
            ['present' => 4, 'half' => 2, 'absent' => 1, 'ot' => '3.5', 'days' => '5.0', 'wage' => '650.50', 'wages' => '3252.50', 'type' => 'Own'],
            collect($this->row($report, 'worker', 'Ramesh'))->only(['present', 'half', 'absent', 'ot', 'days', 'wage', 'wages', 'type'])->all(),
        );
        $this->assertSame('Sharma Labour', $this->row($report, 'worker', 'Anil')['type']);
        $this->assertSame('400.00', $this->row($report, 'worker', 'Anil')['wages']);
        $this->assertSame(['present' => 5, 'half' => 2, 'absent' => 1, 'ot' => '3.5', 'days' => '6.0', 'wages' => '3652.50'], $report->totals);
    }

    public function test_petty_cash_shows_opening_movement_and_closing_for_each_wallet(): void
    {
        $project = $this->makeProject($this->org, ['code' => 'P1']);
        $wallet = PettyCashWallet::create(['organization_id' => $this->org->id, 'project_id' => $project->id, 'user_id' => $this->pm->id, 'name' => 'Site cash']);
        PettyCashWallet::create(['organization_id' => $this->org->id, 'project_id' => $project->id, 'name' => 'Never used']);

        $entry = fn (string $type, string $amount, string $date) => PettyCashEntry::create([
            'organization_id' => $this->org->id, 'petty_cash_wallet_id' => $wallet->id, 'type' => $type, 'amount' => $amount, 'entry_date' => $date, 'category' => $type === 'expense' ? 'Fuel' : null,
        ]);

        $entry('topup', '5000.00', '2026-10-20');
        $entry('expense', '1200.50', '2026-10-25');
        $entry('topup', '2000.00', '2026-11-03');
        $entry('expense', '750.25', '2026-11-10');
        $entry('expense', '100.00', '2026-12-05');   // after the period

        $report = $this->builder->build('petty_cash', $this->org, null, $this->day('2026-11-01'), $this->day('2026-11-30'));

        $this->assertCount(1, $report->rows);
        $this->assertSame(
            ['project' => 'P1', 'wallet' => 'Site cash', 'holder' => $this->pm->name, 'opening' => '3799.50', 'topups' => '2000.00', 'expenses' => '750.25', 'closing' => '5049.25'],
            $report->rows[0],
        );
        $this->assertSame(['opening' => '3799.50', 'topups' => '2000.00', 'expenses' => '750.25', 'closing' => '5049.25'], $report->totals);

        // A period that ends before anything happened has nothing to show.
        $this->assertSame([], $this->builder->build('petty_cash', $this->org, null, $this->day('2026-09-01'), $this->day('2026-09-30'))->rows);
    }

    public function test_every_report_reads_only_the_named_company_even_with_nobody_signed_in(): void
    {
        $mine = $this->makeProject($this->org, ['code' => 'MINE']);
        $this->makeBoqItem($mine);
        $other = $this->tenant('Other Co', 'business');
        $theirs = $this->makeProject($other, ['code' => 'THEIRS']);
        $theirItem = $this->makeBoqItem($theirs);
        $this->draftEntry($theirs, $theirItem, $this->member($other, 'pm'), ['measured_on' => '2026-11-03']);
        RaBill::create(['organization_id' => $other->id, 'project_id' => $theirs->id, 'type' => 'client', 'bill_no' => 1, 'number' => 'T-1', 'period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'status' => 'DRAFT']);
        $worker = Worker::create(['organization_id' => $other->id, 'name' => 'Their worker', 'trade' => 'Mason', 'type' => 'own', 'daily_wage' => '500']);
        Attendance::create(['organization_id' => $other->id, 'project_id' => $theirs->id, 'worker_id' => $worker->id, 'attendance_date' => '2026-11-03', 'status' => 'present']);
        $wallet = PettyCashWallet::create(['organization_id' => $other->id, 'project_id' => $theirs->id, 'name' => 'Their cash']);
        PettyCashEntry::create(['organization_id' => $other->id, 'petty_cash_wallet_id' => $wallet->id, 'type' => 'topup', 'amount' => '100', 'entry_date' => '2026-11-03']);

        // The scheduler runs like this: no signed-in user, so no automatic company filter.
        Auth::logout();
        $this->assertGuest();

        foreach (array_keys(ReportBuilder::DEFINITIONS) as $key) {
            $report = $this->builder->build($key, $this->org, $key === 'boq_ledger' ? $mine->id : null, $this->day('2026-11-01'), $this->day('2026-11-30'));
            $text = json_encode($report->rows);

            $this->assertStringNotContainsString('THEIRS', $text, $key);
            $this->assertStringNotContainsString('Their', $text, $key);
            $this->assertStringNotContainsString('T-1', $text, $key);
        }

        $this->assertCount(1, $this->builder->build('boq_ledger', $this->org, $mine->id)->rows);
        $this->assertCount(1, $this->builder->build('measurement_register', $other, null, $this->day('2026-11-01'), $this->day('2026-11-30'))->rows);
    }

    public function test_who_may_run_which_report(): void
    {
        $matrix = [
            'owner' => ['boq_ledger', 'measurement_register', 'ra_bill_register', 'attendance_summary', 'petty_cash'],
            'pm' => ['boq_ledger', 'measurement_register', 'ra_bill_register', 'attendance_summary', 'petty_cash'],
            'qs' => ['boq_ledger', 'measurement_register', 'ra_bill_register'],
            'accounts' => ['boq_ledger', 'measurement_register', 'ra_bill_register', 'petty_cash'],
            'engineer' => [],
            'store' => [],
            'viewer' => [],
        ];

        foreach ($matrix as $role => $expected) {
            $user = $this->member($this->org, $role);
            $this->assertSame($expected, array_keys($this->builder->availableFor($user, $this->org)), $role);
        }

        // A plan without the reports module, or without the data module behind a report.
        $free = $this->tenant('Small Co', 'free');
        $this->assertSame([], $this->builder->availableFor($this->member($free, 'owner'), $free));

        config(['buildos.plans.business.features' => ['site', 'measurement', 'reports']]);
        $this->assertSame(['boq_ledger', 'measurement_register'], array_keys($this->builder->availableFor($this->owner, $this->org->fresh())));
    }

    public function test_periods_resolve_to_dates(): void
    {
        $now = CarbonImmutable::parse('2026-11-09 15:30:00');

        $range = fn (string $period) => array_map(fn ($date) => $date->toDateString(), ReportPeriod::resolve($period, null, null, $now));

        $this->assertSame(['2026-11-03', '2026-11-09'], $range('last_7_days'));
        $this->assertSame(['2026-10-11', '2026-11-09'], $range('last_30_days'));
        $this->assertSame(['2026-11-01', '2026-11-09'], $range('this_month'));
        $this->assertSame(['2026-10-01', '2026-10-31'], $range('last_month'));
        // The financial year runs April to March.
        $this->assertSame(['2026-04-01', '2026-11-09'], $range('this_fy'));
        $this->assertSame(['2025-04-01', '2026-02-15'], array_map(fn ($date) => $date->toDateString(), ReportPeriod::resolve('this_fy', null, null, CarbonImmutable::parse('2026-02-15'))));
        $this->assertSame(['2026-02-01', '2026-02-28'], array_map(fn ($date) => $date->toDateString(), ReportPeriod::resolve('last_month', null, null, CarbonImmutable::parse('2026-03-31'))));
        $this->assertSame(['2026-05-05', '2026-05-20'], array_map(fn ($date) => $date->toDateString(), ReportPeriod::resolve('custom', '2026-05-05', '2026-05-20', $now)));
    }

    public function test_result_helpers(): void
    {
        $report = new ReportResult('x', 'X', 's', [['key' => 'a', 'label' => 'A', 'type' => 'text']], [['a' => 1], ['a' => 2], ['a' => 3]]);

        $this->assertSame(3, $report->rowCount());
        $this->assertFalse($report->truncated);
        $this->assertSame($report, $report->limited(3));
        $this->assertCount(2, $report->limited(2)->rows);
        $this->assertTrue($report->limited(2)->truncated);

        $this->assertSame('12,34,567.50', ReportResult::display('money', '1234567.5'));
        $this->assertSame('1,250.500', ReportResult::display('qty', '1250.5'));
        $this->assertSame('09 Nov 2026', ReportResult::display('date', '2026-11-09'));
        $this->assertSame('', ReportResult::display('money', null));
        $this->assertSame('7', ReportResult::display('int', '7'));
    }
}
