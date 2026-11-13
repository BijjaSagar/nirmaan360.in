<?php

namespace Tests\Feature\BillCheck;

use App\Models\RaBill;
use App\Models\RaBillItem;
use App\Services\BillCheck\Flag;
use App\Services\RaBillService;
use Illuminate\Support\Facades\DB;

/**
 * A1 to A4. A draft bill is worked out by the app, so these findings only appear when a stored figure
 * no longer matches. The tests change one stored figure at a time and check that exactly it is named.
 */
class ArithmeticFlagsTest extends BillCheckTestCase
{
    private function tidyBill(string $qty = '20'): RaBill
    {
        $this->measure($qty);
        $this->confirmFormPercentages();

        return $this->clientBill();
    }

    private function line(RaBill $bill): RaBillItem
    {
        return RaBillItem::withoutGlobalScopes()->where('ra_bill_id', $bill->id)->sole();
    }

    private function alterLine(RaBill $bill, array $values): void
    {
        DB::table('ra_bill_items')->where('ra_bill_id', $bill->id)->update($values);
    }

    private function alterBill(RaBill $bill, array $values): void
    {
        DB::table('ra_bills')->where('id', $bill->id)->update($values);
    }

    public function test_a_tidy_bill_gives_no_findings(): void
    {
        $bill = $this->tidyBill();

        // 20 at 2,400 is 48,000. The figures the checks work from are the bill's own.
        $this->assertSame('48000.00', $bill->gross_amount);

        $result = $this->inspect($bill);

        $this->assertSame([], $this->keys($result));
        $this->assertSame(['blocking' => 0, 'tick' => 0, 'note' => 0], $result->summary());
        $this->assertNull($result->expected);
        $this->assertSame([], $result->toAcknowledge());
    }

    public function test_a1_a_line_amount_that_is_not_quantity_times_rate(): void
    {
        $bill = $this->tidyBill();
        $line = $this->line($bill);
        $this->alterLine($bill, ['current_amount' => '48010.00']);

        $result = $this->inspect($bill);

        // The line no longer adds up, so its cumulative amount and the gross value are off as well.
        $this->assertSame(["A1:{$line->id}:amount", "A1:{$line->id}:cumulative", 'A2'], $this->keys($result));

        $flag = $this->found($result, "A1:{$line->id}:amount");
        $this->assertSame('A1.amount', $flag->code);
        $this->assertSame(Flag::BLOCKING, $flag->level);
        $this->assertSame(['item' => $this->item->code, 'qty' => '20.000', 'rate' => '2400.00', 'stored' => '48010.00', 'expected' => '48000.00'], $flag->params);

        $this->assertSame(['item' => $this->item->code, 'stored' => '48000.00', 'expected' => '48010.00'], $this->found($result, "A1:{$line->id}:cumulative")->params);
        $this->assertSame(['stored' => '48000.00', 'expected' => '48010.00'], $this->found($result, 'A2')->params);
    }

    public function test_a1_cumulative_quantity_that_is_not_previous_plus_this_bill(): void
    {
        $bill = $this->tidyBill();
        $line = $this->line($bill);
        $this->alterLine($bill, ['cumulative_qty' => '19.500']);

        $result = $this->inspect($bill);

        $this->assertSame(["A1:{$line->id}:qty"], $this->keys($result));
        $this->assertSame('A1.cum_qty', $this->found($result, "A1:{$line->id}:qty")->code);
        $this->assertSame(
            ['item' => $this->item->code, 'prev' => '0.000', 'current' => '20.000', 'stored' => '19.500', 'expected' => '20.000'],
            $this->found($result, "A1:{$line->id}:qty")->params,
        );
    }

    public function test_a1_a_lump_sum_line_stops_at_its_fixed_value(): void
    {
        // 50 at 900 is worth 45,000 in all. 60 are measured, and the bill pays for 45,000 only.
        $workOrder = $this->workOrder(['rate_type' => 'lump_sum']);
        $this->confirmFormPercentages($workOrder);
        $this->measure('60', ['work_order_id' => $workOrder->id]);
        $bill = $this->subBill($workOrder);
        $line = $this->line($bill);

        $this->assertSame('45000.00', $line->current_amount);
        $this->assertSame([], $this->keys($bill), 'The lump sum rule is the same one the bill was made with.');

        $this->alterLine($bill, ['current_amount' => '54000.00', 'cumulative_amount' => '54000.00']);
        $this->alterBill($bill, ['gross_amount' => '54000.00']);
        $flag = $this->found($bill, "A1:{$line->id}:amount");

        $this->assertSame('A1.lump', $flag->code);
        $this->assertSame(['item' => $this->item->code, 'value' => '45000.00', 'stored' => '54000.00', 'expected' => '45000.00'], $flag->params);

        // Without the work order line the fixed value is unknown, and the check says so instead of guessing.
        DB::table('work_order_items')->where('work_order_id', $workOrder->id)->delete();
        $this->assertSame('A1.lump_gone', $this->found($bill, "A1:{$line->id}:amount")->code);
    }

    public function test_a2_gross_value_that_is_not_the_total_of_the_lines(): void
    {
        $bill = $this->tidyBill();
        $this->alterBill($bill, ['gross_amount' => '48500.00']);

        $flag = $this->found($bill, 'A2');

        $this->assertSame(Flag::BLOCKING, $flag->level);
        $this->assertSame(['stored' => '48500.00', 'expected' => '48000.00'], $flag->params);
    }

    public function test_a3_every_deduction_and_total_is_worked_out_again_from_the_saved_terms(): void
    {
        $bill = $this->tidyBill();

        // 6.5 of 48,000 retention, 8 advance recovery, 1.75 TDS and 12.5 GST, as typed on the form in this test.
        $this->assertSame(['retention' => '3120.00', 'advance' => '3840.00', 'tds' => '840.00'], $bill->deductions()->pluck('amount', 'type')->all());
        $this->assertSame('6000.00', $bill->gst_amount);
        $this->assertSame('46200.00', $bill->net_payable);

        DB::table('ra_bill_deductions')->where('ra_bill_id', $bill->id)->where('type', 'retention')->update(['amount' => '3125.00']);
        $result = $this->inspect($bill);
        $this->assertSame(['A3:deduction:retention'], $this->keys($result));
        $this->assertSame(['head' => 'retention', 'stored' => '3125.00', 'expected' => '3120.00'], $this->found($result, 'A3:deduction:retention')->params);
        $this->assertSame('A3.deduction', $this->found($result, 'A3:deduction:retention')->code);

        DB::table('ra_bill_deductions')->where('ra_bill_id', $bill->id)->where('type', 'retention')->delete();
        $missing = $this->found($bill, 'A3:deduction:retention');
        $this->assertSame('A3.deduction_missing', $missing->code);
        $this->assertSame(['head' => 'retention', 'expected' => '3120.00'], $missing->params);

        DB::table('ra_bill_deductions')->where('ra_bill_id', $bill->id)->where('type', 'tds')->update(['type' => 'penalty', 'label' => 'Penalty']);
        $extra = $this->found($bill, 'A3:deduction:penalty');
        $this->assertSame('A3.deduction_extra', $extra->code);
        $this->assertSame(['head' => 'penalty', 'stored' => '840.00'], $extra->params);
        $this->assertSame('A3.deduction_missing', $this->found($bill, 'A3:deduction:tds')->code);
    }

    public function test_a3_the_totals_of_the_bill(): void
    {
        $bill = $this->tidyBill();

        $this->alterBill($bill, ['deduction_total' => '7900.00']);
        $this->assertSame(['A3:total'], $this->keys($bill));
        $this->assertSame(['stored' => '7900.00', 'expected' => '7800.00'], $this->found($bill, 'A3:total')->params);

        $this->alterBill($bill, ['deduction_total' => '7800.00', 'gst_amount' => '6100.00']);
        $this->assertSame(['A3:gst'], $this->keys($bill));
        $this->assertSame(['stored' => '6100.00', 'expected' => '6000.00'], $this->found($bill, 'A3:gst')->params);

        $this->alterBill($bill, ['gst_amount' => '6000.00', 'net_payable' => '46000.00']);
        $this->assertSame(['A3:net'], $this->keys($bill));
        $this->assertSame(['stored' => '46000.00', 'expected' => '46200.00'], $this->found($bill, 'A3:net')->params);

        // A running bill releases no retention.
        $this->alterBill($bill, ['net_payable' => '46200.00', 'retention_released' => '50.00']);
        $this->assertSame(['A3:released'], $this->keys($bill));
        $this->assertSame(['stored' => '50.00', 'expected' => '0.00'], $this->found($bill, 'A3:released')->params);
    }

    public function test_a3_a_final_bill_releases_what_the_earlier_bills_hold(): void
    {
        $this->confirmFormPercentages();
        $this->measure('20');
        $first = $this->approveBill($this->clientBill());
        $this->measure('30');
        $final = $this->finalClientBill();

        $this->assertSame('3120.00', $final->retention_released);
        $this->assertNotFound($final, 'A3');

        DB::table('ra_bills')->where('id', $final->id)->update(['retention_released' => '3000.00']);

        $flag = $this->found($final, 'A3:released');
        $this->assertSame(Flag::BLOCKING, $flag->level);
        $this->assertSame(['stored' => '3000.00', 'expected' => '3120.00'], $flag->params);
        $this->assertNotFound($final, 'A3:net');

        // The earlier bill is locked once approved, so the released figure can only be wrong on the final bill itself.
        $this->assertSame('APPROVED', $first->status);
    }

    public function test_a3_saved_terms_the_engine_cannot_work_out(): void
    {
        $bill = $this->tidyBill();
        $this->alterBill($bill, ['terms' => json_encode([...$bill->terms, 'order' => ['retention', 'mystery_head']])]);

        $result = $this->inspect($bill);

        $this->assertContains('A3:terms', $this->keys($result));
        $this->assertSame(Flag::BLOCKING, $this->found($result, 'A3:terms')->level);
        $this->assertNotFound($result, 'A3:deduction');
    }

    public function test_a4_net_payable_below_zero(): void
    {
        // A final bill recovers the whole advance. Here that is more than the little work billed.
        $this->confirmFormPercentages();
        $this->measure('2');
        $bill = $this->finalClientBill();

        $this->assertTrue(str_starts_with($bill->net_payable, '-'));

        $flag = $this->found($bill, 'A4');
        $this->assertSame(Flag::BLOCKING, $flag->level);
        $this->assertSame(['net' => $bill->net_payable], $flag->params);
        $this->assertNotFound($bill, 'A3');

        // Not below zero, no finding.
        $this->assertNotFound($this->tidyBillOnAnotherProject(), 'A4');
    }

    private function tidyBillOnAnotherProject(): RaBill
    {
        $project = $this->makeProject($this->org, ['code' => 'MB2', ...self::FORM]);
        $item = $this->makeBoqItem($project, '100.000', '2400.00');
        $this->approveEntry($this->draftEntry($project, $item, $this->engineer, ['measured_on' => '2026-11-10']), $this->engineer, $this->owner);

        return app(RaBillService::class)->generate($project, 'client', self::FROM, self::TO, $this->owner);
    }
}
