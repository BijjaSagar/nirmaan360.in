<?php

namespace Tests\Feature\BillCheck;

use App\Models\Dpr;
use App\Models\DprWorkItem;
use App\Models\RaBill;
use App\Models\RaBillItem;
use App\Services\BillCheck\Flag;
use App\Services\MeasurementService;
use App\Services\RaBillService;

/** D1 to D7, E2 and E3: the measurements behind the bill and around it. */
class MeasurementFlagsTest extends BillCheckTestCase
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

    private function dailyReport(string $status, string $qty, string $date = '2026-11-12'): Dpr
    {
        $dpr = Dpr::create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'report_date' => $date, 'summary' => 'Slab pour', 'status' => $status, 'created_by' => $this->engineer->id]);
        DprWorkItem::create(['organization_id' => $this->org->id, 'dpr_id' => $dpr->id, 'boq_item_id' => $this->item->id, 'description' => 'Slab pour', 'qty' => $qty]);

        return $dpr;
    }

    public function test_d1_measurements_dated_before_the_bill_period(): void
    {
        // The bill takes every approved, unbilled measurement up to the period end, however old.
        $this->measure('3', ['measured_on' => '2026-10-20']);
        $this->measure('4', ['measured_on' => '2026-10-28']);
        $this->measure('20', ['measured_on' => '2026-11-10']);
        $bill = $this->clientBill();

        $result = $this->inspect($bill);
        $this->assertSame(['D1'], $this->keys($result));

        $flag = $this->found($result, 'D1');
        $this->assertSame(Flag::TICK, $flag->level);
        $this->assertSame(['count' => 2, 'from' => '2026-11-01', 'earliest' => '2026-10-20'], $flag->params);
    }

    public function test_d2_approved_work_dated_after_the_period_is_a_note_on_a_running_bill(): void
    {
        $this->measure('20');
        $this->measure('6', ['measured_on' => '2026-12-04']);
        $bill = $this->clientBill();

        $result = $this->inspect($bill);
        $this->assertSame(['D2'], $this->keys($result));
        $this->assertSame(Flag::NOTE, $this->found($result, 'D2')->level);
        $this->assertSame(['count' => 1], $this->found($result, 'D2')->params);
        $this->assertSame([], $result->toAcknowledge(), 'A note needs no acknowledgement.');
    }

    public function test_d2_on_a_final_bill_it_has_to_be_looked_at(): void
    {
        $this->measure('20');
        $final = $this->finalClientBill();
        $this->assertNotFound($final, 'D2');

        // Approved after the final bill was drafted. No later bill will ever pick it up.
        $this->measure('6', ['measured_on' => '2026-12-04']);

        $this->assertSame(Flag::TICK, $this->found($final, 'D2')->level);
    }

    public function test_d3_measurements_in_the_period_that_are_not_approved_yet(): void
    {
        $this->measure('20');
        $this->draft('7', ['measured_on' => '2026-11-18']);
        $inReview = $this->draft('2', ['measured_on' => '2026-11-30']);
        app(MeasurementService::class)->submit($inReview, $this->engineer);
        // Outside the period, so not this bill's business.
        $this->draft('9', ['measured_on' => '2026-12-02']);
        $this->draft('9', ['measured_on' => '2026-10-31']);
        $bill = $this->clientBill();

        $result = $this->inspect($bill);
        $this->assertSame(['D3'], $this->keys($result));
        $this->assertSame(Flag::TICK, $this->found($result, 'D3')->level);
        $this->assertSame(['count' => 2], $this->found($result, 'D3')->params);
    }

    public function test_d3_on_a_subcontractor_bill_counts_that_work_order_only(): void
    {
        $workOrder = $this->workOrder();
        $this->confirmFormPercentages($workOrder);
        $this->measure('10', ['work_order_id' => $workOrder->id]);
        $this->draft('7', ['measured_on' => '2026-11-18']);
        $bill = $this->subBill($workOrder);

        $this->assertNotFound($bill, 'D3');

        $this->draft('3', ['measured_on' => '2026-11-19', 'work_order_id' => $workOrder->id]);
        $this->assertSame(['count' => 1], $this->found($bill, 'D3')->params);
    }

    public function test_d4_measured_more_than_approved_daily_reports_show(): void
    {
        $this->measure('20');
        $bill = $this->clientBill();

        // No daily report gives a quantity for the item: nothing to compare.
        $this->assertNotFound($bill, 'D4');

        // Reports that are not approved do not count, and neither does one dated after the period.
        $this->dailyReport('DRAFT', '15', '2026-11-13');
        $this->dailyReport('IN_REVIEW', '15', '2026-11-14');
        $this->dailyReport('APPROVED', '2', '2026-12-03');
        $this->assertNotFound($bill, 'D4');

        $this->dailyReport('APPROVED', '11');
        $this->dailyReport('APPROVED', '4.5', '2026-11-28');
        $flag = $this->found($bill, "D4:{$this->item->id}");

        $this->assertSame(Flag::NOTE, $flag->level);
        $this->assertSame(['item' => $this->item->code, 'unit' => 'cum', 'measured' => '20.000', 'reported' => '15.500'], $flag->params);

        // Once the reports cover what was measured there is nothing to say.
        $this->dailyReport('APPROVED', '4.5', '2026-11-29');
        $this->assertNotFound($bill, 'D4');
    }

    public function test_d6_measurements_without_a_photo_or_a_sketch(): void
    {
        $this->measure('20');
        $this->measure('3', ['location' => 'Stair core'], photo: false);
        $this->measure('2', ['location' => 'Lift pit'], photo: false);
        $bill = $this->clientBill();

        $result = $this->inspect($bill);
        $this->assertSame(['D6'], $this->keys($result));
        $this->assertSame(Flag::NOTE, $this->found($result, 'D6')->level);
        $this->assertSame(['count' => 2, 'total' => 3], $this->found($result, 'D6')->params);
    }

    public function test_d7_a_line_with_no_rate_and_a_line_whose_boq_item_was_removed(): void
    {
        $free = $this->makeBoqItem($this->project, '10.000', '0.00');
        $this->measure('4', [], true, $free);
        $bill = $this->clientBill();
        $line = $this->line($bill);

        $result = $this->inspect($bill);
        $this->assertSame(["D7:{$line->id}:rate"], $this->keys($result));
        $this->assertSame('D7.rate', $this->found($result, "D7:{$line->id}:rate")->code);
        $this->assertSame(Flag::TICK, $this->found($result, "D7:{$line->id}:rate")->level);
        $this->assertSame(['item' => $free->code], $this->found($result, "D7:{$line->id}:rate")->params);

        $free->delete();
        $removed = $this->found($bill, "D7:{$line->id}:removed");
        $this->assertSame('D7.removed', $removed->code);
        $this->assertSame(['item' => $free->code], $removed->params);
    }

    public function test_d7_a_rate_of_zero_on_a_subcontractor_bill_is_not_this_finding(): void
    {
        $workOrder = $this->workOrder([], '50', '0');
        $this->confirmFormPercentages($workOrder);
        $this->measure('10', ['work_order_id' => $workOrder->id]);

        $this->assertNotFound($this->subBill($workOrder), 'D7');
    }

    public function test_e2_the_same_work_entered_twice_on_one_bill(): void
    {
        $same = ['measured_on' => '2026-11-12', 'location' => 'Second floor slab', 'length' => '4', 'breadth' => '2.5'];
        $first = $this->measure('1', $same);
        $this->measure('1', $same);
        // The same day and item somewhere else, or with another length, is other work.
        $this->measure('1', [...$same, 'location' => 'Third floor slab']);
        $this->measure('1', [...$same, 'length' => '4.2']);
        $this->measure('1', [...$same, 'measured_on' => '2026-11-13']);
        $bill = $this->clientBill();

        $result = $this->inspect($bill);
        $this->assertSame(["E2:{$first->id}"], $this->keys($result));

        $flag = $this->found($result, "E2:{$first->id}");
        $this->assertSame(Flag::TICK, $flag->level);
        $this->assertSame(['item' => $this->item->code, 'unit' => 'cum', 'date' => '2026-11-12', 'qty' => '10.000', 'count' => 2], $flag->params);
    }

    public function test_e2_a_twin_on_an_earlier_bill_counts_and_one_that_was_reversed_does_not(): void
    {
        $same = ['measured_on' => '2026-11-06', 'location' => 'Plinth beam'];
        $first = $this->measure('8', $same);
        $earlier = $this->approveBill($this->clientBill('2026-11-01', '2026-11-10'));

        // Approved late, with an old date, so it lands on the next bill.
        $second = $this->measure('8', $same);
        $bill = $this->clientBill('2026-11-11', '2026-11-30');

        $this->assertSame(['item' => $this->item->code, 'unit' => 'cum', 'date' => '2026-11-06', 'qty' => '8.000', 'count' => 2], $this->found($bill, "E2:{$first->id}")->params);
        $this->assertNotFound($earlier, 'E2');

        // The second entry is reversed and the reversal approved: the duplicate has been put right.
        $reversal = app(MeasurementService::class)->reverse($second, $this->engineer, 'Entered twice');
        $this->approveEntry($reversal, $this->engineer, $this->owner);

        $this->assertNotFound($bill, 'E2');
    }

    public function test_e3_a_measurement_and_its_reversal_on_the_same_bill(): void
    {
        $entry = $this->measure('20');
        $this->measure('5', ['location' => 'Lift pit']);
        // A reversal is dated the day it is made, here 20 December, so the bill runs to the end of the year.
        $reversal = app(MeasurementService::class)->reverse($entry, $this->engineer, 'Wrong item');
        $this->approveEntry($reversal, $this->engineer, $this->owner);
        $bill = $this->clientBill(self::FROM, '2026-12-31');

        $this->assertSame('5.000', $this->line($bill)->current_qty);

        $flag = $this->found($bill, "E3:{$entry->id}");
        $this->assertSame(Flag::TICK, $flag->level);
        $this->assertSame(['item' => $this->item->code, 'unit' => 'cum', 'qty' => '20.000'], $flag->params);
        $this->assertNotFound($bill, 'E2');

        // On different bills the pair is ordinary: billed once, taken back later.
        app(RaBillService::class)->cancel($bill);
        $november = $this->clientBill();
        $this->assertNotFound($november, 'E3');
    }
}
