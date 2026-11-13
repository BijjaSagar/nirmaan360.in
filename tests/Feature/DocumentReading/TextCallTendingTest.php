<?php

namespace Tests\Feature\DocumentReading;

use App\Jobs\DocumentReading\RunTextCall;
use App\Models\AiTextCall;
use App\Models\DocumentRead;
use App\Services\DocumentReading\ReadTending;
use App\Services\DocumentReading\TextCallRunner;
use App\Services\DocumentReading\TextCallWords;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\DocumentReading\Support\FakeTextHandler;
use Tests\Feature\Website\BannedWording;

/** Calls nothing else is moving: one whose worker died, one whose job was lost, one nobody ever picked up. */
class TextCallTendingTest extends TextCallTestCase
{
    private function runner(): TextCallRunner
    {
        return app(TextCallRunner::class);
    }

    /** A call a worker claimed that long ago and never came back from. */
    private function claimed(int $secondsAgo): AiTextCall
    {
        $call = $this->start($this->record());

        AiTextCall::withoutGlobalScopes()->whereKey($call->id)->update([
            'status' => AiTextCall::RUNNING, 'attempts' => 1, 'attempt_uuid' => (string) Str::uuid(), 'claimed_at' => now()->subSeconds($secondsAgo),
        ]);

        return $call->refresh();
    }

    public function test_a_call_whose_worker_died_is_ended_as_failed_and_stops_holding_credits(): void
    {
        $call = $this->claimed(TextCallRunner::LOST_SECONDS + 5);
        $this->assertSame($call->max_credits, $this->credits()->held($this->organization));

        $now = $this->runner()->tend($call);

        $this->assertSame([AiTextCall::FAILED, 'worker_lost', '0.0000'], [$now->status, $now->fail_reason, $now->credits]);
        $this->assertSame([[$call->id, 'worker_lost']], FakeTextHandler::$failed);
        $this->assertSame(['fallback' => 'worker_lost'], DocumentRead::withoutGlobalScopes()->find($call->subject_id)->review);
        $this->assertSame('0.0000', $this->credits()->held($this->organization));
        $this->assertNotCharged();

        // Nobody knows whether it was sent, so no audit row claims that it was. It is not handed to the queue again.
        $this->assertSame([], $this->audits());
        $this->assertSame([$call->id], $this->queuedCalls());

        // A second look changes nothing.
        $this->runner()->tend($call);
        $this->assertCount(1, FakeTextHandler::$failed);
    }

    public function test_a_call_that_is_still_within_its_time_is_left_with_its_worker(): void
    {
        $call = $this->claimed(TextCallRunner::LOST_SECONDS - 10);

        $this->assertSame(AiTextCall::RUNNING, $this->runner()->tend($call)->status);
        $this->assertSame(0, $this->runner()->tendAll());
        $this->assertSame([], FakeTextHandler::$failed);
    }

    public function test_a_worker_that_was_only_slow_finds_its_call_ended_and_writes_nothing(): void
    {
        $call = $this->start($this->record());
        $this->fakeModel([function () use ($call) {
            // The answer takes so long that tending has given the call up before it arrives.
            Carbon::setTestNow(now()->addSeconds(TextCallRunner::LOST_SECONDS + 1));
            $this->runner()->tend($call);

            return $this->answer();
        }]);

        $this->runCall($call);

        $call->refresh();
        $this->assertSame([AiTextCall::FAILED, 'worker_lost', '0.0000', 0], [$call->status, $call->fail_reason, $call->credits, $call->input_tokens]);
        $this->assertSame([], FakeTextHandler::$done);
        $this->assertSame([[$call->id, 'worker_lost']], FakeTextHandler::$failed);
        $this->assertNotCharged();
        $this->assertSame([], $this->audits());
    }

    public function test_a_call_left_waiting_is_handed_to_the_queue_once_more(): void
    {
        $call = $this->start($this->record());

        Carbon::setTestNow(now()->addSeconds(TextCallRunner::QUEUED_AGAIN_SECONDS - 5));
        $this->runner()->tend($call);
        $this->assertSame([$call->id], $this->queuedCalls());

        Carbon::setTestNow(now()->addSeconds(10));
        $this->assertSame(AiTextCall::QUEUED, $this->runner()->tend($call)->status);
        $this->assertSame([$call->id, $call->id], $this->queuedCalls());

        // The second job was handed over just now, so a look right after does not hand over a third.
        $this->runner()->tend($call);
        $this->assertSame(0, $this->runner()->tendAll());
        $this->assertCount(2, $this->queuedCalls());
        Queue::assertPushed(RunTextCall::class, fn (RunTextCall $job) => $job->delay === null);

        // Both jobs may turn up. The call is sent once.
        $this->fakeModel([$this->answer()]);
        $this->runCall($call);
        $this->runCall($call);
        Http::assertSentCount(1);
    }

    public function test_a_call_nobody_picked_up_is_given_up_so_it_stops_holding_credits(): void
    {
        $call = $this->start($this->record());

        Carbon::setTestNow(now()->addMinutes(TextCallRunner::GIVE_UP_MINUTES)->addSecond());
        $now = $this->runner()->tend($call);

        $this->assertSame([AiTextCall::FAILED, 'not_started', 0], [$now->status, $now->fail_reason, $now->attempts]);
        $this->assertSame([[$call->id, 'not_started']], FakeTextHandler::$failed);
        $this->assertSame('0.0000', $this->credits()->held($this->organization));

        // A job that turns up after all finds the call ended.
        $this->fakeModel([$this->answer()]);
        $this->runCall($call);
        Http::assertNothingSent();
    }

    public function test_the_round_that_tends_the_reads_tends_the_calls_of_every_company(): void
    {
        $other = $this->tenant('Konkan Infra');
        $lost = $this->claimed(TextCallRunner::LOST_SECONDS + 5);
        $theirs = $this->start($this->record($other), $this->member($other, 'owner'));
        $fine = $this->start($this->record());
        AiTextCall::withoutGlobalScopes()->whereKey($theirs->id)->update(['updated_at' => now()->subSeconds(TextCallRunner::QUEUED_AGAIN_SECONDS + 5)]);

        $this->artisan('document-reads:tend')->assertSuccessful();

        $this->assertSame([AiTextCall::FAILED, 'worker_lost'], [$lost->fresh()->status, $lost->fresh()->fail_reason]);
        $this->assertSame(AiTextCall::QUEUED, $theirs->fresh()->status);
        $this->assertSame(AiTextCall::QUEUED, $fine->fresh()->status);
        $this->assertSame([$lost->id, $theirs->id, $fine->id, $theirs->id], $this->queuedCalls());
        $this->assertNotNull(ReadTending::lastRun());
    }

    public function test_a_status_request_looks_after_the_call_it_asks_about(): void
    {
        $call = $this->claimed(TextCallRunner::LOST_SECONDS + 5);
        $other = $this->claimed(TextCallRunner::LOST_SECONDS + 5);

        $now = $this->calls()->watch($call);

        $this->assertSame([$call->id, AiTextCall::FAILED, 'worker_lost'], [$now->id, $now->status, $now->fail_reason]);
        // Only that one: the others wait for the scheduled round.
        $this->assertSame(AiTextCall::RUNNING, $other->fresh()->status);

        // A call that has ended comes back as it is.
        $this->assertSame(AiTextCall::FAILED, $this->calls()->watch($now)->status);
    }

    public function test_how_long_the_oldest_call_has_waited_can_be_seen(): void
    {
        $this->assertNull(TextCallRunner::oldestQueuedSince());

        Carbon::setTestNow('2027-01-20 11:00:00');
        $first = $this->start($this->record());
        Carbon::setTestNow('2027-01-20 11:04:00');
        $this->start($this->record());

        $this->assertSame('2027-01-20 11:00:00', TextCallRunner::oldestQueuedSince()->toDateTimeString());

        AiTextCall::withoutGlobalScopes()->whereKey($first->id)->update(['status' => AiTextCall::DONE]);
        $this->assertSame('2027-01-20 11:04:00', TextCallRunner::oldestQueuedSince()->toDateTimeString());
    }

    public function test_every_way_a_call_can_end_without_an_answer_has_a_plain_sentence_in_three_languages(): void
    {
        $reasons = [
            'rejected', 'invalid_output', 'cut_off', 'declined', 'timeout', 'unavailable', 'bad_request', 'internal_error', 'subject_gone',
            'worker_lost', 'not_started', 'starter_gone', 'consent_off', 'plan', 'not_connected', 'not_released', 'platform_ceiling', 'company_limit',
        ];

        foreach ($reasons as $reason) {
            $english = TextCallWords::failure($reason, 'en');

            $this->assertNotSame('', $english, $reason);
            $this->assertSame([], BannedWording::found($english), $english);

            foreach (['hi', 'mr'] as $locale) {
                $this->assertNotSame($english, TextCallWords::failure($reason, $locale), "{$reason} in {$locale}");
            }

            // A company is never told which of the provider's reasons it was.
            $this->assertDoesNotMatchRegularExpression('/key|billing|spend/i', $english, $reason);
        }

        $this->assertSame('', TextCallWords::failure(null));
        $this->assertSame('What the AI wrote did not pass the app\'s checks, so it was not used. Nothing was charged.', TextCallWords::failure('rejected', 'en'));
    }
}
