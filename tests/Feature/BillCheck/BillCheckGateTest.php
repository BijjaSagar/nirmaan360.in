<?php

namespace Tests\Feature\BillCheck;

use App\Models\AuditLog;
use App\Models\BillCheck;
use App\Models\BillCheckAck;
use App\Models\BillCheckPolicy;
use App\Models\ContractTerm;
use App\Models\RaBill;
use App\Services\BillCheck\BillCheckGate;
use App\Services\BillCheck\BillChecks;
use App\Services\BillCheck\Flag;
use App\Services\RaBillService;
use Illuminate\Validation\ValidationException;

/**
 * The gate in front of "submit for approval". Off for every company until its owner or admin turns it
 * on. On, it runs the check again at the moment of submitting and never trusts the stored one.
 */
class BillCheckGateTest extends BillCheckTestCase
{
    private function submit(RaBill $bill)
    {
        return $this->actingAs($this->pm)->from(route('ra-bills.show', $bill))->post(route('ra-bills.submit', $bill));
    }

    private function refusal(RaBill $bill): string
    {
        try {
            app(BillCheckGate::class)->assertMaySubmit($bill, $this->pm);
        } catch (ValidationException $exception) {
            return $exception->validator->errors()->first('check');
        }

        $this->fail('The gate let the bill through.');
    }

    public function test_the_gate_is_off_by_default_and_changes_nothing(): void
    {
        $this->assertSame(0, BillCheckPolicy::withoutGlobalScopes()->count(), 'No company has a policy until someone sets one.');
        $this->assertFalse(app(BillChecks::class)->isRequired($this->org->id));

        // A bill with four findings nobody looked at goes into its approval chain as it always did.
        $this->measure('20');
        $bill = $this->clientBill();
        $this->assertCount(4, $this->inspect($bill)->toAcknowledge());

        $this->submit($bill)->assertRedirect(route('ra-bills.show', $bill))->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('IN_REVIEW', $bill->refresh()->status);
        $this->assertSame(0, BillCheck::withoutGlobalScopes()->count(), 'The gate did not run, so nothing was stored.');
    }

    public function test_turning_it_off_again_lets_bills_through_as_before(): void
    {
        $this->requireCheck();
        $this->requireCheck(false);
        $this->measure('20');
        $bill = $this->clientBill();

        $this->submit($bill)->assertSessionHasNoErrors();

        $this->assertSame('IN_REVIEW', $bill->refresh()->status);
    }

    public function test_required_a_bill_that_was_never_checked_is_held_and_its_check_is_kept(): void
    {
        $this->requireCheck();
        $this->measure('20');
        $bill = $this->clientBill();

        $this->submit($bill)
            ->assertRedirect(route('ra-bills.show', $bill))
            ->assertSessionHasErrors(['check' => 'Check this bill before submitting it. Your company requires every finding of the check to be acknowledged first.']);

        $this->assertSame('DRAFT', $bill->refresh()->status);

        // What the gate found is on the bill page, so the person can see why.
        $check = BillCheck::withoutGlobalScopes()->sole();
        $this->assertSame($this->pm->id, $check->run_by);
        $this->assertSame(['T1:retention', 'T1:tds', 'T1:gst', 'T1:advance_recovery'], array_column($check->flags, 'key'));
    }

    public function test_required_every_finding_must_be_acknowledged_before_the_bill_goes(): void
    {
        $this->requireCheck();
        $this->measure('20');
        $bill = $this->clientBill();
        $check = $this->check($bill, $this->pm);

        foreach (['T1:retention', 'T1:tds', 'T1:gst'] as $key) {
            app(BillChecks::class)->acknowledge($check, $key, 'Agreed with the client by letter', $this->pm);
        }

        $this->submit($bill)->assertSessionHasErrors(['check' => 'Acknowledge every finding of the check before submitting this bill. Still open: 1.']);
        $this->assertSame('DRAFT', $bill->refresh()->status);
        $this->assertSame(1, BillCheck::withoutGlobalScopes()->count(), 'Nothing changed, so no second check was written.');

        app(BillChecks::class)->acknowledge($check, 'T1:advance_recovery', 'Agreed with the client by letter', $this->qs);

        $this->submit($bill)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('IN_REVIEW', $bill->refresh()->status);
        $this->assertSame(1, BillCheck::withoutGlobalScopes()->count());
        $this->assertSame(4, BillCheckAck::withoutGlobalScopes()->where('bill_check_id', $check->id)->count());
    }

    public function test_required_a_bill_with_nothing_to_acknowledge_goes_through_and_leaves_a_record(): void
    {
        $this->requireCheck();
        $this->confirmFormPercentages();
        // A note needs no acknowledgement: here, a measurement without a photo.
        $this->measure('20', photo: false);
        $bill = $this->clientBill();

        $this->submit($bill)->assertSessionHasNoErrors();

        $this->assertSame('IN_REVIEW', $bill->refresh()->status);
        $check = BillCheck::withoutGlobalScopes()->sole();
        $this->assertSame(['D6'], array_column($check->flags, 'key'));
        $this->assertSame(Flag::NOTE, $check->flags[0]['level']);
        $this->assertSame($this->pm->id, $check->run_by);
    }

    public function test_a_term_confirmed_with_another_percentage_after_the_acknowledgement_holds_the_bill(): void
    {
        $this->requireCheck();
        $this->measure('20');
        $bill = $this->clientBill();
        $before = $bill->fresh()->getAttributes();
        $this->acknowledgeAll($first = $this->check($bill));

        // Everything is acknowledged. Then the agreement is read and its retention confirmed at another percentage.
        $term = $this->term(['pct' => '7.25']);

        $this->submit($bill)->assertSessionHasErrors('check');
        $message = session('errors')->first('check');
        $this->assertStringStartsWith('Something this bill depends on has changed since the check. Check it again.', $message);
        $this->assertStringContainsString('New or changed: Retention on this bill is 6.5%, but the term confirmed for this contract says 7.25% (Sample agreement, part C, in force from 01 Apr 2026).', $message);

        // The bill itself is exactly what it was.
        $this->assertSame($before, $bill->fresh()->getAttributes());

        // The fresh run is stored as the newest check, with nothing acknowledged on it yet.
        $second = app(BillChecks::class)->latest($bill);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(["T2:retention:{$term->id}", 'T1:tds', 'T1:gst', 'T1:advance_recovery'], array_column($second->flags, 'key'));
        $this->assertSame(0, BillCheckAck::withoutGlobalScopes()->where('bill_check_id', $second->id)->count());

        // Trying again changes nothing and writes no third check.
        $this->submit($bill)->assertSessionHasErrors(['check' => 'Acknowledge every finding of the check before submitting this bill. Still open: 4.']);
        $this->assertSame(2, BillCheck::withoutGlobalScopes()->count());

        $this->acknowledgeAll($second);
        $this->submit($bill)->assertSessionHasNoErrors();
        $this->assertSame('IN_REVIEW', $bill->refresh()->status);
    }

    public function test_the_same_finding_with_another_figure_is_a_changed_finding(): void
    {
        $this->requireCheck();
        $this->confirmFormPercentages();
        $this->measure('120');
        $bill = $this->clientBill();
        $this->acknowledgeAll($this->check($bill));

        // The BOQ quantity is revised. The line is still above it, by a different amount.
        $this->item->update(['qty' => '110.000']);

        $this->assertStringStartsWith('Something this bill depends on has changed since the check.', $this->refusal($bill));
        $this->assertSame('110.000', collect(app(BillChecks::class)->latest($bill)->flags)->firstWhere('code', 'C1')['params']['limit']);
    }

    public function test_an_earlier_bill_cancelled_after_the_acknowledgement_holds_the_bill(): void
    {
        $this->requireCheck();
        $this->confirmFormPercentages();
        $this->measure('20', ['measured_on' => '2026-11-10']);
        $first = $this->clientBill('2026-11-01', '2026-11-15');
        $this->measure('5', ['measured_on' => '2026-11-20']);
        $second = $this->clientBill('2026-11-16', '2026-11-30');

        $check = $this->check($second);
        $this->assertSame(["B3:{$first->id}"], array_column($check->flags, 'key'));
        $this->acknowledgeAll($check);

        app(RaBillService::class)->cancel($first);

        $this->submit($second)->assertSessionHasErrors('check');
        $this->assertStringContainsString('Something this bill depends on has changed since the check.', session('errors')->first('check'));
        $this->assertSame('DRAFT', $second->refresh()->status);

        $keys = array_column(app(BillChecks::class)->latest($second)->flags, 'key');
        $this->assertContains('B2', $keys);
        $this->assertNotContains("B3:{$first->id}", $keys);
    }

    public function test_a_finding_that_went_away_does_not_hold_the_bill(): void
    {
        $this->requireCheck();
        $this->measure('20');
        $bill = $this->clientBill();
        $this->acknowledgeAll($this->check($bill));

        // The retention term is confirmed at the bill's own percentage: one finding fewer, none new.
        $this->term();

        $this->submit($bill)->assertSessionHasNoErrors();
        $this->assertSame('IN_REVIEW', $bill->refresh()->status);
        $this->assertSame(1, BillCheck::withoutGlobalScopes()->count());
    }

    public function test_a_bill_that_is_already_in_review_is_left_to_the_approval_rules(): void
    {
        $this->requireCheck();
        $this->measure('20');
        $bill = $this->clientBill();
        $this->acknowledgeAll($check = $this->check($bill));
        $this->submit($bill)->assertSessionHasNoErrors();

        // Something changes while the bill is with its approvers, and someone presses submit again.
        $this->term(['pct' => '7.25']);

        $this->submit($bill)->assertSessionHasErrors(['status' => 'Only draft or returned documents can be submitted.'])->assertSessionDoesntHaveErrors('check');

        // The check that was acknowledged at the time stays the record of what was seen.
        $this->assertSame($check->id, BillCheck::withoutGlobalScopes()->sole()->id);
        $this->assertSame('IN_REVIEW', $bill->refresh()->status);
    }

    public function test_the_refusal_names_the_finding_without_figures_for_someone_who_may_not_see_money(): void
    {
        $this->requireCheck();
        $this->measure('20');
        $bill = $this->clientBill();
        $this->acknowledgeAll($this->check($bill));
        $this->term(['pct' => '7.25', 'source_ref' => 'Sample agreement, part C']);

        $siteClerk = $this->holding(['ra_bill.view', 'ra_bill.generate', 'project.view']);

        $this->actingAs($siteClerk)->post(route('ra-bills.submit', $bill))->assertSessionHasErrors('check');
        $message = session('errors')->first('check');

        $this->assertStringContainsString('New or changed: Retention on this bill is not the percentage of the term confirmed for this contract, in force from 01 Apr 2026.', $message);
        $this->assertStringNotContainsString('7.25', $message);
        $this->assertStringNotContainsString('Sample agreement', $message);
    }

    public function test_the_gate_is_one_company_s_choice_and_leaves_another_company_alone(): void
    {
        [$rivalOrg, $rivalOwner, $rivalBill] = $this->rival();
        $this->requireCheck();

        $this->assertFalse(app(BillChecks::class)->isRequired($rivalOrg->id));

        $this->actingAs($rivalOwner)->post(route('ra-bills.submit', $rivalBill))->assertSessionHasNoErrors();
        $this->assertSame('IN_REVIEW', $rivalBill->refresh()->status);
    }

    public function test_running_a_check_and_acknowledging_are_written_to_the_audit_log_without_figures(): void
    {
        $this->measure('20');
        $bill = $this->clientBill();
        $check = $this->check($bill, $this->pm);
        app(BillChecks::class)->acknowledge($check, 'T1:tds', 'As per the contract form', $this->qs);
        $this->requireCheck();

        $run = AuditLog::withoutGlobalScopes()->where('action', 'bill_check.run')->sole();
        $this->assertSame($this->pm->id, $run->user_id);
        $this->assertSame(['ra_bill_id' => $bill->id, 'flags_hash' => $check->flags_hash, 'blocking' => 0, 'tick' => 4, 'note' => 0], $run->new_values);

        $ack = AuditLog::withoutGlobalScopes()->where('action', 'bill_check.acknowledged')->sole();
        $this->assertSame($this->qs->id, $ack->user_id);
        $this->assertSame(['ra_bill_id' => $bill->id, 'flag_key' => 'T1:tds', 'remark' => 'As per the contract form'], $ack->new_values);

        $policy = BillCheckPolicy::withoutGlobalScopes()->sole();
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('auditable_type', $policy->getMorphClass())->where('auditable_id', $policy->id)->where('action', 'created')->exists());
        $this->assertSame(0, ContractTerm::withoutGlobalScopes()->count(), 'A check never writes a contract term.');
    }
}
