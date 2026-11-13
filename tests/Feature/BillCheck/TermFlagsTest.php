<?php

namespace Tests\Feature\BillCheck;

use App\Models\ClientReceipt;
use App\Models\ContractTerm;
use App\Models\RaBill;
use App\Services\BillCheck\Flag;
use App\Services\Finance\SettlementService;
use App\Services\RaBillService;
use Illuminate\Support\Carbon;

/**
 * T1 to T4: the bill against the dated contract terms a person has confirmed. The app has no
 * percentage of its own, so every figure below is one this test typed and confirmed.
 */
class TermFlagsTest extends BillCheckTestCase
{
    /** The named values of a row, in the order asked for. */
    private function pick(array $row, array $keys): array
    {
        $picked = [];

        foreach ($keys as $key) {
            $picked[$key] = $row[$key];
        }

        return $picked;
    }

    private function billOfTwenty(): RaBill
    {
        $this->measure('20');

        return $this->clientBill();
    }

    public function test_t1_a_percentage_that_nobody_has_confirmed_against_the_agreement(): void
    {
        // No term is seeded and none is assumed: with nothing entered, all four are only what the form says.
        $this->assertSame(0, ContractTerm::withoutGlobalScopes()->count());
        $bill = $this->billOfTwenty();

        $result = $this->inspect($bill);
        $this->assertSame(['T1:retention', 'T1:tds', 'T1:gst', 'T1:advance_recovery'], $this->keys($result));

        foreach (['retention' => '6.5', 'tds' => '1.75', 'gst' => '12.5', 'advance_recovery' => '8'] as $code => $pct) {
            $flag = $this->found($result, "T1:{$code}");
            $this->assertSame('T1', $flag->code);
            $this->assertSame(Flag::TICK, $flag->level);
            $this->assertSame(['head' => $code, 'pct' => $pct], $flag->params);
        }
    }

    public function test_t1_only_a_confirmed_term_in_force_at_the_period_end_settles_it(): void
    {
        $bill = $this->billOfTwenty();

        // Typed but not confirmed.
        $this->term(['confirmed_by' => null, 'confirmed_at' => null]);
        // Confirmed, but it ended before the period did, or starts after it.
        $this->term(['effective_to' => '2026-11-29']);
        $this->term(['effective_from' => '2026-12-01']);
        // Confirmed for a work order, not for the client contract.
        $this->term(['work_order_id' => $this->workOrder()->id]);
        // Confirmed and in force, but removed.
        $this->term()->delete();

        $this->assertContains('T1:retention', $this->keys($bill));

        $this->term(['pct' => '6.5000', 'effective_to' => '2026-11-30']);

        $this->assertSame(['T1:tds', 'T1:gst', 'T1:advance_recovery'], $this->keys($bill));
    }

    public function test_t1_and_t2_leave_out_what_a_final_bill_sets_aside(): void
    {
        $this->term(['pct' => '9.75']);
        $this->measure('20');
        $final = $this->finalClientBill();

        // A final bill holds no retention and recovers the whole advance, so neither percentage is on it.
        $this->assertSame(['T1:tds', 'T1:gst'], array_values(array_filter($this->keys($final), fn (string $key) => str_starts_with($key, 'T'))));
    }

    public function test_t2_a_confirmed_term_that_differs_from_the_bill_is_blocking(): void
    {
        $this->confirmFormPercentages();
        $bill = $this->billOfTwenty();
        $this->assertSame([], $this->keys($bill));

        // The agreement is read again and the retention term corrected and confirmed. The bill is not touched.
        $term = ContractTerm::withoutGlobalScopes()->where('code', 'retention')->sole();
        $term->update(['pct' => '7.1250', 'source_ref' => 'Sample agreement, part C, as amended']);

        $result = $this->inspect($bill);
        $this->assertSame(["T2:retention:{$term->id}"], $this->keys($result));

        $flag = $this->found($result, "T2:retention:{$term->id}");
        $this->assertSame('T2', $flag->code);
        $this->assertSame(Flag::BLOCKING, $flag->level);
        $this->assertSame(
            ['head' => 'retention', 'bill' => '6.5', 'term' => '7.125', 'source' => 'Sample agreement, part C, as amended', 'from' => '2026-04-01'],
            $flag->params,
        );

        // Four decimals against two are compared as numbers, never as text.
        $term->update(['pct' => '6.5000']);
        $this->assertSame([], $this->keys($bill));
    }

    public function test_t2_on_a_subcontractor_bill_reads_the_terms_of_its_work_order(): void
    {
        $workOrder = $this->workOrder();
        $this->confirmFormPercentages();
        $this->measure('10', ['work_order_id' => $workOrder->id]);
        $bill = $this->subBill($workOrder);

        // The client contract's terms say nothing about the subcontract.
        $this->assertSame(['T1:retention', 'T1:tds', 'T1:gst', 'T1:advance_recovery'], $this->keys($bill));

        $this->confirmFormPercentages($workOrder);
        $this->assertSame([], $this->keys($bill));

        $term = ContractTerm::withoutGlobalScopes()->where('code', 'tds')->where('work_order_id', $workOrder->id)->sole();
        $term->update(['pct' => '1.1']);
        $this->assertSame(['head' => 'tds', 'bill' => '0.75', 'term' => '1.1', 'source' => 'Sample agreement, part C', 'from' => '2026-04-01'], $this->found($bill, "T2:tds:{$term->id}")->params);
    }

    public function test_t3_with_no_other_head_entered_there_is_no_sheet(): void
    {
        $this->confirmFormPercentages();
        // An unconfirmed head does not count, and neither does one for another contract.
        $this->term(['code' => 'labour_cess', 'label' => 'Labour cess', 'pct' => '1.2', 'confirmed_by' => null, 'confirmed_at' => null]);
        $this->term(['code' => 'royalty', 'label' => 'Royalty', 'pct' => '0.9', 'work_order_id' => $this->workOrder()->id]);

        $result = $this->inspect($this->billOfTwenty());

        $this->assertNull($result->expected);
        $this->assertNotFound($result, 'T3');
    }

    public function test_t3_the_sheet_of_deductions_that_are_not_on_the_bill(): void
    {
        $this->confirmFormPercentages();
        $cess = $this->term(['code' => 'labour_cess', 'label' => 'Labour cess', 'pct' => '1.2', 'base' => 'gross', 'nature' => 'cost', 'source_ref' => 'Sample agreement, part D']);
        $deposit = $this->term(['code' => 'security_deposit', 'label' => 'Security deposit', 'pct' => '2', 'base' => 'running', 'nature' => 'withheld_refundable']);
        $testing = $this->term(['code' => 'testing', 'label' => 'Testing charges', 'pct' => '0.5', 'base' => 'gross_plus_gst']);
        $rent = $this->term(['code' => 'other', 'label' => 'Site office rent', 'method' => 'fixed_per_bill', 'pct' => null, 'base' => null, 'amount' => '750.00']);
        $insurance = $this->term(['code' => 'insurance', 'label' => 'Insurance', 'method' => 'typed_each_bill', 'pct' => null, 'base' => null]);
        $bill = $this->billOfTwenty();

        // The bill itself is what it was: these heads are listed beside it and never worked into it.
        $this->assertSame('46200.00', $bill->net_payable);
        $this->assertSame(['retention', 'advance', 'tds'], $bill->deductions()->pluck('type')->all());

        $result = $this->inspect($bill);
        $sheet = $result->expected;

        $this->assertSame('client', $sheet['party']);
        $this->assertSame([$insurance->id, $cess->id, $rent->id, $deposit->id, $testing->id], array_column($sheet['lines'], 'term_id'));

        $lines = collect($sheet['lines'])->keyBy('code');

        // 1.2 of the gross 48,000.
        $this->assertSame(['base_amount' => '48000.00', 'amount' => '576.00'], $this->pick($lines['labour_cess'], ['base_amount', 'amount']));
        // 2 of what is left after the bill's own deductions: 48,000 less 7,800.
        $this->assertSame(['base_amount' => '40200.00', 'amount' => '804.00'], $this->pick($lines['security_deposit'], ['base_amount', 'amount']));
        // 0.5 of gross plus GST: 54,000.
        $this->assertSame(['base_amount' => '54000.00', 'amount' => '270.00'], $this->pick($lines['testing'], ['base_amount', 'amount']));
        $this->assertSame('750.00', $lines['other']['amount']);
        $this->assertNull($lines['insurance']['amount'], 'An amount typed on each bill has no figure here.');

        $this->assertSame('Sample agreement, part D', $lines['labour_cess']['source_ref']);
        $this->assertSame('2026-04-01', $lines['labour_cess']['effective_from']);
        $this->assertSame('Site office rent', $lines['other']['label']);

        $this->assertSame(1, $sheet['unpriced']);
        $this->assertSame('2400.00', $sheet['total']);
        $this->assertSame('46200.00', $sheet['net_payable']);
        $this->assertSame('43800.00', $sheet['expected_receipt']);

        $this->assertSame(['T3'], $this->keys($result));
        $flag = $this->found($result, 'T3');
        $this->assertSame('T3', $flag->code);
        $this->assertSame(Flag::TICK, $flag->level);
        $this->assertSame(['count' => 5, 'total' => '2400.00', 'receipt' => '43800.00'], $flag->params);
    }

    public function test_t3_a_limit_on_a_head_is_its_highest_total_over_the_whole_contract(): void
    {
        $this->confirmFormPercentages();
        $deposit = $this->term(['code' => 'security_deposit', 'label' => 'Security deposit', 'pct' => '2', 'base' => 'gross', 'cap_amount' => '1200.00']);

        // First bill: 2 of 48,000 is 960, inside the limit of 1,200.
        $this->measure('20', ['measured_on' => '2026-11-05']);
        $first = $this->clientBill('2026-11-01', '2026-11-15');
        $line = $this->inspect($first)->expected['lines'][0];
        $this->assertSame(['amount' => '960.00', 'cap_amount' => '1200.00', 'earlier_amount' => '0.00'], $this->pick($line, ['amount', 'cap_amount', 'earlier_amount']));
        $this->approveBill($first);

        // Second bill: another 960 would pass the limit, so only the 240 still open is expected.
        $this->measure('20', ['measured_on' => '2026-11-20']);
        $second = $this->clientBill('2026-11-16', '2026-11-30');
        $line = $this->inspect($second)->expected['lines'][0];
        $this->assertSame(['amount' => '240.00', 'cap_amount' => '1200.00', 'earlier_amount' => '960.00'], $this->pick($line, ['amount', 'cap_amount', 'earlier_amount']));
        $this->assertSame($deposit->id, $line['term_id']);
        $this->approveBill($second);

        // Third bill: the limit is used up.
        $this->measure('10', ['measured_on' => '2026-12-05']);
        $third = $this->clientBill('2026-12-01', '2026-12-15');
        $sheet = $this->inspect($third)->expected;
        $this->assertSame(['amount' => '0.00', 'earlier_amount' => '1200.00'], $this->pick($sheet['lines'][0], ['amount', 'earlier_amount']));
        $this->assertSame($third->net_payable, $sheet['expected_receipt']);
    }

    public function test_t3_on_a_subcontractor_bill_lists_the_heads_of_its_work_order(): void
    {
        $workOrder = $this->workOrder();
        $this->confirmFormPercentages($workOrder);
        $this->term(['code' => 'labour_cess', 'label' => 'Labour cess', 'pct' => '1.2']);
        $this->term(['code' => 'other', 'label' => 'Water and power', 'pct' => '1.5', 'work_order_id' => $workOrder->id]);
        $this->measure('10', ['work_order_id' => $workOrder->id]);
        $bill = $this->subBill($workOrder);

        $result = $this->inspect($bill);

        $this->assertSame('subcontractor', $result->expected['party']);
        $this->assertSame(['Water and power'], array_column($result->expected['lines'], 'label'));
        // 10 at 900 is 9,000, and 1.5 of it is 135.
        $this->assertSame('135.00', $result->expected['total']);
        $this->assertSame('T3.sub', $this->found($result, 'T3')->code);
    }

    public function test_t4_an_earlier_bill_unpaid_beyond_the_confirmed_payment_period(): void
    {
        $this->confirmFormPercentages();
        $period = ['kind' => 'payment_period', 'code' => 'payment_due', 'label' => 'Payment due', 'method' => null, 'pct' => null, 'base' => null, 'days' => 17, 'counted_from' => 'bill_date', 'source_ref' => 'Sample agreement, part G'];

        // The first bill is approved on 25 November, which is its date.
        $this->measure('20', ['measured_on' => '2026-11-05']);
        $this->travelTo(Carbon::parse('2026-11-25 10:00:00'));
        $first = $this->approveBill($this->clientBill('2026-11-01', '2026-11-15'));
        $this->travelTo(Carbon::parse('2026-12-20 11:00:00'));

        $this->measure('5', ['measured_on' => '2026-11-20']);
        $bill = $this->clientBill('2026-11-16', '2026-11-30');

        // With no payment period entered, the app has none of its own.
        $this->assertNotFound($bill, 'T4');
        $this->term([...$period, 'confirmed_by' => null, 'confirmed_at' => null]);
        $this->assertNotFound($bill, 'T4');

        // 17 days from 25 November is 12 December, and today is the 20th.
        $term = $this->term($period);
        $result = $this->inspect($bill);
        $this->assertSame(["T4:{$first->id}"], $this->keys($result));

        $flag = $this->found($result, "T4:{$first->id}");
        $this->assertSame(Flag::TICK, $flag->level);
        // What is owed on the first bill is its net payable with the TDS on it, as the finance screens count it.
        $this->assertSame(['number' => $first->number, 'due' => '2026-12-12', 'outstanding' => '47040.00', 'source' => 'Sample agreement, part G'], $flag->params);

        // A part payment changes the figure, and settling the bill ends the finding.
        $pay = fn (string $amount) => app(SettlementService::class)->recordReceipt($first, ['received_on' => '2026-12-18', 'amount' => $amount, 'mode' => 'bank'], $this->accounts);
        $pay('40000');
        $this->assertSame('7040.00', $this->found($bill, "T4:{$first->id}")->params['outstanding']);
        $pay('7040');
        $this->assertNotFound($bill, 'T4');

        // A longer period that has not run out yet leaves nothing to say either.
        $this->assertSame(17, $term->days);
    }

    public function test_t4_takes_a_bill_marked_paid_on_its_page_as_paid_although_no_receipt_was_recorded(): void
    {
        $this->confirmFormPercentages();

        $this->measure('20', ['measured_on' => '2026-11-05']);
        $this->travelTo(Carbon::parse('2026-11-25 10:00:00'));
        $first = $this->approveBill($this->clientBill('2026-11-01', '2026-11-15'));
        $this->travelTo(Carbon::parse('2026-12-20 11:00:00'));
        $this->measure('5', ['measured_on' => '2026-11-20']);
        $bill = $this->clientBill('2026-11-16', '2026-11-30');
        $this->term(['kind' => 'payment_period', 'code' => 'payment_due', 'label' => 'Payment due', 'method' => null, 'pct' => null, 'base' => null, 'days' => 17, 'counted_from' => 'bill_date']);
        $this->assertSame(["T4:{$first->id}"], $this->keys($bill));

        // The button on the RA bill page writes no receipt. The finance screens still count the bill as owed; the pre-check does not.
        app(RaBillService::class)->markPaid($first->fresh());
        $this->assertSame(0, ClientReceipt::withoutGlobalScopes()->where('ra_bill_id', $first->id)->count());
        $this->assertSame('47040.00', app(SettlementService::class)->clientBills($this->org->id, $this->project->id)->first()['outstanding']);
        $this->assertNotFound($bill, 'T4');
    }

    public function test_t4_is_quiet_while_the_period_has_not_run_out(): void
    {
        $this->confirmFormPercentages();
        $this->term(['kind' => 'payment_period', 'code' => 'payment_due', 'label' => 'Payment due', 'method' => null, 'pct' => null, 'base' => null, 'days' => 40, 'counted_from' => 'bill_date']);

        $this->measure('20', ['measured_on' => '2026-11-05']);
        $this->travelTo(Carbon::parse('2026-11-25 10:00:00'));
        $this->approveBill($this->clientBill('2026-11-01', '2026-11-15'));
        $this->travelTo(Carbon::parse('2026-12-20 11:00:00'));
        $this->measure('5', ['measured_on' => '2026-11-20']);
        $bill = $this->clientBill('2026-11-16', '2026-11-30');

        // 40 days from 25 November is 4 January.
        $this->assertNotFound($bill, 'T4');

        $this->travelTo(Carbon::parse('2027-01-04 09:00:00'));
        $this->assertNotFound($bill, 'T4');

        $this->travelTo(Carbon::parse('2027-01-05 09:00:00'));
        $this->assertContains('T4:'.RaBill::withoutGlobalScopes()->orderBy('id')->value('id'), $this->keys($bill));
    }
}
