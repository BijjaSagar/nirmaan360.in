<?php

namespace Tests\Feature\BillCheck;

use App\Models\RaBill;
use App\Models\RaBillItem;
use App\Services\ApprovalService;
use App\Services\BillCheck\Flag;
use App\Services\RaBillService;
use Illuminate\Support\Facades\DB;

/**
 * B1 to B6: what can move under a draft bill after it was made. Nothing on the bill changes in these
 * tests. Another bill, the contract form or a rate does.
 */
class EarlierBillFlagsTest extends BillCheckTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->confirmFormPercentages();
    }

    private function line(RaBill $bill): RaBillItem
    {
        return RaBillItem::withoutGlobalScopes()->where('ra_bill_id', $bill->id)->sole();
    }

    /** A first bill of 20 for the first half of the month, and a second of 5 for the rest. */
    private function twoBills(): array
    {
        $this->measure('20', ['measured_on' => '2026-11-10']);
        $first = $this->clientBill('2026-11-01', '2026-11-15');
        $this->measure('5', ['measured_on' => '2026-11-20']);
        $second = $this->clientBill('2026-11-16', '2026-11-30');

        return [$first, $second];
    }

    public function test_b3_an_earlier_bill_that_is_not_approved_yet(): void
    {
        [$first, $second] = $this->twoBills();

        $this->assertSame('20.000', $this->line($second)->prev_qty);

        $result = $this->inspect($second);
        $this->assertSame(["B3:{$first->id}"], $this->keys($result));

        $flag = $this->found($result, "B3:{$first->id}");
        $this->assertSame(Flag::TICK, $flag->level);
        $this->assertSame(['number' => $first->number], $flag->params);

        // In review it is still not approved.
        app(ApprovalService::class)->submit($first, $this->owner);
        $this->assertSame(["B3:{$first->id}"], $this->keys($second));

        // Approved, the earlier bill is settled and nothing is left to say. The first bill itself never had an earlier one.
        $approval = $first->refresh()->currentApproval;

        while ($approval->status === 'IN_REVIEW') {
            $approval = app(ApprovalService::class)->act($approval, $this->owner, 'approve');
        }

        $this->assertSame([], $this->keys($second));
        $this->assertSame([], $this->keys($first->refresh()));
    }

    public function test_b1_and_b2_an_earlier_bill_cancelled_after_this_one_was_made(): void
    {
        [$first, $second] = $this->twoBills();
        $line = $this->line($second);

        // The second bill counts the first as previous, and the advance the first one recovers as recovered.
        $this->assertSame('48000.00', $line->prev_amount);
        $this->assertSame('36160.00', $second->terms['advance_balance']);

        app(RaBillService::class)->cancel($first);
        $result = $this->inspect($second);

        $previous = $this->found($result, "B1:{$line->id}");
        $this->assertSame('B1', $previous->code);
        $this->assertSame(Flag::BLOCKING, $previous->level);
        $this->assertSame(
            ['item' => $this->item->code, 'stored_qty' => '20.000', 'now_qty' => '0.000', 'stored_amount' => '48000.00', 'now_amount' => '0.00'],
            $previous->params,
        );

        $advance = $this->found($result, 'B2');
        $this->assertSame(Flag::BLOCKING, $advance->level);
        $this->assertSame(['stored' => '36160.00', 'now' => '40000.00'], $advance->params);

        // The cancelled bill is no longer an unsettled earlier bill, and its measurements are free again:
        // they are approved, unbilled and dated before this bill's period ends, so C4 has nothing to say either.
        $this->assertNotFound($result, 'B3');
        $this->assertNotFound($result, 'B4');
        $this->assertSame(["B1:{$line->id}", 'B2'], $this->keys($result));
    }

    public function test_b4_another_live_bill_with_an_overlapping_period(): void
    {
        $this->measure('20', ['measured_on' => '2026-11-10']);
        $first = $this->approveBill($this->clientBill('2026-11-01', '2026-11-20'));
        $this->measure('5', ['measured_on' => '2026-11-25']);
        $second = $this->clientBill('2026-11-15', '2026-11-30');

        $flag = $this->found($second, "B4:{$first->id}");
        $this->assertSame(Flag::TICK, $flag->level);
        $this->assertSame(['number' => $first->number, 'from' => '2026-11-01', 'to' => '2026-11-20'], $flag->params);

        // It is said on both bills, because it is true of both.
        $this->assertSame(['number' => $second->number, 'from' => '2026-11-15', 'to' => '2026-11-30'], $this->found($first, "B4:{$second->id}")->params);

        // Periods that only touch do not overlap, and a cancelled bill does not count.
        app(RaBillService::class)->cancel($second);
        $this->assertNotFound($first->refresh(), 'B4');
        $third = $this->clientBill('2026-11-21', '2026-11-30');
        $this->assertNotFound($third, 'B4');
    }

    public function test_b5_the_contract_form_changed_after_the_bill_was_made(): void
    {
        $this->measure('20');
        $bill = $this->clientBill();
        $this->assertSame([], $this->keys($bill));

        // The confirmed terms still say what the bill says, so only the form is out of step.
        $this->project->update(['retention_pct' => '7.00', 'advance_amount' => '55000.00', 'deduction_order' => ['tds', 'retention', 'advance', 'material'], 'deduction_basis' => 'running']);
        $result = $this->inspect($bill);

        $this->assertSame(['B5:retention_pct', 'B5:advance_amount', 'B5:order', 'B5:basis'], $this->keys($result));

        $retention = $this->found($result, 'B5:retention_pct');
        $this->assertSame('B5.pct', $retention->code);
        $this->assertSame(Flag::TICK, $retention->level);
        $this->assertSame(['head' => 'retention', 'bill' => '6.5', 'now' => '7'], $retention->params);
        $this->assertSame(['bill' => '40000.00', 'now' => '55000.00'], $this->found($result, 'B5:advance_amount')->params);
        $this->assertSame('B5.order', $this->found($result, 'B5:order')->code);
        $this->assertSame('B5.basis', $this->found($result, 'B5:basis')->code);
    }

    public function test_b5_leaves_out_what_a_bill_sets_aside_on_purpose(): void
    {
        // A final bill holds no retention and recovers the whole advance, whatever the form says.
        $this->measure('20');
        $final = $this->finalClientBill();
        $this->assertSame('0.00', $final->terms['retention_pct']);
        $this->assertNotFound($final, 'B5');

        // A bill raised without GST differs from the form on purpose. That is F4, said once.
        app(RaBillService::class)->cancel($final);
        $bill = $this->clientBill(withGst: false);
        $this->assertNotFound($bill, 'B5');
        $this->assertContains('F4', $this->keys($bill));

        // GST on both, at different percentages, is a change of the form.
        app(RaBillService::class)->cancel($bill);
        $withGst = $this->clientBill();
        $this->project->update(['gst_pct' => '9.25']);
        $this->assertSame(['head' => 'gst', 'bill' => '12.5', 'now' => '9.25'], $this->found($withGst, 'B5:gst_pct')->params);
    }

    public function test_b6_a_rate_changed_after_the_bill_was_made(): void
    {
        $this->measure('20');
        $bill = $this->clientBill();
        $line = $this->line($bill);

        $this->item->update(['rate' => '2550.00']);
        $result = $this->inspect($bill);

        $this->assertSame(["B6:{$line->id}"], $this->keys($result));
        $this->assertSame(Flag::TICK, $this->found($result, "B6:{$line->id}")->level);
        $this->assertSame(['item' => $this->item->code, 'bill' => '2400.00', 'now' => '2550.00'], $this->found($result, "B6:{$line->id}")->params);
    }

    public function test_b6_a_subcontractor_bill_is_held_against_the_work_order_rate(): void
    {
        $workOrder = $this->workOrder();
        $this->confirmFormPercentages($workOrder);
        $this->measure('10', ['work_order_id' => $workOrder->id]);
        $bill = $this->subBill($workOrder);
        $line = $this->line($bill);
        $this->assertSame([], $this->keys($bill));

        // The BOQ rate is the client's rate and says nothing about this bill.
        $this->item->update(['rate' => '2550.00']);
        $this->assertSame([], $this->keys($bill));

        DB::table('work_order_items')->where('work_order_id', $workOrder->id)->update(['rate' => '950.00']);
        $this->assertSame(['item' => $this->item->code, 'bill' => '900.00', 'now' => '950.00'], $this->found($bill, "B6:{$line->id}")->params);

        DB::table('work_order_items')->where('work_order_id', $workOrder->id)->delete();
        $this->assertSame('B6.gone', $this->found($bill, "B6:{$line->id}")->code);
    }
}
