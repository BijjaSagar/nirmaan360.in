<?php

namespace Tests\Feature\SiteDiary;

use App\Models\AiTextCall;
use App\Models\SiteLetter;
use App\Services\DocumentReading\TextRequest;
use App\Services\SiteDiary\LetterDrafts;
use App\Services\SiteDiary\SiteLetters;
use App\Services\Wording\FactSheet;
use App\Services\Wording\WordingService;
use App\Support\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * The AI writes the three sentences of a draft letter that state no figure: the opening, the sentence
 * before the list and the close. Every figure, and every sentence that states one, stays the app's.
 * Every date, agreement detail and bill here is invented for the test.
 */
class LetterAiDraftTest extends SiteDiaryAiTestCase
{
    private const OPENING_MR = 'महोदय, वरील कामासाठी पूर्ण करण्याची मुदत वाढवून मिळावी अशी विनंती करण्यासाठी हे पत्र लिहीत आहोत.';

    private const LINKING_MR = 'कामात आलेले अडथळे आमच्या दैनिक साइट अहवालांत नोंदवलेले आहेत, ते खाली दिले आहेत.';

    private const CLOSING_MR = 'कृपया वरील बाबींचा विचार करून मुदतवाढ मंजूर करावी आणि हे पत्र मिळाल्याची पोच द्यावी.';

    private SiteLetter $letter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agreement();
        $entry = $this->entry('2026-03-02', '2026-03-06', ['description' => 'Podium slab drawings awaited']);
        $this->letter = $this->draft(self::EOT, ['hindrance_ids' => [$entry->id], 'language' => 'mr', 'addressed_to' => 'The Executive Engineer']);
    }

    private function ask(?SiteLetter $letter = null, ?object $as = null)
    {
        return $this->actingAs($as ?? $this->pm)->postJson(route('site-diary.letters.draft', $letter ?? $this->letter));
    }

    /** @param  array<string, string>  $texts */
    private function slots(array $texts): array
    {
        return $this->answer(['slots' => array_map(fn ($id, $text) => ['id' => $id, 'text' => $text], array_keys($texts), $texts)], 2100, 260);
    }

    private function fine(): array
    {
        return $this->slots(['opening' => self::OPENING_MR, 'linking' => self::LINKING_MR, 'closing' => self::CLOSING_MR]);
    }

    public function test_the_ai_writes_the_three_sentences_and_every_figure_stays_the_apps(): void
    {
        $before = $this->letter->body_rendered;
        $this->fakeModel([$this->fine()]);

        $this->actingAs($this->pm)->post(route('site-diary.letters.draft', $this->letter))
            ->assertRedirect(route('site-diary.letters.show', $this->letter))
            ->assertSessionHas('success');

        $call = AiTextCall::sole();
        $this->assertSame([WordingService::PURPOSE, $this->letter->getMorphClass(), $this->letter->id, $this->project->id, AiTextCall::QUEUED], [$call->purpose, $call->subject_type, $call->subject_id, $call->project_id, $call->status]);
        Http::assertNothingSent();
        $this->assertSame($before, $this->letter->fresh()->body_rendered, 'Until the AI answers, the letter is as it was.');

        // The page waits for the answer and looks again by itself.
        $this->get(route('site-diary.letters.show', $this->letter))->assertOk()
            ->assertSee('The AI is writing the opening, the sentence before the list and the closing sentences. This page looks again by itself.')
            ->assertDontSee('Write them with the AI');

        $this->runCall($call);

        $this->assertSame(AiTextCall::DONE, $call->fresh()->status);
        $this->assertCount(1, $this->charges());

        $letter = $this->letter->fresh();
        $this->assertSame(['ai', 'ai', 'ai'], array_column($letter->slots, 'source'));
        $this->assertSame(self::OPENING_MR, $letter->slots['opening']['text']);
        $this->assertStringContainsString(self::OPENING_MR, $letter->body_rendered);
        $this->assertStringContainsString(self::CLOSING_MR, $letter->body_rendered);
        $this->assertSame([], $letter->typed_numbers);

        // What was sent: what each sentence is for and the names of the figures, never a figure, a name or a typed word.
        $body = $this->sent[0];
        $message = $this->messageOf($body);
        $this->assertSame([], TextRequest::problems($body, WordingService::PURPOSE));
        $this->assertStringContainsString('Language: Marathi.', $message);
        $this->assertStringContainsString('Drawings or decisions awaited', $message);

        foreach (FactSheet::fromArray($letter->facts) as $fact) {
            if (mb_strlen($fact->value) >= 3) {
                $this->assertStringNotContainsString($fact->value, json_encode($body, JSON_UNESCAPED_UNICODE), $fact->key);
            }
        }

        foreach (['Shivganga', 'Lake View Residency', 'Executive Engineer', 'Podium slab drawings awaited', 'SG/LVR/07'] as $word) {
            $this->assertStringNotContainsString($word, json_encode($body, JSON_UNESCAPED_UNICODE), $word);
        }

        // The page shows the AI's sentences as the AI's, and the figures still wait for their ticks.
        $this->get(route('site-diary.letters.show', $this->letter))->assertOk()
            ->assertSee(self::OPENING_MR)
            ->assertSee('Written by the AI. Read it before you finalise the letter.')
            ->assertSee('Write them with the AI');

        $this->post(route('site-diary.letters.finalise', $letter), ['number_ticks' => [], 'seen' => $letter->seal()])->assertSessionHasErrors('number_ticks');
        $this->post(route('site-diary.letters.finalise', $letter), ['number_ticks' => $this->ticks($letter), 'seen' => $letter->fresh()->seal()])->assertSessionHasNoErrors();
        $this->assertTrue($letter->fresh()->isFinalised());
        $this->assertStringContainsString(self::LINKING_MR, $letter->fresh()->body_rendered);
    }

    public function test_sentences_that_state_a_figure_are_not_used_and_not_charged(): void
    {
        $before = $this->letter->only(['slots', 'body_rendered', 'fact_fingerprint']);

        foreach ([
            'a digit' => 'कृपया आम्हाला 15 दिवसांची मुदतवाढ द्यावी.',
            'a token' => 'कृपया {{proposed_completion}} पर्यंत मुदतवाढ द्यावी.',
            'a number word beside a unit' => 'कृपया आम्हाला पंधरा दिवस अधिक द्यावेत.',
        ] as $case => $closing) {
            AiTextCall::withoutGlobalScopes()->delete();
            $this->sent = [];
            $this->fakeModel([$this->slots(['opening' => self::OPENING_MR, 'linking' => self::LINKING_MR, 'closing' => $closing])]);

            $this->ask()->assertStatus(202);
            $call = AiTextCall::sole();
            $this->runCall($call);

            // Refused once, asked once more with what was wrong, refused again: the call ends uncharged.
            $this->assertSame(AiTextCall::QUEUED, $call->fresh()->status, $case);
            $this->runCall($call);
            $this->assertSame([AiTextCall::REJECTED, 'rejected'], [$call->fresh()->status, $call->fresh()->fail_reason], $case);
            $this->assertCount(2, $this->sent, $case);
            $this->assertStringContainsString('An earlier answer to this request was discarded', $this->messageOf($this->sent[1]), $case);

            $this->assertSame([], $this->charges(), $case);
            $this->assertSame($before, $this->letter->fresh()->only(['slots', 'body_rendered', 'fact_fingerprint']), $case);
        }

        $this->actingAs($this->pm)->get(route('site-diary.letters.show', $this->letter))->assertOk()
            ->assertSee('The sentences the AI wrote stated a figure, so the app kept its own sentences. You were not charged.');

        // Changing the draft afterwards clears the notice: it was about the letter as it then stood.
        Carbon::setTestNow(now()->addMinutes(2));
        $this->patch(route('site-diary.letters.update', $this->letter), ['hindrance_ids' => app(SiteLetters::class)->chosenOf($this->letter)['hindrance_ids'], 'addressed_to' => 'The Superintending Engineer'])->assertSessionHasNoErrors();
        $this->get(route('site-diary.letters.show', $this->letter))->assertOk()->assertDontSee('The sentences the AI wrote stated a figure');
    }

    public function test_an_everyday_one_alone_is_not_a_figure_in_a_hindi_letter(): void
    {
        $entry = $this->entry('2026-03-10', null, ['description' => 'Power cut on site']);
        $letter = $this->draft(self::DELAY, ['hindrance_ids' => [$entry->id], 'language' => 'hi']);
        $opening = 'महोदय, हम एक अनुरोध के साथ यह पत्र लिख रहे हैं कि ऊपर बताए गए काम में रुकावट आ रही है।';
        $this->fakeModel([$this->slots(['opening' => $opening, 'linking' => 'नीचे दी गई रुकावटें हमारी दैनिक साइट रिपोर्टों में दर्ज हैं।', 'closing' => 'कृपया इस पर ध्यान दें और इस पत्र की पावती दें।'])]);

        $this->ask($letter)->assertStatus(202);
        $this->runCall(AiTextCall::sole());

        $this->assertSame(AiTextCall::DONE, AiTextCall::sole()->status);
        $this->assertSame(['text' => $opening, 'source' => 'ai'], $letter->fresh()->slots['opening']);
        $this->assertStringContainsString('Language: Hindi.', $this->messageOf($this->sent[0]));
    }

    public function test_a_sentence_the_person_rewrote_is_kept(): void
    {
        $chosen = app(SiteLetters::class)->chosenOf($this->letter);
        $mine = 'महोदय, आमच्या कामातील अडचणी आपल्या निदर्शनास आणून देत आहोत.';
        $this->actingAs($this->pm)->patch(route('site-diary.letters.update', $this->letter), [
            'hindrance_ids' => $chosen['hindrance_ids'], 'addressed_to' => $chosen['addressed_to'], 'slots' => ['opening' => $mine],
        ])->assertSessionHasNoErrors();
        $this->assertSame('edited', $this->letter->fresh()->slots['opening']['source']);

        $this->fakeModel([$this->fine()]);
        $this->ask()->assertStatus(202);
        $this->runCall(AiTextCall::sole());

        $slots = $this->letter->fresh()->slots;
        $this->assertSame(['text' => $mine, 'source' => 'edited'], $slots['opening']);
        $this->assertSame(['ai', 'ai'], [$slots['linking']['source'], $slots['closing']['source']]);

        // With all three rewritten there is nothing to ask for, and nothing is made.
        $this->patch(route('site-diary.letters.update', $this->letter), [
            'hindrance_ids' => $chosen['hindrance_ids'], 'addressed_to' => $chosen['addressed_to'],
            'slots' => ['opening' => $mine, 'linking' => 'खालील नोंदी पाहाव्यात.', 'closing' => 'कृपया पोच द्यावी.'],
        ])->assertSessionHasNoErrors();
        $this->ask()->assertStatus(422)->assertJsonValidationErrors('ai_reader');
        $this->assertSame(1, AiTextCall::withoutGlobalScopes()->count());
    }

    public function test_a_letter_finalised_while_the_ai_was_writing_keeps_its_words_and_nothing_is_charged(): void
    {
        $this->fakeModel([$this->fine()]);
        $this->ask()->assertStatus(202);

        $this->post(route('site-diary.letters.finalise', $this->letter), ['number_ticks' => $this->ticks($this->letter), 'seen' => $this->letter->fresh()->seal()])->assertSessionHasNoErrors();
        $finalised = $this->letter->fresh()->only(['slots', 'body_rendered']);

        $this->runCall(AiTextCall::sole());

        $this->assertSame(AiTextCall::FAILED, AiTextCall::sole()->status);
        $this->assertSame([], $this->charges());
        $this->assertSame($finalised, $this->letter->fresh()->only(['slots', 'body_rendered']));

        // A finalised letter takes no new request, and its page offers none.
        $this->ask()->assertStatus(409);
        $this->get(route('site-diary.letters.show', $this->letter))->assertOk()->assertDontSee('Write them with the AI');
    }

    public function test_drafting_needs_no_consent_switch_but_the_ai_must_be_on(): void
    {
        // Only what each sentence is for and the names of the figures are sent, so the switch is not asked for.
        $this->consent($this->org, false);
        $this->ask()->assertStatus(202);

        AiTextCall::withoutGlobalScopes()->delete();
        app(PlatformSettings::class)->set(['ai_readers_released' => '0']);

        $this->ask()->assertStatus(409)->assertJson(['code' => 'not_released']);
        $this->actingAs($this->pm)->from(route('site-diary.letters.show', $this->letter))->post(route('site-diary.letters.draft', $this->letter))
            ->assertRedirect(route('site-diary.letters.show', $this->letter))->assertSessionHasErrors('ai_reader');
        $this->get(route('site-diary.letters.show', $this->letter))->assertOk()->assertDontSee('Write them with the AI');
        $this->assertSame(0, AiTextCall::withoutGlobalScopes()->count());
    }

    public function test_who_may_ask(): void
    {
        $this->actingAs($this->pm)->get(route('site-diary.letters.show', $this->letter))->assertOk()
            ->assertSee('Have the AI write the three sentences')
            ->assertSee('Write them with the AI')
            ->assertSee('Every date, day count, quantity and amount, and the sentence that states it, is placed by the app from your records.');

        // Letters without the AI: the page works and offers no button.
        $writer = $this->holding(['project.view', 'site_diary.view', 'site_diary.letters']);
        $this->ask(as: $writer)->assertForbidden();
        $this->actingAs($writer)->get(route('site-diary.letters.show', $this->letter))->assertOk()->assertDontSee('Write them with the AI');

        // The AI without letters.
        $this->ask(as: $this->engineer)->assertForbidden();
        $this->ask(as: $this->qs)->assertForbidden();

        // A payment follow-up is money: someone who may not see money may not have it drafted either.
        $bill = $this->clientBill('SG1/RA/1', '250000.00');
        $payment = $this->draft(self::PAYMENT, ['bill_ids' => [$bill->id]]);
        $this->ask($payment, $this->holding(['project.view', 'site_diary.view', 'site_diary.letters', 'agents.use', 'ra_bill.view']))->assertForbidden();
        $this->ask($payment)->assertStatus(202);

        // A plan without the AI features keeps its letters and is told the AI is part of other plans.
        $small = $this->tenant('Small Works', 'contractor');
        $smallPm = $this->member($small, 'pm');
        $project = $this->makeProject($small);
        $entry = $this->entry('2026-03-02', null, [], null, $project);
        $letter = $this->draft(self::DELAY, ['hindrance_ids' => [$entry->id]], $smallPm, $project);
        $this->ask($letter, $smallPm)->assertForbidden();
        $this->actingAs($smallPm)->get(route('site-diary.letters.show', $letter))->assertOk()
            ->assertDontSee('Write them with the AI')
            ->assertSee('Writing these sentences with the AI is part of the Business and Enterprise plans.');
    }

    public function test_another_company_or_another_project_is_not_reached(): void
    {
        [, $rivalOwner] = $this->rival();
        $this->ask(as: $rivalOwner)->assertNotFound();

        $elsewhere = $this->makeProject($this->org, ['code' => 'SG3']);
        $this->ask(as: $this->limitTo($this->member($this->org, 'pm'), $elsewhere))->assertNotFound();

        $this->assertSame(0, AiTextCall::withoutGlobalScopes()->count());
    }

    public function test_the_card_reads_in_hindi_and_marathi(): void
    {
        $this->pm->update(['locale' => 'hi']);
        $this->actingAs($this->pm)->get(route('site-diary.letters.show', $this->letter))->assertOk()
            ->assertSee('तीनों वाक्य AI से लिखवाएँ')->assertSee('इन्हें AI से लिखवाएँ')->assertDontSee('Write them with the AI');

        $this->pm->update(['locale' => 'mr']);
        $this->actingAs($this->pm)->get(route('site-diary.letters.show', $this->letter))->assertOk()
            ->assertSee('तिन्ही वाक्ये AI कडून लिहून घ्या')->assertSee('ही AI कडून लिहून घ्या')->assertDontSee('Write them with the AI');
    }

    public function test_the_document_sent_is_the_letter_as_it_was_last_prepared(): void
    {
        $document = app(LetterDrafts::class)->document($this->letter);

        $this->assertSame(['opening', 'linking', 'closing'], $document->type->freeIds());
        $this->assertSame('mr', $document->locale);
        $this->assertSame($this->letter->fact_fingerprint, $document->fingerprint());
        $this->assertSame(['The hindrances in the letter are of these kinds: Drawings or decisions awaited.'], $document->notes);
    }
}
