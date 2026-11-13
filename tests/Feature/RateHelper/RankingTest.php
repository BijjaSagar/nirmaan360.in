<?php

namespace Tests\Feature\RateHelper;

use App\Jobs\DocumentReading\RunTextCall;
use App\Models\AiDocumentSettings;
use App\Models\AiTextCall;
use App\Models\AuditLog;
use App\Models\BoqItem;
use App\Models\RateSuggestionRanking;
use App\Models\RateSuggestionRun;
use App\Models\User;
use App\Services\Agents\CreditService;
use App\Services\DocumentReading\SpendRefused;
use App\Services\DocumentReading\TextCallRunner;
use App\Services\DocumentReading\TextCalls;
use App\Services\DocumentReading\TextRequest;
use App\Services\RateHelper\Ranking\RankAnswer;
use App\Services\RateHelper\Ranking\RankCall;
use App\Services\RateHelper\Ranking\RankRequest;
use App\Services\RateHelper\Sources\OwnBoqSource;
use App\Services\RateHelper\Sources\RateLibrarySource;
use App\Support\PlatformSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * "Rank with AI" on a run of rate suggestions: the model is sent descriptions and units, never a rate
 * or a name, and only puts each line's candidates in order with a verdict and a short note. Who may
 * ask and read, that a company and a project never see another's, what of an answer is kept, and
 * that every rate is still read by the app. Every company, name and figure here is made up.
 */
class RankingTest extends RateHelperTestCase
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @var list<array<string, mixed>> */
    private array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();
        PartRefused::$refuse = null;
        Queue::fake();
        Http::preventStrayRequests();
        config(['modules.document_reading.ceilings.cache_seconds' => 0, 'modules.document_reading.typical.cache_seconds' => 0]);
    }

    /** What the platform admin does before any AI is offered: release with a monthly cap, and save a key. */
    private function aiOn(): void
    {
        app(PlatformSettings::class)->set(['ai_readers_released' => '1', 'ai_tier_monthly_cap_usd' => '1000', 'anthropic_api_key' => 'test-key-value']);
    }

    /** Answers the Messages address with these replies in turn; the last one repeats. */
    private function fakeModel(array ...$replies): void
    {
        $this->replies = $replies;

        Http::fake([
            'api.anthropic.com/v1/messages' => function (Request $request) {
                $this->sent[] = $request->data();
                $reply = count($this->replies) > 1 ? array_shift($this->replies) : $this->replies[0];

                return Http::response($reply, 200, ['request-id' => 'req_rank_'.count($this->sent)]);
            },
        ]);
    }

    /**
     * A model's answer.
     *
     * @param  array<string, list<array{0: string, 1: string, 2?: string}>>  $matches  Line ref => [candidate ref, verdict, note].
     */
    private function answer(array $matches): array
    {
        $entries = [];

        foreach ($matches as $line => $picks) {
            $entries[] = ['line' => $line, 'picks' => array_map(fn (array $pick) => ['candidate' => $pick[0], 'same_work' => $pick[1], 'note' => $pick[2] ?? ''], $picks)];
        }

        return [
            'id' => 'msg_rank_'.uniqid(), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
            'content' => [['type' => 'text', 'text' => json_encode(['matches' => $entries], JSON_UNESCAPED_UNICODE)]],
            'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 3000, 'output_tokens' => 400],
        ];
    }

    private function rank(User $by, RateSuggestionRun $run, array $body = []): TestResponse
    {
        return $this->actingAs($by)->postJson(route('rate-suggestions.rank', $run), $body);
    }

    private function rankingOf(User $by, RateSuggestionRun $run): TestResponse
    {
        return $this->actingAs($by)->getJson(route('rate-suggestions.ranking', $run));
    }

    /** Runs every call that is waiting, as the queue would. */
    private function runCalls(): void
    {
        foreach (AiTextCall::withoutGlobalScopes()->where('status', AiTextCall::QUEUED)->orderBy('id')->pluck('id') as $id) {
            app(TextCallRunner::class)->run((int) $id);
        }
    }

    private function available(): string
    {
        return app(CreditService::class)->wallet($this->org)->available();
    }

    /** @return array<string, array<string, mixed>> The stored shortlist of a line, by ref, in its stored order. */
    private function stored(RateSuggestionRun $run, BoqItem $item): array
    {
        return array_column($this->shortlist($run, $item), null, 'ref');
    }

    /** @return list<array<string, mixed>> What one request sent in <lines>. */
    private function linesSent(array $body): array
    {
        $text = $body['messages'][0]['content'][0]['text'];
        $this->assertStringStartsWith("<lines>\n", $text);

        return json_decode(substr($text, strlen("<lines>\n"), -strlen("\n</lines>")), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * A slab line with a rate in the library (C1, the closest words), a line of another project (C2)
     * and a line of a past tender, and a plaster line with one library rate.
     *
     * @return array{0: RateSuggestionRun, 1: BoqItem, 2: BoqItem}
     */
    private function slabRun(User $by): array
    {
        $slab = $this->line('RCC M25 in slab 150 mm thick');
        $plaster = $this->line('Cement plaster 12 mm thick in two coats', 'sqm');
        $this->libraryRate('RCC M25 in slab 150 mm thick', 'cum', '6200.00');
        $this->line('RCC M20 in slab 150 mm thick', 'cum', '5800.00', $this->other);
        $this->tenderLine('RCC M25 in slab 125 mm thick', 'cum', '7100.00');
        $this->libraryRate('Cement plaster 12 mm thick', 'sqm', '340.00');

        return [$this->startRun($by, $slab, $plaster), $slab, $plaster];
    }

    public function test_without_the_ai_the_apps_order_stands_and_nothing_calls_a_model(): void
    {
        [$run, $slab] = $this->slabRun($this->pm);
        $before = $this->shortlist($run, $slab);

        $this->actingAs($this->pm)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertDontSee('Rank with AI')->assertDontSee('id="ranking"', false);
        $this->rankingOf($this->pm, $run)->assertOk()->assertExactJson(['run_id' => $run->id, 'status' => 'none', 'ranked_lines' => 0, 'parts' => []]);

        // Not released by the platform admin.
        $this->rank($this->pm, $run)->assertStatus(409)->assertJsonPath('code', 'not_released');

        // Released, but no key saved.
        app(PlatformSettings::class)->set(['ai_readers_released' => '1', 'ai_tier_monthly_cap_usd' => '1000', 'anthropic_api_key' => '']);
        $this->rank($this->pm, $run)->assertStatus(409)->assertJsonPath('code', 'not_connected');

        // In a browser the person is sent back to the run with the sentence.
        $this->actingAs($this->pm)->from(route('rate-suggestions.show', $run))->post(route('rate-suggestions.rank', $run))
            ->assertRedirect(route('rate-suggestions.show', $run))->assertSessionHasErrors('ai_reader');

        $this->assertSame(0, AiTextCall::withoutGlobalScopes()->count());
        $this->assertSame(0, RateSuggestionRanking::withoutGlobalScopes()->count());
        $this->assertSame($before, $this->shortlist($run, $slab));
        Http::assertNothingSent();
    }

    public function test_the_model_is_sent_descriptions_and_units_and_never_a_rate_or_a_name(): void
    {
        $this->aiOn();
        [$run, $slab, $plaster] = $this->slabRun($this->pm);
        $library = $this->refOf($run, $slab, RateLibrarySource::KEY, (int) DB::table('rate_library_items')->where('item', 'RCC M25 in slab 150 mm thick')->value('id'));
        $this->assertSame(['C1', 'C2', 'C3'], array_keys($this->stored($run, $slab)));
        $this->assertSame('C1', $library);

        $page = $this->actingAs($this->pm)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('Rank with AI')
            ->assertSee('No rate is typed by the AI. It only ranks items the app found in your own data, and the app fetches the rate. No government schedule is loaded.')
            ->assertSee('Lines waiting to be ranked: 2');
        $this->assertMatchesRegularExpression('/Ranking uses up to [1-9]\d* AI credits/', $page->getContent());

        $uuid = (string) Str::uuid();
        $response = $this->rank($this->pm, $run, ['client_uuid' => $uuid])->assertStatus(202)
            ->assertJsonPath('status', 'ranking')->assertJsonPath('parts.0.lines', 2)->assertJsonPath('refused', null);
        $call = AiTextCall::withoutGlobalScopes()->findOrFail($response->json('parts.0.call_id'));
        $part = RateSuggestionRanking::withoutGlobalScopes()->sole();

        $this->assertSame([RankRequest::PURPOSE, RateSuggestionRanking::class, $part->id, $this->project->id, $this->pm->id, AiTextCall::QUEUED], [
            $call->purpose, $call->subject_type, $call->subject_id, $call->project_id, $call->user_id, $call->status,
        ]);
        $this->assertSame([$this->org->id, $this->project->id, $run->id, $this->pm->id, $uuid, 1, 'en', 2, 'asked', $call->id], [
            $part->organization_id, $part->project_id, $part->run_id, $part->requested_by, $part->client_uuid, $part->part, $part->locale, $part->lines, $part->status, $part->ai_text_call_id,
        ]);
        Queue::assertPushed(RunTextCall::class, fn (RunTextCall $job) => $job->callId === $call->id);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'rate_suggestions.ranking_asked')->count());

        // While the AI works, the page says so and offers no second press.
        $this->actingAs($this->pm)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('The AI is putting the suggestions in order.')->assertDontSee('Rank with AI</button>', false);
        $this->rankingOf($this->pm, $run)->assertOk()->assertJsonPath('status', 'ranking');

        // The model puts the other project's line first, says the tender line is other work, and writes a note with a figure.
        $this->fakeModel($this->answer([
            'L1' => [['C2', 'yes', 'Same slab work in the same grade.'], ['C1', 'similar', 'The slab here is 125 mm thinner.'], ['C3', 'no', 'A different concrete grade.']],
            'L2' => [['C1', 'yes', 'Same plaster work.']],
        ]));
        $this->runCalls();

        $body = $this->sent[0];
        $this->assertSame([], TextRequest::problems($body, RankRequest::PURPOSE));
        $this->assertSame('claude-haiku-5-5', $body['model']);
        $this->assertSame(TextRequest::RULES."\n\n".RankRequest::INSTRUCTIONS, $body['system'][0]['text']);
        $this->assertSame(RankRequest::SCHEMA, $body['output_config']['format']['schema']);
        $this->assertSame([
            ['ref' => 'L1', 'description' => 'RCC M25 in slab 150 mm thick', 'unit' => 'cum', 'candidates' => [
                ['ref' => 'C1', 'source' => 'rate_library', 'description' => 'RCC M25 in slab 150 mm thick', 'unit' => 'cum'],
                ['ref' => 'C2', 'source' => 'own_boq', 'description' => 'RCC M20 in slab 150 mm thick', 'unit' => 'cum'],
                ['ref' => 'C3', 'source' => 'own_tender', 'description' => 'RCC M25 in slab 125 mm thick', 'unit' => 'cum'],
            ]],
            ['ref' => 'L2', 'description' => 'Cement plaster 12 mm thick in two coats', 'unit' => 'sqm', 'candidates' => [
                ['ref' => 'C1', 'source' => 'rate_library', 'description' => 'Cement plaster 12 mm thick', 'unit' => 'sqm'],
            ]],
        ], $this->linesSent($body));
        $this->assertStringContainsString('Language of the notes: English.', json_encode($body));

        // No rate, no project, tender, company or person, no id of the app's own rows.
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        foreach (['6200', '6,200', '5800', '5,800', '7100', '7,100', '340', 'North Block', 'NB1', 'Maplewood Plaza', 'GP2', 'Ward office building', 'District Council', $this->org->name, $this->pm->name, 'line_id', 'row_id'] as $value) {
            $this->assertStringNotContainsString($value, $json, $value);
        }

        $call->refresh();
        $this->assertSame(AiTextCall::DONE, $call->status);
        $this->assertTrue((float) $call->credits > 0);
        $this->assertSame(number_format(500 - (float) $call->credits, 4, '.', ''), $this->available());

        // The order is the model's: the same work, then similar, then other work. Refs, sources and rows are unchanged.
        $slabList = $this->stored($run, $slab);
        $this->assertSame(['C2', 'C1', 'C3'], array_keys($slabList));
        $this->assertSame(['yes', 'similar', 'no'], array_column($slabList, 'same_work'));
        $this->assertSame(['Same slab work in the same grade.', null, 'A different concrete grade.'], array_column($slabList, 'note'), 'A note with a figure is dropped.');
        $this->assertSame([OwnBoqSource::KEY, RateLibrarySource::KEY, 'own_tender'], array_column($slabList, 'source'));
        $this->assertSame([true, true, true], array_column($slabList, 'ranked'));
        $this->assertSame(['yes'], array_column($this->stored($run, $plaster), 'same_work'));

        $part->refresh();
        $this->assertSame([RateSuggestionRanking::RANKED, null], [$part->status, $part->material], 'What was sent is not kept once the call is over.');
        $this->rankingOf($this->pm, $run)->assertOk()->assertJsonPath('status', 'done')->assertJsonPath('ranked_lines', 2)->assertJsonPath('parts.0.status', 'ranked');

        $this->actingAs($this->pm)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSeeInOrder(['RCC M20 in slab 150 mm thick', 'AI: same work', 'Same slab work in the same grade.', '5,800.00', 'RCC M25 in slab 150 mm thick', 'AI: similar work, check the difference', '6,200.00', 'AI: other work'])
            ->assertSee('Lines ranked by the AI: 2')
            ->assertDontSee('125 mm thinner')
            ->assertDontSee('Rank with AI</button>', false);

        // The notes are on the lines only: not on the call, in the audit trail or in the ledger.
        $elsewhere = json_encode([DB::table('ai_text_calls')->get(), DB::table('audit_logs')->get(), DB::table('credit_ledger')->get(), DB::table('rate_suggestion_rankings')->get()], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Same slab work', $elsewhere);
        $this->assertStringNotContainsString('grade', $elsewhere);
        // Nor is what was sent: no description is left on the call, its part or the audit rows of the ranking.
        $ranking = json_encode([DB::table('ai_text_calls')->get(), DB::table('rate_suggestion_rankings')->get(), DB::table('audit_logs')->where('action', 'like', 'rate_suggestions.%')->get()], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('RCC M', $ranking);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'rate_suggestions.ranked')->count());

        // Accepting what the model put first writes the rate the app reads from that row, as before.
        $this->accept($this->pm, $run, $slab, 'C2', ['checked_warnings' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('5800.00', $slab->fresh()->rate);

        // Pressing again sends nothing: every line that could be ranked is.
        $this->rank($this->pm, $run)->assertOk()->assertJsonPath('status', 'done')->assertJsonPath('parts', []);
        $this->rank($this->pm, $run, ['client_uuid' => $uuid])->assertOk()->assertJsonPath('status', 'done')->assertJsonPath('parts.0.id', $part->id);
        $this->assertSame(1, AiTextCall::withoutGlobalScopes()->count());
        Http::assertSentCount(1);
    }

    public function test_pressing_twice_while_the_ai_works_makes_no_second_call(): void
    {
        $this->aiOn();
        [$run] = $this->slabRun($this->qs);

        $first = $this->rank($this->qs, $run, ['client_uuid' => (string) Str::uuid()])->assertStatus(202)->json('parts.0.call_id');
        $this->rank($this->qs, $run, ['client_uuid' => (string) Str::uuid()])->assertStatus(202)->assertJsonPath('parts.0.call_id', $first);
        $this->rank($this->owner, $run)->assertStatus(202)->assertJsonPath('parts.0.call_id', $first);

        $this->assertSame(1, AiTextCall::withoutGlobalScopes()->count());
        $this->assertSame(1, RateSuggestionRanking::withoutGlobalScopes()->count());

        // A client_uuid of a press on another run is not taken for this one.
        [$otherRun] = [$this->startRun($this->qs, $this->line('Brick masonry in cement mortar'))];
        $uuid = RateSuggestionRanking::withoutGlobalScopes()->value('client_uuid');
        $this->rank($this->qs, $otherRun, ['client_uuid' => $uuid])->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
        $this->rank($this->qs, $run, ['client_uuid' => 'not-a-uuid'])->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
    }

    public function test_ranking_counts_on_the_text_call_counter_and_not_on_the_plain_one(): void
    {
        $this->aiOn();
        [$run] = $this->slabRun($this->qs);

        // The plain per-person counter other screens share is full, and ranking still answers.
        $shared = sha1((string) $this->qs->id);

        foreach (range(1, 500) as $hit) {
            RateLimiter::hit($shared, 60);
        }

        $this->rank($this->qs, $run)->assertStatus(202);

        // The text call counter allows twenty a minute.
        foreach (range(2, 20) as $attempt) {
            $this->rank($this->qs, $run)->assertStatus(202);
        }

        $this->rank($this->qs, $run)->assertStatus(429);
        $this->assertSame(1, AiTextCall::withoutGlobalScopes()->count());

        // Another person has a counter of their own.
        $this->rank($this->owner, $run)->assertStatus(202);
    }

    public function test_refs_that_were_not_sent_are_dropped_and_a_note_with_a_figure_currency_or_month_is_dropped(): void
    {
        $this->aiOn();
        [$run, $slab, $plaster] = $this->slabRun($this->pm);
        $this->rank($this->pm, $run)->assertStatus(202);

        $this->fakeModel($this->answer([
            // A line that was never sent, and a candidate of another line.
            'L9' => [['C1', 'yes', 'Invented line.']],
            'L2' => [['C2', 'yes', 'Not one of its candidates.'], ['c1', 'Similar', 'Costs ₹ less.']],
            'l1' => [['C1', 'yes', 'Same slab, laid in March.'], ['C1', 'no', 'Named twice.'], ['C9', 'yes', 'Not sent.'], ['C2', 'similar', '   A   thinner   slab.  ']],
            // The same line again: only the first entry counts.
            'L1 ' => [['C3', 'yes', 'Second entry for the line.']],
        ]));
        $this->runCalls();

        $this->assertSame(AiTextCall::DONE, AiTextCall::withoutGlobalScopes()->sole()->status);

        $slabList = $this->stored($run, $slab);
        $this->assertSame(['C1', 'C2', 'C3'], array_keys($slabList));
        $this->assertSame(['yes', 'similar', null], array_column($slabList, 'same_work'));
        $this->assertSame([null, 'A thinner slab.', null], array_column($slabList, 'note'));
        $this->assertSame([1, 2, null], array_column($slabList, 'rank'));

        $plasterList = $this->stored($run, $plaster);
        $this->assertSame(['similar', null], [$plasterList['C1']['same_work'], $plasterList['C1']['note']]);

        $text = json_encode(DB::table('rate_suggestion_lines')->get(), JSON_UNESCAPED_UNICODE);
        foreach (['Invented line', 'Not one of its candidates', 'Named twice', 'Not sent', 'Second entry', '₹', 'March'] as $dropped) {
            $this->assertStringNotContainsString($dropped, $text, $dropped);
        }
    }

    public function test_an_answer_that_names_nothing_that_was_sent_is_refused_not_charged_and_may_be_asked_for_again(): void
    {
        $this->aiOn();
        [$run, $slab] = $this->slabRun($this->pm);
        $before = $this->shortlist($run, $slab);
        $call = $this->rank($this->pm, $run)->json('parts.0.call_id');

        $this->fakeModel($this->answer(['L7' => [['C1', 'yes', 'Wrong line.']]]), $this->answer(['L1' => [['C8', 'yes', 'Wrong candidate.']]]));
        $this->runCalls();
        $this->assertSame(AiTextCall::QUEUED, AiTextCall::withoutGlobalScopes()->find($call)->status, 'One corrective request.');
        $this->runCalls();

        $this->assertStringContainsString('An earlier answer to this request was discarded', json_encode($this->sent[1]));
        $this->assertSame([AiTextCall::REJECTED, '0.0000'], [AiTextCall::withoutGlobalScopes()->find($call)->status, AiTextCall::withoutGlobalScopes()->find($call)->credits]);
        $this->assertSame('500.0000', $this->available());

        $part = RateSuggestionRanking::withoutGlobalScopes()->sole();
        $this->assertSame([RateSuggestionRanking::FAILED, 'rejected', null], [$part->status, $part->reason, $part->material]);
        $this->assertSame($before, $this->shortlist($run, $slab), 'The app\'s own order stands.');

        $this->actingAs($this->pm)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('Lines the AI could not rank: 2. They stay in the app\'s own order, and you can ask again.')
            ->assertSee('Rank with AI');
        $this->rank($this->pm, $run)->assertStatus(202)->assertJsonPath('parts.0.call_id', fn ($id) => $id !== $call);
    }

    public function test_decided_lines_lump_sums_and_lines_with_nothing_found_are_not_sent(): void
    {
        $this->aiOn();
        $this->withoutMiddleware(ThrottleRequests::class);
        $decided = $this->line('Brick masonry in cement mortar');
        $skipped = $this->line('Vitrified tile flooring', 'sqm');
        $lumpSum = $this->line('Site clearance', 'LS');
        $nothing = $this->line('Structural glazing to facade', 'sqm');
        $sent = $this->line('Cement plaster 12 mm thick', 'sqm');
        $this->libraryRate('Brick masonry in cement mortar', 'cum', '4100.00');
        $this->libraryRate('Vitrified tile flooring', 'sqm', '980.00');
        $this->libraryRate('Cement plaster 12 mm thick', 'sqm', '340.00');
        $run = $this->startRun($this->pm, $decided, $skipped, $lumpSum, $nothing, $sent);

        $this->accept($this->pm, $run, $decided, 'C1')->assertSessionHasNoErrors();
        $this->actingAs($this->pm)->post(route('rate-suggestions.lines.skip', [$run, $this->lineOf($run, $skipped)]))->assertSessionHasNoErrors();

        $this->rank($this->pm, $run)->assertStatus(202)->assertJsonPath('parts.0.lines', 1);
        $this->fakeModel($this->answer(['L1' => [['C1', 'yes', 'Same plaster.']]]));
        $this->runCalls();

        $this->assertSame(['Cement plaster 12 mm thick'], array_column($this->linesSent($this->sent[0]), 'description'));

        // A run with nothing left to rank says so.
        $empty = $this->startRun($this->pm, $lumpSum, $nothing);
        $this->rank($this->pm, $empty)->assertUnprocessable()->assertJsonValidationErrors('run');
        $this->assertSame(1, AiTextCall::withoutGlobalScopes()->count());
    }

    public function test_who_may_ask_and_who_may_read(): void
    {
        $this->aiOn();
        [$run] = $this->slabRun($this->owner);

        foreach (['engineer', 'store', 'viewer', 'accounts'] as $role) {
            $this->rank($this->{$role}, $run)->assertForbidden();
            $this->rankingOf($this->{$role}, $run)->assertForbidden();
        }

        // Managing the BOQ and seeing money is not enough to use the AI.
        $clerk = $this->holding(['project.view', 'boq.view', 'boq.manage', 'money.view', 'rate.view']);
        $this->rank($clerk, $run)->assertForbidden();
        $this->rankingOf($clerk, $run)->assertOk()->assertJsonPath('status', 'none');
        $this->actingAs($clerk)->get(route('rate-suggestions.show', $run))->assertOk()->assertDontSee('Rank with AI');

        $this->assertSame(0, AiTextCall::withoutGlobalScopes()->count());

        // A plan without the AI features: the run and its status work, asking is refused, the page says which plans have it.
        $this->org->update(['plan' => 'contractor']);
        $this->rank($this->owner, $run)->assertForbidden();
        $this->rankingOf($this->owner, $run)->assertOk();
        $this->actingAs($this->owner)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('Ranking with AI is part of the Business and Enterprise plans.')->assertDontSee('Rank with AI</button>', false);

        $this->org->update(['plan' => 'business']);
        $this->rank($this->qs, $run)->assertStatus(202);
        $this->rankingOf($this->pm, $run)->assertOk()->assertJsonPath('status', 'ranking');
    }

    public function test_another_company_and_another_project_cannot_reach_a_ranking(): void
    {
        $this->aiOn();
        [$run] = $this->slabRun($this->pm);
        [, $rivalOwner, $rivalProject] = $this->rival();
        $rivalLine = $this->line('RCC M25 in slab 150 mm thick', 'cum', '0.00', $rivalProject);
        $this->libraryRate('RCC M25 in slab', 'cum', '6000.00', $rivalOwner->organization);
        $rivalRun = $this->startRun($rivalOwner, $rivalLine);
        $limited = $this->limited('pm', $this->other);

        $this->rank($rivalOwner, $run)->assertNotFound();
        $this->rankingOf($rivalOwner, $run)->assertNotFound();
        $this->rank($this->owner, $rivalRun)->assertNotFound();
        $this->rankingOf($this->owner, $rivalRun)->assertNotFound();
        $this->rank($limited, $run)->assertNotFound();
        $this->rankingOf($limited, $run)->assertNotFound();

        $this->assertSame(0, AiTextCall::withoutGlobalScopes()->count());

        // Parts asked for by one company are never read by the other, nor by a person limited to another project.
        $this->rank($this->pm, $run)->assertStatus(202);
        $this->actingAs($limited);
        $this->assertSame(0, RateSuggestionRanking::count());
        $this->actingAs($rivalOwner);
        $this->assertSame(0, RateSuggestionRanking::count());
        $this->assertSame(1, RateSuggestionRanking::withoutGlobalScopes()->count());
    }

    public function test_a_person_limited_to_chosen_projects_sends_only_what_they_reach_and_sees_no_note_written_beside_what_they_do_not(): void
    {
        $this->aiOn();
        [$run, $slab] = $this->slabRun($this->owner);
        $limitedQs = $this->limited('qs', $this->project);

        // Ranked by the owner, who reaches the other project's line.
        $this->rank($this->owner, $run)->assertStatus(202);
        $this->fakeModel($this->answer([
            'L1' => [['C1', 'yes', 'Same slab in the same grade.'], ['C3', 'similar', 'Leaner concrete than this line.'], ['C2', 'similar', 'Thinner slab.']],
            'L2' => [['C1', 'yes', 'Same plaster work.']],
        ]));
        $this->runCalls();

        // The limited QS sees no line of the other project, no note on the slab line, and the verdicts on what they reach.
        $page = $this->actingAs($limitedQs)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertDontSee('RCC M20 in slab 150 mm thick')->assertDontSee('Maplewood Plaza')
            ->assertDontSee('Same slab in the same grade.')->assertDontSee('Leaner concrete')
            ->assertSee('The AI&#039;s notes on this line are not shown to you', false)
            ->assertSee('AI: same work')
            ->assertSee('Same plaster work.');
        $this->assertStringNotContainsString('5,800.00', $page->getContent());

        // The owner sees every note.
        $this->actingAs($this->owner)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('Same slab in the same grade.')->assertSee('Leaner concrete than this line.')->assertDontSee('are not shown to you');

        // When the limited QS presses on a run of their own, the other project's line is never sent.
        $mine = $this->startRun($limitedQs, $this->line('RCC M25 in slab 150 mm thick'));
        $this->assertNull($this->refOf($mine, BoqItem::withoutGlobalScopes()->where('project_id', $this->project->id)->latest('id')->first(), OwnBoqSource::KEY, (int) BoqItem::withoutGlobalScopes()->where('project_id', $this->other->id)->value('id')));
        $this->rank($limitedQs, $mine)->assertStatus(202);
        $this->runCalls();
        $this->assertStringNotContainsString('RCC M20', json_encode($this->sent[1], JSON_UNESCAPED_UNICODE));
        $sources = array_column($this->linesSent($this->sent[1])[0]['candidates'], 'source');
        $this->assertContains(RateLibrarySource::KEY, $sources);
        $this->assertNotContains(OwnBoqSource::KEY, $sources);
    }

    public function test_many_lines_go_in_parts_and_a_part_that_cannot_start_ends_without_holding_anything(): void
    {
        $this->aiOn();
        config(['modules.rate_helper.rank.lines_per_call' => 2]);
        $this->libraryRate('Cement plaster 12 mm thick', 'sqm', '340.00');
        $lines = [];

        foreach (['internal walls', 'external walls', 'ceilings', 'staircase walls', 'lift walls'] as $where) {
            $lines[] = $this->line('Cement plaster 12 mm thick to '.$where, 'sqm');
        }

        $run = $this->startRun($this->pm, ...$lines);
        // Routes keep their controller, so the stand-in is put in place before the first press. It refuses nothing until told to.
        $this->app->bind(TextCalls::class, PartRefused::class);

        // The company's daily budget for ranking is used up: nothing is made.
        AiDocumentSettings::for($this->org)->update(['budgets' => ['company' => ['rate_match' => '0']]]);
        $this->rank($this->pm, $run)->assertStatus(402)->assertJsonPath('code', 'daily_budget');
        $this->assertSame(0, AiTextCall::withoutGlobalScopes()->count());
        $this->assertSame(['failed', 'failed', 'failed'], RateSuggestionRanking::withoutGlobalScopes()->orderBy('id')->pluck('status')->all());
        $this->assertSame([null, null, null], RateSuggestionRanking::withoutGlobalScopes()->orderBy('id')->pluck('material')->all());
        AiDocumentSettings::for($this->org)->update(['budgets' => null]);

        // The second part is refused: the first is under way, the later ones end at once.
        PartRefused::$refuse = 2;
        $response = $this->rank($this->pm, $run)->assertStatus(202)->assertJsonPath('refused.code', 'daily_budget');
        $this->assertSame([2, 2, 1], array_column($response->json('parts'), 'lines'));
        $this->assertSame(['asked', 'failed', 'failed'], array_column($response->json('parts'), 'status'));
        $this->assertSame(1, AiTextCall::withoutGlobalScopes()->count());

        $this->fakeModel($this->answer(['L1' => [['C1', 'yes', 'Same plaster.']], 'L2' => [['C1', 'similar', 'Outside face.']]]));
        $this->runCalls();
        $this->assertCount(2, $this->linesSent($this->sent[0]));
        $this->assertSame(['L1', 'L2'], array_column($this->linesSent($this->sent[0]), 'ref'));

        $this->actingAs($this->pm)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('Lines ranked by the AI: 2')
            ->assertSee('Lines the AI could not rank: 3.', false)
            ->assertSee('Lines waiting to be ranked: 3');

        // Pressing again sends the three lines still waiting, in two parts.
        PartRefused::$refuse = null;
        $this->rank($this->pm, $run)->assertStatus(202);
        $this->runCalls();
        $this->assertSame([2, 1], [count($this->linesSent($this->sent[1])), count($this->linesSent($this->sent[2]))]);
        $this->assertSame(5, $this->rankingOf($this->pm, $run)->json('ranked_lines'));
    }

    public function test_the_status_request_looks_after_a_call_whose_worker_died_and_a_part_that_never_started(): void
    {
        $this->aiOn();
        [$run] = $this->slabRun($this->pm);
        $callId = $this->rank($this->pm, $run)->json('parts.0.call_id');

        AiTextCall::withoutGlobalScopes()->whereKey($callId)->update(['status' => AiTextCall::RUNNING, 'attempt_uuid' => (string) Str::uuid(), 'claimed_at' => now()->subMinutes(10)]);

        $this->rankingOf($this->pm, $run)->assertOk()->assertJsonPath('status', 'done')
            ->assertJsonPath('parts.0.status', 'failed')->assertJsonPath('parts.0.reason', TextCallRunner::WORKER_LOST);
        $this->assertSame(AiTextCall::FAILED, AiTextCall::withoutGlobalScopes()->find($callId)->status);
        $this->assertNull(RateSuggestionRanking::withoutGlobalScopes()->sole()->material);

        // A part left without its call, as by a request that stopped half way, is closed after a while.
        $stray = RateSuggestionRanking::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id, 'run_id' => $run->id, 'requested_by' => $this->pm->id,
            'part' => 1, 'locale' => 'en', 'lines' => 1, 'material' => [['ref' => 'L1']], 'status' => RateSuggestionRanking::ASKED,
        ]);
        $this->rankingOf($this->pm, $run)->assertJsonPath('status', 'ranking');
        RateSuggestionRanking::withoutGlobalScopes()->whereKey($stray->id)->update(['created_at' => now()->subMinutes(5)]);
        $this->rankingOf($this->pm, $run)->assertJsonPath('status', 'done')->assertJsonPath('parts.1.reason', 'not_started');
        $this->assertNull($stray->fresh()->material);
    }

    public function test_notes_are_written_in_the_language_of_the_person_who_presses_and_the_screen_speaks_it(): void
    {
        $this->aiOn();
        [$run, $slab] = $this->slabRun($this->pm);
        $this->pm->update(['locale' => 'mr']);

        $this->actingAs($this->pm->fresh())->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('AI कडून क्रम')
            ->assertSee('AI कोणताही दर लिहित नाही.')
            ->assertSee('AI कडून क्रम लावून घ्या')
            ->assertDontSee('Rank with AI');

        $this->rank($this->pm->fresh(), $run)->assertStatus(202);
        $this->assertSame('mr', RateSuggestionRanking::withoutGlobalScopes()->sole()->locale);

        $this->fakeModel($this->answer([
            'L1' => [['C1', 'yes', 'हेच स्लॅबचे काम आहे.'], ['C2', 'similar', 'स्लॅबची जाडी १२५ मिमी आहे.'], ['C3', 'no', 'काँक्रीटचा ग्रेड वेगळा आहे.']],
        ]));
        $this->runCalls();
        $this->assertStringContainsString('Language of the notes: Marathi.', json_encode($this->sent[0]));

        $slabList = $this->stored($run, $slab);
        $this->assertSame(['हेच स्लॅबचे काम आहे.', null, 'काँक्रीटचा ग्रेड वेगळा आहे.'], array_column($slabList, 'note'), 'A note with Devanagari digits is dropped.');
        $this->assertSame(['mr', null, 'mr'], array_column($slabList, 'note_locale'));

        $this->actingAs($this->pm->fresh())->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('AI: तेच काम')->assertSee('AI: वेगळे काम')->assertSee('lang="mr"', false)->assertSee('हेच स्लॅबचे काम आहे.');

        $this->pm->update(['locale' => 'hi']);
        $this->actingAs($this->pm->fresh())->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('AI से क्रम')->assertSee('AI: वही काम')->assertSee('AI द्वारा क्रम में लगाई गई लाइनें: 1');
    }

    public function test_a_note_is_kept_short_and_the_order_follows_the_verdicts(): void
    {
        $answers = app(RankAnswer::class);

        $long = trim(str_repeat('The candidate covers the same work with a different finish ', 8));
        $note = $answers->note($long, 'en');
        $this->assertNotNull($note);
        $this->assertLessThanOrEqual(240, mb_strlen($note));
        $this->assertStringEndsWith('…', $note);
        $this->assertNull($answers->note("Two coats\u{200B} of paint", 'en'), 'A hidden character.');
        $this->assertNull($answers->note('Same work for {{f1_rate}}', 'en'));
        $this->assertNull($answers->note('A week longer', 'en'));
        $this->assertNull($answers->note(['not text'], 'en'));
        $this->assertNull($answers->note('   ', 'en'));

        // Same work, then similar, then what the answer did not mention, then other work; reference figures stay last.
        $stored = [
            ['ref' => 'C1', 'score' => 90], ['ref' => 'C2', 'score' => 80], ['ref' => 'C3', 'score' => 70], ['ref' => 'C4', 'score' => 60], ['ref' => 'C5', 'score' => null],
        ];
        $ranked = RankCall::ranked($stored, ['C1', 'C2', 'C3', 'C4'], [
            ['ref' => 'C1', 'same_work' => 'no', 'note' => null, 'rank' => 1],
            ['ref' => 'C3', 'same_work' => 'similar', 'note' => null, 'rank' => 2],
            ['ref' => 'C4', 'same_work' => 'yes', 'note' => null, 'rank' => 3],
        ]);
        $this->assertSame(['C4', 'C3', 'C2', 'C1', 'C5'], array_column($ranked, 'ref'));
        $this->assertSame([60, 70, 80, 90, null], array_column($ranked, 'score'));
        $this->assertArrayNotHasKey('ranked', $ranked[4]);
    }

    public function test_the_migration_can_be_rolled_back_and_run_again_and_the_first_one_still_rolls_back_alone(): void
    {
        $this->aiOn();
        [$run] = $this->slabRun($this->pm);
        RateSuggestionRanking::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id, 'run_id' => $run->id, 'requested_by' => $this->pm->id,
            'part' => 1, 'locale' => 'en', 'lines' => 2, 'status' => RateSuggestionRanking::FAILED, 'reason' => 'rejected',
        ]);

        $mine = require database_path('migrations/2027_01_15_100002_create_rate_suggestion_rankings_table.php');
        $first = require database_path('migrations/2027_01_15_100001_create_rate_helper_tables.php');

        // The rate helper's first migration rolls back while a ranking of one of its runs exists.
        $first->down();
        $this->assertFalse(Schema::hasTable('rate_suggestion_runs'));
        $first->up();

        $mine->down();
        $this->assertFalse(Schema::hasTable('rate_suggestion_rankings'));
        $this->assertTrue(Schema::hasTable('rate_suggestion_lines'));
        $mine->up();

        $this->assertTrue(Schema::hasColumns('rate_suggestion_rankings', [
            'organization_id', 'project_id', 'run_id', 'requested_by', 'client_uuid', 'part', 'locale', 'lines', 'material', 'status', 'reason', 'ai_text_call_id', 'ranked_at',
        ]));

        [$again] = $this->slabRun($this->pm);
        $this->rank($this->pm, $again)->assertStatus(202)->assertJsonPath('parts.0.lines', 2);
        $this->assertSame(1, RateSuggestionRanking::withoutGlobalScopes()->count());
        $this->assertSame(1, AiTextCall::withoutGlobalScopes()->count());
    }
}

/** Refuses the call for one part number of a ranking, as a daily budget would, and makes every other one. */
class PartRefused extends TextCalls
{
    public static ?int $refuse = null;

    public function start(string $purpose, Model $subject, User $by, array $with = []): AiTextCall
    {
        if ($subject instanceof RateSuggestionRanking && (int) $subject->part === self::$refuse) {
            throw new SpendRefused(SpendRefused::DAILY_BUDGET, 402, __('Your daily budget for this is used up.'), ['feature' => $purpose, 'scope' => 'user']);
        }

        return parent::start($purpose, $subject, $by, $with);
    }
}
