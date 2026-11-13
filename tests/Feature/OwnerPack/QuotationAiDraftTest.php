<?php

namespace Tests\Feature\OwnerPack;

use App\Models\AiTextCall;
use App\Models\AuditLog;
use App\Models\ClientQuotation;
use App\Services\Access\TeamAccessService;
use App\Services\DocumentReading\TextRequest;
use App\Services\OwnerPack\PackDrafts;
use App\Services\Wording\FactSheet;
use App\Services\Wording\WordingService;
use App\Support\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * The AI writes the opening and the closing of a draft quotation's cover note, the two sentences that
 * state no figure. Every figure, and every sentence that states one, stays the app's. Every name and
 * figure here is invented for the test.
 */
class QuotationAiDraftTest extends OwnerPackAiTestCase
{
    private const OPENING_MR = 'आपल्या घराच्या कामासाठी दर कळवण्याची संधी दिल्याबद्दल आभारी आहोत. तपशील खाली दिला आहे.';

    private const CLOSING_MR = 'कोणत्याही ओळीबद्दल अधिक माहिती हवी असल्यास कृपया आम्हाला कळवा.';

    private ClientQuotation $quotation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quotation = $this->withStages($this->draft(['language' => 'mr']));
    }

    private function ask(?ClientQuotation $quotation = null, ?object $as = null)
    {
        return $this->actingAs($as ?? $this->manager)->postJson(route('quotations.draft', $quotation ?? $this->quotation));
    }

    private function fine(): array
    {
        return $this->slots(['opening' => self::OPENING_MR, 'closing' => self::CLOSING_MR]);
    }

    public function test_the_ai_writes_the_two_sentences_and_every_figure_stays_the_apps(): void
    {
        $this->fakeModel([$this->fine()]);

        $this->actingAs($this->manager)->post(route('quotations.draft', $this->quotation))
            ->assertRedirect(route('quotations.show', $this->quotation).'#ai-sentences')
            ->assertSessionHas('success');

        $call = AiTextCall::sole();
        $this->assertSame([WordingService::PURPOSE, $this->quotation->getMorphClass(), $this->quotation->id, $this->project->id, AiTextCall::QUEUED], [$call->purpose, $call->subject_type, $call->subject_id, $call->project_id, $call->status]);
        Http::assertNothingSent();
        $this->assertNull($this->quotation->fresh()->slots, 'Until the AI answers, the cover note is as it was.');

        // The page waits for the answer and looks again by itself.
        $this->assertLooksAgainByItself($this->get(route('quotations.show', $this->quotation))->assertOk()
            ->assertSee('The AI is writing the opening and the closing sentences. This page looks again by itself.')
            ->assertDontSee('Write them with the AI'));

        // A page opened now, before the sentences come, ticks the figures of the text as it then stood.
        $before = $this->allTicks($this->quotation);
        $factsBefore = $this->quotation->fresh()->fact_fingerprint;

        $this->runCall($call);

        $this->assertSame(AiTextCall::DONE, $call->fresh()->status);
        $this->assertCount(1, $this->charges());

        $quotation = $this->quotation->fresh();
        $this->assertSame(['opening' => ['text' => self::OPENING_MR, 'source' => 'ai'], 'closing' => ['text' => self::CLOSING_MR, 'source' => 'ai']], $quotation->slots);
        $this->assertSame($factsBefore, $quotation->fact_fingerprint, 'Only the two sentences changed, not the facts.');
        [, $note] = $this->quotations()->review($quotation);
        $this->assertSame(self::OPENING_MR, $note->slot('opening')->text);
        $this->assertSame('ai', $note->slot('closing')->source);
        $this->assertSame(1, AuditLog::where('action', 'quotation.worded_by_ai')->count());

        // What was sent: what each sentence is for and the names of the figures, never a figure, a name or a line.
        $body = $this->sent[0];
        $this->assertSame([], TextRequest::problems($body, WordingService::PURPOSE));
        $this->assertSame('claude-sonnet-5-5', $body['model']);
        $this->assertStringContainsString('Language: Marathi.', $this->messageOf($body));
        $this->assertStringContainsString('cover note of a quotation', $this->messageOf($body));

        foreach (FactSheet::fromArray($quotation->facts) as $fact) {
            // As a word of its own: the unit "cum" stands inside "document".
            if (mb_strlen($fact->value) >= 3) {
                $this->assertDoesNotMatchRegularExpression('/(?<![\p{L}\p{N}])'.preg_quote($fact->value, '/').'(?![\p{L}\p{N}])/u', $this->bodyText($body), $fact->key);
            }
        }

        foreach (['Anil Deshmukh', 'Deshmukh Bungalow', 'Sahyadri', 'Excavation', 'Plinth beam cast'] as $word) {
            $this->assertStringNotContainsString($word, $this->bodyText($body), $word);
        }

        // The page shows the AI's sentences as the AI's.
        $this->get(route('quotations.show', $this->quotation))->assertOk()
            ->assertSee(self::OPENING_MR)
            ->assertSee('Written by the AI. Read it before you issue the quotation.')
            ->assertSee('Write them with the AI');

        // Ticks given on the page opened before the sentences came do not issue the text the person did not read.
        $this->post(route('quotations.issue', $this->quotation), $before)->assertSessionHasErrors('facts');
        $this->assertTrue($this->quotation->fresh()->isDraft());

        // Read again, every figure ticked: issued with the AI's sentences around the app's.
        $this->post(route('quotations.issue', $this->quotation), $this->allTicks($this->quotation))->assertSessionHasNoErrors();
        $this->assertTrue($this->quotation->fresh()->isIssued());
    }

    public function test_sentences_that_state_a_figure_are_not_used_and_not_charged(): void
    {
        $before = $this->quotation->fresh()->only(['slots', 'facts', 'fact_fingerprint']);

        foreach ([
            'a digit' => 'कृपया 15 दिवसांत कळवा.',
            'a token' => 'एकूण रक्कम {{total}} आहे.',
            'a number word beside a unit' => 'कृपया पंधरा दिवसांत कळवा.',
            'a month' => 'कृपया मार्च अखेरपर्यंत कळवा.',
        ] as $case => $closing) {
            AiTextCall::withoutGlobalScopes()->delete();
            $this->sent = [];
            $this->fakeModel([$this->slots(['opening' => self::OPENING_MR, 'closing' => $closing])]);

            $this->ask()->assertStatus(202)->assertJsonStructure(['call_id', 'status']);
            $call = AiTextCall::sole();
            $this->runCall($call);

            // Refused once, asked once more with what was wrong, refused again: the call ends uncharged.
            $this->assertSame(AiTextCall::QUEUED, $call->fresh()->status, $case);
            $this->runCall($call);
            $this->assertSame([AiTextCall::REJECTED, 'rejected'], [$call->fresh()->status, $call->fresh()->fail_reason], $case);
            $this->assertCount(2, $this->sent, $case);
            $this->assertStringContainsString('An earlier answer to this request was discarded', $this->messageOf($this->sent[1]), $case);

            $this->assertSame([], $this->charges(), $case);
            $this->assertSame($before, $this->quotation->fresh()->only(['slots', 'facts', 'fact_fingerprint']), $case);
        }

        $this->actingAs($this->manager)->get(route('quotations.show', $this->quotation))->assertOk()
            ->assertSee('The sentences the AI wrote stated a figure, so the app kept its own sentences. You were not charged.');

        // Saving the draft afterwards clears the notice: it was about the draft as it then stood.
        Carbon::setTestNow(now()->addMinutes(2));
        $this->patch(route('quotations.update', $this->quotation), ['client_name' => 'Anil and Sunita Deshmukh', 'language' => 'mr'])->assertSessionHasNoErrors();
        $this->get(route('quotations.show', $this->quotation))->assertOk()->assertDontSee('The sentences the AI wrote stated a figure');
    }

    public function test_an_answer_with_no_sentence_in_it_is_not_said_to_have_stated_a_figure(): void
    {
        foreach (['no sentence' => ['slots' => []], 'only slots that are not asked for' => ['slots' => [['id' => 'signature', 'text' => 'आपला विश्वासू']]]] as $case => $form) {
            AiTextCall::withoutGlobalScopes()->delete();
            $this->fakeModel([$this->answer($form)]);

            $this->ask()->assertStatus(202);
            $call = AiTextCall::sole();
            $this->runCall($call);
            $this->runCall($call);

            $this->assertSame([AiTextCall::REJECTED, 'rejected', ['slots' => ['empty']]], [$call->fresh()->status, $call->fresh()->fail_reason, $call->fresh()->correction], $case);
            $this->assertSame([], $this->charges(), $case);
            $this->actingAs($this->manager)->get(route('quotations.show', $this->quotation))->assertOk()
                ->assertSee('The AI could not write the sentences this time, so the sentences of the app stand. You were not charged.')
                ->assertDontSee('stated a figure');
        }
    }

    public function test_a_sentence_the_person_rewrote_is_kept_and_with_both_rewritten_nothing_is_asked(): void
    {
        $mine = 'आपल्या घराच्या कामासाठी आमचे दर पुढीलप्रमाणे आहेत.';
        $this->actingAs($this->manager)->patch(route('quotations.update', $this->quotation), ['language' => 'mr', 'opening' => $mine])->assertSessionHasNoErrors();
        $this->assertSame('edited', $this->quotation->fresh()->slots['opening']['source']);

        $this->fakeModel([$this->fine()]);
        $this->ask()->assertStatus(202);
        $this->runCall(AiTextCall::sole());

        $slots = $this->quotation->fresh()->slots;
        $this->assertSame(['text' => $mine, 'source' => 'edited'], $slots['opening']);
        $this->assertSame(['text' => self::CLOSING_MR, 'source' => 'ai'], $slots['closing']);

        // Saving the form as it stands keeps the AI's sentence as the AI's.
        $this->patch(route('quotations.update', $this->quotation), ['language' => 'mr', 'opening' => $mine, 'closing' => self::CLOSING_MR])->assertSessionHasNoErrors();
        $this->assertSame('ai', $this->quotation->fresh()->slots['closing']['source']);

        // With both rewritten there is nothing to ask for, and nothing is made.
        $this->patch(route('quotations.update', $this->quotation), ['language' => 'mr', 'opening' => $mine, 'closing' => 'कृपया प्रश्न असल्यास कळवा.'])->assertSessionHasNoErrors();
        $this->ask()->assertStatus(422)->assertJsonValidationErrors('ai_reader');
        $this->assertSame(1, $this->calls());
    }

    public function test_a_new_language_drops_the_ais_sentences_even_when_the_form_sends_them_back(): void
    {
        $this->fakeModel([$this->fine()]);
        $this->ask()->assertStatus(202);
        $this->runCall(AiTextCall::sole());
        $this->assertSame('ai', $this->quotation->fresh()->slots['opening']['source']);

        // The form still shows the Marathi sentences when Hindi is chosen; they are not taken as typed.
        $this->actingAs($this->manager)->patch(route('quotations.update', $this->quotation), ['language' => 'hi', 'opening' => self::OPENING_MR, 'closing' => self::CLOSING_MR])->assertSessionHasNoErrors();

        $quotation = $this->quotation->fresh();
        $this->assertSame('hi', $quotation->language);
        $this->assertNull($quotation->slots);
        [, $note] = $this->quotations()->review($quotation);
        $this->assertSame('template', $note->slot('opening')->source);
        $this->assertStringNotContainsString(self::OPENING_MR, $note->text());
    }

    public function test_a_quotation_issued_while_the_ai_was_writing_keeps_its_words_and_nothing_is_charged(): void
    {
        $this->fakeModel([$this->fine()]);
        $this->ask()->assertStatus(202);

        $this->post(route('quotations.issue', $this->quotation), $this->allTicks($this->quotation))->assertSessionHasNoErrors();
        $issued = $this->quotation->fresh()->only(['slots', 'facts', 'status']);

        $this->runCall(AiTextCall::sole());

        $this->assertSame(AiTextCall::FAILED, AiTextCall::sole()->status);
        $this->assertSame([], $this->charges());
        $this->assertSame($issued, $this->quotation->fresh()->only(['slots', 'facts', 'status']));

        // An issued quotation takes no new request, and its page offers none.
        $this->ask()->assertStatus(409);
        $this->get(route('quotations.show', $this->quotation))->assertOk()->assertDontSee('Write them with the AI');
    }

    public function test_drafting_needs_no_consent_switch_but_the_ai_must_be_on(): void
    {
        // Only what each sentence is for and the names of the figures are sent: the switch is not asked for.
        $this->ask()->assertStatus(202);

        AiTextCall::withoutGlobalScopes()->delete();
        app(PlatformSettings::class)->set(['ai_readers_released' => '0']);

        $this->ask()->assertStatus(409)->assertJson(['code' => 'not_released']);
        $this->actingAs($this->manager)->from(route('quotations.show', $this->quotation))->post(route('quotations.draft', $this->quotation))
            ->assertRedirect(route('quotations.show', $this->quotation))->assertSessionHasErrors('ai_reader');
        $this->get(route('quotations.show', $this->quotation))->assertOk()->assertDontSee('Write them with the AI');
        $this->assertSame(0, $this->calls());
    }

    public function test_who_may_ask(): void
    {
        $this->actingAs($this->manager)->get(route('quotations.show', $this->quotation))->assertOk()
            ->assertSee('Have the AI write the opening and the closing')
            ->assertSee('Write them with the AI')
            ->assertSee('Uses up to');

        // Readers of quotations may not have one written, and do not see the button.
        foreach (['accounts', 'qs'] as $role) {
            $reader = $this->member($this->organization, $role);
            $this->ask(as: $reader)->assertForbidden();
            $this->actingAs($reader)->get(route('quotations.show', $this->quotation))->assertOk()->assertDontSee('Write them with the AI');
        }

        // The pack and money without the AI.
        $this->ask(as: $this->holding(['project.view', 'owner_pack.view', 'owner_pack.manage', 'money.view']))->assertForbidden();
        // The AI and the pack without money: a quotation is nothing but money.
        $this->ask(as: $this->holding(['project.view', 'owner_pack.view', 'owner_pack.manage', 'agents.use']))->assertForbidden();
        $this->ask(as: $this->engineer)->assertForbidden();
        $this->assertSame(0, $this->calls());

        // A plan without the AI features keeps its quotations and is told the AI is part of other plans.
        $this->organization->update(['plan' => 'contractor']);
        $this->ask(as: $this->manager->fresh())->assertForbidden();
        $this->actingAs($this->manager->fresh())->get(route('quotations.show', $this->quotation))->assertOk()
            ->assertDontSee('Write them with the AI')
            ->assertSee('Writing these sentences with the AI is part of the Business and Enterprise plans.');
        $this->assertSame(0, $this->calls());
    }

    public function test_another_company_or_another_project_is_not_reached(): void
    {
        $rival = $this->tenant('Konkan Builders', 'business');
        $this->ask(as: $this->member($rival, 'owner'))->assertNotFound();

        $elsewhere = $this->makeProject($this->organization, ['name' => 'Other Site']);
        $limited = $this->member($this->organization, 'pm');
        app(TeamAccessService::class)->applyProjects($limited, true, [$elsewhere->id]);
        $this->ask(as: $limited->refresh())->assertNotFound();

        $this->assertSame(0, $this->calls());
    }

    public function test_the_card_reads_in_hindi_and_marathi(): void
    {
        $this->manager->update(['locale' => 'hi']);
        $this->actingAs($this->manager)->get(route('quotations.show', $this->quotation))->assertOk()
            ->assertSee('शुरुआती और समापन वाक्य AI से लिखवाएँ')->assertSee('इन्हें AI से लिखवाएँ')->assertDontSee('Write them with the AI');

        $this->manager->update(['locale' => 'mr']);
        $this->actingAs($this->manager)->get(route('quotations.show', $this->quotation))->assertOk()
            ->assertSee('सुरुवातीचे आणि शेवटचे वाक्य AI कडून लिहून घ्या')->assertSee('ही AI कडून लिहून घ्या')->assertDontSee('Write them with the AI');
    }

    public function test_the_document_sent_is_the_cover_note_as_it_was_last_saved(): void
    {
        $document = app(PackDrafts::class)->document($this->quotation->fresh());

        $this->assertSame(['opening', 'closing'], $document->type->freeIds());
        $this->assertSame('mr', $document->locale);
        $this->assertSame($this->quotation->fresh()->fact_fingerprint, $document->fingerprint());

        // The opening is told the names of the figures after it, never their values.
        $brief = $document->type->brief($document->sheet);
        $this->assertSame(['Quotation number', 'Revision', 'Client name'], $brief[0]['nearby_facts']);
    }
}
