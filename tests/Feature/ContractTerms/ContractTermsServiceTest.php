<?php

namespace Tests\Feature\ContractTerms;

use App\Models\ContractTerm;
use App\Services\Access\TeamAccessService;
use App\Services\ContractTerms\ContractTerms;
use App\Services\ContractTerms\TermText;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/** Which terms count on a day, for which contract, and how a term reads. */
class ContractTermsServiceTest extends ContractTermsTestCase
{
    private function effective(string $on, ?string $kind = null, $workOrder = null, $project = null): array
    {
        return app(ContractTerms::class)->effective($project ?? $this->project, $workOrder, Carbon::parse($on), $kind)->pluck('id')->all();
    }

    public function test_a_term_counts_from_its_first_day_to_its_last_and_only_once_confirmed(): void
    {
        $dated = $this->confirmed(['effective_from' => '2026-04-01', 'effective_to' => '2026-09-30']);
        $open = $this->confirmed(['code' => 'tds', 'label' => 'TDS', 'pct' => '0.75', 'effective_from' => '2026-06-15']);
        $this->term(['code' => 'gst', 'label' => 'GST', 'pct' => '4', 'effective_from' => '2026-01-01']);

        $this->assertSame([], $this->effective('2026-03-31'));
        $this->assertSame([$dated->id], $this->effective('2026-04-01'));
        $this->assertSame([$dated->id], $this->effective('2026-06-14'));
        $this->assertSame([$dated->id, $open->id], $this->effective('2026-06-15'));
        $this->assertSame([$dated->id, $open->id], $this->effective('2026-09-30'));
        $this->assertSame([$open->id], $this->effective('2026-10-01'));
        $this->assertSame([$open->id], $this->effective('2040-01-01'));

        // The time of day does not move either end.
        $this->assertSame([$dated->id], $this->effective('2026-04-01 00:00:00'));
        $this->assertSame([$dated->id, $open->id], $this->effective('2026-09-30 23:59:59'));
    }

    public function test_an_unconfirmed_a_changed_and_a_removed_term_do_not_count(): void
    {
        $term = $this->term();
        $this->assertSame([], $this->effective('2026-05-01'));

        $this->actingAs($this->pm);
        $service = app(ContractTerms::class);
        $service->confirm($term, $this->pm);
        $this->assertSame([$term->id], $this->effective('2026-05-01'));

        $service->update($term, [...$this->form(), 'pct' => '6']);
        $this->assertSame([], $this->effective('2026-05-01'));

        $service->confirm($term, $this->pm);
        $this->assertSame([$term->id], $this->effective('2026-05-01'));

        $service->delete($term);
        $this->assertSame([], $this->effective('2026-05-01'));
    }

    public function test_a_change_saved_from_a_stale_copy_never_ends_up_under_someone_elses_confirmation(): void
    {
        $this->actingAs($this->pm);
        $service = app(ContractTerms::class);
        $term = $this->term();

        // One request loaded the term before it was confirmed, and saves its change after.
        $stale = ContractTerm::findOrFail($term->id);
        $service->confirm($term, $this->qs, $term->fingerprint());
        $this->assertTrue($term->refresh()->isConfirmed());

        $service->update($stale, [...$this->form(), 'pct' => '6']);

        $term->refresh();
        $this->assertSame('6.0000', $term->pct);
        $this->assertFalse($term->isConfirmed());
        $this->assertSame([], $this->effective('2026-05-01'));

        // And a stale copy that changes nothing leaves a confirmation alone.
        $stale = ContractTerm::findOrFail($term->id);
        $service->confirm($term, $this->qs);
        $service->update($stale, [...$this->form(), 'pct' => '6']);
        $this->assertTrue($term->refresh()->isConfirmed());

        // A term removed in the meantime can be neither changed nor confirmed.
        $stale = ContractTerm::findOrFail($term->id);
        $service->delete($term);

        foreach ([fn () => $service->update($stale, [...$this->form(), 'pct' => '3.5']), fn () => $service->confirm($stale, $this->qs)] as $late) {
            try {
                $late();
                $this->fail('A removed term was changed.');
            } catch (ModelNotFoundException) {
                // Gone.
            }
        }

        $this->assertSame('6.0000', ContractTerm::withTrashed()->findOrFail($term->id)->pct);
    }

    public function test_the_client_contract_and_each_subcontract_have_their_own_terms(): void
    {
        $first = $this->workOrder(null, 'WO-11');
        $second = $this->workOrder(null, 'WO-12');
        $client = $this->confirmed();
        $forFirst = $this->confirmed(['work_order_id' => $first->id, 'pct' => '4']);

        $this->assertSame([$client->id], $this->effective('2026-05-01'));
        $this->assertSame([$forFirst->id], $this->effective('2026-05-01', null, $first));
        $this->assertSame([], $this->effective('2026-05-01', null, $second));
    }

    public function test_one_kind_can_be_asked_for(): void
    {
        $deduction = $this->confirmed();
        $empty = ['method' => null, 'pct' => null, 'base' => null];
        $notice = $this->confirmed([...$empty, 'kind' => 'notice', 'code' => 'delay_notice', 'label' => 'Notice of delay', 'days' => 9, 'counted_from' => 'hindrance_start']);
        $date = $this->confirmed([...$empty, 'kind' => 'reference', 'code' => 'stipulated_completion', 'label' => 'Stipulated completion date', 'value_date' => '2027-03-31']);

        $this->assertSame([$deduction->id, $notice->id, $date->id], $this->effective('2026-05-01'));
        $this->assertSame([$deduction->id], $this->effective('2026-05-01', 'deduction'));
        $this->assertSame([$notice->id], $this->effective('2026-05-01', 'notice'));
        $this->assertSame([$date->id], $this->effective('2026-05-01', 'reference'));
        $this->assertSame([], $this->effective('2026-05-01', 'payment_period'));
        $this->assertSame([], $this->effective('2026-05-01', 'no such kind'));

        $found = app(ContractTerms::class)->effective($this->project, null, Carbon::parse('2026-05-01'), 'notice')->sole();
        $this->assertSame([9, 'hindrance_start', null], [$found->days, $found->counted_from, $found->combine]);
        $this->assertTrue($found->isConfirmed());
    }

    public function test_with_nobody_signed_in_it_still_returns_only_this_project_of_this_company(): void
    {
        $mine = $this->confirmed();
        $this->confirmed(['pct' => '2'], $this->makeProject($this->org, ['code' => 'MB2']));
        [, , $rivalProject] = $this->rival();
        $theirs = $this->confirmed(['pct' => '3'], $rivalProject);

        Auth::forgetGuards();
        $this->assertFalse(Auth::check());

        $this->assertSame([$mine->id], $this->effective('2026-05-01'));
        $this->assertSame([$theirs->id], $this->effective('2026-05-01', null, null, $rivalProject));
    }

    public function test_someone_limited_to_other_projects_gets_nothing_from_this_one(): void
    {
        $term = $this->confirmed();
        $elsewhere = $this->makeProject($this->org, ['code' => 'MB2']);
        $limited = $this->member($this->org, 'pm');
        app(TeamAccessService::class)->applyProjects($limited, true, [$elsewhere->id]);

        $this->actingAs($limited->refresh());
        $this->assertSame([], $this->effective('2026-05-01'));

        $this->actingAs($this->pm);
        $this->assertSame([$term->id], $this->effective('2026-05-01'));
    }

    public function test_a_work_order_of_another_project_is_a_mistake_in_the_calling_code(): void
    {
        $elsewhere = $this->workOrder($this->makeProject($this->org, ['code' => 'MB2']), 'WO-21');

        $this->expectException(InvalidArgumentException::class);

        app(ContractTerms::class)->effective($this->project, $elsewhere, Carbon::parse('2026-05-01'));
    }

    public function test_a_term_reads_back_as_it_was_typed(): void
    {
        $text = app(TermText::class);
        $empty = ['method' => null, 'pct' => null, 'base' => null];
        $this->actingAs($this->accounts);

        $percent = $this->term(['cap_amount' => '250000.50', 'nature' => 'withheld_refundable', 'effective_to' => '2027-03-31']);
        $this->assertSame('7.25%', $text->value($percent));
        $this->assertSame(['Worked out on: The gross bill amount', 'Not more than ₹2,50,000.50 in all', 'Held back and paid later'], $text->notes($percent));
        $this->assertSame('01 Apr 2026 to 31 Mar 2027', $text->period($percent));
        $this->assertSame('Retention', $text->name($percent));
        $this->assertSame('Deduction from bills', $text->kind($percent));

        $this->assertSame('3%', $text->percent('3.0000'));
        $this->assertSame('0%', $text->percent('0.0000'));
        $this->assertSame('40%', $text->percent('40.0000'));
        $this->assertSame('0.125%', $text->percent('0.1250'));

        $fixed = $this->term([...$empty, 'code' => 'other', 'label' => 'Site office rent', 'method' => 'fixed_per_bill', 'amount' => '1500.00']);
        $this->assertSame('₹1,500.00 on every bill', $text->value($fixed));
        $this->assertSame('Site office rent', $text->name($fixed));
        $this->assertSame('From 01 Apr 2026', $text->period($fixed));

        $typed = $this->term([...$empty, 'code' => 'royalty', 'label' => 'Royalty', 'method' => 'typed_each_bill']);
        $this->assertSame('Worked out on each bill', $text->value($typed));
        $this->assertSame([], $text->notes($typed));

        $notice = $this->term([...$empty, 'kind' => 'notice', 'code' => 'eot_notice', 'label' => 'Notice for extension of time', 'days' => 11, 'counted_from' => 'hindrance_end', 'combine' => 'latest', 'combine_group' => 'A']);
        $this->assertSame('11 days', $text->value($notice));
        $this->assertSame(['Counted from: The day the hindrance ended', 'Group A: Whichever comes later'], $text->notes($notice));

        $payment = $this->term([...$empty, 'kind' => 'payment_period', 'code' => 'payment_due', 'label' => 'Payment due', 'days' => 1, 'counted_from' => 'bill_date']);
        $this->assertSame('1 day', $text->value($payment));
        $this->assertSame(['Counted from: The bill date'], $text->notes($payment));

        $excess = $this->term([...$empty, 'kind' => 'excess_quantity', 'code' => 'excess_quantity', 'label' => 'Quantity allowed above the BOQ', 'pct' => '12.5']);
        $this->assertSame('12.5% above the BOQ quantity', $text->value($excess));

        $number = $this->term([...$empty, 'kind' => 'reference', 'code' => 'agreement_no', 'label' => 'Agreement number', 'value_text' => 'AG/77']);
        $this->assertSame('AG/77', $text->value($number));

        $date = $this->term([...$empty, 'kind' => 'reference', 'code' => 'agreement_date', 'label' => 'Agreement date', 'value_date' => '2026-02-03']);
        $this->assertSame('03 Feb 2026', $text->value($date));

        // The name follows the reader's language only while it is the code's own name.
        app()->setLocale('mr');
        $this->assertSame('रिटेन्शन', $text->name($percent));
        $this->assertSame('Site office rent', $text->name($fixed));
        $this->assertSame('11 दिवस', $text->value($notice));
    }

    public function test_a_term_in_words_leaves_rupees_out_for_a_reader_who_may_not_see_money(): void
    {
        $text = app(TermText::class);
        $percent = $this->term(['cap_amount' => '250000.50']);
        $fixed = $this->term(['code' => 'other', 'label' => 'Site office rent', 'method' => 'fixed_per_bill', 'pct' => null, 'base' => null, 'amount' => '1500.00']);

        foreach ([$this->engineer, null] as $reader) {
            $reader ? $this->actingAs($reader) : Auth::forgetGuards();

            $words = implode(' ', [$text->value($percent), ...$text->notes($percent), $text->value($fixed), ...$text->notes($fixed)]);

            $this->assertStringNotContainsString('₹', $words);
            $this->assertStringNotContainsString('1,500', $words);
            $this->assertStringNotContainsString('2,50,000', $words);
            $this->assertSame('A fixed amount on every bill', $text->value($fixed));
        }
    }

    public function test_the_model_says_whether_its_dates_cover_a_day(): void
    {
        $term = $this->term(['effective_from' => '2026-04-01', 'effective_to' => '2026-09-30']);

        $this->assertFalse($term->covers(Carbon::parse('2026-03-31 23:59:59')));
        $this->assertTrue($term->covers(Carbon::parse('2026-04-01')));
        $this->assertTrue($term->covers(Carbon::parse('2026-09-30 18:00:00')));
        $this->assertFalse($term->covers(Carbon::parse('2026-10-01')));
        $this->assertTrue($this->term(['effective_from' => '2026-04-01'])->covers(Carbon::parse('2090-01-01')));
    }

    public function test_nothing_is_seeded_and_no_column_has_a_default(): void
    {
        // A new company, its first project and the sample data loader leave the table empty.
        [, $rivalOwner] = $this->rival();
        $this->artisan('sample:fill', ['email' => $rivalOwner->email])->assertSuccessful();

        $this->assertSame(0, ContractTerm::withoutGlobalScopes()->withTrashed()->count());

        foreach (glob(database_path('seeders/*.php')) as $seeder) {
            $this->assertStringNotContainsStringIgnoringCase('contractterm', (string) file_get_contents($seeder), basename($seeder));
            $this->assertStringNotContainsString('contract_terms', (string) file_get_contents($seeder), basename($seeder));
        }

        foreach (Schema::getColumns('contract_terms') as $column) {
            if ($column['name'] !== 'id') {
                $this->assertNull($column['default'], "contract_terms.{$column['name']} has a default.");
            }
        }

        // Every value a term can hold may be left empty by the table, so nothing has to be invented to save one.
        $nullable = collect(Schema::getColumns('contract_terms'))->where('nullable', true)->pluck('name')->all();
        $this->assertSame([], array_diff(ContractTerm::VALUE_COLUMNS, $nullable));
    }
}
