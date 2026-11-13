<?php

namespace Tests\Feature\OwnerPack;

use App\Models\AiTextCall;
use App\Models\AuditLog;
use App\Models\Dpr;
use App\Models\DprPhoto;
use App\Models\OwnerUpdate;
use App\Services\Access\TeamAccessService;
use App\Services\DocumentReading\TextRequest;
use App\Services\OwnerPack\PackDrafts;
use App\Services\Wording\FactSheet;
use App\Services\Wording\WordingService;

/**
 * The AI writes the opening and the closing of a draft weekly update. Every figure, every sentence
 * that states one, the photo captions staff typed and the contractor's own words about next week
 * stay as they are and are never sent. Every name and figure here is invented for the test.
 */
class OwnerUpdateAiDraftTest extends OwnerPackAiTestCase
{
    private const OPENING_HI = 'नमस्कार, आपके घर के काम की इस सप्ताह की जानकारी नीचे दी गई है।';

    private const CLOSING_HI = 'किसी भी बात पर अधिक जानकारी चाहिए तो कृपया हमें बताएँ।';

    private const NEXT_WEEK = 'Plinth beam shuttering and two bays of brickwork';

    private OwnerUpdate $update;

    /** @var array{reports: list<Dpr>, photos: list<DprPhoto>} */
    private array $week;

    protected function setUp(): void
    {
        parent::setUp();

        $this->week = $this->week();
        $quotation = $this->quotationWithAStageReached();
        $this->update = $this->draftUpdate([
            'language' => 'hi',
            'show_money' => true,
            'quotation_id' => $quotation->id,
            'received_amount' => '40000',
            'photo_ids' => [$this->week['photos'][0]->id],
            'next_week_text' => self::NEXT_WEEK,
        ]);
    }

    private function ask(?OwnerUpdate $update = null, ?object $as = null)
    {
        return $this->actingAs($as ?? $this->manager)->postJson(route('owner-updates.draft', $update ?? $this->update));
    }

    private function fine(): array
    {
        return $this->slots(['opening' => self::OPENING_HI, 'closing' => self::CLOSING_HI]);
    }

    public function test_the_ai_writes_the_two_sentences_and_nothing_a_person_typed_is_sent(): void
    {
        $this->fakeModel([$this->fine()]);

        $this->actingAs($this->manager)->post(route('owner-updates.draft', $this->update))
            ->assertRedirect(route('owner-updates.show', $this->update).'#ai-sentences')
            ->assertSessionHas('success');

        $call = AiTextCall::sole();
        $this->assertSame([WordingService::PURPOSE, $this->update->getMorphClass(), $this->update->id, $this->project->id], [$call->purpose, $call->subject_type, $call->subject_id, $call->project_id]);

        $this->assertLooksAgainByItself($this->get(route('owner-updates.show', $this->update))->assertOk()
            ->assertSee('The AI is writing the opening and the closing sentences. This page looks again by itself.'));

        $before = $this->updateTicks($this->update);
        $this->runCall($call);

        $this->assertSame(AiTextCall::DONE, $call->fresh()->status);
        $this->assertCount(1, $this->charges());

        $update = $this->update->fresh();
        $this->assertSame(['opening' => ['text' => self::OPENING_HI, 'source' => 'ai'], 'closing' => ['text' => self::CLOSING_HI, 'source' => 'ai']], $update->slots);
        $this->assertSame(self::NEXT_WEEK, $update->next_week_text, 'The contractor\'s own words are never written for him.');
        $this->assertSame(1, AuditLog::where('action', 'owner_update.worded_by_ai')->count());

        [, $note] = $this->updates()->review($update);
        $this->assertSame(self::OPENING_HI, $note->slot('opening')->text);
        $this->assertSame(['edited', self::NEXT_WEEK], [$note->slot('next_week')->source, $note->slot('next_week')->text]);

        // What was sent: the purpose of each sentence and the names of figures, in Hindi. No figure, caption,
        // work row, typed word, name or the words about next week.
        $body = $this->sent[0];
        $text = $this->bodyText($body);
        $this->assertSame([], TextRequest::problems($body, WordingService::PURPOSE));
        $this->assertStringContainsString('Language: Hindi.', $this->messageOf($body));
        $this->assertStringContainsString('weekly update', $this->messageOf($body));
        $this->assertStringNotContainsString('next_week', $text);

        foreach (FactSheet::fromArray($update->facts) as $fact) {
            if (mb_strlen($fact->value) >= 3) {
                $this->assertDoesNotMatchRegularExpression('/(?<![\p{L}\p{N}])'.preg_quote($fact->value, '/').'(?![\p{L}\p{N}])/u', $text, $fact->key);
            }
        }

        foreach ([self::NEXT_WEEK, 'Footing F2', 'Excavation', 'Brick masonry', 'Internal remark', 'Deshmukh', 'Sahyadri'] as $word) {
            $this->assertStringNotContainsString($word, $text, $word);
        }

        $this->get(route('owner-updates.show', $this->update))->assertOk()
            ->assertSee(self::OPENING_HI)
            ->assertSee('Written by the AI. Read it before the update is approved.');

        // Ticks from the page opened before the sentences came approve nothing; read again, they do.
        $this->post(route('owner-updates.approve', $this->update), $before)->assertSessionHasErrors('facts');
        $this->post(route('owner-updates.approve', $this->update), $this->updateTicks($this->update))->assertSessionHasNoErrors();

        $approved = $this->update->fresh();
        $this->assertTrue($approved->isApproved());
        $this->assertSame(self::OPENING_HI, collect($approved->body_rendered)->firstWhere('id', 'opening')['text']);
        $this->assertSame(self::NEXT_WEEK, collect($approved->body_rendered)->firstWhere('id', 'next_week')['text']);
    }

    public function test_a_sentence_with_a_figure_is_not_used_and_not_charged(): void
    {
        $before = $this->update->fresh()->only(['slots', 'facts', 'fact_fingerprint']);
        $this->fakeModel([$this->slots(['opening' => self::OPENING_HI, 'closing' => 'इस सप्ताह 3 दिन काम हुआ।'])]);

        $this->ask()->assertStatus(202);
        $call = AiTextCall::sole();
        $this->runCall($call);
        $this->runCall($call);

        $this->assertSame([AiTextCall::REJECTED, 'rejected'], [$call->fresh()->status, $call->fresh()->fail_reason]);
        $this->assertSame([], $this->charges());
        $this->assertSame($before, $this->update->fresh()->only(['slots', 'facts', 'fact_fingerprint']));
        $this->get(route('owner-updates.show', $this->update))->assertOk()
            ->assertSee('The sentences the AI wrote stated a figure, so the app kept its own sentences. You were not charged.');
    }

    public function test_a_new_language_drops_the_ais_sentences(): void
    {
        $this->fakeModel([$this->fine()]);
        $this->ask()->assertStatus(202);
        $this->runCall(AiTextCall::sole());

        $this->actingAs($this->manager)->patch(route('owner-updates.update', $this->update), ['language' => 'mr', 'opening' => self::OPENING_HI, 'closing' => self::CLOSING_HI])->assertSessionHasNoErrors();

        $update = $this->update->fresh();
        $this->assertSame(['mr', null], [$update->language, $update->slots]);
        [, $note] = $this->updates()->review($update);
        $this->assertSame('template', $note->slot('opening')->source);
    }

    public function test_the_engineer_may_have_an_update_without_money_written_and_not_one_with_money(): void
    {
        $plain = $this->draftUpdate([], $this->engineer);
        $this->fakeModel([$this->slots(['opening' => 'Here is this week\'s update on your home.', 'closing' => 'Please ask us about anything in it.'])]);

        $this->ask($plain, $this->engineer)->assertStatus(202)->assertJson(['status' => AiTextCall::QUEUED]);
        // Pressing again while the request is open gives the same request.
        $this->ask($plain, $this->engineer)->assertStatus(202);
        $this->assertSame(1, $this->calls());

        $this->ask(as: $this->engineer)->assertForbidden();
        $this->assertSame(1, $this->calls());

        $this->runCall(AiTextCall::sole());
        $this->assertSame('ai', $plain->fresh()->slots['opening']['source']);
    }

    public function test_an_approved_update_takes_no_request(): void
    {
        $approved = $this->approvedUpdate($this->draftUpdate());

        $this->ask($approved)->assertStatus(409);
        $this->actingAs($this->manager)->get(route('owner-updates.show', $approved))->assertOk()->assertDontSee('Write them with the AI');
        $this->assertSame(0, $this->calls());

        // An update approved while the AI was writing keeps the words it was approved with.
        $this->fakeModel([$this->fine()]);
        $this->ask()->assertStatus(202);
        $this->post(route('owner-updates.approve', $this->update), $this->updateTicks($this->update))->assertSessionHasNoErrors();
        $body = $this->update->fresh()->body_rendered;

        $this->runCall(AiTextCall::sole());
        $this->assertSame(AiTextCall::FAILED, AiTextCall::sole()->status);
        $this->assertSame([], $this->charges());
        $this->assertSame($body, $this->update->fresh()->body_rendered);
    }

    public function test_who_may_ask_and_from_where(): void
    {
        $this->actingAs($this->manager)->get(route('owner-updates.show', $this->update))->assertOk()
            ->assertSee('Have the AI write the opening and the closing')->assertSee('Write them with the AI');

        foreach (['accounts', 'qs', 'store', 'viewer'] as $role) {
            $this->ask(as: $this->member($this->organization, $role))->assertForbidden();
        }

        $this->ask(as: $this->holding(['project.view', 'owner_pack.progress', 'owner_pack.manage', 'money.view']))->assertForbidden();

        $rival = $this->tenant('Konkan Builders', 'business');
        $this->ask(as: $this->member($rival, 'owner'))->assertNotFound();

        $elsewhere = $this->makeProject($this->organization, ['name' => 'Other Site']);
        $limited = $this->member($this->organization, 'pm');
        app(TeamAccessService::class)->applyProjects($limited, true, [$elsewhere->id]);
        $this->ask(as: $limited->refresh())->assertNotFound();

        $this->assertSame(0, $this->calls());

        // A plan without the AI features keeps its updates and is told the AI is part of other plans.
        $this->organization->update(['plan' => 'contractor']);
        $this->ask(as: $this->manager->fresh())->assertForbidden();
        $this->actingAs($this->manager->fresh())->get(route('owner-updates.show', $this->update))->assertOk()
            ->assertDontSee('Write them with the AI')
            ->assertSee('Writing these sentences with the AI is part of the Business and Enterprise plans.');
    }

    public function test_the_document_sent_leaves_out_the_words_about_next_week(): void
    {
        $document = app(PackDrafts::class)->document($this->update->fresh());

        $this->assertSame(['opening', 'closing'], $document->type->freeIds());
        $this->assertNull($document->type->slot('next_week'));
        $this->assertNull($document->type->slot('next_week_intro'));
        $this->assertSame('hi', $document->locale);
        $this->assertSame($this->update->fresh()->fact_fingerprint, $document->fingerprint());
    }
}
