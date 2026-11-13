<?php

namespace Tests\Feature\OwnerPack;

use App\Models\AiTextCall;
use App\Models\AuditLog;
use App\Models\BoqItem;
use App\Models\ClientQuotation;
use App\Models\ClientQuotationItem;
use App\Services\Access\TeamAccessService;
use App\Services\DocumentReading\TextRequest;
use App\Services\OwnerPack\DescriptionTranslation;
use App\Services\OwnerPack\QuotationTranslations;
use App\Services\Wording\FactSheet;
use Illuminate\Support\Str;

/**
 * The AI translates the descriptions of a draft quotation's lines into its language. A translation is
 * kept only when its numbers, grades and ratios are those of the description in the same order; it
 * is printed only after a person accepted its line, and always beside the description. Every name,
 * line and figure here is invented for the test.
 */
class DescriptionTranslationTest extends OwnerPackAiTestCase
{
    private const MR = [
        'L1' => 'सर्व प्रकारच्या मातीत पायासाठी खोदकाम',
        'L2' => 'सिमेंट मसाल्यात विटांचे बांधकाम',
        'L3' => 'जागेची साफसफाई आणि सपाटीकरण',
        'L4' => 'फुटिंगखाली M15 PCC, 100 mm जाड, मिश्रण 1:2:4',
        // The numbers of the description in another order: dropped.
        'L5' => 'CM 1:6 मध्ये 230 mm जाड वीटकाम',
    ];

    private ClientQuotation $quotation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lines[] = $this->line($this->project, 'D-02', 'PCC M15 below footings, 100 mm thick, mix 1:2:4', 'cum', '2.000', '5200.00', 4);
        $this->lines[] = $this->line($this->project, 'E-07', 'Brickwork 230 mm thick in CM 1:6', 'cum', '6.000', '6100.00', 5);
        // A phone number is a personal detail. This description is never sent, so it cannot come back with a part missing.
        $this->lines[] = $this->line($this->project, 'F-01', 'Water from tanker; call 9876543210 before delivery', 'LS', '1.000', '3000.00', 6);

        $this->quotation = $this->withStages($this->draft(['language' => 'mr']));
    }

    private function translate(?ClientQuotation $quotation = null, ?object $as = null)
    {
        return $this->actingAs($as ?? $this->manager)->postJson(route('quotations.translate', $quotation ?? $this->quotation));
    }

    /** @param  array<string, string>  $texts  Ref => text. */
    private function lines(array $texts): array
    {
        return $this->answer(['lines' => array_map(fn ($ref, $text) => ['ref' => $ref, 'text' => $text], array_keys($texts), $texts)], 900, 400);
    }

    /** @return list<ClientQuotationItem> */
    private function items(?ClientQuotation $quotation = null): array
    {
        return ClientQuotationItem::withoutGlobalScopes()->where('quotation_id', ($quotation ?? $this->quotation)->id)->orderBy('sort')->orderBy('id')->get()->all();
    }

    private function translated(): void
    {
        $this->fakeModel([$this->lines(self::MR)]);
        $this->translate()->assertStatus(202);
        $this->runCall(AiTextCall::sole());
    }

    private function accept(ClientQuotationItem $item)
    {
        return $this->actingAs($this->manager)->post(route('quotations.translation.accept', [$this->quotation, $item]), ['seen' => QuotationTranslations::seal($item->fresh())]);
    }

    public function test_translations_that_keep_the_numbers_wait_on_their_lines_and_are_not_printed(): void
    {
        $this->fakeModel([$this->lines(self::MR)]);

        $this->actingAs($this->manager)->post(route('quotations.translate', $this->quotation))
            ->assertRedirect(route('quotations.show', $this->quotation).'#translations')
            ->assertSessionHas('success');

        $call = AiTextCall::sole();
        $this->assertSame([DescriptionTranslation::PURPOSE, $this->quotation->getMorphClass(), $this->quotation->id, $this->project->id], [$call->purpose, $call->subject_type, $call->subject_id, $call->project_id]);
        $this->get(route('quotations.show', $this->quotation))->assertOk()->assertSee('The AI is translating the descriptions. This page looks again by itself.');

        $this->runCall($call);

        $this->assertSame(AiTextCall::DONE, $call->fresh()->status);
        $this->assertCount(1, $this->charges());

        $items = $this->items();
        $this->assertSame([self::MR['L1'], self::MR['L2'], self::MR['L3'], self::MR['L4'], null, null], array_map(fn ($item) => $item->description_translated, $items));
        $this->assertSame([null, null, null, null, null, null], array_map(fn ($item) => $item->translation_accepted_at, $items), 'Nothing is accepted by the AI.');
        $this->assertSame(['translated' => 4, 'left_out' => 1], array_intersect_key(AuditLog::where('action', 'quotation.translated_by_ai')->sole()->new_values, ['translated' => 1, 'left_out' => 1]));

        // What was sent: the five descriptions that can be sent whole, by ref, and nothing else of the quotation.
        $body = $this->sent[0];
        $text = $this->bodyText($body);
        $this->assertSame([], TextRequest::problems($body, DescriptionTranslation::PURPOSE));
        $this->assertSame('claude-haiku-5-5', $body['model']);
        $this->assertStringContainsString('Language: Marathi. Translate the descriptions of these lines: L1, L2, L3, L4, L5.', $this->messageOf($body));
        $this->assertStringContainsString('PCC M15 below footings, 100 mm thick, mix 1:2:4', $text);

        foreach (['tanker', '9876543210', 'Anil Deshmukh', 'Deshmukh Bungalow', 'Sahyadri', '4,200.10', '4200.10', 'On agreement', 'A-01'] as $word) {
            $this->assertStringNotContainsString($word, $text, $word);
        }

        // Nobody accepted them, so neither the issued document nor the facts carry them.
        $this->assertFalse(collect(FactSheet::fromArray($this->quotation->fresh()->facts)->keys())->contains(fn ($key) => str_ends_with($key, '_translated')));
        $page = $this->get(route('quotations.show', $this->quotation))->assertOk()
            ->assertSee('Translations of the descriptions')
            ->assertSee(self::MR['L4'])
            ->assertSee('Not accepted: not printed')
            ->assertDontSee('data-translation', false);
        $this->assertSame(4, substr_count($page->getContent(), 'I have read it: print it beside the description'));

        // Asked again, the line whose translation was dropped goes first, then those that wait; the line with a phone number never.
        $this->assertSame(
            ['L1' => 'Brickwork 230 mm thick in CM 1:6', 'L2' => 'Excavation for foundation in all soils', 'L3' => 'Brick masonry in cement mortar', 'L4' => 'Site clearance and levelling', 'L5' => 'PCC M15 below footings, 100 mm thick, mix 1:2:4'],
            array_map(fn (array $line) => $line['text'], app(QuotationTranslations::class)->toSend($this->quotation->fresh())),
        );
    }

    public function test_an_accepted_translation_is_printed_beside_the_description_and_issued_with_it(): void
    {
        $this->translated();
        [$first, , , $pcc] = $this->items();
        $before = $this->allTicks($this->quotation);

        $this->accept($pcc)->assertRedirect(route('quotations.show', $this->quotation).'#translations')->assertSessionHasNoErrors();

        $pcc = $pcc->fresh();
        $this->assertSame($this->manager->id, $pcc->translation_accepted_by);
        $this->assertNotNull($pcc->translation_accepted_at);
        $this->assertSame(self::MR['L4'], FactSheet::fromArray($this->quotation->fresh()->facts)->get('line_4_work_translated')->value);
        $this->assertSame(1, AuditLog::where('action', 'quotation.translation_accepted')->count());

        // Accepting twice changes nothing.
        $this->accept($pcc)->assertSessionHasNoErrors();
        $this->assertSame(1, AuditLog::where('action', 'quotation.translation_accepted')->count());

        $this->get(route('quotations.show', $this->quotation))->assertOk()
            ->assertSee('<span class="mt-1 block whitespace-pre-line text-ink-2" lang="mr" data-translation>'.e(self::MR['L4']).'</span>', false)
            ->assertSee('Do not print it');

        // The page opened before the translation was accepted showed other facts: it issues nothing.
        $this->post(route('quotations.issue', $this->quotation), $before)->assertSessionHasErrors('facts');
        $this->post(route('quotations.issue', $this->quotation), $this->allTicks($this->quotation))->assertSessionHasNoErrors();
        $this->assertTrue($this->quotation->fresh()->isIssued());

        // The client reads the accepted translation beside the description, and the original alone on every other line.
        [, $url] = $this->shared($this->quotation->fresh());
        $this->signOut();
        $shared = $this->get($url)->assertOk()
            ->assertSee('PCC M15 below footings, 100 mm thick, mix 1:2:4')
            ->assertSee(self::MR['L4'])
            ->assertSee($first->description)
            ->assertDontSee(self::MR['L1']);
        $this->assertSame(1, substr_count($shared->getContent(), 'data-translation'));

        // Issued, its lines never change.
        $this->actingAs($this->manager)->post(route('quotations.translation.withdraw', [$this->quotation, $pcc]))->assertStatus(409);
        $this->translate()->assertStatus(409);
    }

    public function test_a_translation_changed_after_the_page_was_opened_is_not_accepted_and_one_can_be_taken_back(): void
    {
        $this->translated();
        $pcc = $this->items()[3];
        $facts = $this->quotation->fresh()->fact_fingerprint;

        $this->actingAs($this->manager)->post(route('quotations.translation.accept', [$this->quotation, $pcc]), ['seen' => hash('sha256', 'what the page showed earlier')])
            ->assertSessionHasErrors('translation');
        $this->assertNull($pcc->fresh()->translation_accepted_at);

        // A line with no translation has nothing to accept.
        $phone = $this->items()[5];
        $this->post(route('quotations.translation.accept', [$this->quotation, $phone]), ['seen' => QuotationTranslations::seal($phone)])->assertSessionHasErrors('translation');

        $this->accept($pcc)->assertSessionHasNoErrors();
        $this->assertNotSame($facts, $this->quotation->fresh()->fact_fingerprint);

        $this->post(route('quotations.translation.withdraw', [$this->quotation, $pcc]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([null, null, self::MR['L4']], [$pcc->fresh()->translation_accepted_at, $pcc->fresh()->translation_accepted_by, $pcc->fresh()->description_translated]);
        $this->assertSame($facts, $this->quotation->fresh()->fact_fingerprint, 'Taken back, the facts are those of the description alone again.');
        $this->assertSame(1, AuditLog::where('action', 'quotation.translation_withdrawn')->count());
        $this->get(route('quotations.show', $this->quotation))->assertOk()->assertDontSee('data-translation', false);
    }

    public function test_an_answer_with_nothing_that_can_be_kept_is_refused_and_not_charged(): void
    {
        $this->fakeModel([$this->lines([
            'L4' => 'फुटिंगखाली M 15 PCC, 100 mm जाड, मिश्रण 1:2:4',
            'L5' => 'CM १:६ मध्ये २३० mm जाड वीटकाम',
            'L1' => 'Excavation for foundation in all soils',
            'L9' => 'काहीतरी',
        ])]);

        $this->translate()->assertStatus(202);
        $call = AiTextCall::sole();
        $this->runCall($call);

        $this->assertSame(AiTextCall::QUEUED, $call->fresh()->status);
        $this->assertSame(['L4' => ['grades'], 'L5' => ['numbers'], 'L1' => ['same'], 'L9' => ['unknown_ref']], $call->fresh()->correction);

        $this->runCall($call);
        $this->assertSame([AiTextCall::REJECTED, 'rejected'], [$call->fresh()->status, $call->fresh()->fail_reason]);
        $this->assertStringContainsString('An earlier answer to this request was discarded, because no translation in it could be kept.', $this->messageOf($this->sent[1]));
        $this->assertStringContainsString('grades or ratios that differ from the description', $this->messageOf($this->sent[1]));

        $this->assertSame([], $this->charges());
        $this->assertSame([null, null, null, null, null, null], array_map(fn ($item) => $item->description_translated, $this->items()));
        $this->get(route('quotations.show', $this->quotation))->assertOk()
            ->assertSee('No translation the AI wrote kept the numbers, grades and ratios of its line, so none was kept. You were not charged.');
    }

    public function test_what_can_be_translated(): void
    {
        // An English quotation has nothing to translate into, and its page offers nothing.
        $english = $this->draft();
        $this->translate($english)->assertStatus(422)->assertJsonValidationErrors('ai_reader');
        $this->actingAs($this->manager)->get(route('quotations.show', $english))->assertOk()->assertDontSee('Translations of the descriptions');

        // With every line that can be sent accepted, nothing waits.
        $this->translated();

        foreach (array_slice($this->items(), 0, 4) as $item) {
            $this->accept($item)->assertSessionHasNoErrors();
        }

        // The line whose translation was dropped is sent again, alone.
        $this->fakeModel([$this->lines(['L1' => '230 mm जाड वीटकाम, CM 1:6 मध्ये'])]);
        $this->translate()->assertStatus(202);
        $this->runCall(AiTextCall::latest('id')->first());
        $this->assertStringContainsString('Translate the descriptions of these lines: L1.', $this->messageOf($this->sent[1]));
        $this->assertSame('230 mm जाड वीटकाम, CM 1:6 मध्ये', $this->items()[4]->description_translated);
        $this->accept($this->items()[4])->assertSessionHasNoErrors();

        $this->translate()->assertStatus(422)->assertJsonValidationErrors('ai_reader');
        $this->assertSame(2, $this->calls());
    }

    public function test_a_new_language_clears_translations_and_lines_copied_again_or_revised_keep_theirs(): void
    {
        $this->translated();
        $this->accept($this->items()[3])->assertSessionHasNoErrors();

        // Copying the lines again: an unchanged line keeps its translation and its acceptance.
        $ids = array_map(fn (BoqItem $line) => $line->id, $this->lines);
        $this->actingAs($this->manager)->patch(route('quotations.update', $this->quotation), ['boq_item_ids' => $ids])->assertSessionHasNoErrors();
        $items = $this->items();
        $this->assertSame([self::MR['L1'], self::MR['L4']], [$items[0]->description_translated, $items[3]->description_translated]);
        $this->assertNotNull($items[3]->translation_accepted_at);
        $this->assertNull($items[0]->translation_accepted_at);
        $this->assertTrue(FactSheet::fromArray($this->quotation->fresh()->facts)->has('line_4_work_translated'));

        // A revision of the issued quotation keeps its lines' translations, and whether each was accepted.
        $this->post(route('quotations.issue', $this->quotation), $this->allTicks($this->quotation))->assertSessionHasNoErrors();
        $this->post(route('quotations.revise', $this->quotation), ['client_uuid' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $revision = ClientQuotation::where('revision', 2)->sole();
        $copied = $this->items($revision);
        $this->assertSame(self::MR['L4'], $copied[3]->description_translated);
        $this->assertNotNull($copied[3]->translation_accepted_at);
        $this->assertTrue(FactSheet::fromArray($revision->facts)->has('line_4_work_translated'));

        // In another language every translation goes: it was written for Marathi.
        $this->patch(route('quotations.update', $revision), ['language' => 'hi'])->assertSessionHasNoErrors();
        $this->assertSame([], array_values(array_filter(array_map(fn ($item) => $item->description_translated, $this->items($revision)))));
        $this->assertFalse(FactSheet::fromArray($revision->fresh()->facts)->has('line_4_work_translated'));
        $this->assertSame(self::MR['L4'], $this->items()[3]->description_translated, 'The issued revision keeps its own.');
    }

    public function test_a_quotation_issued_while_the_ai_was_translating_is_left_as_it_was_and_nothing_is_charged(): void
    {
        $this->fakeModel([$this->lines(self::MR)]);
        $this->translate()->assertStatus(202);
        $this->post(route('quotations.issue', $this->quotation), $this->allTicks($this->quotation))->assertSessionHasNoErrors();

        $this->runCall(AiTextCall::sole());

        $this->assertSame([AiTextCall::FAILED, 'subject_changed'], [AiTextCall::sole()->status, AiTextCall::sole()->fail_reason]);
        $this->assertSame([], $this->charges());
        $this->assertSame([null, null, null, null, null, null], array_map(fn ($item) => $item->description_translated, $this->items()));
    }

    public function test_who_may_translate_and_accept_and_from_where(): void
    {
        $this->translated();
        $pcc = $this->items()[3];
        $accept = fn ($as) => $this->actingAs($as)->post(route('quotations.translation.accept', [$this->quotation, $pcc]), ['seen' => QuotationTranslations::seal($pcc)]);
        $withdraw = fn ($as) => $this->actingAs($as)->post(route('quotations.translation.withdraw', [$this->quotation, $pcc]));

        foreach (['accounts', 'qs', 'engineer', 'viewer'] as $role) {
            $person = $this->member($this->organization, $role);
            $this->translate(as: $person)->assertForbidden();
            $accept($person)->assertForbidden();
            $withdraw($person)->assertForbidden();
        }

        // Accepting calls no model: the pack and money are enough. Translating also needs the AI.
        $noAi = $this->holding(['project.view', 'owner_pack.view', 'owner_pack.manage', 'money.view']);
        $this->translate(as: $noAi)->assertForbidden();
        $accept($noAi)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($pcc->fresh()->translation_accepted_at);
        $withdraw($noAi)->assertRedirect();
        $this->assertNull($pcc->fresh()->translation_accepted_at);

        // A line of another quotation is not reached through this one.
        $other = $this->draft(['language' => 'mr']);
        $this->actingAs($this->manager)->post(route('quotations.translation.accept', [$other, $pcc]), ['seen' => QuotationTranslations::seal($pcc)])->assertNotFound();

        $rival = $this->tenant('Konkan Builders', 'business');
        $stranger = $this->member($rival, 'owner');
        $this->translate(as: $stranger)->assertNotFound();
        $accept($stranger)->assertNotFound();
        $withdraw($stranger)->assertNotFound();

        $elsewhere = $this->makeProject($this->organization, ['name' => 'Other Site']);
        $limited = $this->member($this->organization, 'pm');
        app(TeamAccessService::class)->applyProjects($limited, true, [$elsewhere->id]);
        $this->translate(as: $limited->refresh())->assertNotFound();
        $accept($limited)->assertNotFound();

        $this->assertNull($pcc->fresh()->translation_accepted_at);
        $this->assertSame(1, $this->calls());

        // A plan without the AI features keeps the translations it has and may still accept them.
        $this->organization->update(['plan' => 'contractor']);
        $this->translate(as: $this->manager->fresh())->assertForbidden();
        $this->actingAs($this->manager->fresh())->get(route('quotations.show', $this->quotation))->assertOk()
            ->assertSee('Translating the descriptions with the AI is part of the Business and Enterprise plans.')
            ->assertSee('I have read it: print it beside the description');
        $accept($this->manager->fresh())->assertSessionHasNoErrors();
        $this->assertNotNull($pcc->fresh()->translation_accepted_at);
    }

    public function test_the_card_reads_in_hindi_and_marathi(): void
    {
        $this->translated();

        $this->manager->update(['locale' => 'hi']);
        $this->actingAs($this->manager)->get(route('quotations.show', $this->quotation))->assertOk()
            ->assertSee('विवरणों के अनुवाद')->assertSee('स्वीकार नहीं किया: छपता नहीं')->assertDontSee('Not accepted: not printed');

        $this->manager->update(['locale' => 'mr']);
        $this->actingAs($this->manager)->get(route('quotations.show', $this->quotation))->assertOk()
            ->assertSee('वर्णनांची भाषांतरे')->assertSee('मी वाचले आहे: हे वर्णनाच्या शेजारी छापा')->assertDontSee('Translations of the descriptions');
    }
}
