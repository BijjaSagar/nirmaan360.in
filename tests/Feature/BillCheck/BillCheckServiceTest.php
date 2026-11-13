<?php

namespace Tests\Feature\BillCheck;

use App\Models\AuditLog;
use App\Models\BillCheck;
use App\Services\Access\TeamAccessService;
use App\Services\BillCheck\BillCheckGate;
use App\Services\BillCheck\BillChecks;
use App\Services\BillCheck\CheckResult;
use App\Services\BillCheck\Flag;
use App\Services\DeductionEngine;
use App\Services\RaBillService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The check as a whole: that it only reads, that it is the same for everyone, and how its hashes behave. */
class BillCheckServiceTest extends BillCheckTestCase
{
    /** Every table a bill is made of or worked out from. */
    private const BILLING = [
        'ra_bills', 'ra_bill_items', 'ra_bill_deductions', 'measurement_entries', 'measurement_attachments',
        'projects', 'boq_items', 'work_orders', 'work_order_items', 'contract_terms', 'approvals', 'approval_rules', 'parties',
    ];

    private function billing(): string
    {
        return (string) json_encode(array_map(fn (string $table) => DB::table($table)->orderBy('id')->get()->all(), array_combine(self::BILLING, self::BILLING)));
    }

    public function test_a_check_reads_billing_data_and_writes_nothing_but_itself(): void
    {
        $this->requireCheck();
        $this->measure('120', ['measured_on' => '2026-11-10']);
        $first = $this->clientBill('2026-11-01', '2026-11-15');
        $this->measure('5', ['measured_on' => '2026-11-20'], photo: false);
        $bill = $this->clientBill('2026-11-16', '2026-11-30');
        $this->term(['code' => 'labour_cess', 'label' => 'Labour cess', 'pct' => '1.2']);
        app(RaBillService::class)->cancel($first);

        $before = $this->billing();
        $audits = AuditLog::withoutGlobalScopes()->count();

        // Looking writes nothing at all.
        $result = $this->inspect($bill);
        $this->assertGreaterThan(5, count($result->flags));
        $this->assertSame($before, $this->billing());
        $this->assertSame($audits, AuditLog::withoutGlobalScopes()->count());
        $this->assertSame(0, BillCheck::withoutGlobalScopes()->count());

        // Checking stores the check and one audit line, and no billing record moves.
        $check = $this->check($bill, $this->pm);
        $this->assertSame($before, $this->billing());
        $this->assertSame($audits + 1, AuditLog::withoutGlobalScopes()->count());
        $this->assertSame($result->hash(), $check->flags_hash);
        $this->assertSame($result->flagsForStorage(), $check->flags);
        $this->assertSame($result->summary(), $check->summary);
        $this->assertEquals($result->expected, $check->expected);

        // Acknowledging, and a submit the gate refuses, leave the bill and everything under it as they were.
        app(BillChecks::class)->acknowledge($check, $check->flags[0]['key'], 'Seen and noted', $this->pm);

        try {
            app(BillCheckGate::class)->assertMaySubmit($bill, $this->pm);
            $this->fail('The gate let the bill through.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->billing());
        }
    }

    public function test_the_engine_and_the_bill_generator_are_only_asked_never_changed(): void
    {
        // The check works a bill out again with the same engine the bill was made with.
        $this->measure('20');
        $bill = $this->clientBill();
        $again = app(DeductionEngine::class)->compute($bill->gross_amount, $bill->terms);

        $this->assertSame($bill->deduction_total, $again['deduction_total']);
        $this->assertSame($bill->gst_amount, $again['gst_amount']);
        $this->assertSame($bill->net_payable, $again['net_payable']);
        $this->assertNotFound($bill, 'A3');
    }

    public function test_the_check_is_the_same_whoever_runs_it_and_with_nobody_signed_in(): void
    {
        $this->measure('120');
        $this->term(['pct' => '7.25']);
        $this->term(['code' => 'labour_cess', 'label' => 'Labour cess', 'pct' => '1.2']);
        $bill = $this->clientBill();
        $other = $this->makeProject($this->org, ['code' => 'MB7']);

        $asOwner = $this->inspect($bill);

        $limited = $this->member($this->org, 'pm');
        app(TeamAccessService::class)->applyProjects($limited, true, [$this->project->id, $other->id]);
        $this->actingAs($limited->refresh());
        $asLimited = $this->inspect($bill);

        Auth::forgetUser();
        $this->app['auth']->forgetGuards();
        $asNobody = $this->inspect($bill);

        $this->assertSame($asOwner->hash(), $asLimited->hash());
        $this->assertSame($asOwner->hash(), $asNobody->hash());
        $this->assertEquals($asOwner->expected, $asNobody->expected);
        $this->assertNotNull($asNobody->expected);
    }

    public function test_the_hash_stays_the_same_until_a_finding_or_one_of_its_figures_changes(): void
    {
        $this->confirmFormPercentages();
        $this->measure('120');
        $bill = $this->clientBill();

        $first = $this->inspect($bill);
        $second = $this->inspect($bill);
        $this->assertSame($first->hash(), $second->hash());
        $this->assertSame(64, strlen($first->hash()));

        // A figure of one finding changes: that finding's hash changes, the others keep theirs.
        $this->item->update(['qty' => '110.000']);
        $third = $this->inspect($bill);
        $this->assertNotSame($first->hash(), $third->hash());

        $hashes = fn (CheckResult $result) => collect($result->flags)->mapWithKeys(fn (Flag $flag) => [$flag->key => $flag->hash()])->all();
        $changed = array_keys(array_diff_assoc($hashes($third), $hashes($first)));
        $this->assertCount(1, $changed);
        $this->assertStringStartsWith('C1:', $changed[0]);

        // A note counts too: the whole list is hashed, not only what needs an acknowledgement.
        $empty = new CheckResult([], null);
        $withNote = new CheckResult([new Flag('D6', 'D6', Flag::NOTE, ['count' => 1, 'total' => 1])], null);
        $this->assertNotSame($empty->hash(), $withNote->hash());
    }

    public function test_a_finding_s_hash_covers_its_key_level_and_figures_whatever_their_order_or_type(): void
    {
        $flag = new Flag('C1:7', 'C1', Flag::TICK, ['item' => 'A-1', 'excess' => '2.000', 'count' => 3]);

        $this->assertSame($flag->hash(), (new Flag('C1:7', 'C1', Flag::TICK, ['count' => '3', 'excess' => '2.000', 'item' => 'A-1']))->hash());
        $this->assertSame($flag->hash(), Flag::fromArray(json_decode((string) json_encode($flag->toArray()), true))->hash(), 'Stored and read back, it is the same finding.');

        $this->assertNotSame($flag->hash(), (new Flag('C1:8', 'C1', Flag::TICK, $flag->params))->hash());
        $this->assertNotSame($flag->hash(), (new Flag('C1:7', 'C1', Flag::BLOCKING, $flag->params))->hash());
        $this->assertNotSame($flag->hash(), (new Flag('C1:7', 'C1', Flag::TICK, [...$flag->params, 'excess' => '2.001']))->hash());

        $this->assertTrue($flag->needsAck());
        $this->assertTrue((new Flag('A2', 'A2', Flag::BLOCKING))->needsAck());
        $this->assertFalse((new Flag('D6', 'D6', Flag::NOTE))->needsAck());

        // Two results with the same findings in another order are the same result.
        $other = new Flag('D3', 'D3', Flag::TICK, ['count' => 2]);
        $this->assertSame((new CheckResult([$flag, $other], null))->hash(), (new CheckResult([$other, $flag], null))->hash());
    }

    public function test_findings_are_listed_with_what_does_not_match_first(): void
    {
        $this->measure('120', photo: false);
        $bill = $this->clientBill();
        DB::table('ra_bills')->where('id', $bill->id)->update(['net_payable' => '1.00']);

        $levels = array_map(fn (Flag $flag) => $flag->level, $this->inspect($bill)->flags);
        $ranks = array_map(fn (string $level) => array_search($level, Flag::LEVELS, true), $levels);
        $sorted = $ranks;
        sort($sorted);

        $this->assertSame($sorted, $ranks);
        $this->assertSame([Flag::BLOCKING, Flag::TICK, Flag::NOTE], array_values(array_unique($levels)));
    }

    public function test_x1_a_subcontractor_bill_whose_work_order_is_gone_is_checked_only_against_itself(): void
    {
        $workOrder = $this->workOrder();
        $this->confirmFormPercentages($workOrder);
        $this->term(['code' => 'labour_cess', 'label' => 'Labour cess', 'pct' => '1.2', 'work_order_id' => $workOrder->id]);
        $this->measure('60', ['work_order_id' => $workOrder->id]);
        $bill = $this->subBill($workOrder);
        $this->assertContains('T3', $this->keys($bill));

        // Work orders are only ever marked as removed. This is the row itself going, which the app never does.
        DB::table('work_orders')->where('id', $workOrder->id)->delete();
        $this->assertNull($bill->refresh()->work_order_id);

        $result = $this->inspect($bill);
        $this->assertSame(['X1'], $this->keys($result));
        $this->assertSame(Flag::TICK, $this->found($result, 'X1')->level);
        $this->assertNull($result->expected);

        // What the bill says about itself is still added up.
        DB::table('ra_bills')->where('id', $bill->id)->update(['gross_amount' => '1.00']);
        $this->assertContains('A2', $this->keys($bill));
        $this->assertContains('X1', $this->keys($bill));
    }

    public function test_a_remark_is_read_with_the_figures_or_not_at_all(): void
    {
        $this->measure('20');
        $bill = $this->clientBill();
        $check = $this->check($bill, $this->pm);
        app(BillChecks::class)->acknowledge($check, 'T1:tds', 'Client deducts Rs 840 on this bill', $this->qs);

        $ack = fn ($user) => collect($this->actingAs($user)->getJson(route('bill-checks.show', $bill))->assertOk()->json('check.flags'))->firstWhere('key', 'T1:tds')['ack'];

        $this->assertSame('Client deducts Rs 840 on this bill', $ack($this->accounts)['remark']);
        $this->assertNull($ack($this->viewer)['remark']);
        $this->assertSame($this->qs->name, $ack($this->viewer)['user']['name']);

        $this->actingAs($this->viewer)->get(route('ra-bills.show', $bill))->assertOk()
            ->assertSee('Acknowledged by '.$this->qs->name)->assertDontSee('Client deducts')->assertDontSee('840');
        $this->actingAs($this->accounts)->get(route('ra-bills.show', $bill))->assertOk()->assertSee('Client deducts Rs 840 on this bill');
    }
}
