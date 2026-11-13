<?php

namespace Tests\Feature\TenderReading;

use App\Models\AuditLog;
use App\Models\CrmActivity;
use App\Models\CrmTask;
use App\Models\CrmTenderFact;
use App\Models\CrmTenderPayment;
use App\Models\CustomRole;
use App\Models\User;
use App\Services\TenderReading\TenderFactBuilder;
use App\Services\TenderReading\TenderFields;
use App\Services\TenderReading\TenderValue;

/**
 * Putting a confirmed fact of the tender sheet to use: into the tender's own field, as a follow-up,
 * or as a planned payment. One fact at a time, by a person, checked with the CRM form's own rule.
 */
class TenderFactApplyTest extends TenderReadingTestCase
{
    private function makeFact(string $field, string $raw, int $page = 2, array $overrides = []): CrmTenderFact
    {
        $kind = TenderFields::kind($field);

        $fact = CrmTenderFact::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'tender_id' => $this->tender->id,
            'field' => $field,
            'value_raw' => $raw,
            'value_norm' => TenderValue::normalise($kind, $raw),
            'value_kind' => $kind,
            'page' => $page,
            'quote' => 'The line of page '.$page.' that holds '.$raw,
            'quote_line' => 'The line of page '.$page.' that holds '.$raw,
            'quote_found' => true,
            'source' => 'text',
            'status' => CrmTenderFact::CONFIRMED,
            'page_viewed' => true,
            'confirmed_by' => $this->owner->id,
            'confirmed_at' => now(),
            ...$overrides,
        ]);

        app(TenderFactBuilder::class)->regroup($this->organization->id, $this->tender->id);

        return $fact->refresh();
    }

    private function apply(CrmTenderFact $fact, string $target, array $data = [], ?User $by = null)
    {
        return $this->actingAs($by ?? $this->owner)->postJson(route('tender-facts.apply', $fact), ['target' => $target, ...$data]);
    }

    public function test_a_fact_is_put_to_use_only_once_a_person_confirmed_it(): void
    {
        $proposed = $this->makeFact('due_at', '15-12-2026 up to 15:00 hrs', 2, ['status' => CrmTenderFact::PROPOSED, 'page_viewed' => false, 'confirmed_by' => null, 'confirmed_at' => null]);

        $this->apply($proposed, 'field')->assertStatus(409)->assertJson(['code' => 'not_confirmed', 'message' => 'Confirm this fact against its page before you use it.']);

        $rejected = $this->makeFact('reference', 'SWD/77/2026-27', 1, ['status' => CrmTenderFact::REJECTED]);
        $this->apply($rejected, 'field')->assertStatus(409)->assertJson(['code' => 'not_confirmed']);

        $this->tender->refresh();
        $this->assertNull($this->tender->due_at);
        $this->assertNull($this->tender->reference);
        $this->assertNull($proposed->refresh()->applied_to);
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'tender_fact.applied')->count());
    }

    public function test_a_deadline_goes_into_the_tender_with_its_time_and_a_new_warning(): void
    {
        $this->tender->forceFill(['due_at' => '2026-12-01 12:00', 'due_alerted_at' => now()])->save();
        $fact = $this->makeFact('due_at', 'Last date of online submission: 15-12-2026 up to 15:00 hrs');

        $this->apply($fact, 'field')->assertCreated()->assertJson(['id' => $fact->id, 'status' => 'confirmed', 'applied_to' => ['field'], 'repeated' => false]);

        $this->tender->refresh();
        $this->assertSame('2026-12-15 15:00', $this->tender->due_at->format('Y-m-d H:i'));
        $this->assertNull($this->tender->due_alerted_at, 'A new deadline deserves a new warning.');
        $this->assertSame('field', $fact->refresh()->applied_to);

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'tender_fact.applied')->sole();
        $this->assertSame(['target' => 'field', 'field' => 'due_at', 'page' => 2, 'record_type' => $this->tender->getMorphClass(), 'record_id' => $this->tender->id], $audit->new_values);
        $this->assertSame((int) $this->owner->id, (int) $audit->user_id);

        $activity = CrmActivity::withoutGlobalScopes()->where('subject_type', 'tender')->where('subject_id', $this->tender->id)->sole();
        $this->assertSame('Submission deadline taken from the tender sheet (page 2).', $activity->body);

        // The same request again changes nothing more and answers with what the first one did.
        $this->apply($fact, 'field')->assertOk()->assertJson(['repeated' => true, 'record' => ['id' => $this->tender->id]]);
        $this->assertSame(1, CrmActivity::withoutGlobalScopes()->where('subject_id', $this->tender->id)->count());
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'tender_fact.applied')->count());

        // A fact that was put to use stays on the sheet and can no longer be rejected.
        $this->actingAs($this->owner)->postJson(route('tender-facts.reject', $fact))->assertStatus(409);
    }

    public function test_a_date_without_a_time_is_not_put_into_a_deadline_but_can_be_a_follow_up(): void
    {
        $date = $this->makeFact('due_at', '15-12-2026', 2);

        $this->apply($date, 'field')->assertStatus(422)->assertJsonValidationErrors(['value' => 'No time was read with this date, so it is not put on the tender. Add it as a follow-up, or type it on the tender yourself.']);
        $this->assertNull($this->tender->refresh()->due_at);

        $uuid = '7d1c3f44-5a5e-4c1e-9d43-2f0d6f3c8a11';
        $response = $this->apply($date, 'task', ['client_uuid' => $uuid])->assertCreated()->assertJson(['applied_to' => ['task']]);

        $task = CrmTask::withoutGlobalScopes()->findOrFail($response->json('record.id'));
        $this->assertSame(['Submission deadline', 'tender', $this->tender->id, '2026-12-15', null, $uuid, $this->owner->id],
            [$task->title, $task->subject_type, (int) $task->subject_id, $task->due_on->toDateString(), $task->due_time, $task->client_uuid, (int) $task->assigned_to]);
        $this->assertStringStartsWith('From the tender sheet, page 2:', $task->notes);
        $this->assertSame('task:'.$task->id, $date->refresh()->applied_to);

        // A meeting read with its time becomes a follow-up at that time; a date that repeats is named by its printed label.
        $meeting = $this->makeFact('pre_bid_at', 'Pre-bid meeting on 10-12-2026 at 11:00 hrs', 3);
        $task = CrmTask::withoutGlobalScopes()->findOrFail($this->apply($meeting, 'task')->assertCreated()->json('record.id'));
        $this->assertSame(['2026-12-10', '11:00'], [$task->due_on->toDateString(), $task->due_time]);

        $visit = $this->makeFact('other_date', '05-12-2026', 3, ['label' => 'Site visit']);
        $task = CrmTask::withoutGlobalScopes()->findOrFail($this->apply($visit, 'task')->assertCreated()->json('record.id'));
        $this->assertSame('Other date: Site visit', $task->title);
        $this->assertSame(3, CrmTask::withoutGlobalScopes()->count());
    }

    public function test_links_other_than_http_and_https_are_refused_and_the_tender_is_unchanged(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==', 'ftp://files.example.test/nit.pdf', 'etender.example.test/nit/77'] as $link) {
            $fact = $this->makeFact('portal_url', $link, 1);

            $this->apply($fact, 'field')->assertStatus(422)->assertJsonValidationErrors('value');
            $this->assertNull($this->tender->refresh()->portal_url, $link);
            $this->assertNull($fact->refresh()->applied_to);
            CrmTenderFact::withoutGlobalScopes()->whereKey($fact->id)->update(['status' => CrmTenderFact::REJECTED]);
        }

        // The sheet shows a link read from a document as text, never as a link.
        $this->makeFact('portal_url', 'javascript:alert(1)', 1, ['status' => CrmTenderFact::PROPOSED]);
        $sheet = $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk();
        $sheet->assertSee('javascript:alert(1)');
        $sheet->assertDontSee('href="javascript:', false);
        CrmTenderFact::withoutGlobalScopes()->where('status', CrmTenderFact::PROPOSED)->update(['status' => CrmTenderFact::REJECTED]);

        $fact = $this->makeFact('portal_url', 'https://etender.example.test/nit/77', 1);
        $this->apply($fact, 'field')->assertCreated();
        $this->assertSame('https://etender.example.test/nit/77', $this->tender->refresh()->portal_url);
    }

    public function test_a_payment_is_always_planned_and_needs_the_right_to_record_emd_and_fees(): void
    {
        $emd = $this->makeFact('emd_amount', 'Earnest Money Deposit: Rs. 61,500/-', 2);

        $this->apply($emd, 'payment', [], $this->pm)->assertStatus(422)->assertJsonValidationErrors(['mode' => 'Choose how the payment will be made.']);
        $this->apply($emd, 'payment', ['mode' => 'barter'], $this->pm)->assertStatus(422)->assertJsonValidationErrors('mode');

        // Someone who manages tenders but does not record EMD and fees.
        $role = CustomRole::create(['organization_id' => $this->organization->id, 'name' => 'Tender writer', 'permissions' => ['crm.view', 'crm.manage', 'agents.use']]);
        $writer = $this->member($this->organization, 'viewer');
        $writer->forceFill(['custom_role_id' => $role->id])->save();
        $writer->refresh();

        $this->apply($emd, 'payment', ['mode' => 'online'], $writer)->assertForbidden();
        $this->actingAs($writer)->get(route('tender-facts.index', $this->tender))->assertOk()->assertDontSee('Add as a planned payment');

        // Accounts records EMD and fees, but does not manage tenders, so it does not put sheet facts to use.
        $this->apply($emd, 'payment', ['mode' => 'online'], $this->member($this->organization, 'accounts'))->assertForbidden();
        $this->assertSame(0, CrmTenderPayment::withoutGlobalScopes()->count());

        $response = $this->apply($emd, 'payment', ['mode' => 'dd', 'status' => 'paid', 'paid_on' => '2026-11-01'], $this->pm)->assertCreated();
        $payment = CrmTenderPayment::withoutGlobalScopes()->findOrFail($response->json('record.id'));
        $this->assertSame(['emd', '61500.00', 'dd', 'planned', null, true], [$payment->kind, (string) $payment->amount, $payment->mode, $payment->status, $payment->paid_on, (bool) $payment->refundable]);
        $this->assertSame('payment:'.$payment->id, $emd->refresh()->applied_to);

        $fee = $this->makeFact('tender_fee', 'Tender fee: Rs. 2,950/- non refundable', 2);
        $payment = CrmTenderPayment::withoutGlobalScopes()->findOrFail($this->apply($fee, 'payment', ['mode' => 'online'], $this->pm)->assertCreated()->json('record.id'));
        $this->assertSame(['tender_fee', '2950.00', 'planned', null, false], [$payment->kind, (string) $payment->amount, $payment->status, $payment->paid_on, (bool) $payment->refundable]);

        $this->assertSame(2, CrmActivity::withoutGlobalScopes()->where('body', 'like', 'Planned payment added from the tender sheet:%')->count());
    }

    public function test_a_value_the_app_could_not_read_or_that_fails_the_tender_rule_is_not_used(): void
    {
        $lakh = $this->makeFact('estimated_value', 'Rs. 48.60 lakh', 1);
        $this->assertNull($lakh->value_norm);
        $this->apply($lakh, 'field')->assertStatus(422)->assertJsonValidationErrors(['value' => 'The app could not read this as one amount, so it is not used. Type it on the tender yourself.']);

        $long = $this->makeFact('state', str_repeat('Maharashtra ', 8), 1);
        $this->apply($long, 'field')->assertStatus(422)->assertJsonValidationErrors('value');

        $this->tender->refresh();
        $this->assertSame(['0.00', null], [(string) $this->tender->estimated_value, $this->tender->state]);

        // The unread value still stands beside the readable one until a person rejects it.
        $value = $this->makeFact('estimated_value', 'Estimated cost put to tender: Rs. 48,60,000/-', 1, ['label' => 'Estimated cost']);
        $this->apply($value, 'field')->assertStatus(409)->assertJson(['code' => 'in_conflict']);
        $this->actingAs($this->owner)->postJson(route('tender-facts.reject', $lakh))->assertOk();
        $this->apply($value->refresh(), 'field')->assertCreated();
        $this->assertSame('4860000.00', (string) $this->tender->refresh()->estimated_value);

        // The pre-bid meeting place is the tender's pre-bid note.
        $venue = $this->makeFact('pre_bid_venue', 'Conference hall, Sample Works Division office', 2);
        $this->apply($venue, 'field')->assertCreated();
        $this->assertSame('Conference hall, Sample Works Division office', $this->tender->refresh()->pre_bid_note);
    }

    public function test_an_unchecked_line_a_fact_in_contradiction_and_a_field_with_no_place_on_the_tender_are_not_used(): void
    {
        $unchecked = $this->makeFact('reference', 'SWD/77/2026-27', 1, ['quote_found' => false, 'page_viewed' => false]);
        $this->apply($unchecked, 'field')->assertStatus(409)->assertJson(['code' => 'unchecked']);

        CrmTenderFact::withoutGlobalScopes()->whereKey($unchecked->id)->update(['edited' => true]);
        $this->apply($unchecked->refresh(), 'field')->assertCreated();
        $this->assertSame('SWD/77/2026-27', $this->tender->refresh()->reference);

        $one = $this->makeFact('opens_at', '17-12-2026 at 11:00 hrs', 2);
        $two = $this->makeFact('opens_at', '18-12-2026 at 11:00 hrs', 5);
        $this->assertNotNull($one->refresh()->conflict_group);
        $this->apply($one, 'field')->assertStatus(409)->assertJson(['code' => 'in_conflict']);
        $this->apply($two->refresh(), 'task')->assertStatus(409)->assertJson(['code' => 'in_conflict']);

        // Once the value that does not stand is rejected, the other can be used.
        $this->actingAs($this->owner)->postJson(route('tender-facts.reject', $two))->assertOk();
        $this->apply($one->refresh(), 'field')->assertCreated();
        $this->assertSame('2026-12-17 11:00', $this->tender->refresh()->opens_at->format('Y-m-d H:i'));

        $clause = $this->makeFact('clause', 'Joint venture is not allowed.', 4, ['label' => 'Joint venture']);
        $this->apply($clause, 'field')->assertStatus(422)->assertJsonValidationErrors(['target' => 'This fact cannot be used that way. It stays on the sheet.']);
        $percent = $this->makeFact('emd_percent', '2.37%', 2);
        $this->apply($percent, 'payment', ['mode' => 'online'])->assertStatus(422)->assertJsonValidationErrors('target');
        $this->apply($percent, 'sideways')->assertStatus(422)->assertJsonValidationErrors('target');
    }

    public function test_who_may_put_a_fact_to_use(): void
    {
        $fact = $this->makeFact('reference', 'SWD/77/2026-27', 1);

        $this->apply($fact, 'field', [], $this->qs)->assertForbidden();
        $this->apply($fact, 'field', [], $this->member($this->organization, 'engineer'))->assertForbidden();

        $outsider = $this->member($this->tenant('Godavari Infra'), 'owner');
        $this->apply($fact, 'field', [], $outsider)->assertNotFound();

        // A company whose plan has no CRM does not reach the sheet at all.
        $this->organization->update(['plan' => 'free']);
        $this->apply($fact, 'field')->assertForbidden();

        $this->assertNull($this->tender->refresh()->reference);
        $this->assertNull($fact->refresh()->applied_to);
    }

    public function test_the_sheet_offers_each_use_with_what_it_will_do(): void
    {
        $deadline = $this->makeFact('due_at', '15-12-2026 up to 15:00 hrs', 2);
        $this->makeFact('emd_amount', 'Rs. 61,500/-', 2);
        $this->makeFact('completion_period', '9 (Nine) months', 3);

        $sheet = $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk();
        $sheet->assertSee('Submission deadline on the tender becomes 15 Dec 2026, 03:00 PM.');
        $sheet->assertSee('A follow-up on this tender for 15 Dec 2026, 03:00 PM.');
        $sheet->assertSee('A planned payment of ₹61,500.00 on this tender. It is not marked paid.');
        $sheet->assertSee('Put on the tender');
        $sheet->assertSee('Add a follow-up');
        $sheet->assertSee('Add as a planned payment');
        $sheet->assertSee('name="mode"', false);
        $this->assertSame(3, substr_count((string) $sheet->getContent(), 'name="target"'), 'A period has no place on the tender: it stays on the sheet.');

        $this->actingAs($this->owner)->post(route('tender-facts.apply', $deadline), ['target' => 'field'])
            ->assertRedirect(route('tender-facts.index', $this->tender).'#fact-'.$deadline->id)->assertSessionHas('success', 'Put on the tender.');
        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()->assertSee('Put on the tender.');

        // Someone who may only look sees no way to use a fact.
        $this->actingAs($this->qs)->get(route('tender-facts.index', $this->tender))->assertOk()->assertDontSee('name="target"', false);

        $this->owner->update(['locale' => 'hi']);
        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()->assertSee('फॉलो-अप जोड़ें')->assertSee('टेंडर पर डाला गया।');
        $this->owner->update(['locale' => 'mr']);
        $this->actingAs($this->owner)->get(route('tender-facts.index', $this->tender))->assertOk()->assertSee('नियोजित पेमेंट म्हणून जोडा');
    }
}
