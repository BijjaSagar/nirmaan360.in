<?php

namespace Tests\Feature\ContractTerms;

use App\Models\AuditLog;
use App\Models\ContractTerm;
use App\Services\Access\TeamAccessService;
use Illuminate\Support\Str;

/** The contract terms screen and its four writes: who may use them, what they accept and what they save. */
class ContractTermsHttpTest extends ContractTermsTestCase
{
    public function test_a_percentage_deduction_is_saved_as_typed_and_does_not_count_until_confirmed(): void
    {
        $this->actingAs($this->qs)->post(route('contract-terms.store', $this->project), $this->form([
            'cap_amount' => '250000.50', 'nature' => 'withheld_refundable', 'effective_to' => '2027-03-31',
        ]))->assertSessionHasNoErrors()->assertSessionHas('success');

        $term = ContractTerm::sole();

        $this->assertSame($this->org->id, $term->organization_id);
        $this->assertSame($this->project->id, $term->project_id);
        $this->assertNull($term->work_order_id);
        $this->assertSame(['deduction', 'retention', 'Retention', 'percent', 'gross', 'withheld_refundable'], [$term->kind, $term->code, $term->label, $term->method, $term->base, $term->nature]);
        $this->assertSame('7.2500', $term->pct);
        $this->assertSame('250000.50', $term->cap_amount);
        $this->assertSame(['2026-04-01', '2027-03-31'], [$term->effective_from->toDateString(), $term->effective_to->toDateString()]);
        $this->assertSame('Sample agreement, part C', $term->source_ref);
        $this->assertSame($this->qs->id, $term->entered_by);
        $this->assertFalse($term->isConfirmed());
        $this->assertNull($term->confirmed_by);
        $this->assertNull($term->amount);
        $this->assertNull($term->days);
    }

    public function test_each_kind_keeps_only_the_fields_that_belong_to_it(): void
    {
        $workOrder = $this->workOrder();
        $this->actingAs($this->pm);
        // Everything a form could send at once. Only what belongs to the kind may be saved.
        $noise = ['method' => 'percent', 'pct' => '3', 'base' => 'running', 'amount' => '900', 'cap_amount' => '800', 'nature' => 'cost', 'days' => '12', 'counted_from' => 'hindrance_end', 'combine' => 'latest', 'combine_group' => 'A', 'value_text' => 'AG/77', 'value_date' => '2026-02-03'];
        $empty = array_fill_keys(ContractTerm::VALUE_COLUMNS, null);

        $cases = [
            'fixed amount' => [['kind' => 'deduction', 'code' => 'other', 'label' => 'Site office rent', 'method' => 'fixed_per_bill'], ['method' => 'fixed_per_bill', 'amount' => '900.00', 'nature' => 'cost']],
            'typed each bill' => [['kind' => 'deduction', 'code' => 'royalty', 'method' => 'typed_each_bill'], ['method' => 'typed_each_bill', 'nature' => 'cost']],
            'notice' => [['kind' => 'notice', 'code' => 'eot_notice'], ['days' => 12, 'counted_from' => 'hindrance_end', 'combine' => 'latest', 'combine_group' => 'A']],
            'payment period' => [['kind' => 'payment_period', 'code' => 'payment_due', 'counted_from' => 'bill_date'], ['days' => 12, 'counted_from' => 'bill_date']],
            'excess quantity' => [['kind' => 'excess_quantity', 'code' => 'excess_quantity'], ['pct' => '3.0000']],
            'agreement number' => [['kind' => 'reference', 'code' => 'agreement_no'], ['value_text' => 'AG/77']],
            'completion date' => [['kind' => 'reference', 'code' => 'stipulated_completion'], ['value_date' => '2026-02-03']],
        ];

        foreach ($cases as $name => [$input, $kept]) {
            $uuid = (string) Str::uuid();
            $this->post(route('contract-terms.store', $this->project), [...$this->form(), ...$noise, ...$input, 'client_uuid' => $uuid, 'work_order_id' => $workOrder->id])->assertSessionHasNoErrors();

            $term = ContractTerm::where('client_uuid', $uuid)->sole();
            $saved = collect($term->only(ContractTerm::VALUE_COLUMNS))->map(fn ($value, $key) => $key === 'value_date' ? $value?->toDateString() : $value)->all();

            $this->assertSame([...$empty, ...$kept], $saved, $name);
            $this->assertSame($workOrder->id, $term->work_order_id, $name);
        }

        // A blank name takes the code's own; "other" keeps the name that was typed.
        $this->assertSame('Royalty', ContractTerm::where('code', 'royalty')->sole()->label);
        $this->assertSame('Site office rent', ContractTerm::where('code', 'other')->sole()->label);
    }

    public function test_a_term_is_refused_when_what_its_kind_needs_is_missing_or_wrong(): void
    {
        $this->actingAs($this->pm);
        $post = fn (array $overrides) => $this->post(route('contract-terms.store', $this->project), [...$this->form(), 'client_uuid' => (string) Str::uuid(), ...$overrides]);

        $post(['kind' => 'penalty'])->assertSessionHasErrors('kind');
        $post(['code' => 'eot_notice'])->assertSessionHasErrors('code');
        $post(['kind' => 'deduction.retention', 'code' => 'retention'])->assertSessionHasErrors(['kind', 'code']);
        $post(['pct' => ''])->assertSessionHasErrors('pct');
        $post(['pct' => '-1'])->assertSessionHasErrors('pct');
        $post(['pct' => '101'])->assertSessionHasErrors('pct');
        $post(['pct' => '1.23456'])->assertSessionHasErrors('pct');
        $post(['base' => ''])->assertSessionHasErrors('base');
        $post(['base' => 'net'])->assertSessionHasErrors('base');
        $post(['method' => ''])->assertSessionHasErrors('method');
        $post(['cap_amount' => '0'])->assertSessionHasErrors('cap_amount');
        $post(['nature' => 'gift'])->assertSessionHasErrors('nature');
        // The bill works these four out as a percentage, so their term is one too.
        $post(['method' => 'fixed_per_bill', 'amount' => '500'])->assertSessionHasErrors('method');
        $post(['code' => 'tds', 'method' => 'typed_each_bill'])->assertSessionHasErrors('method');
        $post(['code' => 'insurance', 'method' => 'fixed_per_bill', 'amount' => ''])->assertSessionHasErrors('amount');
        $post(['code' => 'insurance', 'method' => 'fixed_per_bill', 'amount' => '12.345'])->assertSessionHasErrors('amount');
        $post(['code' => 'other', 'label' => ''])->assertSessionHasErrors('label');

        $post(['effective_from' => ''])->assertSessionHasErrors('effective_from');
        $post(['effective_from' => 'tomorrow'])->assertSessionHasErrors('effective_from');
        $post(['effective_to' => '2026-03-31'])->assertSessionHasErrors('effective_to');
        $post(['source_ref' => ''])->assertSessionHasErrors('source_ref');
        $post(['client_uuid' => ''])->assertSessionHasErrors('client_uuid');
        $post(['client_uuid' => 'not-a-uuid'])->assertSessionHasErrors('client_uuid');

        $notice = ['kind' => 'notice', 'code' => 'delay_notice', 'days' => '9', 'counted_from' => 'hindrance_start'];
        $post([...$notice, 'days' => ''])->assertSessionHasErrors('days');
        $post([...$notice, 'days' => '0'])->assertSessionHasErrors('days');
        $post([...$notice, 'days' => '2.5'])->assertSessionHasErrors('days');
        $post([...$notice, 'counted_from' => ''])->assertSessionHasErrors('counted_from');
        $post([...$notice, 'counted_from' => 'bill_date'])->assertSessionHasErrors('counted_from');
        $post([...$notice, 'combine' => 'latest'])->assertSessionHasErrors('combine_group');
        $post([...$notice, 'combine_group' => 'A'])->assertSessionHasErrors('combine');
        $post([...$notice, 'combine' => 'both', 'combine_group' => 'A'])->assertSessionHasErrors('combine');

        $post(['kind' => 'payment_period', 'code' => 'payment_due', 'days' => '9', 'counted_from' => 'hindrance_start'])->assertSessionHasErrors('counted_from');
        $post(['kind' => 'excess_quantity', 'code' => 'excess_quantity', 'pct' => ''])->assertSessionHasErrors('pct');
        $post(['kind' => 'reference', 'code' => 'agreement_no', 'value_text' => ''])->assertSessionHasErrors('value_text');
        $post(['kind' => 'reference', 'code' => 'agreement_date', 'value_date' => ''])->assertSessionHasErrors('value_date');
        $post(['kind' => 'reference', 'code' => 'extension_granted', 'value_date' => '31/03/2027'])->assertSessionHasErrors('value_date');

        $this->assertSame(0, ContractTerm::count());
    }

    public function test_the_work_order_must_be_a_live_one_on_the_same_project_of_the_same_company(): void
    {
        $elsewhere = $this->workOrder($this->makeProject($this->org, ['code' => 'MB2']), 'WO-21');
        [, , $rivalProject] = $this->rival();
        $rivals = $this->workOrder($rivalProject, 'WO-31');
        $removed = $this->workOrder(null, 'WO-41');
        $removed->delete();

        $this->actingAs($this->pm);

        foreach ([$elsewhere, $rivals, $removed] as $workOrder) {
            $this->post(route('contract-terms.store', $this->project), $this->form(['work_order_id' => $workOrder->id]))->assertSessionHasErrors('work_order_id');
        }

        $this->post(route('contract-terms.store', $this->project), $this->form(['work_order_id' => 'abc']))->assertSessionHasErrors('work_order_id');
        $this->assertSame(0, ContractTerm::count());

        // A term already on a work order that was removed since can still be corrected where it is.
        $term = $this->term(['work_order_id' => $removed->id]);
        $this->patch(route('contract-terms.update', $term), $this->form(['work_order_id' => $removed->id, 'pct' => '8']))->assertSessionHasNoErrors();
        $this->assertSame(['8.0000', $removed->id], [$term->refresh()->pct, $term->work_order_id]);
        $this->patch(route('contract-terms.update', $term), $this->form(['work_order_id' => $elsewhere->id]))->assertSessionHasErrors('work_order_id');
    }

    public function test_pressing_save_twice_adds_one_term_and_a_repeat_cannot_cross_projects(): void
    {
        $form = $this->form();

        $this->actingAs($this->pm)->post(route('contract-terms.store', $this->project), $form)->assertSessionHasNoErrors();
        $this->actingAs($this->pm)->post(route('contract-terms.store', $this->project), [...$form, 'pct' => '9'])->assertSessionHasNoErrors();

        $this->assertSame(1, ContractTerm::count());
        $this->assertSame('7.2500', ContractTerm::sole()->pct);
        $this->assertSame($form['client_uuid'], ContractTerm::sole()->client_uuid);

        $elsewhere = $this->makeProject($this->org, ['code' => 'MB2']);
        $this->post(route('contract-terms.store', $elsewhere), $form)->assertSessionHasErrors('client_uuid');

        // A removed term still holds its request id, so a late repeat does not bring it back as a second row.
        ContractTerm::sole()->delete();
        $this->post(route('contract-terms.store', $this->project), $form)->assertSessionHasNoErrors();
        $this->assertSame(0, ContractTerm::count());
        $this->assertSame(1, ContractTerm::withTrashed()->count());

        $this->get(route('contract-terms.index', $this->project))->assertOk()->assertSee('name="client_uuid"', false);
    }

    public function test_a_repeat_from_someone_who_cannot_reach_the_first_project_is_refused(): void
    {
        $form = $this->form();
        $this->actingAs($this->pm)->post(route('contract-terms.store', $this->project), $form)->assertSessionHasNoErrors();

        $elsewhere = $this->makeProject($this->org, ['code' => 'MB2']);
        $limited = $this->member($this->org, 'pm');
        app(TeamAccessService::class)->applyProjects($limited, true, [$elsewhere->id]);

        $this->actingAs($limited->refresh())->post(route('contract-terms.store', $elsewhere), $form)->assertSessionHasErrors('client_uuid');
        $this->assertSame(1, ContractTerm::withoutGlobalScopes()->count());
    }

    public function test_confirming_needs_the_tick_and_records_who_and_when(): void
    {
        $term = $this->term();
        $this->travelTo('2026-11-13 10:30:00');
        $this->actingAs($this->pm);

        $this->post(route('contract-terms.confirm', $term))->assertSessionHasErrors(['read_in_agreement', 'seen']);
        $this->post(route('contract-terms.confirm', $term), [...$this->tick($term), 'read_in_agreement' => '0'])->assertSessionHasErrors('read_in_agreement');
        $this->post(route('contract-terms.confirm', $term), ['read_in_agreement' => '1'])->assertSessionHasErrors('seen');
        $this->post(route('contract-terms.confirm', $term), ['read_in_agreement' => '1', 'seen' => 'short'])->assertSessionHasErrors('seen');
        $this->assertFalse($term->refresh()->isConfirmed());

        $this->post(route('contract-terms.confirm', $term), $this->tick($term))->assertSessionHasNoErrors();

        $term->refresh();
        $this->assertTrue($term->isConfirmed());
        $this->assertSame($this->pm->id, $term->confirmed_by);
        $this->assertSame('2026-11-13 10:30:00', $term->confirmed_at->format('Y-m-d H:i:s'));

        // Confirming again changes nothing: the first person and time stay.
        $this->travelTo('2026-11-14 09:00:00');
        $this->actingAs($this->qs)->post(route('contract-terms.confirm', $term), $this->tick($term))->assertSessionHasNoErrors();
        $this->assertSame([$this->pm->id, '2026-11-13'], [$term->refresh()->confirmed_by, $term->confirmed_at->toDateString()]);
    }

    public function test_a_term_changed_after_the_page_was_opened_is_not_confirmed_unseen(): void
    {
        $term = $this->term();
        $this->actingAs($this->pm);
        $opened = $this->tick($term);

        // Someone else corrects the percentage while the first person still has the old one on screen.
        $this->actingAs($this->qs)->patch(route('contract-terms.update', $term), $this->form(['pct' => '6']))->assertSessionHasNoErrors();

        $this->actingAs($this->pm)->post(route('contract-terms.confirm', $term), $opened)
            ->assertSessionHasErrors(['read_in_agreement' => 'This term was changed after you opened the page. Read it again, then confirm it.']);
        $this->assertFalse($term->refresh()->isConfirmed());

        // The page as it is now carries the new fingerprint, and that one is accepted.
        $html = $this->get(route('contract-terms.index', $this->project))->assertOk()->getContent();
        $this->assertStringContainsString('name="seen" value="'.$term->fingerprint().'"', $html);
        $this->assertStringNotContainsString($opened['seen'], $html);

        $this->post(route('contract-terms.confirm', $term), $this->tick($term))->assertSessionHasNoErrors();
        $this->assertTrue($term->refresh()->isConfirmed());
    }

    public function test_the_fingerprint_follows_everything_a_person_would_read_and_nothing_else(): void
    {
        $term = $this->term(['cap_amount' => '5000', 'nature' => 'cost', 'effective_to' => '2027-03-31']);
        $print = $term->fingerprint();

        $this->assertSame(64, strlen($print));
        $this->assertSame($print, ContractTerm::findOrFail($term->id)->fingerprint(), 'A saved term reads back the same.');

        $workOrder = $this->workOrder();
        $changes = [
            'work_order_id' => $workOrder->id, 'code' => 'tds', 'label' => 'Retention money', 'pct' => '7.2501', 'base' => 'running', 'cap_amount' => '5000.01',
            'nature' => 'recovery', 'effective_from' => '2026-04-02', 'effective_to' => null, 'source_ref' => 'Sample agreement, part D',
            'method' => 'typed_each_bill', 'amount' => '1', 'days' => 3, 'counted_from' => 'bill_date', 'combine' => 'latest', 'combine_group' => 'A',
            'value_text' => 'AG/77', 'value_date' => '2026-02-03', 'kind' => 'notice',
        ];

        foreach ($changes as $column => $value) {
            $changed = ContractTerm::findOrFail($term->id)->forceFill([$column => $value]);
            $this->assertNotSame($print, $changed->fingerprint(), "{$column} is part of what is confirmed.");
        }

        // Who entered or confirmed it, and when, is not part of what the agreement says.
        $term->update(['confirmed_by' => $this->owner->id, 'confirmed_at' => now(), 'entered_by' => $this->qs->id]);
        $this->assertSame($print, $term->refresh()->fingerprint());
    }

    public function test_changing_a_confirmed_term_takes_its_confirmation_away(): void
    {
        $term = $this->confirmed();
        $this->actingAs($this->qs);

        // Saving the same values again is not a change.
        $this->patch(route('contract-terms.update', $term), $this->form(['pct' => '7.250']))->assertSessionHasNoErrors()->assertSessionHas('success', 'Term saved.');
        $this->assertTrue($term->refresh()->isConfirmed());

        $this->patch(route('contract-terms.update', $term), $this->form(['pct' => '6']))->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Term changed. Its confirmation was removed, so confirm it against the agreement again.');

        $term->refresh();
        $this->assertSame('6.0000', $term->pct);
        $this->assertFalse($term->isConfirmed());
        $this->assertNull($term->confirmed_by);

        // Giving it an end date is a change as well.
        $term->update(['confirmed_by' => $this->owner->id, 'confirmed_at' => now()]);
        $this->patch(route('contract-terms.update', $term), $this->form(['pct' => '6', 'effective_to' => '2026-12-31']))->assertSessionHasNoErrors();
        $this->assertFalse($term->refresh()->isConfirmed());
        $this->assertSame('2026-12-31', $term->effective_to->toDateString());
    }

    public function test_an_update_cannot_change_the_request_id_the_project_or_who_entered_the_term(): void
    {
        $term = $this->term(['client_uuid' => '11111111-1111-4111-8111-111111111111', 'entered_by' => $this->pm->id]);
        $elsewhere = $this->makeProject($this->org, ['code' => 'MB2']);

        $this->actingAs($this->qs)->patch(route('contract-terms.update', $term), $this->form([
            'client_uuid' => '22222222-2222-4222-8222-222222222222', 'project_id' => $elsewhere->id, 'organization_id' => 999,
            'entered_by' => $this->qs->id, 'confirmed_by' => $this->qs->id, 'confirmed_at' => '2026-01-01 00:00:00', 'pct' => '3.5',
        ]))->assertSessionHasNoErrors();

        $term->refresh();
        $this->assertSame('3.5000', $term->pct);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $term->client_uuid);
        $this->assertSame([$this->project->id, $this->org->id, $this->pm->id], [$term->project_id, $term->organization_id, $term->entered_by]);
        $this->assertFalse($term->isConfirmed());
    }

    public function test_a_confirmation_cannot_be_slipped_in_with_the_add_form(): void
    {
        $this->actingAs($this->pm)->post(route('contract-terms.store', $this->project), $this->form([
            'confirmed_by' => $this->pm->id, 'confirmed_at' => '2026-01-01 00:00:00', 'read_in_agreement' => '1',
        ]))->assertSessionHasNoErrors();

        $this->assertFalse(ContractTerm::sole()->isConfirmed());
    }

    public function test_two_confirmed_terms_of_one_name_cannot_cover_the_same_day_on_one_contract(): void
    {
        $workOrder = $this->workOrder();
        $this->confirmed(['effective_from' => '2026-04-01', 'effective_to' => '2026-09-30']);
        $this->actingAs($this->pm);
        $confirm = fn (ContractTerm $term) => $this->post(route('contract-terms.confirm', $term), $this->tick($term));

        // Overlaps the last day of the confirmed one.
        $clash = $this->term(['pct' => '6', 'effective_from' => '2026-09-30']);
        $confirm($clash)->assertSessionHasErrors(['read_in_agreement' => 'Another confirmed term of the same name is already entered for this contract: 01 Apr 2026 to 30 Sep 2026. Give that one an end date first, or correct the dates of this one.']);
        $this->assertFalse($clash->refresh()->isConfirmed());

        // The next day is free, and so are another contract and another name.
        $confirm($next = $this->term(['pct' => '6', 'effective_from' => '2026-10-01']))->assertSessionHasNoErrors();
        $confirm($subcontract = $this->term(['pct' => '4', 'work_order_id' => $workOrder->id]))->assertSessionHasNoErrors();
        $confirm($other = $this->term(['code' => 'tds', 'label' => 'TDS', 'pct' => '0.75']))->assertSessionHasNoErrors();

        foreach ([$next, $subcontract, $other] as $term) {
            $this->assertTrue($term->refresh()->isConfirmed());
        }

        // An open-ended term now runs into the one that starts in October.
        $open = $this->term(['pct' => '9', 'effective_from' => '2025-01-01', 'effective_to' => '2026-03-31']);
        $confirm($open)->assertSessionHasNoErrors();
        $open->update(['effective_to' => null]);
        $this->assertTrue($open->refresh()->isConfirmed(), 'A direct write is not the form, so it keeps the confirmation for this check.');
        $late = $this->term(['pct' => '2', 'effective_from' => '2030-01-01']);
        $confirm($late)->assertSessionHasErrors(['read_in_agreement' => 'Another confirmed term of the same name is already entered for this contract: From 01 Jan 2025. Give that one an end date first, or correct the dates of this one.']);

        // An unconfirmed or a removed term of the same name is no clash.
        $open->delete();
        $next->delete();
        $this->term(['pct' => '3', 'effective_from' => '2029-01-01']);
        $confirm($late)->assertSessionHasNoErrors();
    }

    public function test_a_refused_confirmation_or_change_comes_back_to_the_term_it_was_about(): void
    {
        $this->confirmed(['effective_from' => '2026-04-01']);
        $clash = $this->term(['pct' => '6', 'effective_from' => '2026-06-01']);
        $index = route('contract-terms.index', $this->project);
        $this->actingAs($this->pm);

        $page = $this->followingRedirects()->from($index)->post(route('contract-terms.confirm', $clash), [...$this->tick($clash), '_term' => $clash->id])->assertOk();

        $page->assertSee('Another confirmed term of the same name is already entered for this contract: From 01 Apr 2026.')
            ->assertSee('x-data="{ add: false }"', false)
            ->assertSee('name="_term" value="'.$clash->id.'"', false);
        // The menu has folding groups of its own, so only the terms in the page are counted.
        $this->assertSame(2, preg_match_all('/<details class="border-b/', $page->getContent()));
        $this->assertSame(1, preg_match_all('/<details class="border-b[^>]*\bopen\b/', $page->getContent()), 'Only the term the confirmation was about is open.');

        // A change that fails keeps what was typed in that term's own form, and leaves the add form shut and empty.
        $page = $this->followingRedirects()->from($index)->patch(route('contract-terms.update', $clash), [...$this->form(['pct' => '250', 'source_ref' => 'Typed but not saved']), '_term' => $clash->id])->assertOk();

        $page->assertSee('x-data="{ add: false }"', false)->assertSee('value="Typed but not saved"', false);
        $this->assertSame(1, substr_count($page->getContent(), 'value="Typed but not saved"'));
        $this->assertSame('6.0000', $clash->refresh()->pct);

        // A failed add opens the add form again with what was typed.
        $page = $this->followingRedirects()->from($index)->post(route('contract-terms.store', $this->project), $this->form(['pct' => '250', 'source_ref' => 'Typed on the add form']))->assertOk();
        $page->assertSee('x-data="{ add: true }"', false)->assertSee('value="Typed on the add form"', false);
        $this->assertSame(1, substr_count($page->getContent(), 'value="Typed on the add form"'));
    }

    public function test_heads_a_contract_can_have_several_of_are_confirmed_side_by_side(): void
    {
        $this->actingAs($this->pm);
        $confirm = fn (ContractTerm $term) => $this->post(route('contract-terms.confirm', $term), $this->tick($term));
        $head = ['code' => 'other', 'method' => 'fixed_per_bill', 'pct' => null, 'base' => null, 'amount' => '1500.00'];

        $confirm($this->term([...$head, 'label' => 'Site office rent']))->assertSessionHasNoErrors();
        $confirm($this->term([...$head, 'label' => 'Water charges']))->assertSessionHasNoErrors();

        $extension = ['kind' => 'reference', 'code' => 'extension_granted', 'label' => 'Extension granted up to', 'method' => null, 'pct' => null, 'base' => null];
        $confirm($this->term([...$extension, 'value_date' => '2027-06-30']))->assertSessionHasNoErrors();
        $confirm($this->term([...$extension, 'value_date' => '2027-09-30']))->assertSessionHasNoErrors();

        $this->assertSame(4, ContractTerm::whereNotNull('confirmed_at')->count());
    }

    public function test_two_notice_periods_for_one_notice_count_together_only_as_a_named_pair(): void
    {
        $this->actingAs($this->pm);
        $confirm = fn (ContractTerm $term) => $this->post(route('contract-terms.confirm', $term), $this->tick($term));
        $notice = ['kind' => 'notice', 'code' => 'eot_notice', 'label' => 'Notice for extension of time', 'method' => null, 'pct' => null, 'base' => null];

        $confirm($this->term([...$notice, 'days' => 11, 'counted_from' => 'hindrance_start', 'combine' => 'latest', 'combine_group' => 'A']))->assertSessionHasNoErrors();

        // No group, another group, or the other choice: the app could not tell which date applies.
        foreach ([['combine' => null, 'combine_group' => null], ['combine' => 'latest', 'combine_group' => 'B'], ['combine' => 'earliest', 'combine_group' => 'A']] as $pairing) {
            $loose = $this->term([...$notice, 'days' => 4, 'counted_from' => 'hindrance_end', ...$pairing]);
            $confirm($loose)->assertSessionHasErrors('read_in_agreement');
            $this->assertFalse($loose->refresh()->isConfirmed());
        }

        $pair = $this->term([...$notice, 'days' => 4, 'counted_from' => 'hindrance_end', 'combine' => 'latest', 'combine_group' => 'A']);
        $confirm($pair)->assertSessionHasNoErrors();
        $this->assertTrue($pair->refresh()->isConfirmed());

        // A different notice is its own matter.
        $confirm($this->term([...$notice, 'code' => 'delay_notice', 'label' => 'Notice of delay', 'days' => 6, 'counted_from' => 'hindrance_start']))->assertSessionHasNoErrors();
    }

    public function test_removing_a_term_keeps_the_row_and_the_audit_log_tells_the_story(): void
    {
        $this->actingAs($this->pm)->post(route('contract-terms.store', $this->project), $this->form())->assertSessionHasNoErrors();
        $term = ContractTerm::sole();

        $this->actingAs($this->qs)->post(route('contract-terms.confirm', $term), $this->tick($term))->assertSessionHasNoErrors();
        $this->actingAs($this->pm)->patch(route('contract-terms.update', $term), $this->form(['pct' => '6']))->assertSessionHasNoErrors();
        $this->actingAs($this->pm)->delete(route('contract-terms.destroy', $term))->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(0, ContractTerm::count());
        $this->assertTrue(ContractTerm::withTrashed()->sole()->trashed());

        $logs = AuditLog::withoutGlobalScopes()->where('auditable_type', $term->getMorphClass())->where('auditable_id', $term->id)->orderBy('id')->get();

        $this->assertSame(['created', 'updated', 'updated', 'deleted'], $logs->pluck('action')->all());
        $this->assertSame([$this->pm->id, $this->qs->id, $this->pm->id, $this->pm->id], $logs->pluck('user_id')->all());
        $this->assertSame($this->qs->id, $logs[1]->new_values['confirmed_by']);
        $this->assertNull($logs[2]->new_values['confirmed_by']);
        $this->assertSame($this->qs->id, $logs[2]->old_values['confirmed_by']);
        $this->assertEquals('6', $logs[2]->new_values['pct']);
        $this->assertSame($this->org->id, $logs[0]->organization_id);
    }

    public function test_the_page_lists_every_term_under_its_contract_with_what_was_typed(): void
    {
        $workOrder = $this->workOrder();
        $this->confirmed(['cap_amount' => '250000.50', 'nature' => 'withheld_refundable', 'entered_by' => $this->qs->id]);
        $this->term(['code' => 'other', 'label' => 'Site office rent', 'method' => 'fixed_per_bill', 'pct' => null, 'base' => null, 'amount' => '1500.00', 'work_order_id' => $workOrder->id]);
        $this->term(['kind' => 'notice', 'code' => 'delay_notice', 'label' => 'Notice of delay', 'method' => null, 'pct' => null, 'base' => null, 'days' => 9, 'counted_from' => 'hindrance_start', 'effective_from' => '2031-01-01']);
        $this->term(['kind' => 'reference', 'code' => 'agreement_date', 'label' => 'Agreement date', 'method' => null, 'pct' => null, 'base' => null, 'value_date' => '2026-02-03', 'effective_from' => '2026-02-03', 'effective_to' => '2026-02-28']);
        $this->travelTo('2026-11-13 10:00:00');

        $this->actingAs($this->accounts)->get(route('contract-terms.index', $this->project))->assertOk()
            ->assertSeeInOrder(['Client contract', 'Retention', 'Notice of delay', 'Agreement date', 'WO-11', 'Slab shuttering', 'Site office rent'])
            ->assertSee('7.25%')->assertSee('Worked out on: The gross bill amount')->assertSee('Not more than ₹2,50,000.50 in all')->assertSee('Held back and paid later')
            ->assertSee('₹1,500.00 on every bill')
            ->assertSee('9 days')->assertSee('Counted from: The day the hindrance started')->assertSee('Not yet in force')
            ->assertSee('03 Feb 2026')->assertSee('03 Feb 2026 to 28 Feb 2026')->assertSee('Ended')
            ->assertSee('From 01 Apr 2026')->assertSee('Sample agreement, part C')
            ->assertSee($this->qs->name)->assertSee($this->owner->name)
            ->assertSee('Not confirmed yet: 3. A term that is not confirmed does not count.')
            ->assertSee('Entering a term here does not change any bill.')
            // Accounts may read the page and change nothing on it.
            ->assertDontSee('Add a term')->assertDontSee('I have read this in the agreement')->assertDontSee('Save changes')->assertDontSee('Remove this term');

        $this->actingAs($this->qs)->get(route('contract-terms.index', $this->project))->assertOk()
            ->assertSee('Add a term')->assertSee('I have read this in the agreement')->assertSee('Save changes')->assertSee('Remove this term');
    }

    public function test_an_empty_page_says_nothing_is_assumed_and_the_add_form_starts_empty(): void
    {
        $this->workOrder();
        $html = $this->actingAs($this->pm)->get(route('contract-terms.index', $this->project))->assertOk()
            ->assertSee('No contract terms entered')->assertSee('Nothing is assumed until you enter it.')
            ->assertSee('The app fills in nothing on this page.')
            ->assertDontSee('Not confirmed yet')
            ->getContent();

        // No number, date or text is filled in for the user, and no choice that says what the agreement holds is made for them.
        foreach (['label', 'pct', 'cap_amount', 'amount', 'days', 'combine_group', 'value_text', 'value_date', 'effective_from', 'effective_to', 'source_ref'] as $field) {
            $this->assertSame(1, preg_match('/<input[^>]*\bname="'.$field.'"[^>]*>/', $html, $input), $field);
            $this->assertSame(1, preg_match('/\bvalue="([^"]*)"/', $input[0], $value), $field);
            $this->assertSame('', $value[1], "The add form fills in {$field}.");
        }

        foreach (['code', 'method', 'base', 'nature', 'counted_from', 'combine'] as $field) {
            $this->assertGreaterThanOrEqual(1, preg_match_all('/<select[^>]*\bname="'.$field.'"[^>]*>.*?<\/select>/s', $html, $selects), $field);

            foreach ($selects[0] as $select) {
                $this->assertStringNotContainsString(' selected', $select, "The add form chooses a {$field}.");
                $this->assertSame(1, preg_match('/<option value="">/', $select), "{$field} starts on an empty choice.");
            }
        }
    }

    public function test_pm_and_qs_manage_accounts_only_reads_and_site_roles_are_kept_out(): void
    {
        $term = $this->term();
        $writes = fn () => [
            $this->post(route('contract-terms.store', $this->project), $this->form()),
            $this->patch(route('contract-terms.update', $term), $this->form(['pct' => '1'])),
            $this->post(route('contract-terms.confirm', $term), $this->tick($term)),
            $this->delete(route('contract-terms.destroy', $term)),
        ];

        $this->actingAs($this->accounts)->get(route('contract-terms.index', $this->project))->assertOk();
        foreach ($writes() as $response) {
            $response->assertForbidden();
        }

        $outsiders = [
            $this->engineer, $this->member($this->org, 'store'), $this->member($this->org, 'viewer'),
            // The page carries contract amounts, so the module's own permission is not enough without money.view.
            $this->holding(['project.view', 'contract_terms.view', 'contract_terms.manage']),
            // And money.view alone does not open it.
            $this->holding(['project.view', 'money.view']),
        ];

        foreach ($outsiders as $user) {
            $this->actingAs($user)->get(route('contract-terms.index', $this->project))->assertForbidden();

            foreach ($writes() as $response) {
                $response->assertForbidden();
            }
        }

        $this->assertSame('7.2500', $term->refresh()->pct);
        $this->assertFalse($term->isConfirmed());
        $this->assertSame(1, ContractTerm::count());

        foreach ([$this->owner, $this->member($this->org, 'admin'), $this->pm, $this->qs, $this->holding(['project.view', 'money.view', 'contract_terms.manage'])] as $user) {
            $this->actingAs($user)->post(route('contract-terms.store', $this->project), $this->form())->assertSessionHasNoErrors()->assertRedirect();
        }

        $this->assertSame(6, ContractTerm::count());

        auth()->logout();
        $this->get(route('contract-terms.index', $this->project))->assertRedirect(route('login'));
    }

    public function test_one_company_cannot_see_or_touch_anothers_terms(): void
    {
        $term = $this->confirmed(['source_ref' => 'Our own agreement, annexure D']);
        [, $rivalOwner, $rivalProject] = $this->rival();
        $this->term(['code' => 'other', 'label' => 'Rival head', 'method' => 'typed_each_bill', 'pct' => null, 'base' => null], $rivalProject);

        $this->actingAs($rivalOwner);
        $this->get(route('contract-terms.index', $this->project))->assertNotFound();
        $this->get(route('contract-terms.index', $rivalProject))->assertOk()->assertSee('Rival head')->assertDontSee('7.25%')->assertDontSee('Our own agreement, annexure D');
        $this->post(route('contract-terms.store', $this->project), $this->form())->assertNotFound();
        $this->patch(route('contract-terms.update', $term), $this->form(['pct' => '1']))->assertNotFound();
        $this->post(route('contract-terms.confirm', $term), $this->tick($term))->assertNotFound();
        $this->delete(route('contract-terms.destroy', $term))->assertNotFound();

        $term->refresh();
        $this->assertSame('7.2500', $term->pct);
        $this->assertFalse($term->trashed());
        $this->assertSame(1, ContractTerm::withoutGlobalScopes()->where('organization_id', $this->org->id)->count());
    }

    public function test_someone_limited_to_other_projects_cannot_open_or_change_this_projects_terms(): void
    {
        $term = $this->term();
        $elsewhere = $this->makeProject($this->org, ['code' => 'MB2']);
        $theirs = $this->term(['code' => 'tds', 'label' => 'TDS'], $elsewhere);
        $limited = $this->member($this->org, 'pm');
        app(TeamAccessService::class)->applyProjects($limited, true, [$elsewhere->id]);
        $this->actingAs($limited->refresh());

        $this->get(route('contract-terms.index', $this->project))->assertNotFound();
        $this->post(route('contract-terms.store', $this->project), $this->form())->assertNotFound();
        $this->patch(route('contract-terms.update', $term), $this->form(['pct' => '1']))->assertNotFound();
        $this->post(route('contract-terms.confirm', $term), $this->tick($term))->assertNotFound();
        $this->delete(route('contract-terms.destroy', $term))->assertNotFound();

        $this->get(route('contract-terms.index', $elsewhere))->assertOk()->assertSee('TDS');
        $this->post(route('contract-terms.confirm', $theirs), $this->tick($theirs))->assertSessionHasNoErrors();
        $this->assertTrue($theirs->refresh()->isConfirmed());
        $this->assertFalse($term->refresh()->isConfirmed());
    }

    public function test_the_screen_needs_a_plan_with_billing(): void
    {
        $free = $this->tenant('Free Co', 'free');
        $owner = $this->member($free, 'owner');
        $project = $this->makeProject($free);
        $term = $this->term([], $project);

        $this->actingAs($owner);
        $this->get(route('contract-terms.index', $project))->assertForbidden();
        $this->post(route('contract-terms.store', $project), $this->form())->assertForbidden();
        $this->post(route('contract-terms.confirm', $term), $this->tick($term))->assertForbidden();

        $contractor = $this->tenant('Contractor Co', 'contractor');
        $this->actingAs($this->member($contractor, 'owner'))->get(route('contract-terms.index', $this->makeProject($contractor)))->assertOk();
    }

    public function test_a_term_of_a_removed_project_can_no_longer_be_changed(): void
    {
        $term = $this->term();
        $this->project->delete();
        $this->actingAs($this->owner);

        $this->patch(route('contract-terms.update', $term), $this->form(['pct' => '1']))->assertNotFound();
        $this->post(route('contract-terms.confirm', $term), $this->tick($term))->assertNotFound();
        $this->delete(route('contract-terms.destroy', $term))->assertNotFound();

        $this->assertSame('7.2500', $term->refresh()->pct);
        $this->assertFalse($term->trashed());
    }
}
