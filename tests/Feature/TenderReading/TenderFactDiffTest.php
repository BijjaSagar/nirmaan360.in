<?php

namespace Tests\Feature\TenderReading;

use App\Models\AuditLog;
use App\Models\CrmTask;
use App\Models\CrmTenderDocument;
use App\Models\CrmTenderFact;
use App\Services\Crm\TenderPaymentService;
use App\Services\TenderReading\TenderFactBuilder;
use App\Services\TenderReading\TenderFactDiff;
use App\Services\TenderReading\TenderFields;
use App\Services\TenderReading\TenderValue;

/**
 * A later document, such as a corrigendum, that gives a different value from a confirmed one. Both
 * are shown with their pages; confirming the new one marks the old one replaced. Two values in one
 * document stay a contradiction that a person settles by rejecting one.
 */
class TenderFactDiffTest extends TenderReadingTestCase
{
    private CrmTenderDocument $notice;

    private CrmTenderDocument $corrigendum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notice = $this->storeDocument($this->tender, null, 'notice.pdf');
        $this->corrigendum = $this->storeDocument($this->tender, null, 'corrigendum-1.pdf');
    }

    private function makeFact(string $field, string $raw, int $page, CrmTenderDocument $document, string $status = CrmTenderFact::PROPOSED, array $overrides = []): CrmTenderFact
    {
        $kind = TenderFields::kind($field);
        $confirmed = $status === CrmTenderFact::CONFIRMED;

        $fact = CrmTenderFact::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'tender_id' => $this->tender->id,
            'document_id' => $document->id,
            'field' => $field,
            'value_raw' => $raw,
            'value_norm' => TenderValue::normalise($kind, $raw),
            'value_kind' => $kind,
            'page' => $page,
            'quote' => 'The line of page '.$page.' that holds '.$raw,
            'quote_line' => 'The line of page '.$page.' that holds '.$raw,
            'quote_found' => true,
            'source' => 'text',
            'status' => $status,
            'page_viewed' => $confirmed,
            'confirmed_by' => $confirmed ? $this->owner->id : null,
            'confirmed_at' => $confirmed ? now() : null,
            ...$overrides,
        ]);

        app(TenderFactBuilder::class)->regroup($this->organization->id, $this->tender->id);

        return $fact->refresh();
    }

    private function confirm(CrmTenderFact $fact)
    {
        return $this->actingAs($this->owner)->postJson(route('tender-facts.confirm', $fact), ['viewed' => true]);
    }

    public function test_a_later_document_with_another_value_is_shown_beside_the_confirmed_one_and_replaces_it_once_confirmed(): void
    {
        $old = $this->makeFact('emd_amount', 'Rs. 61,500/-', 2, $this->notice, CrmTenderFact::CONFIRMED);
        // An id that holds the digits of the value, as a long run on Postgres, whose sequences are not rolled back, can give it.
        $new = $this->makeFact('emd_amount', 'Rs. 65,100/-', 1, $this->corrigendum, CrmTenderFact::PROPOSED, ['id' => 61061]);

        $changes = TenderFactDiff::changes($this->facts());
        $this->assertCount(1, $changes);
        $this->assertSame([[$old->id], [$new->id]], [$changes[0]['old']->pluck('id')->all(), $changes[0]['new']->pluck('id')->all()]);

        $sheet = $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk();
        $sheet->assertSee('What another document changes');
        $sheet->assertSeeInOrder(['Confirmed against its page', 'Rs. 61,500/-', 'Page 2', 'notice', 'New', 'Rs. 65,100/-', 'Page 1', 'corrigendum-1']);
        $sheet->assertSee('Confirming this replaces the confirmed value from page 2 of notice.');
        $sheet->assertSee('Another document gives a different value from the confirmed one. Check the new one against its page; the app does not choose between them.');

        // While the two stand, neither is put to use.
        $this->actingAs($this->pm)->postJson(route('tender-facts.apply', $old), ['target' => 'payment', 'mode' => 'online'])->assertStatus(409)->assertJson(['code' => 'in_conflict']);

        $this->confirm($new)->assertOk()->assertJson(['status' => 'confirmed', 'conflict' => false]);

        $old->refresh();
        $this->assertSame([CrmTenderFact::SUPERSEDED, null], [$old->status, $old->conflict_group]);
        $this->assertNull($new->refresh()->conflict_group);

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'tender_fact.superseded')->sole();
        $this->assertSame(['status' => 'superseded', 'field' => 'emd_amount', 'page' => 2, 'replaced_by' => $new->id, 'applied' => false], $audit->new_values);
        // The printed value and the amount read from it stay out of the audit row; the ids in it are not values.
        foreach (['61,500', '61500', '65,100', '65100'] as $value) {
            $this->assertStringNotContainsString($value, json_encode([$audit->old_values, $audit->new_values]));
        }

        $sheet = $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk();
        $sheet->assertDontSee('What another document changes');
        $sheet->assertSee('Replaced by another document (1)');
        $this->assertSame([], TenderFactDiff::changes($this->facts()));

        // Nothing reached the tender by itself; the new value is put to use like any other.
        $this->assertSame(0, $this->tender->payments()->count());
        $this->actingAs($this->pm)->postJson(route('tender-facts.apply', $new), ['target' => 'payment', 'mode' => 'online'])->assertCreated();
        $this->assertSame('65100.00', (string) $this->tender->payments()->sole()->amount);
    }

    public function test_a_value_put_on_the_tender_and_then_replaced_says_so(): void
    {
        $old = $this->makeFact('due_at', '15-12-2026 up to 15:00 hrs', 2, $this->notice, CrmTenderFact::CONFIRMED);
        $this->actingAs($this->owner)->postJson(route('tender-facts.apply', $old), ['target' => 'field'])->assertCreated();

        $new = $this->makeFact('due_at', '22-12-2026 up to 15:00 hrs', 1, $this->corrigendum);
        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()->assertSee('on the tender');

        $this->confirm($new)->assertOk();

        $this->assertSame(CrmTenderFact::SUPERSEDED, $old->refresh()->status);
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'tender_fact.superseded')->sole()->new_values['applied']);

        // The tender keeps the first deadline until a person puts the new one there.
        $this->assertSame('2026-12-15 15:00', $this->tender->refresh()->due_at->format('Y-m-d H:i'));
        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()
            ->assertSee('This value was put on the tender. Put the new value on the tender to change it there.');

        $this->actingAs($this->owner)->postJson(route('tender-facts.apply', $new), ['target' => 'field'])->assertCreated();
        $this->assertSame('2026-12-22 15:00', $this->tender->refresh()->due_at->format('Y-m-d H:i'));
    }

    public function test_two_values_in_one_document_stay_side_by_side_until_one_is_rejected(): void
    {
        $one = $this->makeFact('completion_period', '9 (Nine) months', 3, $this->notice);
        $two = $this->makeFact('completion_period', '12 months', 7, $this->notice);

        $this->confirm($one)->assertOk()->assertJson(['conflict' => true]);
        $this->assertSame([], TenderFactDiff::changes($this->facts()), 'Two values of one document are a contradiction, not a change.');
        $this->assertSame(CrmTenderFact::PROPOSED, $two->refresh()->status);

        $this->confirm($two)->assertOk()->assertJson(['conflict' => true]);
        $this->assertSame([CrmTenderFact::CONFIRMED, CrmTenderFact::CONFIRMED], [$one->refresh()->status, $two->refresh()->status]);
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'tender_fact.superseded')->count());

        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()
            ->assertSee('The tender says this in more than one way. Check each against its page; the app does not choose between them.')
            ->assertDontSee('What another document changes');

        $this->actingAs($this->owner)->postJson(route('tender-facts.reject', $two))->assertOk();
        $this->assertNull($one->refresh()->conflict_group);
    }

    public function test_the_same_value_again_or_another_label_of_a_repeating_field_is_no_change(): void
    {
        $this->makeFact('tender_fee', 'Rs. 2,950/-', 2, $this->notice, CrmTenderFact::CONFIRMED);
        $same = $this->makeFact('tender_fee', 'Rs.2950/-', 1, $this->corrigendum);

        $this->makeFact('deduction', '3.7% of the bill amount', 4, $this->notice, CrmTenderFact::CONFIRMED, ['label' => 'Site upkeep charge']);
        $other = $this->makeFact('deduction', '4.1% of the bill amount', 2, $this->corrigendum, CrmTenderFact::PROPOSED, ['label' => 'Sample testing charge']);

        $this->assertSame([], TenderFactDiff::changes($this->facts()));

        $this->confirm($same)->assertOk()->assertJson(['conflict' => false]);
        $this->confirm($other)->assertOk()->assertJson(['conflict' => false]);
        $this->assertSame(0, $this->facts()->where('status', CrmTenderFact::SUPERSEDED)->count());

        // The same label with another value is a change.
        $changed = $this->makeFact('deduction', '3.9% of the bill amount', 2, $this->corrigendum, CrmTenderFact::PROPOSED, ['label' => 'Site upkeep charge']);
        $this->assertSame([$changed->id], TenderFactDiff::changes($this->facts())[0]['new']->pluck('id')->all());
    }

    public function test_rejecting_the_new_value_keeps_the_confirmed_one(): void
    {
        $old = $this->makeFact('bid_validity', '137 days', 3, $this->notice, CrmTenderFact::CONFIRMED);
        $new = $this->makeFact('bid_validity', '151 days', 1, $this->corrigendum);

        $this->actingAs($this->owner)->postJson(route('tender-facts.reject', $new))->assertOk();

        $this->assertSame([CrmTenderFact::CONFIRMED, null], [$old->refresh()->status, $old->conflict_group]);
        $this->assertSame([], TenderFactDiff::changes($this->facts()));
    }

    public function test_rejecting_a_confirmed_new_value_gives_the_replaced_value_back(): void
    {
        $old = $this->makeFact('emd_amount', 'Rs. 61,500/-', 2, $this->notice, CrmTenderFact::CONFIRMED);
        $new = $this->makeFact('emd_amount', 'Rs. 65,100/-', 1, $this->corrigendum);
        $this->confirm($new)->assertOk();
        $this->assertSame(CrmTenderFact::SUPERSEDED, $old->refresh()->status);

        // The corrigendum figure turns out to be misread: rejecting it puts the notice's value back as it was.
        $this->actingAs($this->owner)->postJson(route('tender-facts.reject', $new))->assertOk()->assertJson(['status' => 'rejected']);

        $old->refresh();
        $this->assertSame([CrmTenderFact::CONFIRMED, null, $this->owner->id], [$old->status, $old->conflict_group, (int) $old->confirmed_by]);
        $restored = AuditLog::withoutGlobalScopes()->where('action', 'tender_fact.restored')->sole();
        $this->assertSame([$old->id, ['status' => 'confirmed', 'field' => 'emd_amount', 'page' => 2, 'replaced_by_rejected' => $new->id]], [(int) $restored->auditable_id, $restored->new_values]);
        $this->assertSame([$old->id], AuditLog::withoutGlobalScopes()->where('action', 'tender_fact.rejected')->sole()->new_values['restored']);

        $sheet = $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk();
        $sheet->assertDontSee('Replaced by another document');
        $sheet->assertSee('A planned payment of ₹61,500.00 on this tender. It is not marked paid.');

        // It can be put to use again.
        $this->actingAs($this->pm)->postJson(route('tender-facts.apply', $old), ['target' => 'payment', 'mode' => 'online'])->assertCreated();
        $this->assertSame('61500.00', (string) $this->tender->payments()->sole()->amount);

        // A fact replaced later by a third document is not brought back by rejecting the second one.
        $third = $this->makeFact('emd_amount', 'Rs. 66,000/-', 1, $this->storeDocument($this->tender, null, 'corrigendum-2.pdf'));
        $this->confirm($third)->assertOk();
        $this->assertSame(CrmTenderFact::SUPERSEDED, $old->refresh()->status);
    }

    public function test_a_new_value_changes_the_planned_payment_and_follow_up_the_replaced_value_made(): void
    {
        $old = $this->makeFact('emd_amount', 'Rs. 61,500/-', 2, $this->notice, CrmTenderFact::CONFIRMED);
        $this->actingAs($this->pm)->postJson(route('tender-facts.apply', $old), ['target' => 'payment', 'mode' => 'online'])->assertCreated();
        $payment = $this->tender->payments()->sole();
        $payment->update(['reference' => 'UTR-SAMPLE-1']);

        $new = $this->makeFact('emd_amount', 'Rs. 65,100/-', 1, $this->corrigendum);
        $this->confirm($new)->assertOk();

        $sheet = $this->actingAs($this->pm)->get(route('tender-facts.index', $this->tender))->assertOk();
        $sheet->assertSee('A planned payment was made from this value. Adding the new value as a planned payment changes that payment.');
        $sheet->assertSee('The planned payment made from the replaced value becomes ₹65,100.00. It is not marked paid.');
        $sheet->assertDontSee('Put the new value on the tender to change it there.');

        $this->actingAs($this->pm)->postJson(route('tender-facts.apply', $new), ['target' => 'payment', 'mode' => 'dd'])->assertCreated()
            ->assertJson(['record' => ['id' => $payment->id]]);

        // One planned payment, with the new amount, not two.
        $payment = $this->tender->payments()->sole();
        $this->assertSame(['65100.00', 'planned', null, 'dd', 'UTR-SAMPLE-1', true], [(string) $payment->amount, $payment->status, $payment->paid_on, $payment->mode, $payment->reference, (bool) $payment->refundable]);
        $this->assertSame('payment:'.$payment->id, $new->refresh()->applied_to);
        $this->assertSame('65100.00', app(TenderPaymentService::class)->totals($this->tender->payments()->with('tender')->get())['planned']);
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'tender_fact.applied')->where('auditable_id', $new->id)->sole()->new_values['changed_earlier']);

        // A follow-up made from a replaced date moves to the new date.
        $due = $this->makeFact('due_at', '15-12-2026 up to 15:00 hrs', 2, $this->notice, CrmTenderFact::CONFIRMED);
        $this->actingAs($this->owner)->postJson(route('tender-facts.apply', $due), ['target' => 'task'])->assertCreated();
        $later = $this->makeFact('due_at', '22-12-2026 up to 15:00 hrs', 1, $this->corrigendum);
        $this->confirm($later)->assertOk();

        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()
            ->assertSee('A follow-up was made from this value. Adding the new value as a follow-up moves it.')
            ->assertSee('The follow-up made from the replaced value moves to 22 Dec 2026, 03:00 PM.');
        $this->actingAs($this->owner)->postJson(route('tender-facts.apply', $later), ['target' => 'task'])->assertCreated();

        $task = CrmTask::withoutGlobalScopes()->where('subject_type', 'tender')->where('subject_id', $this->tender->id)->sole();
        $this->assertSame(['2026-12-22', '15:00', 'open'], [$task->due_on->toDateString(), substr((string) $task->due_time, 0, 5), $task->status]);
    }

    public function test_a_payment_no_longer_only_planned_is_left_alone_and_the_new_value_is_added_beside_it(): void
    {
        $old = $this->makeFact('tender_fee', 'Rs. 2,950/-', 2, $this->notice, CrmTenderFact::CONFIRMED);
        $this->actingAs($this->pm)->postJson(route('tender-facts.apply', $old), ['target' => 'payment', 'mode' => 'online'])->assertCreated();
        $paid = $this->tender->payments()->sole();
        $paid->update(['status' => 'paid', 'paid_on' => today()]);

        $new = $this->makeFact('tender_fee', 'Rs. 3,540/-', 1, $this->corrigendum);
        $this->confirm($new)->assertOk();

        $this->actingAs($this->pm)->get(route('tender-facts.index', $this->tender))->assertOk()
            ->assertSee('A payment made from this value is no longer only planned. The new value is added as another planned payment, so check both on the tender.')
            ->assertSee('A planned payment of ₹3,540.00 on this tender. It is not marked paid.');

        $this->actingAs($this->pm)->postJson(route('tender-facts.apply', $new), ['target' => 'payment', 'mode' => 'online'])->assertCreated();
        $this->assertSame([['2950.00', 'paid'], ['3540.00', 'planned']], $this->tender->payments()->orderBy('id')->get()->map(fn ($payment) => [(string) $payment->amount, $payment->status])->all());
    }
}
