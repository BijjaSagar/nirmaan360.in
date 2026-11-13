<?php

namespace Tests\Feature\DocumentReading;

use App\Jobs\DocumentReading\RunTextCall;
use App\Models\AgentCreditLedger;
use App\Models\AiTextCall;
use App\Models\DocumentRead;
use App\Models\User;
use App\Services\Agents\CostMeter;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\ProviderBlock;
use App\Services\DocumentReading\TextCall;
use App\Services\DocumentReading\TextCallRunner;
use App\Services\DocumentReading\TextOutcome;
use App\Services\DocumentReading\TextRequest;
use App\Support\Dec;
use App\Support\PlatformSettings;
use Brick\Math\RoundingMode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\DocumentReading\Support\FakeTextHandler;

/**
 * One text call from the queue to its end: what is sent, what is kept, what is charged, and every
 * way it can end without an answer.
 *
 * The stand-in's usual answer uses 1,400 tokens in and 300 out on the cheapest model: 0.000290
 * dollars, 0.0340 credits.
 */
class TextCallRunnerTest extends TextCallTestCase
{
    private DocumentRead $record;

    private AiTextCall $call;

    protected function setUp(): void
    {
        parent::setUp();

        $this->record = $this->record();
        $this->call = $this->start($this->record);
    }

    private function fresh(): AiTextCall
    {
        return AiTextCall::withoutGlobalScopes()->findOrFail($this->call->id);
    }

    private function assertEndedWithout(string $status, string $reason): AiTextCall
    {
        $call = $this->fresh();

        $this->assertSame([$status, $reason, '0.0000'], [$call->status, $call->fail_reason, $call->credits]);
        $this->assertSame([[$call->id, $reason]], FakeTextHandler::$failed);
        $this->assertSame([], FakeTextHandler::$done);
        $this->assertSame(['fallback' => $reason], $this->record->fresh()->review);
        $this->assertNotCharged();
        // Nothing stays held once a call has ended.
        $this->assertSame('0.0000', $this->credits()->held($this->organization));

        return $call;
    }

    public function test_an_accepted_answer_is_handed_to_the_feature_and_charged_at_what_it_cost(): void
    {
        $this->fakeModel([$this->answer()]);

        $this->runCall($this->call);

        $call = $this->fresh();
        $this->assertSame([AiTextCall::DONE, null, 1], [$call->status, $call->fail_reason, $call->attempts]);
        $this->assertSame([1400, 300, 0, 0], [$call->input_tokens, $call->output_tokens, $call->cache_read_tokens, $call->cache_write_tokens]);
        $this->assertSame('0.000290', $call->cost_usd);
        $this->assertFalse($call->cost_estimated);
        $this->assertSame('0.0340', $call->credits);
        $this->assertSame('end_turn', $call->stop_reason);
        $this->assertStringStartsWith('msg_text_', $call->provider_message_id);
        $this->assertSame('req_text_1', $call->provider_request_id);
        $this->assertNull($call->correction);

        // The feature got the answer, with fixed-set words spelled the way the form spells them, in the same transaction as the charge.
        $this->assertSame([[$call->id, ['picks' => [['candidate' => 'C1', 'same_work' => 'yes', 'note' => 'Same work, same unit.']]], '0.0340']], FakeTextHandler::$done);
        $this->assertSame(['kept' => 1], $this->record->fresh()->review);
        $this->assertSame([], FakeTextHandler::$failed);

        $line = AgentCreditLedger::withoutGlobalScopes()->where('type', 'usage')->sole();
        $this->assertSame(['-0.0340', $call->id, "tc:{$call->id}:1", null], [$line->credits, $line->ai_text_call_id, $line->reference, $line->document_read_id]);
        $this->assertSame('499.9660', $this->wallet($this->organization)->available());
        $this->assertSame('0.0000', $this->credits()->held($this->organization));
        $this->assertLedgerMatchesWallet($this->organization);

        // One request, to the Messages address, with the key and the version and nothing asking for a beta.
        Http::assertSentCount(1);
        $this->assertSame(['test-key-value'], $this->sentHeaders[0]['x-api-key']);
        $this->assertArrayNotHasKey('anthropic-beta', $this->sentHeaders[0]);
        $this->assertSame([], TextRequest::problems($this->sent[0], 'rate_match'));
        $this->assertSame('claude-haiku-5-5', $this->sent[0]['model']);
        $this->assertSame([[null, 0], [$call->id, 1]], FakeTextHandler::$asked);
    }

    public function test_the_audit_row_says_that_it_was_sent_and_nothing_of_what_was_said(): void
    {
        FakeTextHandler::$line = 'Plaster to the flat of Shri Deshpande';
        $this->fakeModel([$this->answer(['picks' => [['candidate' => 'C2', 'same_work' => 'no', 'note' => 'Masonry is another trade altogether.']]])]);

        $this->runCall($this->call);

        $row = $this->audits()[0];
        $this->assertCount(1, $this->audits());
        $this->assertSame([
            'purpose' => 'rate_match',
            'model' => 'claude-haiku-5-5',
            'input_chars' => $this->fresh()->input_chars,
            'outcome' => 'done',
            'provider_request_id' => 'req_text_1',
        ], $row->new_values);
        $this->assertSame([$this->organization->id, $this->engineer->id, AiTextCall::class, $this->call->id], [$row->organization_id, $row->user_id, $row->auditable_type, $row->auditable_id]);
        $this->assertGreaterThan(100, $row->new_values['input_chars']);

        // Neither the call's own row, nor the audit trail, nor the ledger holds a word that was sent or that came back.
        $kept = json_encode([
            DB::table('ai_text_calls')->get(),
            DB::table('audit_logs')->get(),
            DB::table('credit_ledger')->get(),
        ], JSON_UNESCAPED_UNICODE);

        foreach (['Deshpande', 'Plaster', 'Masonry is another trade', 'PCC in foundation', 'Rank the candidates'] as $words) {
            $this->assertStringNotContainsString($words, $kept, $words);
        }
    }

    public function test_a_job_delivered_twice_sends_and_charges_once(): void
    {
        $this->fakeModel([$this->answer()]);

        $this->runCall($this->call);
        $this->runCall($this->call);
        (new RunTextCall($this->call->id))->handle();

        Http::assertSentCount(1);
        $this->assertCount(1, $this->usageLines($this->organization));
        $this->assertCount(1, FakeTextHandler::$done);
        $this->assertCount(1, $this->audits());
        $this->assertSame(1, $this->fresh()->attempts);
    }

    public function test_a_worker_that_comes_back_after_its_claim_was_taken_over_writes_and_charges_nothing(): void
    {
        // While the first worker is inside the call, tending gives the call up and it is asked for and claimed again.
        $this->fakeModel([function () {
            AiTextCall::withoutGlobalScopes()->whereKey($this->call->id)->update(['attempt_uuid' => '11111111-2222-3333-4444-555555555555', 'attempts' => 2]);

            return $this->answer();
        }]);

        $this->runCall($this->call);

        $call = $this->fresh();
        $this->assertSame([AiTextCall::RUNNING, '11111111-2222-3333-4444-555555555555', 2], [$call->status, $call->attempt_uuid, $call->attempts]);
        $this->assertSame([0, '0.000000', '0.0000'], [$call->input_tokens, $call->cost_usd, $call->credits]);
        $this->assertSame([], FakeTextHandler::$done);
        $this->assertSame([], FakeTextHandler::$failed);
        $this->assertSame([], $this->audits());
        $this->assertNotCharged();
    }

    public function test_an_answer_the_feature_refuses_is_asked_for_once_more_and_only_the_one_that_is_kept_is_charged(): void
    {
        FakeTextHandler::$refuse = fn (array $output) => str_contains($output['picks'][0]['note'], '4,250') ? ['note' => ['digit', 'currency']] : [];
        $this->fakeModel([
            $this->answer(['picks' => [['candidate' => 'C1', 'same_work' => 'yes', 'note' => 'Same work at Rs 4,250 a cum.']]], 1400, 300),
            $this->answer(null, 1500, 200),
        ]);

        $this->runCall($this->call);

        // Not charged, not handed over, waiting again with what was wrong as codes and not as words.
        $call = $this->fresh();
        $this->assertSame([AiTextCall::QUEUED, null, 1, '0.0000'], [$call->status, $call->fail_reason, $call->attempts, $call->credits]);
        $this->assertSame(['note' => ['digit', 'currency']], $call->correction);
        $this->assertTrue($call->isCorrective());
        $this->assertSame('0.000290', $call->cost_usd);
        $this->assertSame([], FakeTextHandler::$done);
        $this->assertSame([], FakeTextHandler::$failed);
        $this->assertNotCharged();
        $this->assertStringNotContainsString('4,250', json_encode(DB::table('ai_text_calls')->get()));

        // It still holds its maximum, and its job is on the queue a second time, at once.
        $this->assertSame($call->max_credits, $this->credits()->held($this->organization));
        $this->assertSame([$call->id, $call->id], $this->queuedCalls());
        Queue::assertPushed(RunTextCall::class, fn (RunTextCall $job) => $job->delay === null);

        $this->runCall($this->call);

        $call = $this->fresh();
        $this->assertSame([AiTextCall::DONE, null, 2], [$call->status, $call->fail_reason, $call->attempts]);
        // The second request told the model what was wrong with the first answer.
        $this->assertStringContainsString('{"note":["digit","currency"]}', $this->messageOf($this->sent[1]));
        $this->assertStringNotContainsString('An earlier answer', $this->messageOf($this->sent[0]));

        // Both answers were paid for by the platform; the company pays for the second one alone.
        $this->assertSame([2900, 500], [$call->input_tokens, $call->output_tokens]);
        $this->assertSame('0.000540', $call->cost_usd);
        $this->assertSame('0.0293', $call->credits);
        // So the first answer's cost is the platform's, though the call was charged.
        $this->assertSame('0.000290', $call->absorbed_usd);
        $this->assertSame('0.000290', (string) app(AiSpend::class)->absorbedToday($this->organization)->toScale(6));
        $this->assertSame(['-0.0293', "tc:{$call->id}:2"], [$this->usageLines($this->organization)[0]->credits, $this->usageLines($this->organization)[0]->reference]);
        $this->assertCount(1, $this->usageLines($this->organization));
        $this->assertCount(1, FakeTextHandler::$done);
        $this->assertSame(['asked_again', 'done'], $this->sentOutcomes());
        // What was refused the first time stays on the row, so it can be counted later.
        $this->assertSame(['note' => ['digit', 'currency']], $call->correction);
    }

    public function test_an_answer_refused_twice_ends_the_call_as_rejected_and_uncharged(): void
    {
        FakeTextHandler::$refuse = fn () => ['note' => ['digit']];
        $this->fakeModel([$this->answer()]);

        $this->runCall($this->call);
        $this->runCall($this->call);
        $this->runCall($this->call);

        Http::assertSentCount(2);
        $call = $this->assertEndedWithout(AiTextCall::REJECTED, 'rejected');
        $this->assertSame(2, $call->attempts);
        $this->assertSame('0.000580', $call->cost_usd);
        $this->assertSame(['asked_again', 'rejected'], $this->sentOutcomes());
        $this->assertSame([$call->id, $call->id], $this->queuedCalls());

        // It counts as an unusable answer of the company's, and as cost the company caused without paying.
        $spend = app(AiSpend::class);
        $this->assertSame(1, $spend->unusableAnswers($this->organization));
        $this->assertSame('0.000580', (string) $spend->absorbedToday($this->organization)->toScale(6));
    }

    public function test_an_answer_in_the_wrong_shape_fails_the_call(): void
    {
        $this->fakeModel([$this->answer(['picks' => [['candidate' => 'C1', 'same_work' => 'maybe', 'note' => 'x']]])]);

        $this->runCall($this->call);

        $call = $this->assertEndedWithout(AiTextCall::FAILED, 'invalid_output');
        $this->assertSame('0.000290', $call->cost_usd);
        $this->assertSame(['failed:invalid_output'], $this->sentOutcomes());
        $this->assertSame([$call->id], $this->queuedCalls());
        // The model answered, so this is not counted as a refusal by the feature's checks.
        $this->assertSame(0, app(AiSpend::class)->unusableAnswers($this->organization));
    }

    public function test_text_that_is_not_the_form_at_all_fails_the_call_and_thinking_before_the_answer_is_skipped_over(): void
    {
        $this->fakeModel([$this->answer('Here are my picks: C1 looks right.')]);
        $this->runCall($this->call);
        $this->assertEndedWithout(AiTextCall::FAILED, 'invalid_output');

        FakeTextHandler::reset();
        $second = $this->start($this->record());
        $this->fakeModel([$this->answer(extra: ['content' => [
            ['type' => 'thinking', 'thinking' => 'C1 is concrete, like the line.', 'signature' => 'sig'],
            ['type' => 'text', 'text' => json_encode(['picks' => []])],
        ]])]);

        $this->runCall($second);

        $this->assertSame(AiTextCall::DONE, $second->fresh()->status);
        $this->assertSame(['picks' => []], FakeTextHandler::$done[0][1]);
    }

    public function test_an_answer_cut_off_at_the_output_limit_and_a_refusal_by_the_model_fail_the_call(): void
    {
        $this->fakeModel([$this->answer('{"picks":[{"candidate":"C1","same_wo', 1400, 3000, 'max_tokens')]);
        $this->runCall($this->call);

        $call = $this->assertEndedWithout(AiTextCall::FAILED, 'cut_off');
        $this->assertSame(['max_tokens', '0.001640'], [$call->stop_reason, $call->cost_usd]);

        FakeTextHandler::reset();
        $second = $this->start($this->record());
        $this->fakeModel([$this->answer('I cannot help with that.', 1400, 40, 'refusal', ['stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => 'The request looked harmful.']])]);

        $this->runCall($second);

        $second->refresh();
        $this->assertSame([AiTextCall::FAILED, 'declined', '0.0000'], [$second->status, $second->fail_reason, $second->credits]);
        $this->assertSame([[$second->id, 'declined']], FakeTextHandler::$failed);
        $this->assertStringNotContainsString('harmful', json_encode(DB::table('ai_text_calls')->get()));
        $this->assertSame(['failed:cut_off', 'failed:declined'], $this->sentOutcomes());
    }

    public function test_a_failure_in_the_features_own_step_undoes_the_charge_and_fails_the_call(): void
    {
        FakeTextHandler::$failToKeep = true;
        $this->fakeModel([$this->answer()]);

        $this->runCall($this->call);

        $call = $this->assertEndedWithout(AiTextCall::FAILED, 'internal_error');
        // The call was made and paid for by the platform all the same.
        $this->assertSame([1400, 300, '0.000290'], [$call->input_tokens, $call->output_tokens, $call->cost_usd]);
        $this->assertSame(['failed:internal_error'], $this->sentOutcomes());
    }

    public function test_a_failure_while_the_answer_is_looked_at_still_counts_what_the_call_cost(): void
    {
        FakeTextHandler::$refuse = fn () => throw new \RuntimeException('The check itself failed.');
        $this->fakeModel([$this->answer()]);

        $this->runCall($this->call);

        $call = $this->assertEndedWithout(AiTextCall::FAILED, 'internal_error');
        // It was sent and answered, so it is in the audit trail and in the platform's cost, and it is not asked for again.
        $this->assertSame([1400, '0.000290', 'req_text_1'], [$call->input_tokens, $call->cost_usd, $call->provider_request_id]);
        $this->assertSame(['failed:internal_error'], $this->sentOutcomes());
        $this->assertSame([$call->id], $this->queuedCalls());
    }

    public function test_a_fallback_that_fails_does_not_keep_the_call_from_ending(): void
    {
        FakeTextHandler::$failToFallBack = true;
        $this->fakeModel([$this->answer('not json')]);

        $this->runCall($this->call);

        $call = $this->fresh();
        $this->assertSame([AiTextCall::FAILED, 'invalid_output'], [$call->status, $call->fail_reason]);
        $this->assertSame('0.0000', $this->credits()->held($this->organization));
        // What the fallback had written before it failed was undone with it, and the audit row was still written.
        $this->assertNull($this->record->fresh()->review);
        $this->assertSame(['failed:invalid_output'], $this->sentOutcomes());
    }

    public function test_a_call_that_times_out_fails_and_counts_what_it_may_have_cost(): void
    {
        $this->fakeModel([fn () => throw new ConnectionException('Operation timed out after 90001 milliseconds')]);

        $this->runCall($this->call);

        $call = $this->assertEndedWithout(AiTextCall::FAILED, 'timeout');
        // Priced as every character of the request taken as a token and the most the call may write.
        $all = TextRequest::chars((new FakeTextHandler)->request($call))['all'];
        $this->assertTrue($call->cost_estimated);
        $this->assertSame(number_format(($all * 0.10 + 3000 * 0.50) / 1000000, 6), $call->cost_usd);
        $this->assertSame(0, $call->input_tokens);
        $this->assertSame(['failed:timeout'], $this->sentOutcomes());
        $this->assertSame($call->cost_usd, (string) app(AiSpend::class)->absorbedToday($this->organization)->toScale(6));
    }

    public function test_too_many_requests_is_tried_again_after_the_wait_the_provider_asks_for(): void
    {
        Carbon::setTestNow('2027-01-20 11:00:00');
        $this->fakeModel([
            $this->failure(429, ['type' => 'rate_limit_error', 'message' => 'Rate limited.'], ['retry-after' => '7']),
            // A wait longer than half a minute is not followed: somebody is waiting for the answer.
            $this->failure(429, ['type' => 'rate_limit_error', 'message' => 'Rate limited.'], ['retry-after' => '600']),
            $this->answer(),
        ]);

        $this->runCall($this->call);

        $call = $this->fresh();
        $this->assertSame([AiTextCall::QUEUED, null, 1, '0.000000'], [$call->status, $call->fail_reason, $call->attempts, $call->cost_usd]);
        $this->assertNull($call->correction);
        Queue::assertPushed(RunTextCall::class, fn (RunTextCall $job) => $job->delay !== null && Carbon::parse($job->delay)->toDateTimeString() === '2027-01-20 11:00:07');

        $this->runCall($this->call);
        Queue::assertPushed(RunTextCall::class, fn (RunTextCall $job) => $job->delay !== null && Carbon::parse($job->delay)->toDateTimeString() === '2027-01-20 11:00:20');

        $this->runCall($this->call);

        $call = $this->fresh();
        $this->assertSame([AiTextCall::DONE, 3, '0.0340'], [$call->status, $call->attempts, $call->credits]);
        $this->assertSame("tc:{$call->id}:3", $this->usageLines($this->organization)[0]->reference);
        $this->assertSame(['tried_again_later:rate_limited', 'tried_again_later:rate_limited', 'done'], $this->sentOutcomes());
        $this->assertSame([], FakeTextHandler::$failed);
    }

    public function test_an_outage_is_tried_twice_more_and_then_fails_the_call(): void
    {
        Carbon::setTestNow('2027-01-20 11:00:00');
        $this->fakeModel([$this->failure(529, ['type' => 'overloaded_error', 'message' => 'Overloaded.'])]);

        $this->runCall($this->call);
        Queue::assertPushed(RunTextCall::class, fn (RunTextCall $job) => $job->delay !== null && Carbon::parse($job->delay)->toDateTimeString() === '2027-01-20 11:00:10');
        $this->assertSame(AiTextCall::QUEUED, $this->fresh()->status);

        $this->runCall($this->call);
        $this->assertSame([], FakeTextHandler::$failed);

        $this->runCall($this->call);

        Http::assertSentCount(3);
        $call = $this->assertEndedWithout(AiTextCall::FAILED, 'unavailable');
        $this->assertSame([3, '0.000000'], [$call->attempts, $call->cost_usd]);
        $this->assertSame(['tried_again_later:unavailable', 'tried_again_later:unavailable', 'failed:unavailable'], $this->sentOutcomes());
        $this->assertCount(3, $this->queuedCalls());
    }

    public function test_a_rejected_key_fails_the_call_stops_further_calls_for_a_while_and_tells_only_the_platform_admin(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->tenant('Platform')->id, 'role' => 'owner', 'is_active' => true, 'is_platform_admin' => true]);
        $this->fakeModel([$this->failure(401, ['type' => 'authentication_error', 'message' => 'invalid x-api-key'])]);

        $this->runCall($this->call);

        // The company learns only that the service could not be reached.
        $this->assertEndedWithout(AiTextCall::FAILED, 'not_connected');
        $this->assertSame(['failed:not_connected'], $this->sentOutcomes());
        $this->assertStringNotContainsString('rejected_key', json_encode([DB::table('ai_text_calls')->get(), DB::table('audit_logs')->where('action', 'ai.text_sent')->get()]));

        $this->assertSame('rejected_key', ProviderBlock::current()['reason']);
        $this->assertSame(['The AI readers have stopped: the AI service refused a request'], $this->noticesOf($admin));
        $this->assertSame([], $this->noticesOf($this->owner));

        // A call that was already waiting is not sent while the stop stands, and a new one cannot be made.
        Cache::forget(ProviderBlock::KEY);
        $waiting = $this->start($this->record());
        ProviderBlock::set('rejected_key');

        $this->runCall($waiting);

        Http::assertSentCount(1);
        $this->assertSame([AiTextCall::FAILED, 'not_connected', 0], [$waiting->fresh()->status, $waiting->fresh()->fail_reason, $waiting->fresh()->attempts]);
        $this->assertCount(1, $this->noticesOf($admin));
    }

    public function test_a_spending_limit_of_the_provider_account_stops_the_calls_until_it_is_cleared(): void
    {
        $this->fakeModel([$this->failure(429, [
            'type' => 'rate_limit_error',
            'message' => 'This organization has reached its usage limit.',
            'details' => ['error_code' => 'enforced_spend_limit_reached'],
        ])]);

        $this->runCall($this->call);

        $this->assertEndedWithout(AiTextCall::FAILED, 'not_connected');
        $this->assertSame(['reason' => 'spend_limit', 'until' => null], ProviderBlock::current());
        // It is not tried again by itself, unlike an ordinary "too many requests".
        $this->assertSame([$this->call->id], $this->queuedCalls());
    }

    public function test_a_request_the_provider_will_not_take_fails_the_call_and_its_words_stay_with_the_platform(): void
    {
        $this->fakeModel([$this->failure(400, ['type' => 'invalid_request_error', 'message' => 'output_config.format.schema: unsupported keyword for Sahyadri'])]);

        $this->runCall($this->call);

        $call = $this->assertEndedWithout(AiTextCall::FAILED, 'bad_request');
        $this->assertSame('req_failed', $call->provider_request_id);
        $this->assertSame(['failed:bad_request'], $this->sentOutcomes());

        $this->assertSame('output_config.format.schema: unsupported keyword for Sahyadri', TextCallRunner::rejectionNote($call->id));
        $this->assertNull(TextCallRunner::rejectionNote($call->id + 1));
        $this->assertStringNotContainsString('unsupported keyword', json_encode([DB::table('ai_text_calls')->get(), DB::table('audit_logs')->get()]));
        $this->assertNull(ProviderBlock::current());
    }

    public function test_a_body_that_may_not_be_sent_ends_the_call_without_a_request(): void
    {
        FakeTextHandler::$spoil = function (array $body) {
            $body['temperature'] = 0;

            return $body;
        };
        $this->fakeModel([$this->answer()]);

        $this->runCall($this->call);

        Http::assertNothingSent();
        $this->assertEndedWithout(AiTextCall::FAILED, 'internal_error');
        // Nothing left the server, so there is no audit row that says something was sent.
        $this->assertSame([], $this->audits());
    }

    public function test_a_record_that_is_gone_ends_the_call_without_a_request(): void
    {
        $this->fakeModel([$this->answer()]);
        DocumentRead::withoutGlobalScopes()->whereKey($this->record->id)->delete();

        $this->runCall($this->call);

        Http::assertNothingSent();
        $call = $this->fresh();
        $this->assertSame([AiTextCall::FAILED, 'subject_gone', '0.000000'], [$call->status, $call->fail_reason, $call->cost_usd]);
        $this->assertSame([[$call->id, 'subject_gone']], FakeTextHandler::$failed);
        $this->assertSame([], $this->audits());
        $this->assertSame('0.0000', $this->credits()->held($this->organization));
    }

    public function test_a_call_is_never_charged_more_than_its_maximum_or_than_the_wallet_has(): void
    {
        // Dearer than was foreseen: the charge stops at the call's maximum and the platform bears the rest.
        $this->fakeModel([$this->answer(null, 1400, 3000)]);
        AiTextCall::withoutGlobalScopes()->whereKey($this->call->id)->update(['max_credits' => '0.0500']);

        $this->runCall($this->call);

        $this->assertSame([AiTextCall::DONE, '0.0500', '0.001640'], [$this->fresh()->status, $this->fresh()->credits, $this->fresh()->cost_usd]);
        $this->assertSame('499.9500', $this->wallet($this->organization)->available());
        $this->assertLedgerMatchesWallet($this->organization);

        // What the charge did not cover counts against the company's cap on cost nobody paid for.
        $metered = app(CostMeter::class)->credits('0.001640');
        $uncovered = (string) Dec::of('0.001640')->multipliedBy(Dec::of($metered)->minus(Dec::of('0.05')))->dividedBy(Dec::of($metered), 6, RoundingMode::HalfUp);
        $this->assertTrue(Dec::of($uncovered)->isPositive());
        $this->assertSame($uncovered, $this->fresh()->absorbed_usd);
        $this->assertSame($uncovered, (string) app(AiSpend::class)->absorbedToday($this->organization)->toScale(6));

        // The wallet was emptied by something else while the call was with the model: the answer is kept, the charge is what is there.
        $second = $this->start($this->record());
        $this->setCredits($this->organization, '0.0100');
        $this->fakeModel([$this->answer()]);

        $this->runCall($second);

        $this->assertSame([AiTextCall::DONE, '0.0100'], [$second->fresh()->status, $second->fresh()->credits]);
        $this->assertSame('0.0000', $this->wallet($this->organization)->available());
        $this->assertSame(['-0.0100', '0.0000'], [$this->usageLines($this->organization)[1]->credits, $this->usageLines($this->organization)[1]->balance_after]);
    }

    public function test_the_job_sees_settings_saved_since_the_worker_started(): void
    {
        $this->fakeModel([$this->answer()]);

        // The worker's own copy of the settings still says that no key is saved.
        $stale = app(PlatformSettings::class);
        $stale->set(['anthropic_api_key' => null]);
        $stale->get('anthropic_api_key');
        DB::table('platform_settings')->insert(['key' => 'anthropic_api_key', 'value' => encrypt('later-key', false), 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget('platform_settings.v1');

        (new RunTextCall($this->call->id))->handle();

        $this->assertSame(AiTextCall::DONE, $this->fresh()->status);
        $this->assertSame(['later-key'], $this->sentHeaders[0]['x-api-key']);
    }

    public function test_one_attempt_can_be_made_without_a_row_and_saves_and_charges_nothing(): void
    {
        // What the platform admin's dry run does: the same request, the same judgement, other figures if wanted.
        $unsaved = new AiTextCall(['organization_id' => $this->organization->id, 'purpose' => 'rate_match', 'subject_type' => DocumentRead::class, 'subject_id' => $this->record->id]);
        FakeTextHandler::$refuse = fn (array $output) => $output['picks'] === [] ? ['picks' => ['empty']] : [];
        $this->fakeModel([$this->answer(), $this->answer(['picks' => []]), $this->answer('{"picks":')]);
        $before = [DB::table('ai_text_calls')->count(), DB::table('audit_logs')->count(), DB::table('credit_ledger')->count()];

        $attempt = app(TextCall::class);
        $done = $attempt->run(new FakeTextHandler, $unsaved, ['model' => 'claude-sonnet-5-5', 'max_tokens' => 1200, 'effort' => 'medium', 'timeout' => 30]);
        $refused = $attempt->run(new FakeTextHandler, $unsaved);
        $failed = $attempt->run(new FakeTextHandler, $unsaved);

        $this->assertSame([TextOutcome::DONE, 'C1', 1400, 'req_text_1'], [$done->result, $done->output['picks'][0]['candidate'], $done->usage['input'], $done->requestId]);
        $this->assertSame(['claude-sonnet-5-5', 1200, 'medium'], [$this->sent[0]['model'], $this->sent[0]['max_tokens'], $this->sent[0]['output_config']['effort']]);
        $this->assertSame(['claude-haiku-5-5', 3000, 'low'], [$this->sent[1]['model'], $this->sent[1]['max_tokens'], $this->sent[1]['output_config']['effort']]);
        $this->assertGreaterThan(100, $done->messageChars);
        $this->assertGreaterThan($done->messageChars, $done->allChars);

        $this->assertSame([TextOutcome::REFUSED, ['picks' => ['empty']], null], [$refused->result, $refused->refusals, $refused->output]);
        $this->assertSame([TextOutcome::FAILED, 'invalid_output', true], [$failed->result, $failed->reason, $failed->sent]);

        $this->assertSame($before, [DB::table('ai_text_calls')->count(), DB::table('audit_logs')->count(), DB::table('credit_ledger')->count()]);
        $this->assertSame([], FakeTextHandler::$done);
        $this->assertNotCharged();
    }

    public function test_a_call_of_another_company_is_charged_to_that_company(): void
    {
        $other = $this->tenant('Konkan Infra');
        $theirs = $this->start($this->record($other), $this->member($other, 'owner'));
        $this->fakeModel([$this->answer()]);

        $this->runCall($theirs);

        $this->assertSame('499.9660', $this->wallet($other)->available());
        $this->assertSame('500.0000', $this->wallet($this->organization)->available());
        $this->assertSame($other->id, $this->audits()[0]->organization_id);
        $this->assertSame(AiTextCall::QUEUED, $this->fresh()->status);
    }
}
