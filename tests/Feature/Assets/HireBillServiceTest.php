<?php

namespace Tests\Feature\Assets;

use App\Exceptions\ImmutableRecordException;
use App\Models\Asset;
use App\Models\AssetHireBill;
use App\Models\MachineLog;
use App\Services\Assets\HireBillService;
use App\Services\Assets\PlantLogService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\FieldOps\FieldOpsTestCase;

/** The hire bill figures are worked by hand here, so a wrong rounding rule shows up as a wrong rupee. */
class HireBillServiceTest extends FieldOpsTestCase
{
    private object $w;

    private HireBillService $bills;

    private PlantLogService $logs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = $this->world();
        $this->actingAs($this->w->owner);
        $this->bills = app(HireBillService::class);
        $this->logs = app(PlantLogService::class);
    }

    private function log(Asset $asset, string $date, array $extra = []): MachineLog
    {
        return $this->logs->record(['asset_id' => $asset->id, 'log_date' => $date, ...$extra], $this->w->owner);
    }

    private function generate(Asset $asset, string $from, string $to, array $extra = [])
    {
        return $this->bills->generate(['asset_id' => $asset->id, 'period_from' => $from, 'period_to' => $to, ...$extra], $this->w->owner);
    }

    public function test_per_hour_bill_with_gst_and_tds(): void
    {
        $asset = $this->hiredAsset($this->w->owner, ['hire_basis' => 'hour', 'hire_rate' => '1200']);
        $this->log($asset, '2026-09-01', ['hours' => 8]);
        $this->log($asset, '2026-09-02', ['hours' => 9.5]);

        $calc = $this->bills->calculate($asset, '2026-09-01', '2026-09-30', ['gst_pct' => 18, 'tds_pct' => 2]);

        $this->assertSame('17.500', $calc['quantity']);
        $this->assertSame('21000.00', $calc['gross_amount']);
        $this->assertSame('3780.00', $calc['gst_amount']);
        $this->assertSame('420.00', $calc['tds_amount']);
        $this->assertSame('24360.00', $calc['net_payable']);
    }

    public function test_monthly_minimum_hours_is_charged_for_the_days_the_machine_was_available(): void
    {
        $asset = $this->hiredAsset($this->w->owner, ['hire_basis' => 'hour', 'hire_rate' => '1200', 'min_hours_per_month' => '200']);
        $this->log($asset, '2026-09-01', ['hours' => 8]);
        $this->log($asset, '2026-09-03', ['hours' => 0, 'breakdown_hours' => 6]); // a down day

        $calc = $this->bills->calculate($asset, '2026-09-01', '2026-09-30');

        // September has 30 days and one was lost: 200 x 29/30 = 193.333 hours.
        $this->assertSame('8.000', $calc['actual_quantity']);
        $this->assertSame('193.333', $calc['minimum_quantity']);
        $this->assertSame('193.333', $calc['quantity']);
        $this->assertSame(1, $calc['down_days']);
        $this->assertSame('231999.60', $calc['gross_amount']);
    }

    public function test_work_above_the_minimum_is_billed_as_worked(): void
    {
        $asset = $this->hiredAsset($this->w->owner, ['hire_basis' => 'hour', 'hire_rate' => '100', 'min_hours_per_month' => '10']);
        $this->log($asset, '2026-09-01', ['hours' => 12]);

        $calc = $this->bills->calculate($asset, '2026-09-01', '2026-09-30');

        $this->assertSame('12.000', $calc['quantity']);
        $this->assertSame('1200.00', $calc['gross_amount']);
    }

    public function test_per_day_bill_counts_days_with_work_not_logs(): void
    {
        $asset = $this->hiredAsset($this->w->owner, ['hire_basis' => 'day', 'hire_rate' => '5000']);
        $this->log($asset, '2026-09-01', ['hours' => 6]);
        $this->log($asset, '2026-09-01', ['hours' => 4]); // second shift, same day
        $this->log($asset, '2026-09-02', ['hours' => 8]);
        $this->log($asset, '2026-09-03', ['hours' => 0, 'breakdown_hours' => 8]); // no work
        $this->log($asset, '2026-09-04', ['hours' => 1]);

        $calc = $this->bills->calculate($asset, '2026-09-01', '2026-09-30');

        $this->assertSame('3.000', $calc['quantity']);
        $this->assertSame('15000.00', $calc['gross_amount']);
    }

    public function test_per_km_bill(): void
    {
        $asset = $this->hiredAsset($this->w->owner, ['hire_basis' => 'km', 'hire_rate' => '18', 'meter_unit' => 'km']);
        $this->log($asset, '2026-09-01', ['km' => 120.5]);
        $this->log($asset, '2026-09-02', ['opening_reading' => 1000, 'closing_reading' => 1080]); // 80 km from the meter

        $calc = $this->bills->calculate($asset, '2026-09-01', '2026-09-30');

        $this->assertSame('200.500', $calc['quantity']);
        $this->assertSame('3609.00', $calc['gross_amount']);
    }

    public function test_monthly_bill_is_one_month_for_a_whole_month_whatever_its_length(): void
    {
        $asset = $this->hiredAsset($this->w->owner, ['hire_basis' => 'month', 'hire_rate' => '60000']);

        foreach ([['2026-08-01', '2026-08-31'], ['2026-09-01', '2026-09-30'], ['2026-02-01', '2026-02-28']] as [$from, $to]) {
            $calc = $this->bills->calculate($asset, $from, $to);
            $this->assertSame('1.000', $calc['quantity'], "$from to $to");
            $this->assertSame('60000.00', $calc['gross_amount']);
        }
    }

    public function test_monthly_bill_takes_off_down_days_and_prorates_across_months(): void
    {
        $asset = $this->hiredAsset($this->w->owner, ['hire_basis' => 'month', 'hire_rate' => '60000']);

        foreach (['2026-09-10', '2026-09-11', '2026-09-12'] as $date) {
            $this->log($asset, $date, ['hours' => 0, 'breakdown_hours' => 8]);
        }

        $calc = $this->bills->calculate($asset, '2026-09-01', '2026-09-30');
        $this->assertSame('0.900', $calc['quantity']); // 27 of 30 days
        $this->assertSame('54000.00', $calc['gross_amount']);

        // The same three down days sit inside this period: 16/31 of August plus 12/30 of September.
        $across = $this->bills->calculate($asset, '2026-08-16', '2026-09-15');
        $this->assertSame('0.916', $across['quantity']);
        $this->assertSame('54960.00', $across['gross_amount']);

        // With no down days it is 16/31 + 15/30 = 0.516129 + 0.5 = 1.016 months.
        $clean = $this->hiredAsset($this->w->owner, ['hire_basis' => 'month', 'hire_rate' => '60000']);
        $whole = $this->bills->calculate($clean, '2026-08-16', '2026-09-15');
        $this->assertSame('1.016', $whole['quantity']);
        $this->assertSame('60960.00', $whole['gross_amount']);
    }

    public function test_fuel_issued_to_a_wet_hire_machine_is_taken_off_and_dry_hire_is_not(): void
    {
        $wet = $this->hiredAsset($this->w->owner, ['hire_basis' => 'hour', 'hire_rate' => '2000', 'hire_type' => 'wet']);
        $dry = $this->hiredAsset($this->w->owner, ['hire_basis' => 'hour', 'hire_rate' => '2000', 'hire_type' => 'dry']);

        foreach ([$wet, $dry] as $asset) {
            $this->log($asset, '2026-09-05', ['hours' => 10]);
            $this->logs->issueFuel(['asset_id' => $asset->id, 'issue_date' => '2026-09-05', 'litres' => 50, 'rate' => 90.5], $this->w->owner);
            $this->logs->issueFuel(['asset_id' => $asset->id, 'issue_date' => '2026-10-01', 'litres' => 99, 'rate' => 90], $this->w->owner); // outside the period
        }

        $wetBill = $this->bills->calculate($wet, '2026-09-01', '2026-09-30');
        $this->assertSame('20000.00', $wetBill['gross_amount']);
        $this->assertSame('50.000', $wetBill['fuel_litres']);
        $this->assertSame('4525.00', $wetBill['fuel_recovery']);
        $this->assertSame('15475.00', $wetBill['net_payable']);

        $dryBill = $this->bills->calculate($dry, '2026-09-01', '2026-09-30');
        $this->assertSame('0.00', $dryBill['fuel_recovery']);
        $this->assertSame('20000.00', $dryBill['net_payable']);
    }

    public function test_gst_and_tds_are_worked_on_the_gross_and_other_deductions_come_off_last(): void
    {
        $asset = $this->hiredAsset($this->w->owner, ['hire_basis' => 'hour', 'hire_rate' => '1000']);
        $this->log($asset, '2026-09-01', ['hours' => 10]);

        $calc = $this->bills->calculate($asset, '2026-09-01', '2026-09-30', ['gst_pct' => 18, 'tds_pct' => 1, 'other_deduction' => 500]);

        // 10,000 + 1,800 GST - 100 TDS - 500 other.
        $this->assertSame('11200.00', $calc['net_payable']);
    }

    public function test_deductions_larger_than_the_bill_are_refused(): void
    {
        $asset = $this->hiredAsset($this->w->owner, ['hire_basis' => 'hour', 'hire_rate' => '100']);
        $this->log($asset, '2026-09-01', ['hours' => 1]);

        $this->expectException(ValidationException::class);

        $this->bills->calculate($asset, '2026-09-01', '2026-09-30', ['other_deduction' => 101]);
    }

    public function test_nothing_to_bill_is_an_error(): void
    {
        $asset = $this->hiredAsset($this->w->owner, ['hire_basis' => 'hour', 'hire_rate' => '100']);

        $this->expectException(ValidationException::class);

        $this->bills->calculate($asset, '2026-09-01', '2026-09-30');
    }

    public function test_only_hired_assets_with_a_rate_can_be_billed(): void
    {
        $owned = $this->ownedAsset($this->w->owner);

        $this->expectException(ValidationException::class);

        $this->bills->calculate($owned, '2026-09-01', '2026-09-30');
    }

    public function test_generating_takes_the_logs_and_stops_a_log_being_billed_twice(): void
    {
        $asset = $this->hiredAsset($this->w->owner, ['hire_basis' => 'hour', 'hire_rate' => '1000']);
        $first = $this->log($asset, '2026-09-01', ['hours' => 5]);
        $this->log($asset, '2026-10-01', ['hours' => 7]);

        $bill = $this->generate($asset, '2026-09-01', '2026-09-30');

        $this->assertSame('HB-0001', $bill->number);
        $this->assertSame('DRAFT', $bill->status);
        $this->assertSame('5000.00', $bill->net_payable);
        $this->assertSame($bill->id, $first->refresh()->hire_bill_id);
        $this->assertSame(1, MachineLog::whereNull('hire_bill_id')->count(), 'October stays unbilled.');

        try {
            $this->generate($asset, '2026-09-15', '2026-10-31');
            $this->fail('An overlapping bill must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('HB-0001', $e->errors()['period_from'][0]);
        }

        $second = $this->generate($asset, '2026-10-01', '2026-10-31');
        $this->assertSame('HB-0002', $second->number);
        $this->assertSame('7000.00', $second->net_payable);
    }

    public function test_a_billed_log_cannot_be_deleted_and_the_billed_days_take_no_new_entries(): void
    {
        $asset = $this->hiredAsset($this->w->owner, ['hire_basis' => 'hour', 'hire_rate' => '1000']);
        $log = $this->log($asset, '2026-09-01', ['hours' => 5]);
        $this->generate($asset, '2026-09-01', '2026-09-30');

        try {
            $this->logs->deleteLog($log->refresh());
            $this->fail('Expected a 409.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->expectException(ValidationException::class);
        $this->log($asset, '2026-09-20', ['hours' => 3]);
    }

    public function test_cancelling_frees_the_logs_to_be_billed_again(): void
    {
        $asset = $this->hiredAsset($this->w->owner, ['hire_basis' => 'hour', 'hire_rate' => '1000']);
        $log = $this->log($asset, '2026-09-01', ['hours' => 5]);
        $bill = $this->generate($asset, '2026-09-01', '2026-09-30');

        $this->bills->cancel($bill, 'Wrong rate');

        $this->assertSame('CANCELLED', $bill->refresh()->status);
        $this->assertNull($log->refresh()->hire_bill_id);

        $again = $this->generate($asset, '2026-09-01', '2026-09-30');
        $this->assertSame($again->id, $log->refresh()->hire_bill_id);
    }

    public function test_status_moves_one_way_and_a_final_bill_is_locked(): void
    {
        $asset = $this->hiredAsset($this->w->owner, ['hire_basis' => 'hour', 'hire_rate' => '1000']);
        $this->log($asset, '2026-09-01', ['hours' => 5]);
        $bill = $this->generate($asset, '2026-09-01', '2026-09-30');

        $this->assertStatus(409, fn () => $this->bills->markPaid($bill, ['paid_on' => '2026-10-05']));

        $this->bills->finalize($bill);
        $this->assertSame('FINAL', $bill->refresh()->status);
        $this->assertStatus(409, fn () => $this->bills->finalize($bill));

        try {
            $bill->update(['gross_amount' => '1.00']);
            $this->fail('A final bill must not change.');
        } catch (ImmutableRecordException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $bill->refresh(); // drop the rejected edit from the in-memory copy
        $this->bills->markPaid($bill, ['paid_on' => '2026-10-05', 'payment_reference' => 'UTR123']);
        $this->assertSame('PAID', $bill->refresh()->status);
        $this->assertStatus(409, fn () => $this->bills->cancel($bill, 'Too late'));
    }

    public function test_creating_twice_with_the_same_client_uuid_gives_one_bill(): void
    {
        $asset = $this->hiredAsset($this->w->owner, ['hire_basis' => 'hour', 'hire_rate' => '1000']);
        $this->log($asset, '2026-09-01', ['hours' => 5]);
        $uuid = 'f3b1f0a4-6a53-4f0c-9c3f-1c2b3d4e5f60';

        $first = $this->generate($asset, '2026-09-01', '2026-09-30', ['client_uuid' => $uuid]);
        $second = $this->generate($asset, '2026-09-01', '2026-09-30', ['client_uuid' => $uuid]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AssetHireBill::count());
    }

    private function assertStatus(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected HTTP $status.");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }
}
