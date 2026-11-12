<?php

namespace Tests\Feature\Wording;

use App\Models\AuditLog;
use App\Services\Wording\FinalCheck;
use App\Services\Wording\GuardViolation;
use App\Services\Wording\Rendered;
use App\Services\Wording\SlotText;
use App\Services\Wording\TypedNumber;
use App\Services\Wording\TypedNumbers;
use App\Services\Wording\WordingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\BuildsTenant;

/**
 * Guard D: a draft made from other figures than the records hold now is stopped.
 * Guard E: a number typed by a person is listed, needs its own tick, and the tick is written to the audit log.
 */
class FinalCheckTest extends WordingTestCase
{
    use BuildsTenant, RefreshDatabase;

    private function check(): FinalCheck
    {
        return app(FinalCheck::class);
    }

    private function edited(string $opening, string $locale = 'en'): Rendered
    {
        return $this->renderer()->render($this->letter(), $this->sheet(), $locale, ['opening' => SlotText::edited($opening)]);
    }

    /** @return array<string, list<string>> */
    private function refusal(callable $act): array
    {
        try {
            $act();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('Nothing was refused.');
    }

    public function test_guard_d_lets_a_draft_through_while_the_records_are_unchanged(): void
    {
        $stored = $this->sheet()->fingerprint();

        $this->check()->assertUnchanged($stored, $this->sheet());
        $this->addToAssertionCount(1);
    }

    public function test_guard_d_stops_a_draft_when_a_record_behind_it_has_changed(): void
    {
        $stored = $this->sheet()->fingerprint();

        foreach ([
            'a payment came in' => ['bill_1_outstanding' => '100000.00'],
            'a paisa' => ['bill_1_outstanding' => '150000.01'],
            'the date moved' => ['as_on' => '2026-11-06'],
            'the bill was renumbered' => ['bill_1_number' => 'RA-P101-004'],
            'a fact that the letter does not even print' => ['days_open' => '15'],
        ] as $what => $now) {
            $errors = $this->refusal(fn () => $this->check()->assertUnchanged($stored, $this->sheet($now)));

            $this->assertSame(['facts'], array_keys($errors), $what);
            $this->assertStringContainsString('Prepare the draft again', $errors['facts'][0]);
        }
    }

    public function test_guard_d_stops_a_draft_that_has_no_fingerprint(): void
    {
        foreach ([null, '', 'not-a-fingerprint'] as $stored) {
            $this->assertSame(['facts'], array_keys($this->refusal(fn () => $this->check()->assertUnchanged($stored, $this->sheet()))));
        }
    }

    public function test_guard_e_lists_a_number_a_person_typed(): void
    {
        $document = $this->edited('We wrote to you on 15/10/2026 and again 3 times about the 2 bills.');
        $typed = $document->typedNumbers();

        $this->assertSame(['15/10/2026', '3', '2'], array_map(fn (TypedNumber $number) => $number->text, $typed));
        $this->assertSame(['typed:opening:1:15/10/2026', 'typed:opening:2:3', 'typed:opening:3:2'], array_map(fn (TypedNumber $number) => $number->id, $typed));
        $this->assertSame('15/10/2026', mb_substr($document->slot('opening')->text, $typed[0]->offset, $typed[0]->length));
        $this->assertStringContainsString('again 3 times', $typed[1]->context);
        $this->assertSame(SlotText::EDITED, $document->slot('opening')->source);

        // Typed numbers are marked for the review screen, apart from the figures the code inserted.
        $marked = array_values(array_filter($document->slot('opening')->parts(), fn (array $part) => $part['typed'] !== null));
        $this->assertSame(['15/10/2026', '3', '2'], array_column($marked, 'text'));
        $this->assertSame([null, null, null], array_column($marked, 'fact'));
    }

    public function test_guard_e_lists_numbers_in_any_script_and_any_form(): void
    {
        $numbers = fn (string $text, string $locale = 'en') => array_map(
            fn (TypedNumber $number) => $number->text,
            TypedNumbers::find('opening', $text, $this->sheet(), $locale),
        );

        $this->assertSame(['१५'], $numbers('काम १५ दिवस बंद होते.', 'mr'));
        $this->assertSame(['2,00,000.50', '10:30', '10-15', '25'], $numbers('Rs 2,00,000.50 at 10:30, 10-15 bags of M25.'));
        $this->assertSame(['½'], $numbers('About ½ of the slab.'));
        $this->assertSame([], $numbers('No number here.'));
    }

    public function test_guard_e_takes_a_fact_as_from_the_records_only_in_its_whole_printed_form(): void
    {
        // Whole printed forms of facts on the sheet: not listed.
        $this->assertSame([], $this->edited('As on 5 November 2026 the bill RA-P101-003 shows ₹1,50,000.00 unpaid.')->typedNumbers());
        $this->assertSame([], $this->edited('5 नवंबर 2026 को ₹1,50,000.00 बकाया था।', 'hi')->typedNumbers());
        $this->assertSame([], $this->edited('5 नोव्हेंबर 2026 रोजी ₹1,50,000.00 थकबाकी होती.', 'mr')->typedNumbers());

        $texts = fn (string $opening, string $locale = 'en') => array_map(fn (TypedNumber $number) => $number->text, $this->edited($opening, $locale)->typedNumbers());

        // The same amount with one digit changed, without its rupee sign, or as part of a longer number.
        $this->assertSame(['1,60,000.00'], $texts('The bill shows ₹1,60,000.00 unpaid.'));
        $this->assertSame(['1,50,000.00'], $texts('The bill shows Rs 1,50,000.00 unpaid.'));
        $this->assertSame(['1,50,000.001'], $texts('The bill shows ₹1,50,000.001 unpaid.'));
        $this->assertSame(['21,50,000.00'], $texts('The bill shows ₹21,50,000.00 unpaid.'));

        // A date typed another way, a part of the date, and the English date in a Hindi letter.
        $this->assertSame(['05/11/2026'], $texts('As on 05/11/2026.'));
        $this->assertSame(['2026'], $texts('During 2026 we wrote often.'));
        $this->assertSame(['5', '2026'], $texts('5 November 2026 को पत्र भेजा।', 'hi'));

        // The bill number is a fact, another bill number is not.
        $this->assertSame(['101-004'], $texts('See also RA-P101-004.'));
    }

    public function test_a_document_needs_a_tick_for_every_fact_it_shows(): void
    {
        $document = $this->renderer()->render($this->letter(), $this->sheet(), 'en');

        $this->assertSame(['bill_1_number', 'bill_1_outstanding', 'as_on'], $document->ticksNeeded());
        $this->assertSame(['bill_1_outstanding', 'as_on'], $this->check()->missingTicks($document, ['bill_1_number', 'days_open', 7, null]));

        $errors = $this->refusal(fn () => $this->check()->assertTicked($document, ['bill_1_number', 'as_on']));

        $this->assertSame(['number_ticks'], array_keys($errors));
        $this->assertStringContainsString('Outstanding amount', $errors['number_ticks'][0]);
        $this->assertStringNotContainsString('1,50,000', $errors['number_ticks'][0]);

        $this->check()->assertTicked($document, ['as_on', 'bill_1_outstanding', 'bill_1_number', 'something_else']);
    }

    public function test_guard_e_refuses_until_every_typed_number_has_its_own_tick(): void
    {
        $document = $this->edited('We met 3 times about this.');
        $facts = ['bill_1_number', 'bill_1_outstanding', 'as_on'];

        $this->assertSame([...$facts, 'typed:opening:1:3'], $document->ticksNeeded());

        $errors = $this->refusal(fn () => $this->check()->assertTicked($document, $facts));

        $this->assertStringContainsString('3 (typed by you, not from the records)', $errors['number_ticks'][0]);

        $this->check()->assertTicked($document, [...$facts, 'typed:opening:1:3']);

        // The number is changed after the tick was given. The old tick does not cover the new number.
        $changed = $this->edited('We met 30 times about this.');

        $this->assertSame(['typed:opening:1:30'], $this->check()->missingTicks($changed, [...$facts, 'typed:opening:1:3']));
    }

    public function test_the_refusals_read_in_hindi_and_marathi(): void
    {
        $document = $this->edited('We met 3 times about this.');

        app()->setLocale('hi');
        $this->assertStringContainsString('आपने टाइप किया है, रिकॉर्ड से नहीं लिया गया', $this->refusal(fn () => $this->check()->assertTicked($document, []))['number_ticks'][0]);
        $this->assertStringContainsString('ड्राफ़्ट दोबारा बनाएँ', $this->refusal(fn () => $this->check()->assertUnchanged(null, $this->sheet()))['facts'][0]);

        app()->setLocale('mr');
        $this->assertStringContainsString('प्रत्येक आकड्यावर खूण करा', $this->refusal(fn () => $this->check()->assertTicked($document, []))['number_ticks'][0]);
        $this->assertStringContainsString('मसुदा पुन्हा तयार करा', $this->refusal(fn () => $this->check()->assertUnchanged(null, $this->sheet()))['facts'][0]);
        $this->assertSame('तुम्ही टाइप केले आहे, नोंदींमधून घेतलेले नाही', FinalCheck::typedLabel());
    }

    public function test_guard_e_writes_the_ticked_numbers_to_the_audit_log_of_the_documents_company(): void
    {
        $company = $this->tenant('Acme Buildcon');
        $other = $this->tenant('Other Infra Co');
        $manager = $this->member($company, 'pm');
        $project = $this->makeProject($company);
        $document = $this->edited('We met 3 times, last on 15/10/2026.');

        // Nobody is signed in: the row still goes to the company that owns the record.
        $row = $this->check()->recordTypedNumbers($project, $document, $manager);

        $this->assertNotNull($row);
        $saved = AuditLog::withoutGlobalScopes()->where('action', 'wording.typed_numbers_ticked')->get();

        $this->assertCount(1, $saved);
        $this->assertSame($company->id, $saved[0]->organization_id);
        $this->assertNotSame($other->id, $saved[0]->organization_id);
        $this->assertSame($manager->id, $saved[0]->user_id);
        $this->assertSame($project->getMorphClass(), $saved[0]->auditable_type);
        $this->assertSame($project->id, $saved[0]->auditable_id);
        $this->assertSame('payment_followup', $saved[0]->new_values['document_type']);
        $this->assertSame('en', $saved[0]->new_values['language']);
        $this->assertSame($this->sheet()->fingerprint(), $saved[0]->new_values['fact_fingerprint']);
        $this->assertSame(['3', '15/10/2026'], array_column($saved[0]->new_values['numbers'], 'text'));
        $this->assertSame(['typed:opening:1:3', 'typed:opening:2:15/10/2026'], array_column($saved[0]->new_values['numbers'], 'id'));

        // Someone signed in to another company does not see the row.
        $this->actingAs($this->member($other, 'owner'));
        $this->assertSame(0, AuditLog::where('action', 'wording.typed_numbers_ticked')->count());
    }

    public function test_nothing_is_written_to_the_audit_log_when_no_number_was_typed(): void
    {
        $project = $this->makeProject($this->tenant());

        $this->assertNull($this->check()->recordTypedNumbers($project, $this->renderer()->render($this->letter(), $this->sheet(), 'en')));
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'wording.typed_numbers_ticked')->count());
    }

    public function test_one_call_runs_guard_d_the_ticks_and_the_audit_row_in_that_order(): void
    {
        $company = $this->tenant();
        $manager = $this->member($company, 'pm');
        $project = $this->makeProject($company);
        $stored = $this->sheet()->fingerprint();
        $document = $this->edited('We met 3 times about this.');
        $all = $document->ticksNeeded();
        $audited = fn () => AuditLog::withoutGlobalScopes()->where('action', 'wording.typed_numbers_ticked')->count();

        // A record changed: stopped by Guard D although everything is ticked.
        $now = $this->sheet(['bill_1_outstanding' => '90000.00']);
        $fresh = $this->renderer()->render($this->letter(), $now, 'en', ['opening' => SlotText::edited('We met 3 times about this.')]);
        $this->assertSame(['facts'], array_keys($this->refusal(fn () => $this->check()->pass($project, $stored, $now, $fresh, $all, $manager))));
        $this->assertSame(0, $audited());

        // Unchanged, but a figure is not ticked.
        $this->assertSame(['number_ticks'], array_keys($this->refusal(fn () => $this->check()->pass($project, $stored, $this->sheet(), $document, ['bill_1_number'], $manager))));
        $this->assertSame(0, $audited());

        // Unchanged and ticked.
        $this->check()->pass($project, $stored, $this->sheet(), $document, $all, $manager);
        $this->assertSame(1, $audited());
    }

    public function test_the_ticks_cannot_be_checked_against_a_document_rendered_from_other_figures(): void
    {
        $project = $this->makeProject($this->tenant());
        $now = $this->sheet(['bill_1_outstanding' => '90000.00']);
        $old = $this->renderer()->render($this->letter(), $this->sheet(), 'en');

        $this->expectException(WordingException::class);

        // The stored fingerprint matches the records, but the document in hand was rendered from an older sheet.
        $this->check()->pass($project, $now->fingerprint(), $now, $old, $old->ticksNeeded());
    }

    public function test_edited_text_that_is_not_valid_utf8_is_refused(): void
    {
        $this->expectException(GuardViolation::class);

        $this->edited("We met 3 times \xC3\x28 about this.");
    }
}
