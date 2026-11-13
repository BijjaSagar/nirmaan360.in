<?php

namespace Tests\Feature\Wording;

use App\Jobs\DocumentReading\RunTextCall;
use App\Models\AiDocumentSettings;
use App\Models\AiTextCall;
use App\Models\DocumentRead;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\SpendRefused;
use App\Services\DocumentReading\TextRequest;
use App\Services\Wording\DocumentType;
use App\Services\Wording\Renderer;
use App\Services\Wording\Slot;
use App\Services\Wording\SlotGuard;
use App\Services\Wording\WordingDocument;
use App\Services\Wording\WordingException;
use App\Services\Wording\WordingRequest;
use App\Services\Wording\WordingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\DocumentReading\Support\FakeTextHandler;
use Tests\Feature\DocumentReading\TextCallTestCase;
use Tests\Feature\Wording\Support\FakeLetterWording;

/**
 * A model writes the free sentences of a letter: what it is told, what is kept of its answer, the
 * one corrective request, and the code text that stands whenever its sentences are not used.
 *
 * The usual answer uses 2,100 tokens in and 160 out on the wording model: 0.005800 dollars, 0.6786 credits.
 */
class WordingDraftTest extends TextCallTestCase
{
    private const OPENING_MR = 'आमच्या चालू कामाच्या देयकांबाबत आपले लक्ष वेधण्यासाठी हे पत्र लिहीत आहोत.';

    private const CLOSING_MR = 'आपण याची दखल घेऊन लवकर कळवावे, ही नम्र विनंती.';

    protected function setUp(): void
    {
        parent::setUp();

        FakeLetterWording::reset();
    }

    protected function handlers(): array
    {
        return ['wording' => [DocumentRead::class => FakeLetterWording::class], 'rate_match' => FakeTextHandler::class];
    }

    private function letter(string $locale = 'mr', array $options = []): DocumentRead
    {
        return $this->record(overrides: ['options' => ['locale' => $locale, ...$options]]);
    }

    private function draft(DocumentRead $letter, array $with = []): AiTextCall
    {
        $document = FakeLetterWording::documentOf($letter);

        return app(WordingService::class)->draft(WordingService::PURPOSE, $document->type, $document->sheet, $document->locale, $letter, $this->engineer, $with);
    }

    /** @param  array<string, string>  $texts  Slot id => text, as the model would answer. */
    private function slots(array $texts, int $input = 2100, int $output = 160): array
    {
        $entries = [];

        foreach ($texts as $id => $text) {
            $entries[] = ['id' => $id, 'text' => $text];
        }

        return $this->answer(['slots' => $entries], $input, $output, extra: ['model' => 'claude-sonnet-5-5']);
    }

    /** @return array<string, mixed> What the stand-in feature kept on the letter. */
    private function kept(DocumentRead $letter): array
    {
        return (array) $letter->fresh()->review;
    }

    private function rendered(DocumentRead $letter): string
    {
        $document = FakeLetterWording::documentOf($letter->fresh());

        return app(Renderer::class)->render($document->type, $document->sheet, $document->locale, $this->kept($letter)['slots'] ?? [])->text();
    }

    public function test_asking_for_a_draft_makes_a_wording_call_for_the_letter_and_drafts_nothing_yet(): void
    {
        $letter = $this->letter();

        $call = $this->draft($letter);

        $this->assertSame(['wording', DocumentRead::class, $letter->id, 'claude-sonnet-5-5', AiTextCall::QUEUED, $this->engineer->id], [$call->purpose, $call->subject_type, $call->subject_id, $call->model, $call->status, $call->user_id]);
        $this->assertTrue($call->max_credits > '2.0000' && $call->max_credits < '4.0000', $call->max_credits);
        Queue::assertPushed(RunTextCall::class, fn (RunTextCall $job) => $job->callId === $call->id);

        // Until the call ends the letter stands in its code text, complete.
        Http::assertNothingSent();
        $this->assertNull($letter->fresh()->review);
        $this->assertSame(FakeLetterWording::OPENING['mr']."\n\nबिल RA-P101-003 वर 5 नोव्हेंबर 2026 रोजी थकबाकी ₹1,50,000.00 आहे.\n\n".FakeLetterWording::CLOSING['mr'], $this->rendered($letter));

        // Asking again while it waits makes no second call.
        $this->assertSame($call->id, $this->draft($letter)->id);

        // The request's own id and the project travel with the call, as for any text call.
        $project = $this->makeProject($this->organization);
        $uuid = (string) Str::uuid();
        $other = $this->draft($this->letter('en'), ['client_uuid' => $uuid, 'project' => $project]);
        $this->assertSame([$uuid, $project->id], [$other->client_uuid, $other->project_id]);
    }

    public function test_from_the_request_to_the_kept_draft_through_a_real_job(): void
    {
        $this->runJobsAtOnce();
        $this->fakeModel([$this->slots(['opening' => self::OPENING_MR, 'closing' => self::CLOSING_MR])]);
        $letter = $this->letter();

        // The job is handed over when the call is saved, and here it runs there and then.
        $call = $this->draft($letter);

        Http::assertSentCount(1);
        $this->assertSame([AiTextCall::DONE, 1, '0.6786'], [$call->fresh()->status, $call->fresh()->attempts, $call->fresh()->credits]);
        $this->assertSame('ai', $this->kept($letter)['source']);
        $this->assertSame(self::OPENING_MR, $this->kept($letter)['slots']['opening']['text']);
        $this->assertSame('0.0000', $this->credits()->held($this->organization));
    }

    public function test_the_model_is_told_what_each_sentence_is_for_and_never_a_value(): void
    {
        $letter = $this->letter();
        $call = $this->draft($letter);
        $this->fakeModel([$this->slots(['opening' => self::OPENING_MR, 'closing' => self::CLOSING_MR])]);

        $this->runCall($call);

        $body = $this->sent[0];
        $this->assertSame([], TextRequest::problems($body, 'wording'));
        $this->assertSame('claude-sonnet-5-5', $body['model']);
        $this->assertSame(2000, $body['max_tokens']);
        $this->assertSame(TextRequest::RULES."\n\n".WordingRequest::INSTRUCTIONS, $body['system'][0]['text']);
        $this->assertSame(DocumentType::OUTPUT_SCHEMA, $body['output_config']['format']['schema']);

        $message = $this->messageOf($body);
        $this->assertSame(
            "<slots>\n"
            .'[{"id":"opening","purpose":"Open the letter politely and say it is about unpaid bills.","nearby_facts":["Bill number","Outstanding amount","As on date"]},'
            .'{"id":"closing","purpose":"Close the letter with a courteous line that asks for a reply.","nearby_facts":["Bill number","Outstanding amount","As on date"]}]'
            ."\n</slots>\n"
            .'Language: Marathi. Kind of document: payment followup. Write the sentences for these slots: opening, closing.',
            $message,
        );

        // Nothing the fixed sentence will print reaches the model: no figure, no date, no bill number, no client, no fixed sentence.
        foreach (['150000', '1,50,000', 'RA-P101', 'Acme', 'Buildcon', '2026', 'नोव्हेंबर', 'थकबाकी', $this->organization->name, $this->engineer->name] as $value) {
            $this->assertStringNotContainsString($value, json_encode($body, JSON_UNESCAPED_UNICODE), $value);
        }

        $this->assertDoesNotMatchRegularExpression('/\p{N}/u', $message);
        $this->assertStringNotContainsString('{{', $message);
    }

    public function test_sentences_that_pass_the_guards_are_kept_with_the_fingerprint_of_the_facts_and_charged(): void
    {
        $letter = $this->letter();
        $call = $this->draft($letter);
        $this->fakeModel([$this->slots(['opening' => self::OPENING_MR, 'closing' => self::CLOSING_MR])]);

        $this->runCall($call);

        $call->refresh();
        $this->assertSame([AiTextCall::DONE, 1, '0.005800', '0.6786'], [$call->status, $call->attempts, $call->cost_usd, $call->credits]);
        $this->assertSame('499.3214', $this->wallet($this->organization)->available());

        $this->assertSame([
            'source' => 'ai',
            'slots' => ['opening' => ['text' => self::OPENING_MR, 'source' => 'ai'], 'closing' => ['text' => self::CLOSING_MR, 'source' => 'ai']],
            'unwritten' => [],
            'reason' => null,
            'fingerprint' => FakeLetterWording::sheet($letter)->fingerprint(),
            'locale' => 'mr',
        ], $this->kept($letter));

        // The letter: the model's sentences around the one the code wrote, which alone carries the figures.
        $this->assertSame(self::OPENING_MR."\n\nबिल RA-P101-003 वर 5 नोव्हेंबर 2026 रोजी थकबाकी ₹1,50,000.00 आहे.\n\n".self::CLOSING_MR, $this->rendered($letter));

        // The sentences are on the letter and nowhere else: not on the call, not in the audit trail, not in a job.
        $elsewhere = json_encode([DB::table('ai_text_calls')->get(), DB::table('audit_logs')->get(), DB::table('credit_ledger')->get()], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('देयकांबाबत', $elsewhere);
        $this->assertStringNotContainsString('नम्र विनंती', $elsewhere);
        $this->assertSame(['done'], $this->sentOutcomes());
    }

    public function test_a_sentence_with_a_figure_is_refused_asked_for_once_more_and_only_the_clean_answer_is_charged(): void
    {
        $letter = $this->letter();
        $call = $this->draft($letter);
        $this->fakeModel([
            $this->slots(['opening' => self::OPENING_MR, 'closing' => 'कृपया ₹1,50,000 ची थकबाकी पंधरा दिवसांत द्यावी.']),
            $this->slots(['opening' => self::OPENING_MR, 'closing' => self::CLOSING_MR], 2300, 150),
        ]);

        $this->runCall($call);

        // The whole answer is set aside, the clean sentence with it: an answer that is not charged is not used.
        $call->refresh();
        $this->assertSame([AiTextCall::QUEUED, '0.0000'], [$call->status, $call->credits]);
        $this->assertEqualsCanonicalizing(['digit', 'currency', 'number_word'], $call->correction['closing']);
        $this->assertSame(['closing'], array_keys($call->correction));
        $this->assertNull($letter->fresh()->review);
        $this->assertNotCharged();
        $this->assertStringNotContainsString('पंधरा', json_encode(DB::table('ai_text_calls')->get(), JSON_UNESCAPED_UNICODE));

        $this->runCall($call);

        // The second request says which slot broke which rule, in plain words, and not what the sentence was.
        $second = $this->messageOf($this->sent[1]);
        $this->assertStringContainsString('An earlier answer to this request was discarded: slot "closing" had', $second);
        $this->assertStringContainsString('a digit or another numeral', $second);
        $this->assertStringContainsString('a currency sign or short form', $second);
        $this->assertStringContainsString('a number written in words next to a unit', $second);
        $this->assertStringContainsString('Write every slot again and keep to every rule.', $second);
        $this->assertStringNotContainsString('पंधरा', $second);
        $this->assertStringNotContainsString('1,50,000', $second);
        $this->assertSame([], TextRequest::problems($this->sent[1], 'wording'));

        $call->refresh();
        // 2,300 tokens in and 150 out the second time: 0.006100 dollars.
        $this->assertSame([AiTextCall::DONE, 2, '0.7137', '0.011900'], [$call->status, $call->attempts, $call->credits, $call->cost_usd]);
        $this->assertCount(1, $this->usageLines($this->organization));
        $this->assertSame('ai', $this->kept($letter)['source']);
        $this->assertSame(self::CLOSING_MR, $this->kept($letter)['slots']['closing']['text']);
        $this->assertSame(['asked_again', 'done'], $this->sentOutcomes());
    }

    public function test_after_a_second_refusal_the_code_text_stands_and_nothing_is_charged(): void
    {
        $letter = $this->letter('hi');
        $call = $this->draft($letter);
        $this->fakeModel([
            $this->slots(['opening' => 'हम {{bill_outstanding}} की राशि के बारे में लिख रहे हैं।', 'closing' => 'धन्यवाद।']),
            $this->slots(['opening' => 'हम नवंबर के बिल के बारे में लिख रहे हैं।', 'closing' => 'धन्यवाद।']),
        ]);

        $this->runCall($call);
        $this->assertSame(['opening' => ['token']], $call->fresh()->correction);

        $this->runCall($call);

        $call->refresh();
        $this->assertSame([AiTextCall::REJECTED, 'rejected', 2, '0.0000', '0.011600'], [$call->status, $call->fail_reason, $call->attempts, $call->credits, $call->cost_usd]);
        $this->assertNotCharged();
        $this->assertSame('0.0000', $this->credits()->held($this->organization));

        $kept = $this->kept($letter);
        $this->assertSame(['template', [], ['opening', 'closing'], 'rejected'], [$kept['source'], $kept['slots'], $kept['unwritten'], $kept['reason']]);
        $this->assertSame(FakeLetterWording::OPENING['hi']."\n\nबिल RA-P101-003 पर 5 नवंबर 2026 को बकाया राशि ₹1,50,000.00 है।\n\n".FakeLetterWording::CLOSING['hi'], $this->rendered($letter));

        $this->assertSame(['asked_again', 'rejected'], $this->sentOutcomes());
        $this->assertSame(1, app(AiSpend::class)->unusableAnswers($this->organization));
        Http::assertSentCount(2);
    }

    public function test_an_everyday_number_word_alone_does_not_refuse_a_sentence(): void
    {
        $letter = $this->letter('hi');
        $call = $this->draft($letter);
        // "एक" as "a", and "दो" as the verb "give": neither states a figure.
        $this->fakeModel([$this->slots(['opening' => 'यह पत्र एक निवेदन के रूप में लिखा जा रहा है।', 'closing' => 'कृपया शीघ्र उत्तर दो, यही निवेदन है।'])]);

        $this->runCall($call);

        $this->assertSame(AiTextCall::DONE, $call->fresh()->status);
        $this->assertSame('ai', $this->kept($letter)['source']);
        Http::assertSentCount(1);
    }

    public function test_only_the_free_slots_of_this_letter_are_taken_and_a_slot_left_out_keeps_its_code_text(): void
    {
        $letter = $this->letter();
        $call = $this->draft($letter);
        $this->fakeModel([$this->slots([
            'opening' => self::OPENING_MR,
            // The model tries to rewrite the sentence that carries the figures, and invents a slot.
            'bill_line' => 'थकबाकी फारच कमी आहे.',
            'postscript' => 'ता.क. कृपया त्वरित कार्यवाही करावी.',
        ])]);

        $this->runCall($call);

        $this->assertSame(AiTextCall::DONE, $call->fresh()->status);

        $kept = $this->kept($letter);
        $this->assertSame(['opening'], array_keys($kept['slots']));
        $this->assertSame(['closing'], $kept['unwritten']);
        $this->assertSame(self::OPENING_MR."\n\nबिल RA-P101-003 वर 5 नोव्हेंबर 2026 रोजी थकबाकी ₹1,50,000.00 आहे.\n\n".FakeLetterWording::CLOSING['mr'], $this->rendered($letter));
        $this->assertStringNotContainsString('फारच कमी', $this->rendered($letter));
    }

    public function test_an_answer_with_nothing_usable_is_treated_as_refused(): void
    {
        $letter = $this->letter('en');
        $call = $this->draft($letter);
        $this->fakeModel([$this->slots([]), $this->slots(['postscript' => 'With regards.', 'opening' => '   '])]);

        $this->runCall($call);

        $this->assertSame([AiTextCall::QUEUED, ['slots' => ['empty']]], [$call->fresh()->status, $call->fresh()->correction]);

        $this->runCall($call);

        $this->assertStringContainsString('An earlier answer to this request was discarded: the answer had no usable text.', $this->messageOf($this->sent[1]));
        $this->assertSame([AiTextCall::REJECTED, '0.0000'], [$call->fresh()->status, $call->fresh()->credits]);
        $this->assertSame('template', $this->kept($letter)['source']);
        $this->assertNotCharged();
    }

    public function test_a_call_that_fails_leaves_the_code_text_and_says_why(): void
    {
        $letter = $this->letter('en');
        $call = $this->draft($letter);
        $this->fakeModel([fn () => throw new ConnectionException('Operation timed out')]);

        $this->runCall($call);

        $this->assertSame([AiTextCall::FAILED, 'timeout'], [$call->fresh()->status, $call->fresh()->fail_reason]);
        $this->assertSame(['template', 'timeout'], [$this->kept($letter)['source'], $this->kept($letter)['reason']]);
        $this->assertSame(FakeLetterWording::OPENING['en']."\n\nThe amount outstanding on bill RA-P101-003 is ₹1,50,000.00 as on 5 November 2026.\n\n".FakeLetterWording::CLOSING['en'], $this->rendered($letter));
        $this->assertNotCharged();
    }

    public function test_a_draft_is_kept_with_the_facts_as_they_are_when_it_arrives(): void
    {
        $letter = $this->letter();
        $call = $this->draft($letter);
        $before = FakeLetterWording::sheet($letter)->fingerprint();

        // A payment comes in while the model is writing. The sentences state no figure, so they still fit.
        $this->fakeModel([function () use ($letter) {
            DocumentRead::withoutGlobalScopes()->whereKey($letter->id)->update(['options' => json_encode(['locale' => 'mr', 'outstanding' => '90000.00'])]);

            return $this->slots(['opening' => self::OPENING_MR, 'closing' => self::CLOSING_MR]);
        }]);

        $this->runCall($call);

        $this->assertSame(AiTextCall::DONE, $call->fresh()->status);
        $this->assertNotSame($before, $this->kept($letter)['fingerprint']);
        $this->assertSame(FakeLetterWording::sheet($letter->fresh())->fingerprint(), $this->kept($letter)['fingerprint']);
        $this->assertStringContainsString('₹90,000.00', $this->rendered($letter));
    }

    public function test_sentences_written_for_a_letter_that_changed_its_language_meanwhile_are_not_kept_or_charged(): void
    {
        $letter = $this->letter();
        $call = $this->draft($letter);

        $this->fakeModel([function () use ($letter) {
            DocumentRead::withoutGlobalScopes()->whereKey($letter->id)->update(['options' => json_encode(['locale' => 'hi'])]);

            return $this->slots(['opening' => self::OPENING_MR, 'closing' => self::CLOSING_MR]);
        }]);

        $this->runCall($call);

        $call->refresh();
        $this->assertSame([AiTextCall::FAILED, 'subject_changed', '0.0000', '0.005800'], [$call->status, $call->fail_reason, $call->credits, $call->cost_usd]);
        $this->assertSame(['template', 'subject_changed', 'hi'], [$this->kept($letter)['source'], $this->kept($letter)['reason'], $this->kept($letter)['locale']]);
        $this->assertNotCharged();
        $this->assertSame(['failed:subject_changed'], $this->sentOutcomes());
    }

    public function test_a_letter_that_is_no_longer_a_draft_is_not_sent(): void
    {
        $letter = $this->letter();
        $call = $this->draft($letter);
        $this->fakeModel([$this->slots(['opening' => self::OPENING_MR])]);

        DocumentRead::withoutGlobalScopes()->whereKey($letter->id)->update(['status' => DocumentRead::CONFIRMED]);

        $this->runCall($call);

        Http::assertNothingSent();
        $this->assertSame([AiTextCall::FAILED, 'subject_gone'], [$call->fresh()->status, $call->fresh()->fail_reason]);
        $this->assertNull($letter->fresh()->review);
    }

    public function test_notes_describe_the_letter_in_general_words_and_never_carry_a_figure_or_a_personal_detail(): void
    {
        FakeLetterWording::$notes = [
            'The client has not answered earlier reminders.',
            'The bill was certified on 12 October and Rs 1,50,000 is due.',
            'Site engineer Kulkarni can be reached at kulkarni@acme-buildcon.example for the joint measurement.',
            str_repeat('The work is in its finishing stage. ', 20),
            '   ',
        ];
        $letter = $this->letter('en');
        $call = $this->draft($letter);
        $this->fakeModel([$this->slots(['opening' => 'We write regarding our pending bills.', 'closing' => 'We request an early reply.'])]);

        $this->runCall($call);

        $message = $this->messageOf($this->sent[0]);
        $this->assertStringContainsString("<notes>\nThe client has not answered earlier reminders.\nSite engineer Kulkarni can be reached at [redacted] for the joint measurement.\nThe work is in its finishing stage.", $message);

        // The line with a date and an amount is left out whole, the address is taken out, and a long line is cut.
        $this->assertStringNotContainsString('October', $message);
        $this->assertStringNotContainsString('1,50,000', $message);
        $this->assertStringNotContainsString('kulkarni@', $message);
        $this->assertSame([WordingDocument::NOTE_CHARS, 3], [mb_strlen(FakeLetterWording::documentOf($letter)->notes[2]), count(FakeLetterWording::documentOf($letter)->notes)]);
        $this->assertSame(AiTextCall::DONE, $call->fresh()->status);
    }

    public function test_a_draft_cannot_be_asked_for_a_document_other_than_the_one_the_record_gives(): void
    {
        $letter = $this->letter();
        $service = app(WordingService::class);
        $given = FakeLetterWording::documentOf($letter);

        $tries = [
            // Another language than the letter is in.
            fn () => $service->draft('wording', $given->type, $given->sheet, 'hi', $letter, $this->engineer),
            // Other facts than the record has now.
            fn () => $service->draft('wording', $given->type, FakeLetterWording::sheet($this->letter('mr', ['outstanding' => '99.00'])), 'mr', $letter, $this->engineer),
            // Other slots to write.
            fn () => $service->draft('wording', new DocumentType('payment_followup', [Slot::free('opening', 'Open the letter.', FakeLetterWording::OPENING)]), $given->sheet, 'mr', $letter, $this->engineer),
            // Nothing a model may write.
            fn () => $service->draft('wording', new DocumentType('payment_followup', [Slot::fixed('bill_line', FakeLetterWording::BILL_LINE, ['number' => 'bill_number', 'outstanding' => 'bill_outstanding', 'as_on' => 'as_on'])]), $given->sheet, 'mr', $letter, $this->engineer),
            // A purpose whose handler does not write sentences.
            fn () => $service->draft('rate_match', $given->type, $given->sheet, 'mr', $letter, $this->engineer),
            // A purpose nobody handles for this record.
            fn () => $service->draft('translate', $given->type, $given->sheet, 'mr', $letter, $this->engineer),
        ];

        foreach ($tries as $at => $try) {
            try {
                $try();
                $this->fail("Draft {$at} was accepted.");
            } catch (LogicException) {
                $this->assertSame(0, AiTextCall::withoutGlobalScopes()->count(), "Draft {$at}");
            }
        }

        $this->assertSame([], $this->queuedCalls());
    }

    public function test_a_document_without_its_code_text_in_the_language_is_not_sent_to_a_model(): void
    {
        $letter = $this->letter('hi');
        $given = FakeLetterWording::documentOf($letter);
        // One English sentence with no Hindi line in any language file: the fallback would be missing.
        $type = new DocumentType('payment_followup', [Slot::free('opening', 'Open the letter.', 'We write about a matter that has no translation yet.')]);

        $this->expectException(WordingException::class);

        app(WordingService::class)->draft('wording', $type, $given->sheet, 'hi', $letter, $this->engineer);
    }

    public function test_the_limits_on_text_calls_hold_for_a_draft_and_the_code_text_stays(): void
    {
        AiDocumentSettings::for($this->organization)->update(['budgets' => ['user' => ['wording' => '1']]]);
        $letter = $this->letter();

        try {
            $this->draft($letter);
            $this->fail('The budget did not stop the draft.');
        } catch (SpendRefused $refused) {
            $this->assertSame(['code' => 'daily_budget', 'feature' => 'wording', 'scope' => 'user'], $refused->toArray());
        }

        $this->assertSame(0, AiTextCall::withoutGlobalScopes()->count());
        $this->assertStringContainsString(FakeLetterWording::OPENING['mr'], $this->rendered($letter));
    }

    public function test_wording_needs_no_consent_switch_because_it_sends_nothing_of_the_companys_own(): void
    {
        $this->assertNull(AiDocumentSettings::withoutGlobalScopes()->where('organization_id', $this->organization->id)->first());

        $this->assertSame(AiTextCall::QUEUED, $this->draft($this->letter())->status);
    }

    public function test_what_the_instructions_tell_the_model_to_write_instead_of_a_figure_passes_the_guards(): void
    {
        $guard = app(SlotGuard::class);

        foreach (['We refer to the amount stated below.', 'Kindly see the dates given above.'] as $sentence) {
            $this->assertSame([], $guard->check($sentence, 'en'), $sentence);
        }

        // And the examples of what not to write, named in the instructions, are in fact refused.
        foreach (['seven days', 'two lakh', 'a week', 'twice', 'a fortnight', 'Rs', 'INR'] as $example) {
            $this->assertStringContainsString($example, WordingRequest::INSTRUCTIONS);
            $this->assertNotSame([], $guard->check("We will finish in {$example} as agreed.", 'en'), $example);
        }

        // The instructions ask for more than the guard can hold a sentence to: a share of something that is not a unit gets through.
        $this->assertStringContainsString('half the work', WordingRequest::INSTRUCTIONS);
        $this->assertSame([], $guard->check('We have finished half the work.', 'en'));

        // The instructions are the same for every company and every letter, and name no law, clause or period of their own.
        $this->assertDoesNotMatchRegularExpression('/\d/', WordingRequest::INSTRUCTIONS);
        $this->assertStringContainsString('No figures of any kind', WordingRequest::INSTRUCTIONS);
        $this->assertStringContainsString('do not say that a claim is valid', WordingRequest::INSTRUCTIONS);
    }
}
