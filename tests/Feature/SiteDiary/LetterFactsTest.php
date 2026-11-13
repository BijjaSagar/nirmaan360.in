<?php

namespace Tests\Feature\SiteDiary;

use App\Models\ContractTerm;
use App\Models\Hindrance;
use App\Models\Organization;
use App\Models\RaBill;
use App\Services\SiteDiary\LetterFacts;
use App\Services\Wording\Fact;
use App\Services\Wording\FactSheet;
use App\Services\Wording\FactSource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The facts a letter may state, read from the records. Every figure asserted here is worked out
 * by hand from the invented records of the test.
 */
class LetterFactsTest extends SiteLetterTestCase
{
    private function facts(string $type, array $chosen): FactSheet
    {
        return app(LetterFacts::class)->build($this->project, $type, $chosen, today());
    }

    /** @return array<string, string> Fact key => value. */
    private function values(FactSheet $sheet): array
    {
        return array_column($sheet->toArray(), 'value', 'key');
    }

    /** @return array<string, list<?string>> Fact key => [source type, record id, field]. */
    private function sources(FactSheet $sheet): array
    {
        return array_map(fn (array $fact) => array_values($fact['source']), array_column($sheet->toArray(), null, 'key'));
    }

    /** The messages of a refusal, by field. */
    private function refusal(callable $build): array
    {
        try {
            $build();
        } catch (ValidationException $refusal) {
            return $refusal->errors();
        }

        $this->fail('The letter was prepared.');
    }

    public function test_a_request_for_more_time_states_the_agreement_each_hindrance_and_the_days_with_overlaps_counted_once(): void
    {
        $this->agreement('2026-08-31');
        $first = $this->entry('2026-03-02', '2026-03-06', ['description' => 'Podium slab drawings awaited'], ['2026-03-06', '2026-03-02', '2026-03-04', '2026-03-04']);
        $second = $this->entry('2026-03-05', '2026-03-09', ['category' => 'weather', 'description' => 'Heavy rain, site flooded']);
        $third = $this->entry('2026-03-15', null, ['category' => 'client_material', 'description' => 'Steel to be issued by the client not received'], []);

        $sheet = $this->facts(self::EOT, ['hindrance_ids' => [$third->id, $first->id, $second->id], 'addressed_to' => '  The Engineer in charge  ']);

        $this->assertSame([
            'letter_date' => '2026-03-20',
            'addressed_to' => 'The Engineer in charge',
            'project_name' => 'Lake View Residency',
            'agreement_no' => 'SG/LVR/07',
            'agreement_date' => '2025-09-15',
            'stipulated_completion' => '2026-08-31',
            // 2 to 6 March: both ends counted.
            'h1_category' => 'drawings_or_decisions',
            'h1_what' => 'Podium slab drawings awaited',
            'h1_start' => '2026-03-02',
            'h1_end' => '2026-03-06',
            'h1_days' => '5',
            // Four cited reports on three dates.
            'h1_report_days' => '3',
            'h1_first_report' => '2026-03-02',
            'h1_last_report' => '2026-03-06',
            'h2_category' => 'weather',
            'h2_what' => 'Heavy rain, site flooded',
            'h2_start' => '2026-03-05',
            'h2_end' => '2026-03-09',
            'h2_days' => '5',
            'h2_first_report' => '2026-03-05',
            // Still going on: 15 to 20 March, today counted. No report is cited, so none is stated.
            'h3_category' => 'client_material',
            'h3_what' => 'Steel to be issued by the client not received',
            'h3_start' => '2026-03-15',
            'h3_days' => '6',
            // 2 to 9 March is 8 days, 15 to 20 March is 6.
            'total_days' => '14',
            // 31 August and 14 days more.
            'proposed_completion' => '2026-09-14',
            'company_name' => 'Shivganga Buildcon',
        ], $this->values($sheet));

        $sources = $this->sources($sheet);
        $this->assertSame([FactSource::COMPUTED, null, 'letter_date'], $sources['letter_date']);
        $this->assertSame([FactSource::ENTERED, null, 'addressed_to'], $sources['addressed_to']);
        $this->assertSame(['project', (string) $this->project->id, 'name'], $sources['project_name']);
        $this->assertSame('contract_term', $sources['stipulated_completion'][0]);
        $this->assertSame(['hindrance', (string) $first->id, 'started_on'], $sources['h1_start']);
        $this->assertSame(['hindrance_source', (string) $first->id, 'report_days'], $sources['h1_report_days']);
        $this->assertSame([FactSource::COMPUTED, null, 'h3_days'], $sources['h3_days']);
        $this->assertSame([FactSource::COMPUTED, null, 'proposed_completion'], $sources['proposed_completion']);
        $this->assertSame(['organization', (string) $this->org->id, 'name'], $sources['company_name']);

        // Days and counts are whole numbers, dates are dates, and nothing here is money.
        $this->assertSame(Fact::DAYS, $sheet->get('total_days')->kind);
        $this->assertSame(Fact::COUNT, $sheet->get('h1_report_days')->kind);
        $this->assertSame(Fact::DATE, $sheet->get('proposed_completion')->kind);
        $this->assertFalse($sheet->hasKind(Fact::MONEY));
    }

    public function test_the_proposed_date_starts_from_the_latest_of_the_stipulated_date_and_the_extensions_granted(): void
    {
        $this->agreement('2026-08-31');
        $entry = $this->entry('2026-03-02', '2026-03-11');
        $chosen = ['hindrance_ids' => [$entry->id]];

        // An extension up to a day before the stipulated date changes nothing.
        $early = $this->reference('extension_granted', ['value_date' => '2026-07-15']);
        $sheet = $this->facts(self::EOT, $chosen);
        $this->assertSame(['2026-07-15', '2026-09-10'], [$sheet->get('extension_granted')->value, $sheet->get('proposed_completion')->value]);

        // Of several extensions the latest is the one stated and counted from: 30 September and 10 days more.
        $late = $this->reference('extension_granted', ['value_date' => '2026-09-30']);
        $this->reference('extension_granted', ['value_date' => '2026-09-10']);
        $sheet = $this->facts(self::EOT, $chosen);
        $this->assertSame(['2026-09-30', '2026-10-10'], [$sheet->get('extension_granted')->value, $sheet->get('proposed_completion')->value]);
        $this->assertSame(['contract_term', (string) $late->id, 'value_date'], array_values($sheet->get('extension_granted')->source->toArray()));

        // An extension nobody confirmed, one whose dates do not cover today, and one of a work order do not count.
        $this->reference('extension_granted', ['value_date' => '2026-12-31', 'confirmed_by' => null, 'confirmed_at' => null]);
        $this->reference('extension_granted', ['value_date' => '2026-12-30', 'effective_to' => '2026-03-19']);
        $this->reference('extension_granted', ['value_date' => '2026-12-29', 'effective_from' => '2026-03-21']);
        $this->reference('extension_granted', ['value_date' => '2026-12-28', 'work_order_id' => 77]);
        $this->assertSame('2026-10-10', $this->facts(self::EOT, $chosen)->get('proposed_completion')->value);

        $this->assertNotNull($early);
    }

    public function test_a_completion_date_the_person_types_is_stated_instead_and_marked_as_entered(): void
    {
        $this->agreement('2026-08-31');
        $entry = $this->entry('2026-03-02', '2026-03-11');

        $sheet = $this->facts(self::EOT, ['hindrance_ids' => [$entry->id], 'proposed_completion' => '2026-10-31']);
        $fact = $sheet->get('proposed_completion');

        $this->assertSame('2026-10-31', $fact->value);
        $this->assertSame([FactSource::ENTERED, null, 'proposed_completion'], array_values($fact->source->toArray()));
        $this->assertSame('Proposed completion date, entered by you', $fact->label);
        $this->assertSame('10', $sheet->get('total_days')->value, 'The days are still worked out and stated.');

        // Typed or worked out, the two sheets are not the same sheet.
        $this->assertNotSame($sheet->fingerprint(), $this->facts(self::EOT, ['hindrance_ids' => [$entry->id]])->fingerprint());
    }

    public function test_without_one_confirmed_stipulated_completion_date_no_request_for_more_time_is_prepared(): void
    {
        $entry = $this->entry('2026-03-02', '2026-03-06');
        $chosen = ['hindrance_ids' => [$entry->id]];

        $none = $this->refusal(fn () => $this->facts(self::EOT, $chosen));
        $this->assertSame(['type'], array_keys($none));
        $this->assertStringContainsString('No stipulated completion date is confirmed under Contract terms for today.', $none['type'][0]);

        // Typed, but confirmed by nobody.
        $term = $this->reference('stipulated_completion', ['value_date' => '2026-08-31', 'confirmed_by' => null, 'confirmed_at' => null]);
        $this->assertSame(['type'], array_keys($this->refusal(fn () => $this->facts(self::EOT, $chosen))));

        $term->update(['confirmed_by' => $this->owner->id, 'confirmed_at' => now()]);
        $this->assertSame('2026-08-31', $this->facts(self::EOT, $chosen)->get('stipulated_completion')->value);

        // Two for the same day, written straight to the table: the app does not choose between them.
        $second = $this->reference('stipulated_completion', ['value_date' => '2026-09-30']);
        $two = $this->refusal(fn () => $this->facts(self::EOT, $chosen));
        $this->assertStringContainsString('More than one confirmed stipulated completion date covers today.', $two['type'][0]);

        // The agreement number and date are stated when confirmed, and left out when not. No letter needs them.
        $second->delete();
        $sheet = $this->facts(self::EOT, $chosen);
        $this->assertFalse($sheet->has('agreement_no'));
        $this->assertFalse($sheet->has('agreement_date'));
        $this->assertFalse($sheet->has('addressed_to'));
        $this->assertFalse($sheet->has('extension_granted'));
    }

    public function test_a_notice_of_delay_states_the_hindrances_still_going_on_and_the_days_so_far(): void
    {
        $ongoing = $this->entry('2026-03-12', null, ['category' => 'site_handover', 'description' => 'East plot not handed over'], ['2026-03-12', '2026-03-18']);
        $ended = $this->entry('2026-03-02', '2026-03-06');

        $sheet = $this->facts(self::DELAY, ['hindrance_ids' => [$ongoing->id]]);

        $this->assertSame([
            'letter_date' => '2026-03-20',
            'project_name' => 'Lake View Residency',
            'h1_category' => 'site_handover',
            'h1_what' => 'East plot not handed over',
            'h1_start' => '2026-03-12',
            // 12 to 20 March, today counted.
            'h1_days' => '9',
            'h1_report_days' => '2',
            'h1_first_report' => '2026-03-12',
            'h1_last_report' => '2026-03-18',
            'company_name' => 'Shivganga Buildcon',
        ], $this->values($sheet));

        // It needs no completion date, and it does not take an entry that has ended.
        $refused = $this->refusal(fn () => $this->facts(self::DELAY, ['hindrance_ids' => [$ongoing->id, $ended->id]]));
        $this->assertSame(['hindrance_ids'], array_keys($refused));
        $this->assertStringContainsString('A notice of delay cites confirmed entries that are still going on.', $refused['hindrance_ids'][0]);

        // The day after, the same records give another day and so another sheet.
        $this->travel(1)->days();
        $tomorrow = $this->facts(self::DELAY, ['hindrance_ids' => [$ongoing->id]]);
        $this->assertSame(['2026-03-21', '10'], [$tomorrow->get('letter_date')->value, $tomorrow->get('h1_days')->value]);
        $this->assertNotSame($sheet->fingerprint(), $tomorrow->fingerprint());
    }

    public function test_only_confirmed_entries_that_count_can_be_cited(): void
    {
        $this->agreement();
        $good = $this->entry('2026-03-02', '2026-03-06');
        $chosen = fn (Hindrance ...$entries) => ['hindrance_ids' => [$good->id, ...array_map(fn (Hindrance $entry) => $entry->id, $entries)]];
        $refused = fn (Hindrance $entry) => $this->refusal(fn () => $this->facts(self::EOT, $chosen($entry)));

        $proposed = $this->entry('2026-03-08', '2026-03-09', ['status' => Hindrance::PROPOSED, 'proposed_by' => Hindrance::BY_AI, 'confirmed_by' => null, 'confirmed_at' => null]);
        $dismissed = $this->entry('2026-03-10', '2026-03-11', ['status' => Hindrance::DISMISSED]);
        $removed = $this->entry('2026-03-12', '2026-03-13');
        $removed->delete();
        $elsewhere = $this->entry('2026-03-02', '2026-03-06', [], null, $this->makeProject($this->org, ['code' => 'SG2']));
        [, , $rivalProject] = $this->rival();
        $rivals = $this->entry('2026-03-02', '2026-03-06', [], null, $rivalProject);

        // An entry that a letter cites and that a later entry corrects: the correction counts, the corrected one does not.
        $corrected = $this->entry('2026-03-14', '2026-03-15', ['locked_at' => now()]);
        $correction = $this->entry('2026-03-14', '2026-03-16', ['supersedes_id' => $corrected->id]);

        foreach ([$proposed, $dismissed, $removed, $elsewhere, $rivals, $corrected] as $entry) {
            $errors = $refused($entry);
            $this->assertSame(['hindrance_ids'], array_keys($errors));
            $this->assertStringContainsString('is not a confirmed entry that counts any more', $errors['hindrance_ids'][0]);
        }

        // None chosen is refused as well, and an id that is no entry at all.
        $this->assertSame(['hindrance_ids'], array_keys($this->refusal(fn () => $this->facts(self::EOT, ['hindrance_ids' => []]))));
        $this->assertSame(['hindrance_ids'], array_keys($this->refusal(fn () => $this->facts(self::EOT, ['hindrance_ids' => [$good->id, 999999]]))));

        // A locked entry that still counts, and a correction, can be cited.
        $locked = $this->entry('2026-03-17', '2026-03-17', ['locked_at' => now()]);
        $sheet = $this->facts(self::EOT, $chosen($correction, $locked));
        $this->assertSame(['2026-03-02', '2026-03-14', '2026-03-17'], [$sheet->get('h1_start')->value, $sheet->get('h2_start')->value, $sheet->get('h3_start')->value]);
        $this->assertSame('2026-03-16', $sheet->get('h2_end')->value);
        $this->assertSame('1', $sheet->get('h3_days')->value);
    }

    public function test_the_clause_typed_for_a_notice_period_is_repeated_and_nothing_is_added_to_it(): void
    {
        $this->agreement();
        $early = $this->entry('2026-02-10', '2026-02-12');
        $late = $this->entry('2026-03-02', '2026-03-06');
        $chosen = ['hindrance_ids' => [$early->id, $late->id]];

        // No notice period is entered: the letter repeats no clause, and none is made up.
        $this->assertFalse($this->facts(self::EOT, $chosen)->has('clause_1'));

        // The periods in force on the day each entry started, as the register reads them.
        $first = $this->notice(['code' => 'eot_notice', 'source_ref' => '  Sample agreement, part K  ', 'effective_to' => '2026-02-28']);
        $this->notice(['code' => 'eot_notice', 'source_ref' => 'Sample agreement, part K as amended', 'effective_from' => '2026-03-01']);
        $this->notice(['code' => 'eot_notice', 'source_ref' => 'Sample agreement, part K as amended', 'effective_from' => '2026-03-01', 'combine' => 'earliest', 'combine_group' => 'A']);
        // Not for this notice, confirmed by nobody, blank, or of a work order: left out.
        $this->notice(['code' => 'delay_notice', 'source_ref' => 'Sample agreement, part H']);
        $this->notice(['code' => 'eot_notice', 'source_ref' => 'Typed but not confirmed', 'confirmed_by' => null, 'confirmed_at' => null]);
        $this->notice(['code' => 'eot_notice', 'source_ref' => null]);
        $this->notice(['code' => 'eot_notice', 'source_ref' => 'Subcontract, part C', 'work_order_id' => 55]);

        $sheet = $this->facts(self::EOT, $chosen);
        $this->assertSame(['Sample agreement, part K', 'Sample agreement, part K as amended'], [$sheet->get('clause_1')->value, $sheet->get('clause_2')->value]);
        $this->assertFalse($sheet->has('clause_3'));
        $this->assertSame(Fact::REFERENCE, $sheet->get('clause_1')->kind);
        $this->assertSame(['contract_term', (string) $first->id, 'source_ref'], array_values($sheet->get('clause_1')->source->toArray()));

        // Only the later entry: only the period in force on its first day.
        $one = $this->facts(self::EOT, ['hindrance_ids' => [$late->id]]);
        $this->assertSame('Sample agreement, part K as amended', $one->get('clause_1')->value);
        $this->assertFalse($one->has('clause_2'));

        // A notice of delay repeats the clause of the notice of delay, not of the other notice.
        $ongoing = $this->entry('2026-03-10', null);
        $delay = $this->facts(self::DELAY, ['hindrance_ids' => [$ongoing->id]]);
        $this->assertSame('Sample agreement, part H', $delay->get('clause_1')->value);
        $this->assertFalse($delay->has('clause_2'));

        // The number of days of a period is never a fact of a letter.
        foreach ([$sheet, $delay] as $built) {
            $this->assertSame([], array_values(array_filter($built->keys(), fn (string $key) => str_contains($key, 'notice') || str_contains($key, 'deadline'))));
        }
    }

    public function test_a_payment_follow_up_states_each_unpaid_bill_from_the_receipts_recorded_on_it(): void
    {
        $this->reference('agreement_no', ['value_text' => 'SG/LVR/07']);

        // Net 2,50,000 with 5,000 TDS shown on the bill: 2,55,000 is receivable. One receipt of 1,00,000 with 2,000 TDS.
        $part = $this->clientBill('SG1/RA/1', '250000.00', ['approved_at' => '2026-02-10 10:00:00'], '5000.00');
        $this->receipt($part, '100000.00', '2000.00');
        // Nothing received yet, and never approved through the app: the end of its period is its date.
        $open = $this->clientBill('SG1/RA/2', '80000.50', ['approved_at' => null, 'period_to' => '2026-02-28']);
        // Approved today.
        $today = $this->clientBill('SG1/RA/3', '1200.00', ['approved_at' => '2026-03-20 09:00:00']);

        $sheet = $this->facts(self::PAYMENT, ['bill_ids' => [$today->id, $part->id, $open->id]]);

        $this->assertSame([
            'letter_date' => '2026-03-20',
            'project_name' => 'Lake View Residency',
            'agreement_no' => 'SG/LVR/07',
            'bill_1_number' => 'SG1/RA/1',
            'bill_1_date' => '2026-02-10',
            'bill_1_receivable' => '255000.00',
            'bill_1_received' => '100000.00',
            'bill_1_tds' => '2000.00',
            'bill_1_outstanding' => '153000.00',
            // 10 February to 20 March.
            'bill_1_days' => '38',
            'bill_2_number' => 'SG1/RA/2',
            'bill_2_date' => '2026-02-28',
            'bill_2_receivable' => '80000.50',
            'bill_2_received' => '0.00',
            'bill_2_outstanding' => '80000.50',
            'bill_2_days' => '20',
            'bill_3_number' => 'SG1/RA/3',
            'bill_3_date' => '2026-03-20',
            'bill_3_receivable' => '1200.00',
            'bill_3_received' => '0.00',
            'bill_3_outstanding' => '1200.00',
            'bill_3_days' => '0',
            'total_outstanding' => '234200.50',
            'company_name' => 'Shivganga Buildcon',
        ], $this->values($sheet));

        $sources = $this->sources($sheet);
        $this->assertSame(['ra_bill', (string) $part->id, 'approved_at'], $sources['bill_1_date']);
        $this->assertSame(['ra_bill', (string) $open->id, 'period_to'], $sources['bill_2_date']);
        $this->assertSame(['ra_bill', (string) $part->id, 'outstanding'], $sources['bill_1_outstanding']);
        $this->assertSame([FactSource::COMPUTED, null, 'total_outstanding'], $sources['total_outstanding']);

        foreach (['bill_1_receivable', 'bill_1_received', 'bill_1_tds', 'bill_1_outstanding', 'total_outstanding'] as $key) {
            $this->assertSame(Fact::MONEY, $sheet->get($key)->kind, $key);
        }

        // A receipt changes the sheet, to the paisa.
        $this->receipt($open, '0.50');
        $after = $this->facts(self::PAYMENT, ['bill_ids' => [$open->id]]);
        $this->assertSame(['0.50', '80000.00', '80000.00'], [$after->get('bill_1_received')->value, $after->get('bill_1_outstanding')->value, $after->get('total_outstanding')->value]);
    }

    public function test_a_payment_follow_up_takes_only_approved_client_bills_of_the_project_with_something_outstanding(): void
    {
        $unpaid = $this->clientBill('SG1/RA/1', '50000.00');
        $refused = function (RaBill $bill) use ($unpaid) {
            $errors = $this->refusal(fn () => $this->facts(self::PAYMENT, ['bill_ids' => [$unpaid->id, $bill->id]]));
            $this->assertSame(['bill_ids'], array_keys($errors), $bill->number);
            $this->assertStringContainsString('is not an approved client bill of this project with an amount outstanding any more', $errors['bill_ids'][0]);
        };

        $paid = $this->clientBill('SG1/RA/2', '20000.00');
        $this->receipt($paid, '19000.00', '1000.00');
        $refused($paid);

        $refused($this->clientBill('SG1/RA/3', '20000.00', ['status' => 'DRAFT', 'approved_at' => null]));
        $refused($this->clientBill('SG1/RA/4', '20000.00', ['status' => 'IN_REVIEW', 'approved_at' => null]));
        $refused($this->clientBill('SG1/SUB/1', '20000.00', ['type' => 'subcontractor']));
        $refused($this->clientBill('SG2/RA/1', '20000.00', [], '0', $this->makeProject($this->org, ['code' => 'SG2'])));
        [, , $rivalProject] = $this->rival();
        $refused($this->clientBill('RV1/RA/1', '20000.00', [], '0', $rivalProject));

        $this->assertSame(['bill_ids'], array_keys($this->refusal(fn () => $this->facts(self::PAYMENT, ['bill_ids' => []]))));
        $this->assertSame('50000.00', $this->facts(self::PAYMENT, ['bill_ids' => [$unpaid->id]])->get('total_outstanding')->value);

        // On a plan that keeps no bills and receipts there is nothing to make the letter from.
        Organization::whereKey($this->org->id)->update(['plan' => 'free']);
        $this->project->unsetRelation('organization');
        $errors = $this->refusal(fn () => $this->facts(self::PAYMENT, ['bill_ids' => [$unpaid->id]]));
        $this->assertSame(['type'], array_keys($errors));
        $this->assertFalse(app(LetterFacts::class)->billsAreKept($this->org->refresh()));
    }

    public function test_the_same_records_give_the_same_sheet_and_any_change_to_what_the_letter_states_gives_another(): void
    {
        $this->agreement();
        $entry = $this->entry('2026-03-02', '2026-03-06', [], ['2026-03-02', '2026-03-05']);
        $other = $this->entry('2026-03-08', '2026-03-09');
        $chosen = ['hindrance_ids' => [$entry->id, $other->id], 'addressed_to' => 'The Engineer in charge'];
        $print = fn (array $with = []) => $this->facts(self::EOT, [...$chosen, ...$with])->fingerprint();

        $before = $print();
        $this->assertSame($before, $print());
        $this->assertSame($before, $print(['hindrance_ids' => [$other->id, (string) $entry->id, $entry->id]]), 'The order and form of the ids make no difference.');

        // With nobody signed in the facts are the same: every query names the company and the project itself.
        $this->actingAs($this->pm);
        $signedIn = $print();
        Auth::logout();
        $this->assertSame($before, $signedIn);
        $this->assertSame($before, $print());

        // Each of these is a change to what the letter states.
        $this->assertNotSame($before, $print(['addressed_to' => 'The Executive Engineer']));
        $this->assertNotSame($before, $print(['hindrance_ids' => [$entry->id]]));

        $changes = [
            fn () => $entry->update(['description' => 'Podium slab drawings awaited from the architect']),
            fn () => $entry->update(['ended_on' => '2026-03-07']),
            fn () => $entry->update(['category' => 'other_agency']),
            fn () => $this->cite($entry, $this->dpr('2026-03-06', 'Drawings received')),
            fn () => $this->project->update(['name' => 'Lake View Residency Phase 1']),
            fn () => ContractTerm::query()->where('code', 'stipulated_completion')->update(['value_date' => '2026-09-01']),
            fn () => ContractTerm::query()->where('code', 'agreement_no')->update(['confirmed_at' => null]),
        ];

        foreach ($changes as $at => $change) {
            $change();
            $after = $print();
            $this->assertNotSame($before, $after, "Change {$at} was not noticed.");
            $before = $after;
        }

        // What the letter does not state changes nothing: who is said to be responsible, or the words of a cited report.
        $entry->update(['responsible' => 'Architect of the client']);
        $this->assertSame($before, $print());
    }
}
