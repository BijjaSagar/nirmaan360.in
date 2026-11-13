<?php

namespace App\Services\DocumentReading;

use App\Jobs\DocumentReading\ReadTrialPage;
use App\Models\AiTextCall;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\ReaderTrial;
use App\Models\ReaderTrialPage;
use App\Models\User;
use App\Services\Agents\CostMeter;
use App\Services\Agents\ModelGateway;
use App\Services\Agents\ModelGatewayException;
use App\Services\DocumentReading\Contracts\TriesPastedText;
use App\Support\Dec;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Sends one page of the platform admin's dry run to the model and records what came back.
 *
 * It goes through the very steps a company's page or text call goes through (PageCall, TextCall:
 * the kind's or the handler's request, the single attempt, the shape check, the kind's or the
 * handler's own look at the answer) and stops there. Nothing is staged, nobody is charged, and no
 * row of any company is read or written: a dry run has no company. What it costs counts toward the
 * dry runs' own daily allowance and toward the month's total.
 *
 * A page is taken by one worker at a time, as a company's page is: the claim marks it with a fresh
 * id, and everything written afterwards is written only where that id still stands.
 */
class TrialRunner
{
    public const DISPATCHED = 'dispatched';

    public const FINISHED = 'finished';

    public const IN_FLIGHT = 'in_flight';

    public const IDLE = 'idle';

    /** A page still "running" this long after it was claimed has lost its worker: the longest call is 240 seconds and the job is stopped at 270. */
    public const LOST_SECONDS = 330;

    /** A page that has sat "queued" this long is handed to the queue once more, in case its job was lost. */
    public const QUEUED_AGAIN_SECONDS = 300;

    /** The handler's own checks refused the answer, and the answer to the one corrective request as well. */
    public const REJECTED = 'rejected';

    /** The provider would not take the request as it is. */
    public const BAD_REQUEST = 'bad_request';

    public const NOTHING_TO_SEND = 'nothing_to_send';

    /** Seconds to wait before a call is sent again after the provider was busy or down. After these it is given up. */
    private const AGAIN_DELAYS = [30, 60];

    /** A wait the provider asks for is followed up to this many seconds. */
    private const LONGEST_TRUSTED_WAIT = 120;

    /** A call that failed after waiting this long has probably been worked on, so its cost is estimated. */
    private const ESTIMATE_AFTER_MS = 10000;

    private const MOST_ATTEMPTS = 50;

    private const MOST_TENDED = 100;

    private const REJECTION_DAYS = 14;

    public function __construct(
        private PageCall $pages,
        private TextCall $texts,
        private ReadKinds $kinds,
        private TextCallHandlers $handlers,
        private CostMeter $meter,
        private AiSpend $spend,
        private ReadQuote $quote,
        private ReadQueues $queues,
        private ModelGateway $gateway,
        private ProviderBlockNotice $blockNotice,
    ) {}

    public function run(int $pageId): void
    {
        $claim = $this->claim($pageId);

        if ($claim === null) {
            return;
        }

        [$page, $trial] = $claim;

        // From here on nothing may leave the page claimed: whatever goes wrong in our own code fails it.
        try {
            $outcome = $this->attempt($trial, $page);
        } catch (NothingToSend) {
            $outcome = $this->notSent(self::NOTHING_TO_SEND);
        } catch (Throwable $exception) {
            report($exception);
            // A request that may not be sent says why in words about the request, never about the document.
            $outcome = $this->notSent(PageOutcome::INTERNAL_ERROR, $exception instanceof LogicException ? $exception->getMessage() : null);
        }

        try {
            $written = $this->settle($trial, $page, $outcome);
        } catch (Throwable $exception) {
            report($exception);
            // Keeping the answer failed. The call was paid for all the same.
            $written = $this->settle($trial, $page, [...$outcome, 'result' => 'failed', 'reason' => PageOutcome::INTERNAL_ERROR, 'output' => null, 'refusals' => [], 'detail' => null]);
        }

        if ($written) {
            $this->advance((int) $trial->id);
        }
    }

    /**
     * Hands the next waiting page of a running trial to the queue, one at a time, or ends the trial
     * when none is left. Safe to call at any time.
     */
    public function advance(ReaderTrial|int $trial): string
    {
        $id = $trial instanceof ReaderTrial ? (int) $trial->id : $trial;

        return DB::transaction(function () use ($id) {
            $trial = ReaderTrial::query()->lockForUpdate()->find($id);

            if ($trial === null || $trial->status !== ReaderTrial::RUNNING || $trial->isPurged()) {
                return self::IDLE;
            }

            if ($this->pagesOf($trial)->whereIn('status', [ReaderTrialPage::QUEUED, ReaderTrialPage::RUNNING])->exists()) {
                return self::IN_FLIGHT;
            }

            // A following pass is made after the pass before it, so the order of the rows is the order of reading.
            $next = $this->pagesOf($trial)->where('status', ReaderTrialPage::WAITING)->orderBy('page_no')->orderBy('id')->first();

            if ($next === null) {
                $trial->forceFill(['status' => ReaderTrial::DONE, 'pause_reason' => null, 'finished_at' => now()])->save();

                return self::FINISHED;
            }

            $next->forceFill(['status' => ReaderTrialPage::QUEUED, 'not_before' => null])->save();
            $this->dispatch($trial, (int) $next->id);

            return self::DISPATCHED;
        });
    }

    /**
     * Why nothing may be sent for a dry run right now, as a pause reason, or null when a page may go.
     * The same question is asked when the admin presses Start and again before every page.
     */
    public function reasonNotToSend(): ?string
    {
        if (! $this->gateway->isConnected() || ProviderBlock::current() !== null) {
            return ReaderTrial::PAUSE_NOT_CONNECTED;
        }

        // The dry runs' own daily allowance, then the month's limit for the readers. The companies' daily limit is not theirs to use.
        return match ($this->spend->trialStop()) {
            'trial_allowance' => ReaderTrial::PAUSE_ALLOWANCE,
            'monthly' => ReaderTrial::PAUSE_MONTH,
            default => null,
        };
    }

    /**
     * Looks after one trial that nothing else is moving: a page whose worker died fails as
     * worker_lost, and for a running trial a page whose job was lost is handed to the queue again
     * and the next page is sent or the trial ended. Cheap when nothing is due, so the status request
     * calls it each time.
     */
    public function tend(ReaderTrial|int $trial): void
    {
        $trial = ReaderTrial::query()->find($trial instanceof ReaderTrial ? $trial->id : $trial);

        if ($trial === null) {
            return;
        }

        $lost = $this->lostPages()->where('reader_trial_id', $trial->id)->get();

        foreach ($lost as $page) {
            // Written only where the lost claim still stands, so a worker that was merely slow is not overwritten.
            $this->pagesOf($trial)->whereKey($page->id)->where('status', ReaderTrialPage::RUNNING)
                ->where(fn ($query) => $page->attempt_uuid === null ? $query->whereNull('attempt_uuid') : $query->where('attempt_uuid', $page->attempt_uuid))
                ->update(['status' => ReaderTrialPage::FAILED, 'fail_reason' => PageOutcome::WORKER_LOST, 'error_detail' => null, 'requeues' => 0, 'finished_at' => now(), 'updated_at' => now()]);
        }

        if ($trial->status !== ReaderTrial::RUNNING) {
            return;
        }

        $cutoff = now()->subSeconds(self::QUEUED_AGAIN_SECONDS);

        $stale = $this->pagesOf($trial)->where('status', ReaderTrialPage::QUEUED)->where('updated_at', '<', $cutoff)
            ->where(fn ($query) => $query->whereNull('not_before')->orWhere('not_before', '<=', now()))
            ->pluck('id');

        foreach ($stale as $pageId) {
            // Touching the row is the claim: a second round that comes at the same time changes nothing.
            $changed = $this->pagesOf($trial)->whereKey($pageId)->where('status', ReaderTrialPage::QUEUED)->where('updated_at', '<', $cutoff)
                ->update(['updated_at' => now()]);

            if ($changed === 1) {
                $this->dispatch($trial, (int) $pageId);
            }
        }

        $this->advance($trial);
    }

    /**
     * One round over every running trial, and over any trial that still has a page whose worker
     * died. One that fails is reported and does not stop the others.
     */
    public function tendAll(): int
    {
        $tended = 0;

        $due = ReaderTrial::query()->where('status', ReaderTrial::RUNNING)->orderBy('id')->limit(self::MOST_TENDED)->pluck('id')
            ->merge($this->lostPages()->orderBy('id')->limit(self::MOST_TENDED)->pluck('reader_trial_id'))
            ->map(fn ($id) => (int) $id)->unique()->values();

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

    /** Since when the longest-waiting dry-run page has been queued. A large age means no worker is listening. */
    public static function oldestQueuedSince(): ?Carbon
    {
        $at = ReaderTrialPage::query()->where('status', ReaderTrialPage::QUEUED)
            ->where(fn ($query) => $query->whereNull('not_before')->orWhere('not_before', '<=', now()))
            ->min('updated_at');

        return $at === null ? null : Carbon::parse($at, config('app.timezone'));
    }

    /** The provider's own words about a request it would not take, kept for a while for the admin's result page. */
    public static function rejectionNote(int $pageId): ?string
    {
        $note = Cache::get("ai.trial_rejected:{$pageId}");

        return is_string($note) ? $note : null;
    }

    /**
     * The page a kind is handed in a dry run: never saved, with a read that was never saved either.
     * It is how the kind is asked for its request, and how it is asked about an answer afterwards.
     *
     * @param  ?array<string, mixed>  $result  An accepted answer, for the questions a kind answers from one.
     */
    public static function standIn(ReaderTrial $trial, ReaderTrialPage $page, ?array $result = null): DocumentReadPage
    {
        $read = new DocumentRead(['kind' => $trial->kind, 'pages_total' => (int) $trial->pages_total, 'options' => $trial->options]);

        $row = new DocumentReadPage([
            'page_no' => (int) $page->page_no,
            'pass' => $page->pass,
            'part' => 'full',
            'model' => $page->model,
            'image_width' => $page->image_width,
            'image_height' => $page->image_height,
            'image_bytes' => $page->image_bytes,
            'image_sha256' => $page->image_sha256,
            'text_chars' => $page->text_chars,
            'result' => $result,
        ]);

        return $row->setRelation('documentRead', $read);
    }

    /**
     * The model, the effort, the output limit and the time limit of one page. The trial's own pass
     * uses the figures the admin chose; a pass that follows it uses that pass's usual figures.
     *
     * @return array{model: string, effort: string, max_tokens: int, timeout: int}
     */
    public static function figures(ReaderTrial $trial, ReaderTrialPage $page): array
    {
        if ($trial->isTextCall() || $page->pass === $trial->pass) {
            return ['model' => (string) $trial->model, 'effort' => (string) $trial->effort, 'max_tokens' => (int) $trial->max_tokens, 'timeout' => (int) $trial->timeout];
        }

        $usual = ReaderRequest::figures((string) $trial->kind, (string) $page->pass);

        return ['model' => (string) $usual['model'], 'effort' => (string) $usual['effort'], 'max_tokens' => (int) $usual['max_tokens'], 'timeout' => (int) $usual['timeout']];
    }

    /**
     * Takes the page for this worker, or returns null when it is not this worker's to take. A
     * reason not to send pauses the trial and leaves the page waiting.
     *
     * @return array{0: ReaderTrialPage, 1: ReaderTrial}|null
     */
    private function claim(int $pageId): ?array
    {
        // A first look without a lock: most jobs that have nothing to do find that out here.
        $seen = ReaderTrialPage::query()->find($pageId);

        if ($seen === null || $seen->status !== ReaderTrialPage::QUEUED || $seen->not_before?->isFuture()) {
            return null;
        }

        return DB::transaction(function () use ($seen) {
            // The trial is locked before its page, here and everywhere else.
            $trial = ReaderTrial::query()->lockForUpdate()->find($seen->reader_trial_id);
            $page = $trial === null ? null : $this->pagesOf($trial)->lockForUpdate()->find($seen->id);

            if ($page === null || $page->status !== ReaderTrialPage::QUEUED || $page->not_before?->isFuture()) {
                return null;
            }

            if ($trial->status !== ReaderTrial::RUNNING || $trial->isPurged()) {
                $page->forceFill(['status' => $trial->isOpen() && ! $trial->isPurged() ? ReaderTrialPage::WAITING : ReaderTrialPage::SKIPPED, 'not_before' => null])->save();

                return null;
            }

            if (($reason = $this->reasonNotToSend()) !== null) {
                $this->pause($trial, $reason);

                return null;
            }

            $page->forceFill([
                'status' => ReaderTrialPage::RUNNING,
                'attempt_uuid' => (string) Str::uuid(),
                'attempts' => min(250, (int) $page->attempts + 1),
                'claimed_at' => now(),
                'not_before' => null,
            ])->save();

            return [$page, $trial];
        });
    }

    /**
     * The one call, through the shared steps.
     *
     * @return array<string, mixed> See normalise().
     */
    private function attempt(ReaderTrial $trial, ReaderTrialPage $page): array
    {
        if ((int) $page->attempts > self::MOST_ATTEMPTS) {
            throw new LogicException('This page has been tried too many times.');
        }

        $figures = self::figures($trial, $page);

        if ($trial->isTextCall()) {
            $handler = $this->handlers->find((string) $trial->purpose, $trial->subject_type);

            if (! $handler instanceof TriesPastedText) {
                throw new LogicException("The text call \"{$trial->purpose}\" cannot be tried on pasted text.");
            }

            // Never saved, and of no company: the handler's twin works from the pasted text alone.
            $call = new AiTextCall([
                'purpose' => $trial->purpose,
                'subject_type' => $trial->subject_type,
                'model' => $figures['model'],
                'attempts' => (int) $page->attempts,
                'correction' => $page->correction,
            ]);

            return $this->normalise($this->texts->run($handler->onPasted(TrialFiles::words($trial, $page)), $call, $figures));
        }

        $kind = $this->kinds->get((string) $trial->kind);
        $row = self::standIn($trial, $page);

        if (filled($page->image_path)) {
            return $this->normalise($this->pages->run($kind, $row, TrialFiles::pictureBytes($trial, $page), null, $figures));
        }

        return $this->normalise($this->pages->run($kind, $row, null, ReaderRequest::pages([(int) $page->page_no => TrialFiles::words($trial, $page)]), $figures));
    }

    /**
     * Writes the outcome and adds its tokens and cost to the trial, in one transaction and only where
     * this worker's claim still stands.
     *
     * @param  array<string, mixed>  $outcome
     * @return bool False when the claim no longer stood: nothing was written.
     */
    private function settle(ReaderTrial $trial, ReaderTrialPage $page, array $outcome): bool
    {
        $figures = self::figures($trial, $page);
        [$usd, $estimated] = $this->cost($trial, $page, $figures, $outcome);
        $requeues = (int) $page->requeues;
        $reason = $outcome['reason'];

        $step = match ($outcome['result']) {
            'read' => 'read',
            // A refused answer is asked for once more, with what was wrong. The second refusal is final.
            'refused' => (int) $page->refused >= 1 ? 'failed' : 'corrected',
            'blocked' => 'held',
            'again' => match (true) {
                $requeues < count(self::AGAIN_DELAYS) => 'again',
                $reason === ModelGatewayException::RATE_LIMITED => 'held',
                default => 'failed',
            },
            default => 'failed',
        };

        if ($outcome['result'] === 'refused') {
            $reason = self::REJECTED;
        } elseif ($outcome['result'] === 'again' && $step === 'failed') {
            $reason = PageOutcome::UNAVAILABLE;
        }

        // Asked before anything is written: if the kind fails here, the page fails as a whole.
        $following = $step === 'read' && ! $trial->isTextCall()
            ? array_values(array_intersect($this->kinds->get((string) $trial->kind)->nextPasses(self::standIn($trial, $page, $outcome['output'])), $this->kinds->get((string) $trial->kind)->passes()))
            : [];

        $delay = 0;

        if ($step === 'again') {
            $trusted = $outcome['retry_after'] !== null && $outcome['retry_after'] <= self::LONGEST_TRUSTED_WAIT;
            $delay = $trusted ? max(1, (int) $outcome['retry_after']) : self::AGAIN_DELAYS[$requeues];
        }

        $written = DB::transaction(function () use ($trial, $page, $figures, $outcome, $step, $reason, $usd, $estimated, $following, $delay, $requeues) {
            $locked = ReaderTrial::query()->lockForUpdate()->find($trial->id);

            if ($locked === null) {
                return false;
            }

            // Once the files are gone, or the admin deleted the trial, what comes back is counted and not kept.
            $keeps = ! $locked->isPurged() && $locked->status !== ReaderTrial::DISCARDED;

            $changes = [
                'model' => $figures['model'],
                'stop_reason' => $outcome['stop'] === null ? null : Str::limit($outcome['stop'], 30, ''),
                'provider_request_id' => $outcome['request'] === null ? null : Str::limit($outcome['request'], 250, ''),
                'latency_ms' => max(0, (int) $outcome['latency']),
                'cost_usd' => (string) Dec::of($page->cost_usd)->plus(Dec::of($usd))->toScale(6, RoundingMode::HalfUp),
                'cost_estimated' => $estimated || $page->cost_estimated,
                'updated_at' => now(),
            ];

            if ($outcome['usage'] !== null) {
                $changes += [
                    'input_tokens' => $outcome['usage']['input'], 'output_tokens' => $outcome['usage']['output'],
                    'cache_read_tokens' => $outcome['usage']['cache_read'], 'cache_write_tokens' => $outcome['usage']['cache_write'],
                ];
            }

            // Every attempt that cost something is dated, so the day's allowance and the month's total count it.
            if (in_array($step, ['read', 'failed'], true) || Dec::of($usd)->isPositive()) {
                $changes['finished_at'] = now();
            }

            $changes = [...$changes, ...match ($step) {
                'read' => [
                    'status' => ReaderTrialPage::READ, 'fail_reason' => null, 'error_detail' => null, 'requeues' => 0, 'marks' => null,
                    'result' => $keeps ? json_encode($outcome['output'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null,
                ],
                'corrected' => [
                    'status' => ReaderTrialPage::QUEUED, 'refused' => (int) $page->refused + 1, 'not_before' => null,
                    'correction' => json_encode($outcome['refusals'], JSON_THROW_ON_ERROR),
                ],
                'again' => ['status' => ReaderTrialPage::QUEUED, 'requeues' => $requeues + 1, 'not_before' => now()->addSeconds($delay)],
                'held' => ['status' => ReaderTrialPage::WAITING, 'requeues' => 0, 'not_before' => null],
                default => [
                    'status' => ReaderTrialPage::FAILED, 'fail_reason' => $reason, 'requeues' => 0, 'result' => null, 'marks' => null,
                    'refused' => (int) $page->refused + ($outcome['result'] === 'refused' ? 1 : 0),
                    'error_detail' => $outcome['detail'] === null ? null : Str::limit((string) $outcome['detail'], 290, ''),
                ],
            }];

            $changed = $this->pagesOf($locked)->whereKey($page->id)
                ->where('attempt_uuid', $page->attempt_uuid)
                ->where('status', ReaderTrialPage::RUNNING)
                ->update($changes);

            if ($changed !== 1) {
                return false;
            }

            $usage = $outcome['usage'] ?? ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0];

            $locked->forceFill([
                'input_tokens' => (int) $locked->input_tokens + $usage['input'],
                'output_tokens' => (int) $locked->output_tokens + $usage['output'],
                'cache_read_tokens' => (int) $locked->cache_read_tokens + $usage['cache_read'],
                'cache_write_tokens' => (int) $locked->cache_write_tokens + $usage['cache_write'],
                'cost_usd' => (string) Dec::of($locked->cost_usd)->plus(Dec::of($usd))->toScale(6, RoundingMode::HalfUp),
            ])->save();

            $row = ReaderTrialPage::query()->findOrFail($page->id);

            if ($keeps) {
                $this->addPasses($locked, $row, $following);
            }

            if ($step === 'held') {
                $this->pause($locked, $outcome['result'] === 'blocked' ? (string) $reason : ReaderTrial::PAUSE_PROVIDER_LIMIT);
            }

            if ($outcome['sent']) {
                $this->recordSent($locked, $row, $outcome, $step, $reason);
            }

            if ($step === 'read' && $keeps) {
                TrialScore::refresh($locked);
            }

            if ($step === 'again' || $step === 'corrected') {
                $this->dispatch($locked, (int) $row->id, $delay);
            }

            return true;
        });

        if (! $written) {
            return false;
        }

        if ($outcome['result'] === 'blocked') {
            $this->blockNotice->raise((string) $outcome['reason']);
        }

        if ($outcome['provider_message'] !== null) {
            Cache::put("ai.trial_rejected:{$page->id}", $outcome['provider_message'], now()->addDays(self::REJECTION_DAYS));
            Log::warning('The AI provider would not take a dry-run request', ['trial_page' => $page->id, 'detail' => $outcome['detail'], 'request_id' => $outcome['request']]);
        }

        return true;
    }

    /**
     * What the call cost the platform, in US dollars. A call that never answered reports nothing, so
     * a timeout, or an outage that came after a long wait, is priced as the most it could have cost.
     *
     * @param  array{model: string, effort: string, max_tokens: int, timeout: int}  $figures
     * @param  array<string, mixed>  $outcome
     * @return array{0: string, 1: bool} The cost, and whether it is an estimate.
     */
    private function cost(ReaderTrial $trial, ReaderTrialPage $page, array $figures, array $outcome): array
    {
        if ($outcome['usage'] !== null) {
            return [$this->meter->usd($figures['model'], $outcome['usage']), false];
        }

        $slow = $outcome['result'] === 'again' && $outcome['reason'] === ModelGatewayException::UNAVAILABLE && $outcome['latency'] >= self::ESTIMATE_AFTER_MS;

        if (! $outcome['sent'] || ! ($outcome['reason'] === PageOutcome::TIMEOUT || $slow)) {
            return ['0.000000', false];
        }

        $input = match (true) {
            $trial->isTextCall() => TextRequest::tokens((int) $outcome['chars']),
            $page->image_width && $page->image_height => ImageTokens::count((int) $page->image_width, (int) $page->image_height) + $this->quote->promptTokens((string) $trial->kind, (string) $page->pass),
            default => Dec::of((int) $page->text_chars)->multipliedBy(Dec::of(config('modules.document_reading.text_tokens_per_character')))->toScale(0, RoundingMode::Up)->toInt()
                + $this->quote->promptTokens((string) $trial->kind, (string) $page->pass),
        };

        return [$this->meter->usd($figures['model'], ['input' => $input, 'output' => $figures['max_tokens'], 'cache_read' => 0, 'cache_write' => 0]), true];
    }

    /**
     * A waiting row for each pass that follows on the same page, with the same picture or text and
     * the pass's usual model, as a company's read would get.
     *
     * @param  list<string>  $passes
     */
    private function addPasses(ReaderTrial $trial, ReaderTrialPage $page, array $passes): void
    {
        foreach ($passes as $pass) {
            if ($this->pagesOf($trial)->where('page_no', $page->page_no)->where('pass', $pass)->exists()) {
                continue;
            }

            ReaderTrialPage::query()->create([
                'reader_trial_id' => $trial->id,
                'page_no' => $page->page_no,
                'pass' => $pass,
                'model' => (string) ReaderRequest::figures((string) $trial->kind, $pass)['model'],
                ...$page->only(['image_path', 'image_sha256', 'image_width', 'image_height', 'image_bytes', 'text_path', 'text_chars']),
            ]);
        }
    }

    /** A running trial stops sending. Pages that were queued go back to waiting, so Start sends them later. */
    private function pause(ReaderTrial $trial, string $reason): void
    {
        if ($trial->status !== ReaderTrial::RUNNING) {
            return;
        }

        $trial->forceFill(['status' => ReaderTrial::PAUSED, 'pause_reason' => Str::limit($reason, 24, '')])->save();

        $this->pagesOf($trial)->where('status', ReaderTrialPage::QUEUED)
            ->update(['status' => ReaderTrialPage::WAITING, 'not_before' => null, 'updated_at' => now()]);
    }

    /**
     * One platform audit line for every time a dry-run page left the server: what was sent, never what it said.
     *
     * @param  array<string, mixed>  $outcome
     */
    private function recordSent(ReaderTrial $trial, ReaderTrialPage $page, array $outcome, string $step, ?string $reason): void
    {
        PlatformAudit::record('ai.trial_page_sent', $page, [
            'trial_id' => $trial->id,
            'kind' => self::code($trial->kind),
            'purpose' => self::code($trial->purpose),
            'pass' => self::code($page->pass),
            'page_no' => (int) $page->page_no,
            'attempt' => (int) $page->attempts,
            'model' => self::code($page->model),
            'bytes' => $page->image_bytes === null ? null : (int) $page->image_bytes,
            'chars' => $page->text_chars === null ? null : (int) $page->text_chars,
            'sha256' => $page->image_sha256,
            'width' => $page->image_width === null ? null : (int) $page->image_width,
            'height' => $page->image_height === null ? null : (int) $page->image_height,
            'outcome' => match ($step) {
                'read' => 'read',
                'failed' => 'failed',
                'corrected' => 'asked_again',
                'again' => 'tried_again_later',
                default => 'not_taken',
            },
            'reason' => self::code($reason),
            'provider_request_id' => self::code($outcome['request']),
        ], $trial->user_id === null ? null : User::query()->find($trial->user_id));
    }

    /** A value for an audit line: a code, or nothing. What does not look like a code is left out instead of stopping the work. */
    private static function code(mixed $value): ?string
    {
        try {
            return ReadAudit::code($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function dispatch(ReaderTrial $trial, int $pageId, int $delaySeconds = 0): void
    {
        $job = ReadTrialPage::dispatch($pageId)->afterCommit();

        // A dry run waits in the lane a company's call of the same kind would wait in, so it also shows whether that lane has a worker.
        $queue = $trial->isTextCall() ? $this->queues->name(ReadQueues::INTERACTIVE) : $this->queues->forKind((string) $trial->kind);

        if ($queue !== null) {
            $job->onQueue($queue);
        }

        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }
    }

    /**
     * A page's and a text call's outcome in one form.
     *
     * @return array{result: string, reason: ?string, output: ?array, usage: ?array, sent: bool, stop: ?string, request: ?string, latency: int, retry_after: ?int, detail: ?string, provider_message: ?string, refusals: array, chars: int}
     */
    private function normalise(PageOutcome|TextOutcome $outcome): array
    {
        $text = $outcome instanceof TextOutcome;

        return [
            'result' => match ($outcome->result) {
                PageOutcome::READ, TextOutcome::DONE => 'read',
                TextOutcome::REFUSED => 'refused',
                PageOutcome::AGAIN => 'again',
                PageOutcome::BLOCKED => 'blocked',
                default => 'failed',
            },
            // One word for a request the provider would not take, whichever kind of call it was.
            'reason' => $outcome->reason === PageOutcome::REJECTED ? self::BAD_REQUEST : $outcome->reason,
            'output' => $outcome->output,
            'usage' => $outcome->usage,
            'sent' => $outcome->sent,
            'stop' => $outcome->stopReason,
            'request' => $outcome->requestId,
            'latency' => $outcome->latencyMs,
            'retry_after' => $outcome->retryAfter,
            'detail' => $text && $outcome->result === TextOutcome::REFUSED ? json_encode($outcome->refusals) : $outcome->detail,
            'provider_message' => $outcome->providerMessage,
            'refusals' => $text ? $outcome->refusals : [],
            'chars' => $text ? $outcome->allChars : 0,
        ];
    }

    /** @return array<string, mixed> An outcome for a call that was never made. */
    private function notSent(string $reason, ?string $detail = null): array
    {
        return [
            'result' => 'failed', 'reason' => $reason, 'output' => null, 'usage' => null, 'sent' => false, 'stop' => null, 'request' => null,
            'latency' => 0, 'retry_after' => null, 'detail' => $detail, 'provider_message' => null, 'refusals' => [], 'chars' => 0,
        ];
    }

    private function pagesOf(ReaderTrial $trial)
    {
        return ReaderTrialPage::query()->where('reader_trial_id', $trial->id);
    }

    /** Pages marked as running whose claim is older than any call can last. */
    private function lostPages()
    {
        return ReaderTrialPage::query()->where('status', ReaderTrialPage::RUNNING)
            ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subSeconds(self::LOST_SECONDS)));
    }
}
