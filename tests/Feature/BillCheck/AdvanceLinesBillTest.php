<?php

namespace Tests\Feature\BillCheck;

use App\Models\RaBill;
use App\Services\ProjectService;

/**
 * RA bills on a project whose advance is several lines. The bills read projects.advance_amount and
 * advance_recovery_pct as before, so these show the lines reach them, that a bill already made keeps
 * the terms it was made with, and that the pre-check still sees an advance changed after a bill.
 * The BOQ line is 100 at 2,400 (BillCheckTestCase), and every figure is made up.
 */
class AdvanceLinesBillTest extends BillCheckTestCase
{
    private function setAdvance(array $data): void
    {
        app(ProjectService::class)->update($this->project, $data, $this->owner);
        $this->project->refresh();
    }

    private function advanceOn(RaBill $bill): ?string
    {
        return $bill->deductions()->where('type', 'advance')->value('amount');
    }

    public function test_a_new_bill_uses_the_new_total_and_percentage_and_stops_at_what_is_left(): void
    {
        // 60,000 on a contract of 1,00,000, worked out: 60%.
        $this->setAdvance(['contract_value' => '100000', 'advance_recovery_auto' => true, 'advances' => [['amount' => '60000', 'received_on' => '2026-10-28', 'note' => 'Cheque 004512']]]);
        $this->assertSame('60.00', $this->project->advance_recovery_pct);

        // First bill: 20 × 2,400 = 48,000. Recovery 60% = 28,800.
        $this->measure('20', ['measured_on' => '2026-11-10']);
        $first = $this->approveBill($this->clientBill('2026-11-01', '2026-11-15'));
        $this->assertSame('APPROVED', $first->status);
        $this->assertSame('28800.00', $this->advanceOn($first));

        // A second advance of 10,000 comes in: 70,000 in all, 70%.
        $this->setAdvance(['advances' => [
            ['amount' => '60000', 'received_on' => '2026-10-28', 'note' => 'Cheque 004512'],
            ['amount' => '10000', 'received_on' => '2026-11-18', 'note' => null],
        ]]);
        $this->assertSame('70000.00', $this->project->advance_amount);
        $this->assertSame('70.00', $this->project->advance_recovery_pct);

        // The approved bill keeps the terms it was made with.
        $first->refresh();
        $this->assertSame('60000.00', $first->terms['advance_amount']);
        $this->assertSame('60.00', $first->terms['advance_recovery_pct']);
        $this->assertSame('60000.00', $first->terms['advance_balance']);
        $this->assertSame('28800.00', $this->advanceOn($first));

        // Second bill: 30 × 2,400 = 72,000. 70% would be 50,400, but only 70,000 − 28,800 = 41,200 is left.
        $this->measure('30', ['measured_on' => '2026-11-20']);
        $second = $this->clientBill('2026-11-16', '2026-11-30');

        $this->assertSame('70000.00', $second->terms['advance_amount']);
        $this->assertSame('70.00', $second->terms['advance_recovery_pct']);
        $this->assertSame('41200.00', $second->terms['advance_balance']);
        $this->assertSame('41200.00', $this->advanceOn($second));
    }

    public function test_the_percentage_typed_by_hand_is_what_a_bill_uses(): void
    {
        $this->setAdvance(['advance_recovery_auto' => false, 'advance_recovery_pct' => '8', 'advances' => [['amount' => '30000'], ['amount' => '10000']]]);

        // 20 × 2,400 = 48,000. 8% = 3,840.
        $this->measure('20');
        $bill = $this->clientBill();

        $this->assertSame('40000.00', $bill->terms['advance_amount']);
        $this->assertSame('8.00', $bill->terms['advance_recovery_pct']);
        $this->assertSame('3840.00', $this->advanceOn($bill));
    }

    public function test_b5_says_the_advance_changed_when_a_line_is_added_after_the_bill(): void
    {
        $this->confirmFormPercentages();
        // The form's 40,000 as one line, the percentage still typed by hand.
        $this->setAdvance(['advances' => [['amount' => '40000', 'received_on' => '2026-10-28', 'note' => null]]]);
        $this->assertSame('8.00', $this->project->advance_recovery_pct);

        $this->measure('20');
        $bill = $this->clientBill();
        $this->assertSame([], $this->keys($bill));

        $this->setAdvance(['advances' => [
            ['amount' => '40000', 'received_on' => '2026-10-28', 'note' => null],
            ['amount' => '15000', 'received_on' => '2026-11-25', 'note' => null],
        ]]);

        $result = $this->inspect($bill);
        $this->assertSame(['B5:advance_amount'], $this->keys($result));
        $this->assertSame(['bill' => '40000.00', 'now' => '55000.00'], $this->found($result, 'B5:advance_amount')->params);

        // Worked out from now on, the percentage moves too, and B5 says that as well: 55,000 on 1,00,00,000.
        $this->setAdvance(['advance_recovery_auto' => true]);
        $this->assertSame('0.55', $this->project->advance_recovery_pct);
        $this->assertSame(['head' => 'advance_recovery', 'bill' => '8', 'now' => '0.55'], $this->found($bill, 'B5:advance_recovery_pct')->params);
    }
}
