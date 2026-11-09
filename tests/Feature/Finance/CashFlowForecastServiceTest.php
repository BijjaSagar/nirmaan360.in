<?php

namespace Tests\Feature\Finance;

use App\Services\Finance\CashFlowForecastService;
use App\Services\Finance\VendorBillService;
use App\Support\Dec;
use Brick\Math\BigDecimal;

class CashFlowForecastServiceTest extends FinanceTestCase
{
    private function forecast(int $weeks = 12, ?int $creditDays = 30, ?int $projectId = null): array
    {
        return app(CashFlowForecastService::class)->forecast($this->organization->id, now(), $weeks, $creditDays, $projectId);
    }

    private function bucket(array $forecast, string $from): array
    {
        return collect($forecast['rows'])->firstWhere('from', $from);
    }

    private function scenario(): void
    {
        // Client bill approved 20 Nov, due 20 Dec. Net 2,77,500, of which 1,00,000 has come in.
        $this->receive($this->approvedClientBill(), '100000', '2000');
        // Vendor bill net 1,17,000, due 10 Nov, so overdue. 17,000 has been paid.
        $this->pay($this->approvedVendorBill(['bill_date' => '2026-11-01', 'due_date' => '2026-11-10']), '17000');
        $this->petty($this->wallet(), 'topup', '3000');
    }

    public function test_forecast_matches_a_worked_example(): void
    {
        $this->scenario();

        $forecast = $this->forecast();

        // Received 1,00,000 less paid 17,000 less top-up 3,000.
        $this->assertSame('80000.00', $forecast['opening']);
        $this->assertSame('177500.00', $forecast['total_in']);
        $this->assertSame('100000.00', $forecast['total_out']);
        $this->assertSame('157500.00', $forecast['closing']);

        $overdue = $forecast['rows'][0];
        $this->assertSame('overdue', $overdue['key']);
        $this->assertSame('100000.00', $overdue['out']);
        $this->assertSame('-20000.00', $overdue['closing']);

        // 20 Dec is 30 days out, so it falls in the week starting 18 Dec.
        $week = $this->bucket($forecast, '2026-12-18');
        $this->assertSame('177500.00', $week['in']);
        $this->assertSame('157500.00', $week['closing']);
    }

    public function test_closing_equals_opening_plus_in_less_out_and_rows_sum_to_totals(): void
    {
        $this->scenario();
        $this->approvedSubBill();

        $forecast = $this->forecast();
        $in = BigDecimal::zero();
        $out = BigDecimal::zero();
        $running = Dec::of($forecast['opening']);

        foreach ($forecast['rows'] as $row) {
            $in = $in->plus(Dec::of($row['in']));
            $out = $out->plus(Dec::of($row['out']));
            $running = $running->plus(Dec::of($row['in']))->minus(Dec::of($row['out']));
            $this->assertSame(Dec::money($running), $row['closing']);
            $this->assertSame(Dec::money(Dec::of($row['in'])->minus(Dec::of($row['out']))), $row['net']);
        }

        $this->assertSame($forecast['total_in'], Dec::money($in));
        $this->assertSame($forecast['total_out'], Dec::money($out));
        $this->assertSame($forecast['closing'], Dec::money($running));
    }

    public function test_every_due_bill_is_in_a_bucket_even_beyond_the_horizon(): void
    {
        $this->approvedVendorBill(['due_date' => '2027-09-01']);

        $forecast = $this->forecast(weeks: 4);

        $this->assertSame('117000.00', $forecast['rows'][array_key_last($forecast['rows'])]['out']);
        $this->assertSame('later', $forecast['rows'][array_key_last($forecast['rows'])]['key']);
        $this->assertSame('117000.00', $forecast['total_out']);
    }

    public function test_a_bill_due_today_falls_in_the_first_week(): void
    {
        $this->approvedVendorBill(['bill_date' => '2026-11-01', 'due_date' => '2026-11-20']);

        $this->assertSame('117000.00', $this->bucket($this->forecast(), '2026-11-20')['out']);
    }

    public function test_bill_with_no_due_date_uses_the_credit_days(): void
    {
        $this->approvedVendorBill(['bill_date' => '2026-11-05', 'due_date' => null]);

        // 5 Nov + 21 days = 26 Nov, in the week starting 20 Nov.
        $this->assertSame('117000.00', $this->bucket($this->forecast(creditDays: 21), '2026-11-20')['out']);
    }

    public function test_settled_reversed_and_draft_bills_are_left_out(): void
    {
        $paid = $this->approvedVendorBill(['bill_no' => 'PAID']);
        $this->pay($paid, '117000');
        app(VendorBillService::class)->reverse($this->approvedVendorBill(['bill_no' => 'REV']), 'Wrong', $this->owner);
        $this->draftVendorBill(['bill_no' => 'DRAFT']);

        $forecast = $this->forecast();

        $this->assertSame('0.00', $forecast['total_out']);
        $this->assertSame([], $forecast['items']);
    }

    public function test_it_can_be_limited_to_one_project(): void
    {
        $this->approvedVendorBill();
        $other = $this->projectIn('Maharashtra', $this->client);

        $this->assertSame('117000.00', $this->forecast(projectId: $this->project->id)['total_out']);
        $this->assertSame('0.00', $this->forecast(projectId: $other->id)['total_out']);
    }

    public function test_another_company_is_not_included(): void
    {
        $this->approvedVendorBill();
        $other = $this->tenant('Other');

        $this->assertSame('0.00', app(CashFlowForecastService::class)->forecast($other->id, now())['total_out']);
    }
}
