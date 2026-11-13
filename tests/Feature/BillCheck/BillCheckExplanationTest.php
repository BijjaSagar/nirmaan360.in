<?php

namespace Tests\Feature\BillCheck;

use App\Jobs\DocumentReading\RunTextCall;
use App\Models\AgentCreditWallet;
use App\Models\AiTextCall;
use App\Models\AuditLog;
use App\Models\BillCheck;
use App\Models\BillCheckExplanation;
use App\Models\Organization;
use App\Models\RaBill;
use App\Models\User;
use App\Services\Access\TeamAccessService;
use App\Services\Agents\CreditService;
use App\Services\BillCheck\Explain\BillCheckExplanations;
use App\Services\BillCheck\Explain\FindingTopics;
use App\Services\BillCheck\Flag;
use App\Services\BillCheck\FlagCatalog;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\TextCallRunner;
use App\Services\DocumentReading\TextRequest;
use App\Services\RazorpayGateway;
use App\Services\Wording\WordingRequest;
use App\Support\Dec;
use App\Support\PlatformSettings;
use Brick\Math\RoundingMode;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The findings of a check explained in Marathi, Hindi or English: in the app's own words with no
 * model at all, and with a model that writes only what each kind of finding means. Who may ask and
 * read, that a company and a project never see another's, that money stays with those who may see
 * it, and that the model never sees, places or reorders a figure or a finding.
 *
 * Every figure, item, bill number and date here is made up for the test.
 */
class BillCheckExplanationTest extends BillCheckTestCase
{
    private const MR = [
        'about_line_amount' => 'या ओळीची रक्कम तिच्या प्रमाण आणि दराच्या गुणाकाराशी जुळत नाही. दर किंवा प्रमाण हाताने बदलले आहे का ते पाहा.',
        'about_unconfirmed_term' => 'ही कपात करारनाम्याशी अजून पडताळलेली नाही. करारनामा पाहून ती निश्चित करा.',
        'about_overlap' => 'दुसऱ्या चालू बिलाचा कालावधी या बिलाशी जुळतो. तेच काम दोनदा लागले नाही ना ते पाहा.',
        'about_no_photo' => 'काही मोजमापांना फोटो नाहीत. क्लायंट ते मागू शकतो.',
    ];

    /** One made-up value for each kind of figure, as in BillCheckWordingTest. */
    private const SAMPLES = [
        'money' => '12345.67', 'pct' => '7.25', 'source' => 'Sample agreement, part Z', 'qty' => '3.500',
        'ratio' => '9.5', 'count' => 4, 'date' => '2026-11-30', 'text' => 'XYZ-9', 'head' => 'retention',
    ];

    private RaBill $bill;

    private BillCheck $stored;

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @var list<mixed> */
    private array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['modules.document_reading.ceilings.cache_seconds' => 0, 'modules.document_reading.typical.cache_seconds' => 0]);

        // What the platform admin does before any AI is offered: release with a monthly cap, and save a key.
        app(PlatformSettings::class)->set(['ai_readers_released' => '1', 'ai_tier_monthly_cap_usd' => '1000', 'anthropic_api_key' => 'test-key-value']);

        $this->measure('20');
        $this->bill = $this->clientBill();
        $this->stored = $this->storedCheck($this->bill);
    }

    /**
     * A check of the bill with findings made up for the test: two lines that do not add up, a
     * percentage nobody confirmed, an overlapping bill and measurements without photos.
     */
    private function storedCheck(RaBill $bill, array $flags = []): BillCheck
    {
        $flags = $flags ?: [
            new Flag('A1.amount:11', 'A1.amount', Flag::BLOCKING, ['item' => '2.1', 'qty' => '12.500', 'rate' => '845.00', 'stored' => '10600.00', 'expected' => '10562.50']),
            new Flag('A1.amount:12', 'A1.amount', Flag::BLOCKING, ['item' => '2.4', 'qty' => '3.000', 'rate' => '1275.00', 'stored' => '3900.00', 'expected' => '3825.00']),
            new Flag('T1:tds', 'T1', Flag::TICK, ['head' => 'tds', 'pct' => '1.75']),
            new Flag('B4:77', 'B4', Flag::TICK, ['number' => 'RA-MB1-002', 'from' => '2026-11-10', 'to' => '2026-11-25']),
            new Flag('D6', 'D6', Flag::NOTE, ['count' => 3, 'total' => 7]),
        ];

        return BillCheck::withoutGlobalScopes()->create([
            'organization_id' => $bill->organization_id, 'project_id' => $bill->project_id, 'ra_bill_id' => $bill->id,
            'flags' => array_map(fn (Flag $flag) => $flag->toArray(), $flags), 'flags_hash' => hash('sha256', 'made up'),
            'summary' => ['blocking' => 2, 'tick' => 2, 'note' => 1], 'run_by' => $this->pm->id,
        ]);
    }

    /** Answers the Messages address with these replies in turn; the last one repeats. */
    private function fakeModel(array $replies): void
    {
        $this->replies = $replies;

        Http::fake([
            'api.anthropic.com/v1/messages' => function (Request $request) {
                $this->sent[] = $request->data();
                $reply = count($this->replies) > 1 ? array_shift($this->replies) : $this->replies[0];

                return Http::response($reply, 200, ['request-id' => 'req_explain_'.count($this->sent)]);
            },
        ]);
    }

    /** @param  array<string, string>  $texts  Slot id => text, as the model would answer. */
    private function slots(array $texts): array
    {
        $entries = [];

        foreach ($texts as $id => $text) {
            $entries[] = ['id' => $id, 'text' => $text];
        }

        return [
            'id' => 'msg_explain_'.uniqid(), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => json_encode(['slots' => $entries], JSON_UNESCAPED_UNICODE)]],
            'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 2100, 'output_tokens' => 160],
        ];
    }

    private function runCall(int $id): void
    {
        app(TextCallRunner::class)->run($id);
    }

    private function available(): string
    {
        return app(CreditService::class)->wallet($this->org)->available();
    }

    private function ask(User $user, string $locale = 'mr', array $extra = [])
    {
        return $this->actingAs($user)->postJson(route('bill-checks.explain', $this->stored), ['locale' => $locale, ...$extra]);
    }

    private function read(User $user, ?string $locale = 'mr')
    {
        return $this->actingAs($user)->getJson(route('bill-checks.explanation', ['check' => $this->stored, ...($locale === null ? [] : ['locale' => $locale])]));
    }

    /** A project manager limited to a project other than the bill's. */
    private function limitedElsewhere(): User
    {
        $other = $this->makeProject($this->org, ['code' => 'MB9']);
        $user = $this->member($this->org, 'pm');
        app(TeamAccessService::class)->applyProjects($user, true, [$other->id]);

        return $user->refresh();
    }

    public function test_without_any_ai_every_finding_is_explained_in_the_apps_own_words(): void
    {
        $response = $this->read($this->accounts, 'en')->assertOk()
            ->assertJsonPath('check_id', $this->stored->id)
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('source', 'template')
            ->assertJsonPath('call_id', null)
            ->assertJsonPath('can.ask', false);

        $paragraphs = $response->json('paragraphs');

        // Every finding, once, in the check's own order; what a topic means only before its first finding.
        $this->assertSame(['A1.amount:11', 'A1.amount:12', 'T1:tds', 'B4:77', 'D6'], array_column($paragraphs, 'flag_key'));
        $this->assertSame(['blocking', 'blocking', 'tick', 'tick', 'note'], array_column($paragraphs, 'level'));
        $this->assertSame([
            FindingTopics::about('line_amount'), null, FindingTopics::about('unconfirmed_term'), FindingTopics::about('overlap'), FindingTopics::about('no_photo'),
        ], array_column($paragraphs, 'about'));
        $this->assertSame([
            'Item 2.1: 12.500 at a rate of ₹845.00 comes to ₹10,562.50, but the bill shows ₹10,600.00.',
            'Item 2.4: 3.000 at a rate of ₹1,275.00 comes to ₹3,825.00, but the bill shows ₹3,900.00.',
            'TDS 1.75%: this percentage is the one typed on the contract form. Nobody has confirmed it against the agreement.',
            'Bill RA-MB1-002 covers 10 November 2026 to 25 November 2026, which overlaps the period of this bill.',
            'Measurements on this bill with no photo or sketch: 3 of 7.',
        ], array_column($paragraphs, 'finding'));
        $this->assertSame(FindingTopics::about('line_amount')."\n".$paragraphs[0]['finding'], $paragraphs[0]['text']);
        $this->assertSame($paragraphs[1]['finding'], $paragraphs[1]['text']);

        // Hindi and Marathi, with the same figures.
        $hindi = $this->read($this->accounts, 'hi')->assertOk()->json('paragraphs');
        $this->assertSame(__(FindingTopics::about('line_amount'), [], 'hi'), $hindi[0]['about']);
        $this->assertStringContainsString('₹10,562.50', $hindi[0]['finding']);
        $this->assertStringContainsString('10 नवंबर 2026', $hindi[3]['finding']);
        $marathi = $this->read($this->accounts, 'mr')->assertOk()->json('paragraphs');
        $this->assertStringContainsString('10 नोव्हेंबर 2026', $marathi[3]['finding']);
        $this->assertSame(1, preg_match('/\p{Devanagari}/u', $marathi[2]['about']));

        // Without a language asked for, the reader's own.
        $this->accounts->update(['locale' => 'hi']);
        $this->read($this->accounts->fresh(), null)->assertOk()->assertJsonPath('locale', 'hi');

        Http::assertNothingSent();
        $this->assertSame(0, BillCheckExplanation::withoutGlobalScopes()->count(), 'Reading writes nothing.');
    }

    public function test_reading_needs_the_permission_to_see_bills_and_asking_needs_both_ai_and_bill_making(): void
    {
        $this->read($this->engineer)->assertForbidden();
        $this->read($this->viewer)->assertOk();

        // The engineer may use the AI but not make bills; accounts and the viewer may not make bills either.
        foreach ([$this->engineer, $this->accounts, $this->viewer] as $user) {
            $this->ask($user)->assertForbidden();
        }

        // Someone who makes bills but may not use the AI.
        $clerk = $this->holding(['project.view', 'ra_bill.view', 'ra_bill.generate']);
        $this->ask($clerk)->assertForbidden();
        $this->read($clerk)->assertOk()->assertJsonPath('can.ask', false);

        $this->assertSame(0, AiTextCall::withoutGlobalScopes()->count());

        $this->read($this->pm)->assertOk()->assertJsonPath('can.ask', true)->assertJsonPath('credits', fn ($credits) => is_string($credits) && $credits > '0');
        $this->ask($this->qs)->assertStatus(202);
        $this->assertSame(1, AiTextCall::withoutGlobalScopes()->count());
    }

    public function test_the_model_writes_what_each_kind_of_finding_means_and_never_sees_a_figure(): void
    {
        $uuid = (string) Str::uuid();
        $response = $this->ask($this->pm, 'mr', ['client_uuid' => $uuid])->assertStatus(202)->assertJsonPath('status', 'drafting');
        $call = AiTextCall::withoutGlobalScopes()->findOrFail($response->json('call_id'));

        $explanation = BillCheckExplanation::withoutGlobalScopes()->sole();
        $this->assertSame([$this->org->id, $this->project->id, $this->stored->id, 'mr', 'template', $this->pm->id], [
            $explanation->organization_id, $explanation->project_id, $explanation->bill_check_id, $explanation->locale, $explanation->source, $explanation->requested_by,
        ]);
        $this->assertSame(['wording', BillCheckExplanation::class, $explanation->id, $this->project->id, $uuid, AiTextCall::QUEUED], [
            $call->purpose, $call->subject_type, $call->subject_id, $call->project_id, $call->client_uuid, $call->status,
        ]);
        Queue::assertPushed(RunTextCall::class, fn (RunTextCall $job) => $job->callId === $call->id);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'bill_check.explanation_asked')->count());

        // While it is written the reader sees the app's own words, and is told it is being written.
        $this->read($this->pm)->assertOk()->assertJsonPath('status', 'drafting')->assertJsonPath('source', 'template')->assertJsonPath('can.ask', false);

        // Pressing again, or sending the same request again, makes no second call.
        $this->ask($this->pm, 'mr', ['client_uuid' => $uuid])->assertStatus(202)->assertJsonPath('call_id', $call->id);
        $this->ask($this->pm)->assertStatus(202)->assertJsonPath('call_id', $call->id);
        $this->assertSame(1, AiTextCall::withoutGlobalScopes()->count());

        // The model answers in another order, which changes nothing.
        $this->fakeModel([$this->slots(array_reverse(self::MR, true))]);
        $this->runCall($call->id);

        $body = $this->sent[0];
        $this->assertSame([], TextRequest::problems($body, 'wording'));
        $this->assertSame('claude-sonnet-5-5', $body['model']);
        $this->assertSame(TextRequest::RULES."\n\n".WordingRequest::INSTRUCTIONS, $body['system'][0]['text']);
        $message = implode("\n", array_column($body['messages'][0]['content'], 'text'));

        $this->assertStringContainsString('Language: Marathi. Kind of document: bill check explanation. Write the sentences for these slots: about_line_amount, about_unconfirmed_term, about_overlap, about_no_photo.', $message);
        $this->assertStringContainsString('Item …: the amount on the bill is not its quantity times its rate.', $message);
        $this->assertDoesNotMatchRegularExpression('/\p{N}/u', $message);

        // No figure, no item, no bill, no date, no name of the company, the project, the client or a person.
        foreach (['845', '10,562', '10562', '1,275', '1.75', 'Item 2.1', 'RA-MB1', '2026', 'November', 'नोव्हेंबर', $this->org->name, $this->project->name, $this->client->name, $this->pm->name, 'MB1'] as $value) {
            $this->assertStringNotContainsString($value, json_encode($body, JSON_UNESCAPED_UNICODE), $value);
        }

        $call->refresh();
        $this->assertSame([AiTextCall::DONE, '0.6786'], [$call->status, $call->credits]);
        $this->assertSame('499.3214', $this->available());

        $explanation->refresh();
        $this->assertSame([BillCheckExplanation::AI, $call->id, null], [$explanation->source, $explanation->ai_text_call_id, $explanation->reason]);

        $read = $this->read($this->pm)->assertOk()->assertJsonPath('status', 'done')->assertJsonPath('source', 'ai')->assertJsonPath('call_id', $call->id)->assertJsonPath('can.ask', false);
        $paragraphs = $read->json('paragraphs');

        // The model's sentences, each before the first finding of its kind; the findings and their figures are the app's, in the check's order.
        $this->assertSame(['A1.amount:11', 'A1.amount:12', 'T1:tds', 'B4:77', 'D6'], array_column($paragraphs, 'flag_key'));
        $this->assertSame([self::MR['about_line_amount'], null, self::MR['about_unconfirmed_term'], self::MR['about_overlap'], self::MR['about_no_photo']], array_column($paragraphs, 'about'));
        $this->assertStringContainsString('₹10,562.50', $paragraphs[0]['finding']);
        $this->assertStringContainsString('1.75%', $paragraphs[2]['finding']);

        // The sentences are kept on the explanation only: not on the call, in the audit trail or in the ledger.
        $elsewhere = json_encode([DB::table('ai_text_calls')->get(), DB::table('audit_logs')->get(), DB::table('credit_ledger')->get()], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('गुणाकाराशी', $elsewhere);

        // Asked again in Marathi: it already stands, so nothing is sent and nothing is charged.
        $this->ask($this->pm)->assertOk()->assertJsonPath('call_id', $call->id)->assertJsonPath('status', 'done');
        $this->assertSame(1, AiTextCall::withoutGlobalScopes()->count());
        Http::assertSentCount(1);
    }

    public function test_model_text_with_a_figure_or_a_token_is_refused_not_charged_and_the_apps_words_stand(): void
    {
        $call = $this->ask($this->pm, 'hi')->assertStatus(202)->json('call_id');
        $this->fakeModel([
            $this->slots([...self::MR, 'about_line_amount' => 'इस लाइन में 12 मीटर ज़्यादा लिखा गया है।']),
            $this->slots([...self::MR, 'about_no_photo' => 'फ़ोटो {{f5_count}} मापों के साथ नहीं है।']),
        ]);

        $this->runCall($call);
        $this->assertSame(AiTextCall::QUEUED, AiTextCall::withoutGlobalScopes()->find($call)->status, 'One corrective request.');
        $this->runCall($call);

        $this->assertSame([AiTextCall::REJECTED, '0.0000'], [AiTextCall::withoutGlobalScopes()->find($call)->status, AiTextCall::withoutGlobalScopes()->find($call)->credits]);
        $this->assertSame('500.0000', $this->available());

        $explanation = BillCheckExplanation::withoutGlobalScopes()->sole();
        $this->assertSame([BillCheckExplanation::TEMPLATE, 'rejected', []], [$explanation->source, $explanation->reason, $explanation->slots]);

        $paragraphs = $this->read($this->pm, 'hi')->assertOk()->assertJsonPath('source', 'template')->assertJsonPath('status', 'done')->json('paragraphs');
        $this->assertSame(__(FindingTopics::about('line_amount'), [], 'hi'), $paragraphs[0]['about']);
        $this->assertStringNotContainsString('12 मीटर', json_encode($paragraphs, JSON_UNESCAPED_UNICODE));

        // A refused draft may be asked for again.
        $this->read($this->pm, 'hi')->assertJsonPath('can.ask', true);
        $this->ask($this->pm, 'hi')->assertStatus(202)->assertJsonPath('call_id', fn ($id) => $id !== $call);
    }

    public function test_the_model_cannot_write_a_finding_or_a_slot_of_its_own(): void
    {
        $call = $this->ask($this->pm)->json('call_id');
        $this->fakeModel([$this->slots([
            'about_line_amount' => self::MR['about_line_amount'],
            // It tries to rewrite a finding, and invents a topic and a slot.
            'finding_1' => 'ही ओळ बरोबर आहे.',
            'about_rate_changed' => 'दर बदलला आहे.',
            'postscript' => 'कृपया लक्ष द्यावे.',
        ])]);

        $this->runCall($call);

        $paragraphs = $this->read($this->owner)->assertOk()->assertJsonPath('source', 'ai')->json('paragraphs');
        $this->assertSame(self::MR['about_line_amount'], $paragraphs[0]['about']);
        // The topics it did not write keep the app's own sentence.
        $this->assertSame(__(FindingTopics::about('unconfirmed_term'), [], 'mr'), $paragraphs[2]['about']);
        $this->assertCount(5, $paragraphs);
        $this->assertStringContainsString('₹10,562.50', $paragraphs[0]['finding']);

        foreach (['ही ओळ बरोबर आहे', 'दर बदलला आहे', 'कृपया लक्ष द्यावे'] as $invented) {
            $this->assertStringNotContainsString($invented, json_encode($paragraphs, JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_money_percentages_and_term_words_never_reach_a_reader_who_may_not_see_money(): void
    {
        $call = $this->ask($this->pm)->json('call_id');
        $this->fakeModel([$this->slots(self::MR)]);
        $this->runCall($call);

        $viewer = $this->read($this->viewer)->assertOk()->assertJsonPath('source', 'ai');
        $paragraphs = $viewer->json('paragraphs');

        $this->assertSame(self::MR['about_line_amount'], $paragraphs[0]['about'], 'The sentences about a kind of finding are the same for everyone.');
        $this->assertSame(__('Item :item: the amount on the bill is not its quantity times its rate.', ['item' => '2.1'], 'mr'), $paragraphs[0]['finding']);

        foreach (['₹', '845', '10,562', '10,600', '1,275', '1.75'] as $private) {
            $this->assertStringNotContainsString($private, $viewer->getContent(), "A viewer received {$private}.");
        }

        // Quantities are not money, and stay.
        $this->assertStringContainsString('3 of 7', json_encode($this->read($this->viewer, 'en')->json('paragraphs')));
    }

    public function test_another_company_and_another_project_cannot_reach_an_explanation(): void
    {
        [, $rivalOwner, $rivalBill, $rivalCheck] = $this->rival();

        $this->actingAs($rivalOwner)->getJson(route('bill-checks.explanation', $this->stored))->assertNotFound();
        $this->actingAs($rivalOwner)->postJson(route('bill-checks.explain', $this->stored), ['locale' => 'en'])->assertNotFound();
        $this->actingAs($this->owner)->getJson(route('bill-checks.explanation', $rivalCheck))->assertNotFound();
        $this->actingAs($this->owner)->postJson(route('bill-checks.explain', $rivalCheck), ['locale' => 'en'])->assertNotFound();

        $limited = $this->limitedElsewhere();
        $this->actingAs($limited)->getJson(route('bill-checks.explanation', $this->stored))->assertNotFound();
        $this->actingAs($limited)->postJson(route('bill-checks.explain', $this->stored), ['locale' => 'en'])->assertNotFound();

        $this->assertSame(0, AiTextCall::withoutGlobalScopes()->count());
        $this->assertSame(0, BillCheckExplanation::withoutGlobalScopes()->count());

        // The explanation asked for by one company is never read by the other, nor by a person limited to another project.
        $call = $this->ask($this->pm)->json('call_id');
        $this->fakeModel([$this->slots(self::MR)]);
        $this->runCall($call);
        $this->actingAs($limited);
        $this->assertSame(0, BillCheckExplanation::count());
        $this->assertSame(1, BillCheckExplanation::withoutGlobalScopes()->count());
        $this->actingAs($rivalOwner);
        $this->assertSame(0, BillCheckExplanation::count());
    }

    public function test_the_app_explains_without_the_ai_plan_or_the_ai_service_and_only_asking_is_refused(): void
    {
        // A plan without the AI features: reading works, asking is refused.
        $this->org->update(['plan' => 'contractor']);
        $this->ask($this->owner)->assertForbidden();
        $this->read($this->owner)->assertOk()->assertJsonPath('source', 'template')->assertJsonPath('can.ask', false);

        $this->org->update(['plan' => 'business']);

        // Readers not released by the platform admin.
        app(PlatformSettings::class)->set(['ai_readers_released' => '0']);
        $this->ask($this->pm)->assertStatus(409)->assertJsonPath('code', 'not_released');
        $this->read($this->pm)->assertOk()->assertJsonPath('can.ask', false);

        // No key saved.
        app(PlatformSettings::class)->set(['ai_readers_released' => '1', 'anthropic_api_key' => '']);
        $this->ask($this->pm)->assertStatus(409)->assertJsonPath('code', 'not_connected');
        $this->read($this->pm)->assertOk()->assertJsonPath('paragraphs.0.flag_key', 'A1.amount:11');

        $this->assertSame(0, AiTextCall::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    public function test_a_check_that_found_nothing_has_nothing_to_explain_and_a_language_must_be_one_the_app_writes(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $empty = $this->storedCheck($this->bill, [new Flag('placeholder', 'X1', Flag::TICK)]);
        $empty->forceFill(['flags' => []])->save();

        $this->actingAs($this->pm)->postJson(route('bill-checks.explain', $empty), ['locale' => 'en'])->assertUnprocessable()->assertJsonValidationErrors('check');
        $this->actingAs($this->pm)->getJson(route('bill-checks.explanation', $empty))->assertOk()->assertJsonPath('paragraphs', []);

        $this->ask($this->pm, 'ta')->assertUnprocessable()->assertJsonValidationErrors('locale');
        $this->ask($this->pm, 'en', ['client_uuid' => 'not-a-uuid'])->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
        $this->read($this->pm, 'gu')->assertUnprocessable()->assertJsonValidationErrors('locale');

        $this->assertSame(0, AiTextCall::withoutGlobalScopes()->count());
    }

    public function test_the_card_explains_the_findings_in_the_language_picked(): void
    {
        $page = route('ra-bills.show', $this->bill);

        $this->actingAs($this->pm)->get($page)->assertOk()
            ->assertSee('What these findings mean')
            ->assertSee(FindingTopics::about('line_amount'))
            ->assertSee('Bill RA-MB1-002 covers 10 November 2026 to 25 November 2026, which overlaps the period of this bill.')
            ->assertSee('Explained in the app&#039;s own words. Every figure is placed by the app from the check.', false)
            ->assertSee('Write it in plain words with AI')
            ->assertSee(route('bill-checks.explain', $this->stored));

        $this->actingAs($this->pm)->get($page.'?explain=mr')->assertOk()
            ->assertSee(__(FindingTopics::about('line_amount'), [], 'mr'))
            ->assertSee('10 नोव्हेंबर 2026');

        // A viewer reads the same explanation without money and without the button.
        $this->actingAs($this->viewer)->get($page)->assertOk()
            ->assertSee('What these findings mean')
            ->assertSee('Item 2.1: the amount on the bill is not its quantity times its rate.')
            ->assertDontSee('₹10,562.50')
            ->assertDontSee('Write it in plain words with AI');

        // In Hindi for a Hindi reader; while the AI writes, the card says so and offers nothing more.
        $this->ask($this->pm, 'hi')->assertStatus(202);
        $this->pm->update(['locale' => 'hi']);
        $this->actingAs($this->pm->fresh())->get($page)->assertOk()
            ->assertSee('इन बातों का मतलब')
            ->assertSee('AI यह विवरण लिख रहा है। तैयार होते ही यह यहाँ दिखेगा।')
            // The card asks the explanation's status every few seconds, in the language shown.
            ->assertSee('bill-checks\\/'.$this->stored->id.'\\/explanation?locale=hi', false)
            ->assertDontSee('AI से आसान शब्दों में लिखवाएँ');

        // A browser that asks is sent back to the bill with a message.
        $this->pm->update(['locale' => 'en']);
        $this->actingAs($this->pm->fresh())->from($page.'?explain=en')->post(route('bill-checks.explain', $this->stored), ['locale' => 'en'])
            ->assertRedirect($page.'?explain=en')->assertSessionHas('success', 'The AI is writing the explanation. It appears here when it is ready.');

        // A company whose plan has no AI is told which plans have it, and still reads the explanation.
        $this->org->update(['plan' => 'contractor']);
        $this->actingAs($this->owner)->get($page.'?explain=mr')->assertOk()
            ->assertSee(__(FindingTopics::about('line_amount'), [], 'mr'))
            ->assertSee('Writing it with AI is part of the Business and Enterprise plans.')
            ->assertDontSee('Write it in plain words with AI');
    }

    public function test_a_second_press_from_another_page_or_another_person_while_the_ai_writes_makes_no_second_call(): void
    {
        // Every page draws the button with a client_uuid of its own: two tabs, or two people, send two.
        $first = $this->ask($this->pm, 'mr', ['client_uuid' => (string) Str::uuid()])->assertStatus(202)->json('call_id');
        $held = AiTextCall::withoutGlobalScopes()->findOrFail($first)->max_credits;

        $this->ask($this->pm, 'mr', ['client_uuid' => (string) Str::uuid()])->assertStatus(202)->assertJsonPath('call_id', $first)->assertJsonPath('status', 'drafting');
        $this->ask($this->qs, 'mr', ['client_uuid' => (string) Str::uuid()])->assertStatus(202)->assertJsonPath('call_id', $first);
        $this->actingAs($this->owner)->from(route('ra-bills.show', $this->bill))
            ->post(route('bill-checks.explain', $this->stored), ['locale' => 'mr', 'client_uuid' => (string) Str::uuid()])
            ->assertRedirect()->assertSessionHas('success', 'The AI is writing the explanation. It appears here when it is ready.');

        $this->assertSame(1, AiTextCall::withoutGlobalScopes()->count());
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'bill_check.explanation_asked')->count());
        $this->assertSame((string) Dec::of('500')->minus($held)->toScale(4), app(CreditService::class)->free($this->org), 'One call is held, once.');

        // Another language is another explanation, with its own call.
        $uuid = (string) Str::uuid();
        $hindi = $this->ask($this->qs, 'hi', ['client_uuid' => $uuid])->assertStatus(202)->json('call_id');
        $this->assertNotSame($first, $hindi);

        // A client_uuid of that other call is still refused here, while the first call is open.
        $this->ask($this->pm, 'mr', ['client_uuid' => $uuid])->assertUnprocessable()->assertJsonValidationErrors('client_uuid');

        // Once the call has ended, a press finds the explanation written and asks nothing.
        $this->fakeModel([$this->slots(self::MR)]);
        $this->runCall($first);
        $this->ask($this->qs, 'mr', ['client_uuid' => (string) Str::uuid()])->assertOk()->assertJsonPath('call_id', $first)->assertJsonPath('status', 'done');
        $this->assertSame(2, AiTextCall::withoutGlobalScopes()->count());
        Http::assertSentCount(1);
    }

    public function test_a_press_that_waited_on_the_wallet_while_the_call_was_written_asks_for_nothing_more(): void
    {
        $first = $this->ask($this->pm, 'mr', ['client_uuid' => (string) Str::uuid()])->assertStatus(202)->json('call_id');
        $this->fakeModel([$this->slots(self::MR)]);

        // The second press has read the explanation while the AI was still writing it. It then waits on the company's
        // wallet while the call is settled: the call ends and its sentences are written just before the press gets the lock.
        $settled = false;
        $settle = function () use ($first, &$settled): void {
            if (! $settled) {
                $settled = true;
                $this->runCall($first);
            }
        };
        $this->app->bind(CreditService::class, fn ($app) => new class($app->make(RazorpayGateway::class), $app->make(AiSpend::class), $settle) extends CreditService
        {
            public function __construct(RazorpayGateway $gateway, AiSpend $spend, private Closure $before)
            {
                parent::__construct($gateway, $spend);
            }

            public function wallet(Organization $organization): AgentCreditWallet
            {
                ($this->before)();

                return parent::wallet($organization);
            }
        });

        [$explanation, $call, $asked] = app(BillCheckExplanations::class)->ask($this->stored, 'mr', $this->qs, (string) Str::uuid());

        $this->assertTrue($settled, 'The call was written while the press waited.');
        $this->assertFalse($asked);
        $this->assertSame([BillCheckExplanation::AI, $first], [$explanation->source, $call?->id]);
        $this->assertSame(1, AiTextCall::withoutGlobalScopes()->count(), 'No second call is made, held or charged.');
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'bill_check.explanation_asked')->count());
        Http::assertSentCount(1);
    }

    public function test_the_credits_on_the_button_are_what_a_press_holds_however_many_kinds_of_finding_the_check_has(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        // Every finding the catalogue knows: more kinds than a model is asked about, so the longest request a check sends.
        $every = $this->storedCheck($this->bill, array_map(function (string $code) {
            $definition = FlagCatalog::definition($code);

            return new Flag($code.':1', $code, $definition['level'], array_map(fn (string $type) => self::SAMPLES[$type], $definition['params']));
        }, array_keys(FlagCatalog::DEFINITIONS)));

        foreach ([[$this->stored, 'mr'], [$every, 'mr'], [$every, 'en']] as [$check, $locale]) {
            $shown = $this->actingAs($this->pm)->getJson(route('bill-checks.explanation', ['check' => $check, 'locale' => $locale]))
                ->assertOk()->assertJsonPath('can.ask', true)->json('credits');

            $call = AiTextCall::withoutGlobalScopes()->findOrFail(
                $this->actingAs($this->pm)->postJson(route('bill-checks.explain', $check), ['locale' => $locale])->assertStatus(202)->json('call_id')
            );

            // "Uses up to" is the hold, rounded up to a whole credit: never less than what the press holds and may charge.
            $this->assertSame((string) Dec::of($call->max_credits)->toScale(0, RoundingMode::Ceiling)->toScale(4), $shown, "check {$check->id} {$locale}");
        }

        $this->assertGreaterThan(4, (float) $call->max_credits, 'The longest request holds more than a usual one.');
    }

    public function test_only_the_sentences_a_model_wrote_are_marked_as_written_by_ai(): void
    {
        // Three kinds are asked about; the model writes two of them. The third, and the kind past the limit, keep the app's own sentence.
        config(['modules.bill_check.explain.model_topics' => 3]);
        $call = $this->ask($this->pm, 'en')->assertStatus(202)->json('call_id');
        $written = [
            'about_line_amount' => 'The amount on this line is not its quantity times its rate. See whether the rate or the quantity was changed by hand.',
            'about_overlap' => 'Another open bill covers part of the same period. Make sure the same work is not billed again.',
        ];
        $this->fakeModel([$this->slots($written)]);
        $this->runCall($call);

        $read = $this->read($this->pm, 'en')->assertOk()->assertJsonPath('source', 'ai');
        $paragraphs = $read->json('paragraphs');

        $this->assertSame(['ai', null, 'template', 'ai', 'template'], array_column($paragraphs, 'about_source'));
        $this->assertSame([
            $written['about_line_amount'], null, FindingTopics::about('unconfirmed_term'), $written['about_overlap'], FindingTopics::about('no_photo'),
        ], array_column($paragraphs, 'about'));

        $page = $this->actingAs($this->pm)->get(route('ra-bills.show', $this->bill).'?explain=en')->assertOk()
            ->assertSee('Sentences marked “Written by AI” were written by AI. Every other sentence, the findings and every figure in them are placed by the app from the check.')
            ->assertDontSee('The sentence before each finding was written by AI');
        $this->assertSame(2, substr_count($page->getContent(), '· Written by AI'), 'Only the two sentences the model wrote are marked.');

        // In Hindi the mark and the line about it are in the reader's language.
        $this->pm->update(['locale' => 'hi']);
        $hindi = $this->actingAs($this->pm->fresh())->get(route('ra-bills.show', $this->bill).'?explain=en')->assertOk()
            ->assertSee(__('Sentences marked “Written by AI” were written by AI. Every other sentence, the findings and every figure in them are placed by the app from the check.', [], 'hi'));
        $this->assertSame(2, substr_count($hindi->getContent(), '· AI ने लिखा'));
    }

    public function test_asking_counts_on_the_text_call_counter_and_not_on_the_plain_one(): void
    {
        // The plain per-person counter other screens share is full, and asking still answers.
        $shared = sha1((string) $this->pm->id);

        foreach (range(1, 500) as $hit) {
            RateLimiter::hit($shared, 60);
        }

        $this->ask($this->pm)->assertStatus(202);

        // The text call counter allows twenty a minute.
        foreach (range(2, 20) as $attempt) {
            $this->ask($this->pm)->assertStatus(202);
        }

        $this->ask($this->pm)->assertStatus(429);
        $this->assertSame(1, AiTextCall::withoutGlobalScopes()->count());

        // Another person has a counter of their own.
        $this->ask($this->qs)->assertStatus(202);
    }
}
