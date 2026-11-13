<?php

namespace Tests\Feature\BillCheck;

use App\Models\RaBill;
use App\Models\RaBillItem;
use App\Services\BillCheck\Flag;
use App\Services\MeasurementService;
use Illuminate\Support\Facades\DB;

/** C1 to C5: what has been billed so far against the contract quantity, the measurements and a confirmed limit. */
class QuantityFlagsTest extends BillCheckTestCase
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

    public function test_c1_and_c3_a_client_line_billed_above_its_boq_quantity(): void
    {
        // The BOQ line is 100. 120 are measured, which the measurement book marks as a deviation.
        $entry = $this->measure('120');
        $this->assertTrue($entry->deviation);
        $bill = $this->clientBill();
        $line = $this->line($bill);

        $result = $this->inspect($bill);
        $this->assertSame(["C1:{$line->id}", "C3:{$this->item->id}"], $this->keys($result));

        $above = $this->found($result, "C1:{$line->id}");
        $this->assertSame('C1', $above->code);
        $this->assertSame(Flag::TICK, $above->level);
        $this->assertSame(
            ['item' => $this->item->code, 'unit' => 'cum', 'cumulative' => '120.000', 'limit' => '100.000', 'excess' => '20.000', 'over' => '20'],
            $above->params,
        );

        $deviation = $this->found($result, "C3:{$this->item->id}");
        $this->assertSame(Flag::TICK, $deviation->level);
        $this->assertSame(['item' => $this->item->code, 'unit' => 'cum', 'count' => 1, 'qty' => '120.000'], $deviation->params);
    }

    public function test_c1_counts_the_earlier_bills_and_is_quiet_up_to_the_boq_quantity(): void
    {
        $this->measure('100', ['measured_on' => '2026-11-05']);
        $first = $this->approveBill($this->clientBill('2026-11-01', '2026-11-15'));
        $this->assertNotFound($first, 'C1');

        // 100 before and 0.5 now: above by half a unit, which is half of one in a hundred.
        $this->measure('0.5', ['measured_on' => '2026-11-20']);
        $second = $this->clientBill('2026-11-16', '2026-11-30');

        $flag = $this->found($second, "C1:{$this->line($second)->id}");
        $this->assertSame(['cumulative' => '100.500', 'limit' => '100.000', 'excess' => '0.500', 'over' => '0.5'], array_intersect_key($flag->params, array_flip(['cumulative', 'limit', 'excess', 'over'])));
    }

    public function test_c1_a_line_whose_boq_quantity_is_zero(): void
    {
        $item = $this->makeBoqItem($this->project, '0.000', '1800.00');
        $this->measure('4', [], true, $item);
        $bill = $this->clientBill();

        $flag = $this->found($bill, "C1:{$this->line($bill)->id}");

        $this->assertSame('C1.zero', $flag->code);
        $this->assertSame(['item' => $item->code, 'unit' => 'cum', 'cumulative' => '4.000'], $flag->params);
    }

    public function test_c2_a_subcontractor_line_billed_above_the_work_order_quantity(): void
    {
        // The work order gives 50. Nothing stops 60 from being measured and billed, so the check says it.
        $workOrder = $this->workOrder();
        $this->confirmFormPercentages($workOrder);
        $this->measure('60', ['work_order_id' => $workOrder->id]);
        $bill = $this->subBill($workOrder);
        $line = $this->line($bill);

        $this->assertSame('54000.00', $line->current_amount);
        $result = $this->inspect($bill);
        $this->assertSame(["C2:{$line->id}"], $this->keys($result));

        $flag = $this->found($result, "C2:{$line->id}");
        $this->assertSame('C2', $flag->code);
        $this->assertSame(Flag::TICK, $flag->level);
        $this->assertSame(
            ['item' => $this->item->code, 'unit' => 'cum', 'cumulative' => '60.000', 'limit' => '50.000', 'excess' => '10.000', 'over' => '20'],
            $flag->params,
        );

        // With no quantity on the work order there is nothing to compare with, which is a note and not a finding to tick.
        DB::table('work_order_items')->where('work_order_id', $workOrder->id)->update(['qty' => '0']);
        $none = $this->found($bill, "C2:{$line->id}");
        $this->assertSame('C2.zero', $none->code);
        $this->assertSame(Flag::NOTE, $none->level);
    }

    public function test_c2_leaves_a_lump_sum_work_order_alone(): void
    {
        $workOrder = $this->workOrder(['rate_type' => 'lump_sum']);
        $this->confirmFormPercentages($workOrder);
        $this->measure('60', ['work_order_id' => $workOrder->id]);

        // Its amount already stops at the fixed value, so the quantity above it costs nothing more.
        $this->assertNotFound($this->subBill($workOrder), 'C2');
    }

    public function test_c4_billed_above_what_approved_measurements_add_up_to(): void
    {
        $entry = $this->measure('20');
        $bill = $this->clientBill();
        $line = $this->line($bill);
        $this->assertNotFound($bill, 'C4');

        // The measurement is reversed after billing. The reversal is approved, so only nothing is left measured.
        $reversal = app(MeasurementService::class)->reverse($entry, $this->engineer, 'Entered on the wrong item');
        $this->approveEntry($reversal, $this->engineer, $this->owner);

        $flag = $this->found($bill, "C4:{$line->id}");
        $this->assertSame(Flag::TICK, $flag->level);
        $this->assertSame(['item' => $this->item->code, 'unit' => 'cum', 'cumulative' => '20.000', 'measured' => '0.000'], $flag->params);
    }

    public function test_c5_only_a_confirmed_term_in_force_sets_a_limit_on_extra_quantity(): void
    {
        $this->measure('120');
        $bill = $this->clientBill();
        $line = $this->line($bill);
        $this->assertNotFound($bill, 'C5');

        $excess = ['kind' => 'excess_quantity', 'code' => 'excess_quantity', 'label' => 'Quantity allowed above the BOQ', 'method' => null, 'base' => null, 'pct' => '12.5', 'source_ref' => 'Sample agreement, schedule F'];

        // Typed but not confirmed: it does not count.
        $this->term([...$excess, 'confirmed_by' => null, 'confirmed_at' => null]);
        $this->assertNotFound($bill, 'C5');

        // Confirmed, but it ended before the bill period did.
        $this->term([...$excess, 'effective_to' => '2026-11-29']);
        $this->assertNotFound($bill, 'C5');

        // Confirmed for a work order, not for the client contract.
        $this->term([...$excess, 'work_order_id' => $this->workOrder()->id]);
        $this->assertNotFound($bill, 'C5');

        // Confirmed and in force: 100 and 12.5 in a hundred more is 112.5, and 120 is above it.
        $term = $this->term($excess);
        $flag = $this->found($bill, "C5:{$line->id}:{$term->id}");

        $this->assertSame(Flag::TICK, $flag->level);
        $this->assertSame([
            'item' => $this->item->code, 'unit' => 'cum', 'cumulative' => '120.000', 'limit' => '112.500',
            'allowed' => '12.5', 'source' => 'Sample agreement, schedule F', 'from' => '2026-04-01',
        ], $flag->params);

        // A wider limit that covers the 120 leaves nothing to say.
        $term->update(['pct' => '20']);
        $this->assertNotFound($bill, 'C5');
    }

    public function test_c5_on_a_subcontract_starts_from_the_work_order_quantity(): void
    {
        $workOrder = $this->workOrder();
        $this->confirmFormPercentages($workOrder);
        $this->measure('60', ['work_order_id' => $workOrder->id]);
        $bill = $this->subBill($workOrder);
        $line = $this->line($bill);

        // A term on the client contract says nothing about a subcontract.
        $onClient = ['kind' => 'excess_quantity', 'code' => 'excess_quantity', 'label' => 'Quantity allowed above the BOQ', 'method' => null, 'base' => null, 'pct' => '8'];
        $this->term($onClient);
        $this->assertNotFound($bill, 'C5');

        $term = $this->term([...$onClient, 'work_order_id' => $workOrder->id]);

        // 50 and 8 in a hundred more is 54.
        $this->assertSame('54.000', $this->found($bill, "C5:{$line->id}:{$term->id}")->params['limit']);
    }
}
