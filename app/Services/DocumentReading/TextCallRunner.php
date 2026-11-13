<?php

namespace App\Services\DocumentReading;

use App\Jobs\DocumentReading\RunTextCall;
use App\Models\AiTextCall;
use App\Models\Organization;
use App\Models\User;
use App\Services\Agents\CostMeter;
use App\Services\Agents\CreditService;
use App\Services\Agents\ModelGateway;
use App\Services\Agents\ModelGatewayException;
use App\Services\DocumentReading\Contracts\TextCallHandler;
use App\Services\Notify\Audience;
use App\Support\Dec;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one text call to the model and records how it ended. It is the only code that writes a
 * call's outcome or charges for it.
 *
 * A call is taken by one worker at a time: the claim marks it with a fresh id, and everything
 * written afterwards is written only where that id still stands. A job delivered twice, or a worker
 * that comes back late, therefore changes nothing and charges nothing.
 *
 * What a company pays: a call whose answer was accepted and handed to the feature, at its metered
 * cost, never more than the call's maximum or the wallet has. A call that fails, or whose answer the
 * feature's own checks refuse, is not charged. A refused answer is asked for once more; when that
 * one is refused too the call ends as rejected and the feature falls back on its own code.
 */
class TextCallRunner
{
    /** An attempt the provider was too busy for is made again while fewer than this many were made. */
    public const MOST_TRIES = 3;

    /** A call still "running" this long after it was claimed has lost its worker: the longest call is 90 seconds and the job is stopped at 110. */
    public const LOST_SECONDS = 150;

    /** A call that has sat "queued" this long is handed to the queue again, in case its job was lost, and again each time that long has passed. A second job does no harm. */
    public const QUEUED_AGAIN_SECONDS = 120;

    /** A call nobody has picked up this long after it was made is given up, so it stops holding credits. */
    public const GIVE_UP_MINUTES = 15;

    /** The handler's own checks refused the answer and the corrective answer. The call's status is "rejected" as well. */
    public const REJECTED = 'rejected';

    public const WORKER_LOST = 'worker_lost';

    public const NOT_STARTED = 'not_started';

    /** The person the call was made for is gone, inactive, or may no longer use the AI features or see the project. */
    public const STARTER_GONE = 'starter_gone';

    /** The purpose needs the company's switch, and it is off or was given to an older notice. */
    public const CONSENT_OFF = 'consent_off';

    public const PLAN = 'plan';

    /** No key is saved, or the provider is refusing every call for now. Which of the two is never said to a company. */
    public const NOT_CONNECTED = 'not_connected';

    public const NOT_RELEASED = 'not_released';

    public const PLATFORM_CEILING = 'platform_ceiling';

    public const COMPANY_LIMIT = 'company_limit';

    /** Seconds to wait after "too many requests" when the provider names no usable wait of its own. */
    private const RATE_LIMIT_WAIT = 20;

    /** A wait the provider asks for is followed up to this many seconds: somebody is waiting for the answer. */
    private const LONGEST_TRUSTED_WAIT = 30;

    private const UNAVAILABLE_WAIT = 10;

    /** A call that failed after waiting this long has probably been worked on, so its cost is estimated. */
    private const ESTIMATE_AFTER_MS = 10000;

    private const REJECTION_DAYS = 14;

    private const MOST_TENDED = 200;

    public function __construct(
        private TextCall $call,
        private TextCallHandlers $handlers,
        private CreditService $credits,
        private CostMeter $meter,
        private AiSpend $spend,
        private ModelGateway $gateway,
        private ReadQueues $queues,
        private ProviderBlockNotice $blockNotice,
    ) {}

    public function run(int $callId): void
    {
        $claim = $this->claim($callId);

        if ($claim === null) {
            return;
        }

        [$call, $handler, $starter] = $claim;

        // From here on nothing may leave the call claimed: whatever goes wrong in our own code ends it as failed.
        try {
            $outcome = $this->call->run($handler, $call);
        } catch (NothingToSend $nothing) {
            $outcome = TextOutcome::notSent(self::reasonCode($nothing->reason));
        } catch (Throwable $exception) {
            report($exception);
            $outcome = TextOutcome::notSent();
        }

        try {
            $this->settle($call, $handler, $starter, $outcome);
        } catch (NothingToSend $nothing) {
            // The handler no longer wants the answer: the record went, or changed, while the model was writing.
            $this->settle($call, $handler, $starter, TextOutcome::unkept($outcome, self::reasonCode($nothing->reason)));
        } catch (Throwable $exception) {
            report($exception);
            // Keeping the answer failed, in the handler's own step for instance. The call was paid for all the same.
            $this->settle($call, $handler, $starter, TextOutcome::unkept($outcome));
        }
    }

    /**
     * Hands a call's job to the interactive queue, once the surrounding work has committed. The row
     * must already be "queued".
     */
    public function dispatch(int $callId, int $delaySeconds = 0): void
    {
        $job = RunTextCall::dispatch($callId)->afterCommit();

        if (($queue = $this->queues->name(ReadQueues::INTERACTIVE)) !== null) {
            $job->onQueue($queue);
        }

        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }
    }

    /**
     * Looks after one call that nothing else is moving: a call whose worker died is ended as failed,
     * a call whose job was lost is handed to the queue again, and a call nobody picked up is given up.
     * Cheap when nothing is due, so a feature's status request can call it each time.
     *
     * @return ?AiTextCall The call as it is now.
     */
    public function tend(AiTextCall|int $call): ?AiTextCall
    {
        $id = $call instanceof AiTextCall ? (int) $call->id : $call;
        $seen = AiTextCall::withoutGlobalScopes()->find($id);

        if ($seen === null || ! $seen->isOpen()) {
            return $seen;
        }

        if ($seen->status === AiTextCall::RUNNING) {
            if ($this->lost($seen)) {
                // Written only where the lost claim still stands, so a worker that was merely slow and a second round cannot both act.
                $this->closeWhere($seen, fn (AiTextCall $now) => $now->status === AiTextCall::RUNNING && $now->attempt_uuid === $seen->attempt_uuid && $this->lost($now), self::WORKER_LOST);
            }
        } elseif ($seen->created_at !== null && $seen->created_at->lt(now()->subMinutes(self::GIVE_UP_MINUTES))) {
            $this->closeWhere($seen, fn (AiTextCall $now) => $now->status === AiTextCall::QUEUED, self::NOT_STARTED);
        } elseif ($seen->updated_at !== null && $seen->updated_at->lt($cutoff = now()->subSeconds(self::QUEUED_AGAIN_SECONDS))) {
            // Touching the row is the claim: a second round that comes at the same time changes nothing.
            $changed = AiTextCall::withoutGlobalScopes()->whereKey($seen->id)->where('organization_id', $seen->organization_id)
                ->where('status', AiTextCall::QUEUED)->where('updated_at', '<', $cutoff)
                ->update(['updated_at' => now()]);

            if ($changed === 1) {
                $this->dispatch((int) $seen->id);
            }
        }

        return AiTextCall::withoutGlobalScopes()->find($id);
    }

    /**
     * One round over every company's calls that are due. One call that fails is reported and does
     * not stop the others.
     *
     * @return int How many calls were looked after.
     */
    public function tendAll(): int
    {
        $due = AiTextCall::withoutGlobalScopes()
            ->where(fn ($query) => $query
                ->where(fn ($running) => $running->where('status', AiTextCall::RUNNING)
                    ->where(fn ($claim) => $claim->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subSeconds(self::LOST_SECONDS))))
                ->orWhere(fn ($queued) => $queued->where('status', AiTextCall::QUEUED)->where('updated_at', '<', now()->subSeconds(self::QUEUED_AGAIN_SECONDS))))
            ->orderBy('id')->limit(self::MOST_TENDED)->pluck('id');

        $tended = 0;

        foreach ($due as $id) {
            try {
                $this->tend((int) $id);
                $tended++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $tended;
    }

    /** Since when the longest-waiting call has been queued. A large age means no worker is listening. */
    public static function oldestQueuedSince(): ?Carbon
    {
        $at = AiTextCall::withoutGlobalScopes()->where('status', AiTextCall::QUEUED)->min('created_at');

        return $at === null ? null : Carbon::parse($at, config('app.timezone'));
    }

    /**
     * The provider's own words about a request it would not take, for the platform admin. They are
     * kept apart from the company's rows, so they never reach a company's screen or export.
     */
    public static function rejectionNote(int $callId): ?string
    {
        $note = Cache::get("ai.text_rejected:{$callId}");

        return is_string($note) ? $note : null;
    }

    /**
     * Takes the call for this worker, or returns null when it is not this worker's to take. A call
     * that may not be sent any more ends as failed here, with the reason, and nothing is sent.
     *
     * @return array{0: AiTextCall, 1: TextCallHandler, 2: User}|null
     */
    private function claim(int $callId): ?array
    {
        // A first look without a lock: most jobs that have nothing to do find that out here.
        $seen = AiTextCall::withoutGlobalScopes()->find($callId);

        if ($seen === null || $seen->status !== AiTextCall::QUEUED) {
            return null;
        }

        return DB::transaction(function () use ($seen) {
            $call = AiTextCall::withoutGlobalScopes()->where('organization_id', $seen->organization_id)->lockForUpdate()->find($seen->id);

            if ($call === null || $call->status !== AiTextCall::QUEUED) {
                return null;
            }

            $organization = Organization::find($call->organization_id);
            $handler = $this->handlers->find($call->purpose, $call->subject_type);
            $starter = $call->user_id === null ? null : User::find($call->user_id);
            $reason = $this->reasonNotToSend($call, $organization, $handler, $starter);

            if ($reason !== null) {
                $this->close($call, $reason, $handler);

                return null;
            }

            $call->forceFill([
                'status' => AiTextCall::RUNNING,
                'attempt_uuid' => (string) Str::uuid(),
                'attempts' => (int) $call->attempts + 1,
                'claimed_at' => now(),
            ])->save();

            return [$call, $handler, $starter];
        });
    }

    /** Why this call may not be sent now, as a fail reason, or null when it may. */
    private function reasonNotToSend(AiTextCall $call, ?Organization $organization, ?TextCallHandler $handler, ?User $starter): ?string
    {
        if ($organization === null || $handler === null) {
            return TextOutcome::INTERNAL_ERROR;
        }

        // A call goes out in the name of the person who asked for it, so only while that person could still ask for it today.
        if ($starter === null || ! $starter->is_active || $starter->anonymised_at !== null
            || (int) $starter->organization_id !== (int) $call->organization_id
            || ! $starter->hasPermission('agents.use') || ! Audience::seesProject($starter, $call->project_id)) {
            return self::STARTER_GONE;
        }

        if (AiDocumentConsent::covers((string) $call->purpose) && ! AiDocumentConsent::current($organization)) {
            return self::CONSENT_OFF;
        }

        if (! $organization->allowsFeature('ai_agents')) {
            return self::PLAN;
        }

        if (! $this->gateway->isConnected() || ProviderBlock::current() !== null) {
            return self::NOT_CONNECTED;
        }

        if (! $this->spend->released()) {
            return self::NOT_RELEASED;
        }

        if ($this->spend->platformStop() !== null) {
            return self::PLATFORM_CEILING;
        }

        $stop = $this->spend->companyStop($organization, true);

        // The trial's count of text calls was checked when this call was made, and this call is already part of it.
        return $stop === null || $stop === 'trial_text_calls' ? null : self::COMPANY_LIMIT;
    }

    /**
     * Writes the outcome, charges, and hands the answer to the handler, all in one transaction and
     * only where this worker's claim still stands.
     *
     * @return bool False when the claim no longer stood: nothing was written.
     */
    private function settle(AiTextCall $call, TextCallHandler $handler, User $starter, TextOutcome $outcome): bool
    {
        $plan = $this->plan($call, $outcome);
        $organization = Organization::findOrFail($call->organization_id);

        $written = DB::transaction(function () use ($call, $handler, $starter, $outcome, $plan, $organization) {
            // Locks are always taken in one order: the wallet (only a charge needs it), then the call.
            if ($plan['step'] === 'done') {
                $this->credits->wallet($organization);
            }

            $changed = AiTextCall::withoutGlobalScopes()
                ->whereKey($call->id)
                ->where('organization_id', $call->organization_id)
                ->where('attempt_uuid', $call->attempt_uuid)
                ->where('status', AiTextCall::RUNNING)
                ->update($plan['row']);

            if ($changed !== 1) {
                return false;
            }

            $row = AiTextCall::withoutGlobalScopes()->findOrFail($call->id);

            if ($plan['step'] === 'done') {
                if (Dec::of($plan['metered'])->isPositive()) {
                    $this->credits->chargeCall($row, $plan['metered']);
                }

                $handler->done($row, (array) $outcome->output);
            } elseif ($plan['step'] === 'failed' || $plan['step'] === 'rejected') {
                $this->tellFailed($handler, $row, (string) $plan['reason']);
            }

            // One audit line for every time a request left the server: that it was sent, never what it said.
            if ($outcome->sent) {
                ReadAudit::textSent($row, $plan['audit'], $starter);
            }

            if ($plan['step'] === 'corrective' || $plan['step'] === 'later') {
                $this->dispatch((int) $row->id, $plan['delay']);
            }

            return true;
        });

        if (! $written) {
            return false;
        }

        if ($outcome->result === TextOutcome::BLOCKED) {
            $this->blockNotice->raise((string) $outcome->reason);
        }

        if ($outcome->providerMessage !== null) {
            Cache::put("ai.text_rejected:{$call->id}", $outcome->providerMessage, now()->addDays(self::REJECTION_DAYS));
            Log::warning('The AI provider would not take a text call', ['call' => $call->id, 'detail' => $outcome->detail, 'request_id' => $outcome->requestId]);
        }

        return true;
    }

    /**
     * Decides what becomes of the call, from the outcome and from what happened to it before.
     *
     * step: 'done', 'corrective' (the answer was refused: queued once more with what was wrong),
     * 'rejected' (refused again), 'later' (the provider was busy: queued again after a wait) or 'failed'.
     *
     * @return array{step:string, reason:?string, row:array<string,mixed>, usd:string, metered:string, delay:int, audit:string}
     */
    private function plan(AiTextCall $call, TextOutcome $outcome): array
    {
        [$step, $reason] = match ($outcome->result) {
            TextOutcome::DONE => ['done', null],
            TextOutcome::REFUSED => [$call->isCorrective() ? 'rejected' : 'corrective', self::REJECTED],
            TextOutcome::AGAIN => (int) $call->attempts < self::MOST_TRIES ? ['later', $outcome->reason] : ['failed', TextOutcome::UNAVAILABLE],
            // Which of the provider's reasons it was is the platform admin's business, not the company's.
            TextOutcome::BLOCKED => ['failed', self::NOT_CONNECTED],
            default => ['failed', $outcome->reason ?? TextOutcome::INTERNAL_ERROR],
        };

        [$usd, $estimated] = $this->cost($call, $outcome);

        // Cost and tokens add up over the attempts of a call: the first answer was paid for even when the second one is used.
        $row = [
            'cost_usd' => (string) Dec::of($call->cost_usd)->plus(Dec::of($usd))->toScale(6, RoundingMode::HalfUp),
            'cost_estimated' => $estimated || $call->cost_estimated,
            'latency_ms' => max(0, $outcome->latencyMs),
            ...array_filter([
                'stop_reason' => $outcome->stopReason === null ? null : Str::limit($outcome->stopReason, 30, ''),
                'provider_message_id' => $outcome->messageId === null ? null : Str::limit($outcome->messageId, 250, ''),
                'provider_request_id' => $outcome->requestId === null ? null : Str::limit($outcome->requestId, 250, ''),
                'input_chars' => $outcome->messageChars > 0 ? $outcome->messageChars : null,
            ], fn ($value) => $value !== null),
        ];

        if ($outcome->usage !== null) {
            $row += [
                'input_tokens' => (int) $call->input_tokens + $outcome->usage['input'],
                'output_tokens' => (int) $call->output_tokens + $outcome->usage['output'],
                'cache_read_tokens' => (int) $call->cache_read_tokens + $outcome->usage['cache_read'],
                'cache_write_tokens' => (int) $call->cache_write_tokens + $outcome->usage['cache_write'],
            ];
        }

        $delay = 0;

        if ($step === 'later') {
            $trusted = $outcome->retryAfter !== null && $outcome->retryAfter <= self::LONGEST_TRUSTED_WAIT;
            $delay = $reason === ModelGatewayException::RATE_LIMITED
                ? ($trusted ? max(1, (int) $outcome->retryAfter) : self::RATE_LIMIT_WAIT)
                : self::UNAVAILABLE_WAIT;
        }

        $row += match ($step) {
            'done' => ['status' => AiTextCall::DONE, 'fail_reason' => null],
            'corrective' => ['status' => AiTextCall::QUEUED, 'fail_reason' => null, 'claimed_at' => null, 'correction' => json_encode($outcome->refusals, JSON_THROW_ON_ERROR)],
            'rejected' => ['status' => AiTextCall::REJECTED, 'fail_reason' => self::REJECTED],
            'later' => ['status' => AiTextCall::QUEUED, 'claimed_at' => null],
            default => ['status' => AiTextCall::FAILED, 'fail_reason' => $reason],
        };

        return [
            'step' => $step,
            'reason' => $reason,
            'row' => $row,
            'usd' => $usd,
            'metered' => Dec::of($usd)->isPositive() ? $this->meter->credits($usd) : '0.0000',
            'delay' => $delay,
            'audit' => match ($step) {
                'done' => 'done',
                'corrective' => 'asked_again',
                'rejected' => 'rejected',
                'later' => "tried_again_later:{$reason}",
                default => "failed:{$reason}",
            },
        ];
    }

    /**
     * What the attempt cost the platform, in US dollars. A call that never answered reports nothing,
     * so a timeout, or an outage that came after a long wait, is priced as the most it could have cost.
     *
     * @return array{0: string, 1: bool} The cost, and whether it is an estimate.
     */
    private function cost(AiTextCall $call, TextOutcome $outcome): array
    {
        $model = (string) $call->model;

        if ($outcome->usage !== null) {
            return [$this->meter->usd($model, $outcome->usage), false];
        }

        $slow = $outcome->result === TextOutcome::AGAIN && $outcome->reason === ModelGatewayException::UNAVAILABLE && $outcome->latencyMs >= self::ESTIMATE_AFTER_MS;

        if (! $outcome->sent || ! ($outcome->reason === TextOutcome::TIMEOUT || $slow)) {
            return ['0.000000', false];
        }

        $usage = ['input' => TextRequest::tokens($outcome->allChars), 'output' => (int) TextRequest::figures((string) $call->purpose)['max_tokens'], 'cache_read' => 0, 'cache_write' => 0];

        return [$this->meter->usd($model, $usage), true];
    }

    private function lost(AiTextCall $call): bool
    {
        return $call->claimed_at === null || $call->claimed_at->lt(now()->subSeconds(self::LOST_SECONDS));
    }

    /**
     * Ends a call as failed when, with its row locked, it is still in the state it was seen in.
     *
     * @param  callable(AiTextCall): bool  $still
     */
    private function closeWhere(AiTextCall $seen, callable $still, string $reason): void
    {
        DB::transaction(function () use ($seen, $still, $reason) {
            $locked = AiTextCall::withoutGlobalScopes()->where('organization_id', $seen->organization_id)->lockForUpdate()->find($seen->id);

            if ($locked !== null && $still($locked)) {
                $this->close($locked, $reason, $this->handlers->find($locked->purpose, $locked->subject_type));
            }
        });
    }

    /** Ends a call that was not sent, or whose worker is gone, and lets its feature put its fallback in place. The row is already locked. */
    private function close(AiTextCall $locked, string $reason, ?TextCallHandler $handler): void
    {
        $locked->forceFill(['status' => AiTextCall::FAILED, 'fail_reason' => $reason])->save();

        $this->tellFailed($handler, $locked, $reason);
    }

    /**
     * The feature's own step for a call that ended without an answer. It runs in a transaction of
     * its own inside the one that ends the call: if it throws, its own writes are undone and the
     * error is reported, but the call still ends and stops holding credits.
     */
    private function tellFailed(?TextCallHandler $handler, AiTextCall $call, string $reason): void
    {
        if ($handler === null) {
            return;
        }

        try {
            DB::transaction(fn () => $handler->failed($call, $reason));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** A reason a handler gave, held to what the column takes: a short code. */
    private static function reasonCode(string $reason): string
    {
        return preg_match('/^[a-z][a-z0-9_]{0,23}$/', $reason) === 1 ? $reason : NothingToSend::SUBJECT_GONE;
    }
}
