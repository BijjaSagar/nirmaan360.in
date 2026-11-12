<?php

namespace Tests\Feature\BillCheck;

use App\Models\ApprovalRule;
use App\Models\ContractTerm;
use App\Services\BillCheck\Flag;

/** F1 to F4: what submitting does, and whether the bill names what it needs to. */
class ProcessFlagsTest extends BillCheckTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->confirmFormPercentages();
    }

    private function approvalLevels(array $levels): void
    {
        ApprovalRule::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $this->org->id, 'doc_type' => 'ra_bill'],
            ['levels' => $levels, 'active' => true],
        );
    }

    public function test_f1_with_no_approval_levels_submitting_approves_at_once(): void
    {
        $this->measure('20');
        $bill = $this->clientBill();
        $this->assertNotFound($bill, 'F1');

        $this->approvalLevels([]);
        $result = $this->inspect($bill);

        $this->assertSame(['F1'], $this->keys($result));
        $this->assertSame(Flag::TICK, $this->found($result, 'F1')->level);
        $this->assertSame([], $this->found($result, 'F1')->params);
    }

    public function test_f2_a_client_level_in_the_chain_is_a_note(): void
    {
        $this->measure('20');
        $bill = $this->clientBill();
        $this->approvalLevels(['pm', 'client']);

        $result = $this->inspect($bill);

        $this->assertSame(['F2'], $this->keys($result));
        $this->assertSame(Flag::NOTE, $this->found($result, 'F2')->level);
    }

    public function test_f3_a_client_bill_that_names_no_client_or_charges_gst_without_the_details(): void
    {
        $this->project->update(['client_id' => null, 'state' => null]);
        $this->org->update(['gstin' => null]);
        $this->measure('20');
        $bill = $this->clientBill();

        $result = $this->inspect($bill);

        $this->assertSame(['F3:client', 'F3:gstin', 'F3:state'], $this->keys($result));
        $this->assertSame(['F3.client', 'F3.gstin', 'F3.state'], array_map(fn (Flag $flag) => $flag->code, $result->flags));
        $this->assertSame([Flag::TICK, Flag::TICK, Flag::TICK], array_map(fn (Flag $flag) => $flag->level, $result->flags));
    }

    public function test_f3_gst_details_are_not_asked_for_on_a_bill_without_gst_or_a_subcontractor_bill(): void
    {
        $this->project->update(['state' => null, 'gst_pct' => '0']);
        $this->org->update(['gstin' => '  ']);
        $this->measure('20');

        $this->assertNotFound($this->clientBill(), 'F3');

        $workOrder = $this->workOrder(['gst_pct' => '9.5']);
        $this->measure('10', ['work_order_id' => $workOrder->id, 'measured_on' => '2026-11-11']);
        $this->assertNotFound($this->subBill($workOrder), 'F3');
    }

    public function test_f4_a_bill_without_gst_while_the_contract_form_has_it(): void
    {
        $this->measure('20');
        $bill = $this->clientBill(withGst: false);

        $result = $this->inspect($bill);

        // The bill's zero is a choice made on the bill form, so it is not held against the GST term as "not confirmed" (T1).
        $this->assertSame(['T2:gst:'.$this->gstTermId(), 'F4'], $this->keys($result));

        $flag = $this->found($result, 'F4');
        $this->assertSame('F4.off', $flag->code);
        $this->assertSame(Flag::TICK, $flag->level);
        $this->assertSame(['pct' => '12.5'], $flag->params);
    }

    public function test_f4_a_bill_with_gst_while_the_contract_form_now_has_none(): void
    {
        $this->measure('20');
        $bill = $this->clientBill();
        $this->project->update(['gst_pct' => '0']);

        $flag = $this->found($bill, 'F4');

        $this->assertSame('F4.on', $flag->code);
        $this->assertSame(['pct' => '12.5'], $flag->params);
        $this->assertNotFound($bill, 'B5');
    }

    private function gstTermId(): int
    {
        return ContractTerm::withoutGlobalScopes()->where('code', 'gst')->sole()->id;
    }
}
