<?php

namespace App\Services\DocumentReading;

use App\Models\AuditLog;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Agents\CostMeter;
use App\Services\Agents\CreditService;
use App\Services\Agents\ModelGateway;
use App\Services\Agents\ModelGatewayException;
use App\Services\DocumentReading\Contracts\ReadKind;
use App\Services\Notify\NotificationMessage;
use App\Services\Notify\Notifier;
use App\Support\Dec;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Sends one page of a read to the reader and records what came back. It is the only code that
 * writes a page's result or charges for it.
 *
 * A page is taken by one worker at a time: the claim marks it with a fresh id, and everything
 * written afterwards is written only where that id still stands. A job delivered twice, or a worker
 * that comes back late, therefore changes nothing and charges nothing.
 *
 * What a company pays: a page that ends as read, at its metered cost, never more than the read
 * holds or the wallet has. A page that fails, or that the reader reports as blank, is not charged;
 * its cost counts against the read's limit instead.
 */
class PageReader
{
    /** Seconds to wait after the provider said "too many requests" without a usable wait of its own. */
    private const RATE_LIMIT_DELAYS = [30, 60, 120];

    /** A wait the provider asks for is followed up to this many seconds. */
    private const LONGEST_TRUSTED_WAIT = 120;

    /** Seconds to wait after the provider was down. After these the page fails. */
    private const UNAVAILABLE_DELAYS = [15, 45];

    /** A call that failed after waiting this long has probably been worked on, so its cost is estimated. */
    private const ESTIMATE_AFTER_MS = 10000;

    /** Far more than any page needs. The attempt counter is a small column. */
    private const MOST_ATTEMPTS = 200;

    private const REJECTION_DAYS = 14;

    public function __construct(
        private PageCall $call,
        private ReadKinds $kinds,
        private ReadLifecycle $lifecycle,
        private CreditService $credits,
        private CostMeter $meter,
        private AiSpend $spend,
        private ReadQuote $quote,
        private ModelGateway $gateway,
        private Notifier $notifier,
    ) {}

    public function run(int $pageId): void
    {
        $claim = $this->claim($pageId);

        if ($claim === null) {
            return;
        }

        [$page, $read, $kind, $starter] = $claim;
        $flags = null;

        // From here on nothing may leave the page claimed: whatever goes wrong in our own code fails it as internal_error.
        try {
            [$picture, $text, $flags] = $this->material($page, $read);
            $outcome = $this->call->run($kind, $page, $picture, $text);
            unset($picture, $text);
        } catch (Throwable $exception) {
            report($exception);
            $outcome = PageOutcome::internalError();
        }

        try {
            $settled = $this->settle($page, $read, $kind, $starter, $outcome, $flags);
        } catch (Throwable $exception) {
            report($exception);
            // Saving the answer failed, in the kind's own step for instance. The call was paid for all the same.
            $settled = $this->settle($page, $read, $kind, $starter, PageOutcome::internalError(after: $outcome), $flags);
        }

        if ($settled === null) {
            return;
        }

        $state = $this->lifecycle->advance($read->id);

        if ($settled['paused'] && $state !== ReadLifecycle::FINISHED) {
            $now = DocumentRead::withoutGlobalScopes()->find($read->id);

            if ($now?->status === DocumentRead::PAUSED) {
                $this->lifecycle->tellPaused($now);
            }
        }
    }

    /**
     * The provider's own words about a call it would not take, for the platform admin. They are
     * kept apart from the company's rows, so they never reach a company's screen or export.
     */
    public static function rejectionNote(int $pageId): ?string
    {
        $note = Cache::get("ai.page_rejected:{$pageId}");

        return is_string($note) ? $note : null;
    }

    /**
     * Takes the page for this worker, or returns null when it is not this worker's to take. Every
     * reason not to send pauses the read and leaves the page waiting.
     *
     * @return array{0: DocumentReadPage, 1: DocumentRead, 2: ReadKind, 3: User}|null
     */
    private function claim(int $pageId): ?array
    {
        // A first look without a lock: most jobs that have nothing to do find that out here.
        $seen = DocumentReadPage::withoutGlobalScopes()->find($pageId);

        if ($seen === null || $seen->status !== DocumentReadPage::QUEUED || $seen->not_before?->isFuture()) {
            return null;
        }

        return DB::transaction(function () use ($seen) {
            // The read is locked before its page, here and everywhere else, so two workers can never wait on each other.
            $read = DocumentRead::withoutGlobalScopes()->where('organization_id', $seen->organization_id)->lockForUpdate()->find($seen->document_read_id);
            $page = $read === null ? null : DocumentReadPage::withoutGlobalScopes()->where('document_read_id', $read->id)->lockForUpdate()->find($seen->id);

            if ($page === null || $page->status !== DocumentReadPage::QUEUED || $page->not_before?->isFuture()) {
                return null;
            }

            if ($read->status !== DocumentRead::RUNNING) {
                $this->setAside($page, $read->isClosed() ? DocumentReadPage::SKIPPED : DocumentReadPage::WAITING);

                return null;
            }

            $organization = Organization::find($read->organization_id);
            $kind = $this->kinds->find($read->kind);
            $starter = $read->user_id === null ? null : User::find($read->user_id);

            $reason = Dec::of($read->credits_held)->isPositive()
                ? $this->reasonNotToSend($read, $page, $organization, $kind, $starter)
                : DocumentRead::PAUSE_CREDIT_CAP;

            if ($reason !== null) {
                $this->lifecycle->pause($read, $reason);
                $this->setAside($page, DocumentReadPage::WAITING);

                return null;
            }

            $page->forceFill([
                'status' => DocumentReadPage::RUNNING,
                'attempt_uuid' => (string) Str::uuid(),
                'attempts' => (int) $page->attempts + 1,
                'claimed_at' => now(),
                'started_at' => now(),
                'not_before' => null,
            ])->save();

            $page->setRelation('documentRead', $read);

            return [$page, $read, $kind, $starter];
        });
    }

    /** Why this page may not be sent now, as a pause reason, or null when it may. */
    private function reasonNotToSend(DocumentRead $read, DocumentReadPage $page, ?Organization $organization, ?ReadKind $kind, ?User $starter): ?string
    {
        if ($organization === null || $kind === null) {
            return DocumentRead::PAUSE_INFRA;
        }

        // Pages go out in the starter's name, so only while that person could still start the read today.
        if ($starter === null || ! $starter->is_active || $starter->anonymised_at !== null
            || ! $starter->hasPermission('agents.use') || ! ReadAccess::holds($starter, $read, $kind)) {
            return DocumentRead::PAUSE_STARTER_GONE;
        }

        if (! AiDocumentConsent::current($organization) || $read->consent_version !== AiDocumentConsent::VERSION) {
            return DocumentRead::PAUSE_CONSENT_OFF;
        }

        if (! $organization->allowsFeature('ai_agents')) {
            return DocumentRead::PAUSE_PLAN;
        }

        if (! $this->gateway->isConnected() || ProviderBlock::current() !== null) {
            return DocumentRead::PAUSE_NOT_CONNECTED;
        }

        if (! $this->spend->released()) {
            return DocumentRead::PAUSE_NOT_RELEASED;
        }

        return $this->spend->pauseReason($organization, $page);
    }

    /**
     * What is sent: the stored picture, or the wrapped text of the run with the names of anything in
     * it that looks like an instruction.
     *
     * @return array{0: ?string, 1: ?string, 2: ?list<string>}
     */
    private function material(DocumentReadPage $page, DocumentRead $read): array
    {
        if ((int) $page->attempts > self::MOST_ATTEMPTS) {
            throw new LogicException('This page has been tried too many times.');
        }

        if (filled($page->image_path)) {
            return [ReadFiles::picture($read, $page), null, null];
        }

        $texts = ReadFiles::texts($read, (int) $page->page_no, (int) ($page->page_to ?? $page->page_no));

        return [null, ReaderRequest::pages($texts), ReaderRequest::flags($texts)];
    }

    /**
     * Writes the outcome, charges or burns, and updates the read, all in one transaction and only
     * where this worker's claim still stands.
     *
     * @param  ?list<string>  $flags
     * @return array{paused: bool}|null Null when the claim no longer stood: nothing was written.
     */
    private function settle(DocumentReadPage $page, DocumentRead $read, ReadKind $kind, User $starter, PageOutcome $outcome, ?array $flags): ?array
    {
        // The read as it is saved now. A first try at saving that was undone may have left this copy ahead of its row.
        $read->setRawAttributes(DocumentRead::withoutGlobalScopes()->findOrFail($read->id)->getAttributes(), true);

        $plan = $this->plan($page, $read, $kind, $outcome);
        $organization = Organization::findOrFail($read->organization_id);
        $wasRunning = $read->status === DocumentRead::RUNNING;

        $written = DB::transaction(function () use ($page, $read, $kind, $starter, $outcome, $flags, $plan, $organization) {
            $charged = $plan['step'] === 'read' && $plan['chargeable'];

            // Locks are always taken in one order: the wallet (only a charge needs it), then the read, then the page.
            if ($charged) {
                $this->credits->wallet($organization);
            }

            DocumentRead::withoutGlobalScopes()->lockForUpdate()->findOrFail($read->id);

            $before = Dec::of($page->cost_usd);
            $total = (string) $before->plus(Dec::of($plan['usd']))->toScale(6, RoundingMode::HalfUp);

            $changes = [
                ...$plan['page'],
                // The charge reads this attempt's own cost from the row. The running total is written right after it.
                'cost_usd' => $charged ? $plan['usd'] : $total,
                'cost_estimated' => $plan['estimated'] || $page->cost_estimated,
            ];

            if ($flags !== null) {
                $changes['injection_flags'] = json_encode($flags);
            }

            $changed = DocumentReadPage::withoutGlobalScopes()
                ->whereKey($page->id)
                ->where('organization_id', $read->organization_id)
                ->where('attempt_uuid', $page->attempt_uuid)
                ->where('status', DocumentReadPage::RUNNING)
                ->update($changes);

            if ($changed !== 1) {
                return false;
            }

            $row = DocumentReadPage::withoutGlobalScopes()->findOrFail($page->id);
            $row->setRelation('documentRead', $read);

            if ($charged) {
                $this->credits->chargeRead($read, $row, $plan['metered']);
                $row->forceFill(['cost_usd' => $total])->save();
            } elseif (Dec::of($plan['usd'])->isPositive()) {
                $this->credits->burnRead($read, $row, $plan['metered'], $plan['usd']);
            }

            if ($plan['step'] === 'read') {
                $kind->pageRead($row);
                $this->addPasses($row, $read, $kind->nextPasses($row));
            }

            if ($plan['second_half'] !== null) {
                DocumentReadPage::withoutGlobalScopes()->create(['document_read_id' => $read->id, 'pass' => $row->pass, 'part' => $row->part, 'model' => $row->model, ...$plan['second_half']]);
            }

            $locked = $this->lifecycle->tally($read, match ($plan['step']) {
                'read' => false,
                'failed' => true,
                default => null,
            }, $plan['usd']);

            $pause = $plan['pause'] ?? ($plan['step'] === 'failed' ? $this->lifecycle->stopAfter($locked, $row) : null);

            if ($pause !== null) {
                $this->lifecycle->pause($locked, $pause, notify: false);
            }

            if ($outcome->sent) {
                $this->recordSent($row, $locked, $starter, $outcome, $plan);
            }

            if ($plan['step'] === 'again') {
                $this->lifecycle->queuePage($row->id, $locked->kind, $plan['delay']);
            }

            $read->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if (! $written) {
            return null;
        }

        if ($outcome->result === PageOutcome::BLOCKED && ProviderBlock::set((string) $outcome->reason)) {
            $this->tellAdmins((string) $outcome->reason);
        }

        if ($outcome->providerMessage !== null) {
            Cache::put("ai.page_rejected:{$page->id}", $outcome->providerMessage, now()->addDays(self::REJECTION_DAYS));
            Log::warning('The AI provider would not take a reader request', ['page' => $page->id, 'detail' => $outcome->detail, 'request_id' => $outcome->requestId]);
        }

        return ['paused' => $wasRunning && $read->status === DocumentRead::PAUSED];
    }

    /**
     * Decides what becomes of the page, from the outcome and from how often it was tried already.
     *
     * step: 'read', 'failed', 'again' (queued once more after a wait), 'held' (back to waiting, the
     * read pauses) or 'split' (a run of text pages cut off: read again as two halves).
     *
     * @return array{step:string, page:array<string,mixed>, usd:string, metered:string, estimated:bool, chargeable:bool, pause:?string, delay:int, second_half:?array<string,mixed>, reason:?string}
     */
    private function plan(DocumentReadPage $page, DocumentRead $read, ReadKind $kind, PageOutcome $outcome): array
    {
        $requeues = (int) $page->requeues;
        $reason = $outcome->reason;
        $step = match ($outcome->result) {
            PageOutcome::READ => 'read',
            PageOutcome::BLOCKED => 'held',
            PageOutcome::AGAIN => match (true) {
                $reason === ModelGatewayException::RATE_LIMITED => $requeues >= count(self::RATE_LIMIT_DELAYS) ? 'held' : 'again',
                default => $requeues >= count(self::UNAVAILABLE_DELAYS) ? 'failed' : 'again',
            },
            default => 'failed',
        };

        if ($outcome->result === PageOutcome::AGAIN && $step === 'failed') {
            $reason = PageOutcome::UNAVAILABLE;
        }

        [$usd, $estimated] = $this->cost($page, $read, $outcome);
        $metered = Dec::of($usd)->isPositive() ? $this->meter->credits($usd) : '0.0000';

        $plan = [
            'step' => $step, 'usd' => $usd, 'metered' => $metered, 'estimated' => $estimated, 'chargeable' => false,
            'pause' => null, 'delay' => 0, 'second_half' => null, 'reason' => $reason,
        ];

        $row = [
            'stop_reason' => $outcome->stopReason === null ? null : Str::limit($outcome->stopReason, 30, ''),
            'provider_message_id' => $outcome->messageId === null ? null : Str::limit($outcome->messageId, 250, ''),
            'provider_request_id' => $outcome->requestId === null ? null : Str::limit($outcome->requestId, 250, ''),
            'latency_ms' => max(0, $outcome->latencyMs),
        ];

        if ($outcome->usage !== null) {
            $row += [
                'input_tokens' => $outcome->usage['input'], 'output_tokens' => $outcome->usage['output'],
                'cache_read_tokens' => $outcome->usage['cache_read'], 'cache_write_tokens' => $outcome->usage['cache_write'],
            ];
        }

        // Every attempt that cost something is dated, so the day's and the month's totals count it.
        if ($step === 'read' || $step === 'failed' || Dec::of($usd)->isPositive()) {
            $row['finished_at'] = now();
        }

        if ($step === 'read') {
            $probe = (clone $page)->forceFill(['result' => $outcome->output]);

            return [...$plan, 'chargeable' => $kind->chargeable($probe), 'page' => [
                ...$row, 'status' => DocumentReadPage::READ, 'fail_reason' => null, 'error_detail' => null,
                'result' => json_encode($outcome->output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'needs_picture' => false, 'requeues' => 0, 'auto_requeued' => 0,
            ]];
        }

        if ($step === 'again') {
            $trusted = $outcome->retryAfter !== null && $outcome->retryAfter <= self::LONGEST_TRUSTED_WAIT;
            $delay = $reason === ModelGatewayException::RATE_LIMITED
                ? ($trusted ? max(1, $outcome->retryAfter) : self::RATE_LIMIT_DELAYS[$requeues])
                : self::UNAVAILABLE_DELAYS[$requeues];

            return [...$plan, 'delay' => $delay, 'page' => [
                ...$row, 'status' => DocumentReadPage::QUEUED, 'requeues' => $requeues + 1, 'not_before' => now()->addSeconds($delay),
            ]];
        }

        if ($step === 'held') {
            return [...$plan, 'pause' => $outcome->result === PageOutcome::BLOCKED ? $reason : DocumentRead::PAUSE_PROVIDER_LIMIT, 'page' => [
                ...$row, 'status' => DocumentReadPage::WAITING, 'requeues' => 0, 'not_before' => null,
            ]];
        }

        $behaviour = in_array($reason, DocumentReadPage::BEHAVIOUR_FAILURES, true);
        $failures = min(250, (int) $page->behaviour_failures + ($behaviour ? 1 : 0));
        $from = (int) $page->page_no;
        $to = (int) ($page->page_to ?? $from);
        $cutOff = $reason === PageOutcome::CUT_OFF ? $kind->onCutOff($page) : null;

        if ($cutOff === 'split_run' && blank($page->image_path) && $to > $from) {
            $middle = $from + intdiv($to - $from, 2);
            $texts = ReadFiles::texts($read, $from, $to);
            $length = fn (int $first, int $last) => array_sum(array_map(mb_strlen(...), array_intersect_key($texts, array_flip(range($first, $last)))));

            return [...$plan, 'step' => 'split',
                'second_half' => ['page_no' => $middle + 1, 'page_to' => $to, 'text_chars' => $length($middle + 1, $to)],
                'page' => [
                    ...$row, 'status' => DocumentReadPage::WAITING, 'fail_reason' => null, 'error_detail' => null, 'behaviour_failures' => $failures,
                    'page_to' => $middle, 'text_chars' => $length($from, $middle), 'input_tokens' => 0, 'output_tokens' => 0, 'requeues' => 0, 'auto_requeued' => 0, 'finished_at' => now(),
                ],
            ];
        }

        return [...$plan, 'page' => [
            ...$row, 'status' => DocumentReadPage::FAILED, 'fail_reason' => $reason,
            'error_detail' => $outcome->detail === null ? null : Str::limit($outcome->detail, 290, ''),
            // The page has an answer of its own now, so a later try starts with a clean slate: its own waits, its own second chance.
            'behaviour_failures' => $failures, 'requeues' => 0, 'auto_requeued' => 0,
            // A single text page that does not fit is offered as a page picture instead.
            'needs_picture' => $cutOff === 'split_run' && blank($page->image_path),
        ]];
    }

    /**
     * What the call cost the platform, in US dollars. A call that never answered reports nothing, so
     * a timeout, or an outage that came after a long wait, is priced as the most it could have cost.
     *
     * @return array{0: string, 1: bool} The cost, and whether it is an estimate.
     */
    private function cost(DocumentReadPage $page, DocumentRead $read, PageOutcome $outcome): array
    {
        $model = (string) $page->model;

        if ($outcome->usage !== null) {
            return [$this->meter->usd($model, $outcome->usage), false];
        }

        $slow = $outcome->result === PageOutcome::AGAIN && $outcome->reason === ModelGatewayException::UNAVAILABLE && $outcome->latencyMs >= self::ESTIMATE_AFTER_MS;

        if (! $outcome->sent || ! ($outcome->reason === PageOutcome::TIMEOUT || $slow)) {
            return ['0.000000', false];
        }

        $figures = ReaderRequest::figures($read->kind, (string) $page->pass);
        $prompt = $this->quote->promptTokens($read->kind, (string) $page->pass);

        $input = match (true) {
            $page->image_width && $page->image_height => ImageTokens::count((int) $page->image_width, (int) $page->image_height) + $prompt,
            (int) $page->input_tokens > 0 => (int) $page->input_tokens,
            default => Dec::of((int) $page->text_chars)->multipliedBy(Dec::of(config('modules.document_reading.text_tokens_per_character')))->toScale(0, RoundingMode::Up)->toInt() + $prompt,
        };

        return [$this->meter->usd($model, ['input' => $input, 'output' => (int) $figures['max_tokens'], 'cache_read' => 0, 'cache_write' => 0]), true];
    }

    /**
     * A waiting row for each pass that follows on the same page: the same picture, the pass's usual model.
     *
     * @param  list<string>  $passes
     */
    private function addPasses(DocumentReadPage $page, DocumentRead $read, array $passes): void
    {
        foreach ($passes as $pass) {
            $exists = DocumentReadPage::withoutGlobalScopes()
                ->where('document_read_id', $read->id)->where('page_no', $page->page_no)->where('pass', $pass)->where('part', $page->part)
                ->exists();

            if ($exists) {
                continue;
            }

            DocumentReadPage::withoutGlobalScopes()->create([
                'document_read_id' => $read->id,
                'page_no' => $page->page_no,
                'page_to' => $page->page_to,
                'pass' => $pass,
                'part' => $page->part,
                'model' => (string) ReaderRequest::figures($read->kind, $pass)['model'],
                ...$page->only(['image_path', 'image_sha256', 'image_width', 'image_height', 'image_bytes', 'part_top', 'part_height', 'text_chars']),
            ]);
        }
    }

    /**
     * One audit line for every time a page left the server: what was sent, never what it said.
     *
     * @param  array<string, mixed>  $plan
     */
    private function recordSent(DocumentReadPage $page, DocumentRead $read, User $starter, PageOutcome $outcome, array $plan): void
    {
        AuditLog::record('ai.page_sent', $page, null, [
            'read_id' => $read->id,
            'kind' => $read->kind,
            'page_no' => (int) $page->page_no,
            'page_to' => $page->page_to === null ? null : (int) $page->page_to,
            'pass' => $page->pass,
            'part' => $page->part,
            'attempt' => (int) $page->attempts,
            'model' => $page->model,
            'bytes' => $page->image_bytes === null ? null : (int) $page->image_bytes,
            'sha256' => $page->image_sha256,
            'width' => $page->image_width === null ? null : (int) $page->image_width,
            'height' => $page->image_height === null ? null : (int) $page->image_height,
            'outcome' => match ($plan['step']) {
                'read' => 'read',
                'failed' => 'failed',
                'split' => 'cut_off_split',
                'again' => 'tried_again_later',
                default => 'not_taken',
            },
            'reason' => $plan['reason'],
            'provider_request_id' => $outcome->requestId,
        ], $starter, $read->organization_id);
    }

    /** A queued page that will not be sent after all. Its row is already locked by the claim. */
    private function setAside(DocumentReadPage $page, string $status): void
    {
        DocumentReadPage::withoutGlobalScopes()->whereKey($page->id)->where('status', DocumentReadPage::QUEUED)
            ->update(['status' => $status, 'not_before' => null]);
    }

    /** Platform admins are told once when the provider starts refusing every call. Companies are never told why. */
    private function tellAdmins(string $reason): void
    {
        $admins = User::where('is_active', true)
            ->where(fn ($query) => $query->where('is_platform_admin', true)->orWhereIn('email', config('buildos.platform_admins')))
            ->orderBy('id')->get()->filter->isPlatformAdmin();

        foreach ($admins as $admin) {
            $this->notifier->send($admin, function (string $locale) use ($reason) {
                $what = match ($reason) {
                    ModelGatewayException::SPEND_LIMIT => __('The provider account has reached its spending limit.', [], $locale),
                    ModelGatewayException::BILLING => __('The provider reports a billing problem on the account.', [], $locale),
                    ModelGatewayException::REJECTED_KEY => __('The provider rejected the saved API key.', [], $locale),
                    default => __('No API key for the AI service is saved.', [], $locale),
                };

                $next = $reason === ModelGatewayException::SPEND_LIMIT
                    ? __('Reads in progress are paused. No page is sent until this stop is cleared on the AI reader page.', [], $locale)
                    : __('Reads in progress are paused. New pages are held back for :minutes minutes, then tried again when someone continues a read.', ['minutes' => ProviderBlock::MINUTES], $locale);

                return new NotificationMessage(
                    event: 'ai.provider_block',
                    title: __('The AI readers have stopped: the AI service refused a request', [], $locale),
                    body: $what.' '.$next,
                    url: Route::has('ai-reader.admin.index') ? route('ai-reader.admin.index') : null,
                    critical: true,
                );
            });
        }
    }
}
