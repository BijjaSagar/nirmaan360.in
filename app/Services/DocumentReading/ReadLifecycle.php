<?php

namespace App\Services\DocumentReading;

use App\Jobs\DocumentReading\ReadDocumentPage;
use App\Models\AuditLog;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\User;
use App\Services\Agents\CreditService;
use App\Services\Notify\NotificationMessage;
use App\Services\Notify\Notifier;
use App\Services\Notify\PushTarget;
use App\Support\Dec;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every change of a read's status after it has started: sending the next page, finishing, pausing,
 * cancelling and closing. Whoever moves a read out of "running" gives back its hold in the same
 * transaction, and all of them do it here.
 *
 * It runs on the queue, from the scheduler and in requests, so every query names the read's company.
 *
 * Rows are locked in one order by everything that works on reads: the company's wallet (only a
 * hold or a charge needs it), then the read, then its pages. Never lock a page and then its read.
 */
class ReadLifecycle
{
    /** A page was handed to the queue. */
    public const DISPATCHED = 'dispatched';

    /** Nothing was left, so the read is now ready or failed. */
    public const FINISHED = 'finished';

    /** As many pages as may be are already queued or with the reader. */
    public const IN_FLIGHT = 'in_flight';

    /** The read is not running, so there is nothing to do. */
    public const IDLE = 'idle';

    /** Pages that are not done yet. A read with none of these has nothing left to read. */
    private const UNFINISHED = [DocumentReadPage::WAITING, DocumentReadPage::QUEUED, DocumentReadPage::RUNNING];

    /** A paused read is cancelled after this long. The notice to its starter says so. */
    public const PAUSED_HOURS = 24;

    /** After this many failed tries to finish a read it is ended as failed, so it cannot be tried forever. */
    private const FINISH_TRIES = 5;

    /** This many unusable answers one after another pause the read. */
    private const BEHAVIOUR_FAILURES_IN_A_ROW = 3;

    /** This many pages in a row lost to timeouts and outages pause the read, instead of walking through every page. */
    private const INFRASTRUCTURE_FAILURES_IN_A_ROW = 2;

    /** Once this many pages are done, the read pauses when more than a quarter of them came back unusable. */
    private const SHARE_CHECK_AFTER = 8;

    private const LARGEST_UNUSABLE_SHARE = '0.25';

    public function __construct(
        private CreditService $credits,
        private ReadKinds $kinds,
        private ReadQueues $queues,
        private Notifier $notifier,
    ) {}

    /**
     * Moves a read along: hands the next waiting page to the queue, or, when no page is waiting,
     * queued or being read, ends the read as ready or failed. Safe to call at any time and from
     * several places at once; call it after a start, a resume and a retry.
     *
     * @return string DISPATCHED, FINISHED, IN_FLIGHT or IDLE.
     */
    public function advance(DocumentRead|int $read): string
    {
        $id = $read instanceof DocumentRead ? $read->id : $read;

        $state = DB::transaction(function () use ($id) {
            $read = DocumentRead::withoutGlobalScopes()->lockForUpdate()->find($id);

            if ($read === null || ! in_array($read->status, [DocumentRead::RUNNING, DocumentRead::PAUSED], true)) {
                return self::IDLE;
            }

            // A charge can pause a read by itself. Whatever was queued then goes back to waiting here.
            if ($read->status === DocumentRead::PAUSED) {
                $this->unqueue($read);
            }

            $counts = $this->pages($read)->whereIn('status', self::UNFINISHED)
                ->selectRaw('status, count(*) as pages')->groupBy('status')->pluck('pages', 'status');

            $waiting = (int) ($counts[DocumentReadPage::WAITING] ?? 0);
            $inFlight = (int) ($counts[DocumentReadPage::QUEUED] ?? 0) + (int) ($counts[DocumentReadPage::RUNNING] ?? 0);

            if ($waiting + $inFlight === 0) {
                return 'finish';
            }

            if ($read->status !== DocumentRead::RUNNING) {
                return self::IDLE;
            }

            $slots = max(1, (int) config('modules.document_reading.parallel_pages')) - $inFlight;

            if ($slots <= 0 || $waiting === 0) {
                return self::IN_FLIGHT;
            }

            $sent = 0;

            foreach ($this->nextWaiting($read, $slots) as $pageId) {
                $changed = $this->pages($read)->whereKey($pageId)->where('status', DocumentReadPage::WAITING)
                    ->update(['status' => DocumentReadPage::QUEUED, 'not_before' => null]);

                if ($changed === 1) {
                    $this->queuePage($pageId, $read->kind);
                    $sent++;
                }
            }

            if ($sent === 0) {
                return self::IN_FLIGHT;
            }

            $read->forceFill(['last_dispatched_at' => now()])->save();

            return self::DISPATCHED;
        });

        if ($state !== 'finish') {
            return $state;
        }

        return $this->finish($id) ? self::FINISHED : self::IDLE;
    }

    /**
     * Hands one page job to the queue of its kind, once the surrounding work has committed.
     * The page row must already be "queued".
     */
    public function queuePage(int $pageId, string $kind, int $delaySeconds = 0): void
    {
        $job = ReadDocumentPage::dispatch($pageId)->afterCommit();

        if (($queue = $this->queues->forKind($kind)) !== null) {
            $job->onQueue($queue);
        }

        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }
    }

    /**
     * Pauses a running read: its hold is given back, pages that were queued go back to waiting,
     * and its starter is told. A page that is with the reader at this moment still comes back and
     * is saved, but it is not charged.
     *
     * @return bool False when the read was not running.
     */
    public function pause(DocumentRead $read, string $reason, bool $notify = true): bool
    {
        $paused = DB::transaction(function () use ($read, $reason) {
            $locked = DocumentRead::withoutGlobalScopes()->lockForUpdate()->find($read->id);

            if ($locked === null || $locked->status !== DocumentRead::RUNNING) {
                return null;
            }

            $this->credits->releaseRead($locked);
            $locked->forceFill(['status' => DocumentRead::PAUSED, 'pause_reason' => $reason])->save();
            $this->unqueue($locked);

            AuditLog::record('ai.read_paused', $locked, ['status' => DocumentRead::RUNNING], ['status' => DocumentRead::PAUSED, 'pause_reason' => $reason], null, $locked->organization_id);

            return $locked;
        });

        if ($paused === null) {
            return false;
        }

        $read->setRawAttributes($paused->getAttributes(), true);

        if ($notify) {
            $this->tellPaused($paused);
        }

        return true;
    }

    /**
     * Cancels a read that is not finished: draft, preparing, prepared, quoted, running or paused.
     * Its hold is given back and every page not yet sent is skipped. Nothing new is sent.
     *
     * @param  ?User  $by  The person who cancelled it, or null when the system did.
     * @param  ?string  $error  A ReadWords code for why the system cancelled it.
     * @return bool False when the read was already finished or closed.
     */
    public function cancel(DocumentRead $read, ?User $by = null, ?string $error = null): bool
    {
        return $this->close($read, DocumentRead::OPEN, DocumentRead::CANCELLED, 'ai.read_cancelled', $by, $error);
    }

    /** Closes a ready read that nobody confirmed or discarded in time. */
    public function expire(DocumentRead $read): bool
    {
        return $this->close($read, [DocumentRead::READY], DocumentRead::EXPIRED, 'ai.read_expired', null, null);
    }

    /** Ends a read whose preparation never finished. Its files stay until the usual clean-up. */
    public function failPreparing(DocumentRead $read, string $error): bool
    {
        return $this->close($read, [DocumentRead::PREPARING], DocumentRead::FAILED, 'ai.read_failed', null, $error);
    }

    /** Tells the starter that a read has paused. For a pause that was made without telling anyone. */
    public function tellPaused(DocumentRead $read): void
    {
        $this->tell($read, 'ai.read_paused', fn (string $locale) => [
            __('The reader has paused: :feature', ['feature' => AiSpend::label($read->kind, $locale)], $locale),
            ReadWords::pause($read->pause_reason, $locale).' '.__('A paused document is cancelled after :hours hours.', ['hours' => self::PAUSED_HOURS], $locale),
        ]);
    }

    /**
     * How many pages of a read are read and how many failed, counted from its page rows.
     * A page with two passes or two halves is read when all of them are, and failed when one of
     * them is. A run of text pages counts for every page in it. Skipped and replaced rows do not count.
     *
     * @return array{read:int, failed:int}
     */
    public function count(DocumentRead $read): array
    {
        $rows = $this->pages($read)
            ->whereNotIn('status', [DocumentReadPage::SKIPPED, DocumentReadPage::SUPERSEDED])
            ->get(['page_no', 'page_to', 'status']);

        $totals = ['read' => 0, 'failed' => 0];

        foreach ($rows->groupBy('page_no') as $number => $group) {
            $span = max(1, (int) $group->max(fn ($row) => (int) ($row->page_to ?? $number)) - (int) $number + 1);

            if ($group->contains('status', DocumentReadPage::FAILED)) {
                $totals['failed'] += $span;
            } elseif ($group->every(fn ($row) => $row->status === DocumentReadPage::READ)) {
                $totals['read'] += $span;
            }
        }

        return $totals;
    }

    /**
     * Brings a read's counters up to date after one of its pages ended. Must run inside the
     * transaction that wrote the page, after any charge, because it locks the read.
     *
     * @param  ?bool  $failed  True when the page failed, false when it was read, null when it will be tried again.
     * @param  string  $usd  What the attempt cost the platform, real or estimated.
     * @return DocumentRead The locked row.
     */
    public function tally(DocumentRead $read, ?bool $failed, string $usd = '0'): DocumentRead
    {
        $locked = DocumentRead::withoutGlobalScopes()->lockForUpdate()->findOrFail($read->id);
        $counts = $this->count($locked);

        $locked->forceFill([
            'pages_read' => $counts['read'],
            'pages_failed' => $counts['failed'],
            'cost_usd' => (string) Dec::of($locked->cost_usd)->plus(Dec::of($usd))->toScale(6, RoundingMode::HalfUp),
            'consecutive_failures' => match ($failed) {
                true => min(250, (int) $locked->consecutive_failures + 1),
                false => 0,
                null => (int) $locked->consecutive_failures,
            },
            'last_progress_at' => now(),
        ])->save();

        return $locked;
    }

    /**
     * Whether a read should stop after this failed page, as a pause reason, or null to go on.
     * Unusable answers: three one after another, or more than a quarter once eight pages are done.
     * Timeouts and outages: two pages one after another. Looks at the pages that ended just before this one.
     */
    public function stopAfter(DocumentRead $read, DocumentReadPage $failed): ?string
    {
        $behaviour = in_array($failed->fail_reason, DocumentReadPage::BEHAVIOUR_FAILURES, true);
        $inARow = $behaviour ? self::BEHAVIOUR_FAILURES_IN_A_ROW : self::INFRASTRUCTURE_FAILURES_IN_A_ROW;
        $reasons = $behaviour ? DocumentReadPage::BEHAVIOUR_FAILURES : DocumentReadPage::INFRASTRUCTURE_FAILURES;
        $done = fn () => $this->pages($read)->whereIn('status', [DocumentReadPage::READ, DocumentReadPage::FAILED])->whereNotNull('finished_at');

        $earlier = $done()->whereKeyNot($failed->id)->orderByDesc('finished_at')->orderByDesc('id')->limit($inARow - 1)->get(['status', 'fail_reason']);

        $allTheSame = $earlier->count() === $inARow - 1
            && $earlier->every(fn ($row) => $row->status === DocumentReadPage::FAILED && in_array($row->fail_reason, $reasons, true));

        if ($allTheSame) {
            return $behaviour ? DocumentRead::PAUSE_MANY_FAILURES : DocumentRead::PAUSE_INFRA;
        }

        if (! $behaviour) {
            return null;
        }

        $processed = $done()->count();
        $unusable = $done()->where('status', DocumentReadPage::FAILED)->whereIn('fail_reason', DocumentReadPage::BEHAVIOUR_FAILURES)->count();

        return $processed >= self::SHARE_CHECK_AFTER && Dec::of($unusable)->isGreaterThan(Dec::of($processed)->multipliedBy(Dec::of(self::LARGEST_UNUSABLE_SHARE)))
            ? DocumentRead::PAUSE_MANY_FAILURES
            : null;
    }

    private function finish(int $id): bool
    {
        $tries = "ai.read_finish_failed:{$id}";

        try {
            $finished = DB::transaction(fn () => $this->end($id, withKind: true));
        } catch (Throwable $exception) {
            report($exception);

            Cache::add($tries, 0, now()->addDay());

            if (Cache::increment($tries) < self::FINISH_TRIES) {
                return false;
            }

            // The kind's own finishing step keeps failing. The read is ended without it, marked as failed.
            $finished = DB::transaction(fn () => $this->end($id, withKind: false));
        }

        if ($finished === null) {
            return false;
        }

        Cache::forget($tries);
        $counts = ['read' => (int) $finished->pages_read, 'failed' => (int) $finished->pages_failed, 'total' => max((int) $finished->pages_total, (int) $finished->pages_read + (int) $finished->pages_failed)];

        $this->tell($finished, 'ai.read_finished', function (string $locale) use ($finished, $counts) {
            $feature = ['feature' => AiSpend::label($finished->kind, $locale)];

            if ($finished->status !== DocumentRead::READY) {
                return [
                    __('The reader could not read this: :feature', $feature, $locale),
                    __('No page could be read. Pages that cannot be read are not charged.', [], $locale),
                ];
            }

            return [
                __('The reader has finished: :feature', $feature, $locale),
                $counts['failed'] > 0
                    ? __(':read of :total pages were read and :failed could not be read. Check every value against the page before you save anything.', $counts, $locale)
                    : __(':read of :total pages were read. Check every value against the page before you save anything.', $counts, $locale),
            ];
        });

        return true;
    }

    /** Must run inside a transaction. Null when the read no longer needs finishing. */
    private function end(int $id, bool $withKind): ?DocumentRead
    {
        $read = DocumentRead::withoutGlobalScopes()->lockForUpdate()->find($id);

        if ($read === null || ! in_array($read->status, [DocumentRead::RUNNING, DocumentRead::PAUSED], true)
            || $this->pages($read)->whereIn('status', self::UNFINISHED)->exists()) {
            return null;
        }

        $before = ['status' => $read->status];
        $anyRead = $this->pages($read)->where('status', DocumentReadPage::READ)->exists();
        $counts = $this->count($read);

        $this->credits->releaseRead($read);

        $read->forceFill([
            'status' => $anyRead && $withKind ? DocumentRead::READY : DocumentRead::FAILED,
            'pause_reason' => null,
            'error' => $withKind ? null : ReadWords::FINISH_FAILED,
            'pages_read' => $counts['read'],
            'pages_failed' => $counts['failed'],
            'finished_at' => now(),
        ])->save();

        if ($withKind) {
            $this->kinds->find($read->kind)?->finished($read);
        }

        AuditLog::record('ai.read_finished', $read, $before, [
            'status' => $read->status,
            'pages_read' => $counts['read'],
            'pages_failed' => $counts['failed'],
            'credits_used' => (string) $read->credits_used,
            'credits_burned' => (string) $read->credits_burned,
        ], null, $read->organization_id);

        return $read;
    }

    /**
     * @param  list<string>  $from  The statuses the read may be in.
     */
    private function close(DocumentRead $read, array $from, string $to, string $action, ?User $by, ?string $error): bool
    {
        $closed = DB::transaction(function () use ($read, $from, $to, $action, $by, $error) {
            $locked = DocumentRead::withoutGlobalScopes()->lockForUpdate()->find($read->id);

            if ($locked === null || ! in_array($locked->status, $from, true)) {
                return null;
            }

            $before = ['status' => $locked->status];

            $this->credits->releaseRead($locked);
            $locked->forceFill(['status' => $to, 'pause_reason' => null, 'error' => $error, 'finished_at' => $locked->finished_at ?? now()])->save();

            // A page that is with the reader right now is left alone: its answer is still saved, and nothing is charged for it.
            $this->pages($locked)->whereIn('status', [DocumentReadPage::WAITING, DocumentReadPage::QUEUED])
                ->update(['status' => DocumentReadPage::SKIPPED, 'not_before' => null]);

            AuditLog::record($action, $locked, $before, array_filter(['status' => $to, 'error' => $error]), $by, $locked->organization_id);

            return $locked;
        });

        if ($closed === null) {
            return false;
        }

        $read->setRawAttributes($closed->getAttributes(), true);

        return true;
    }

    /** Queued pages go back to waiting. A job that still arrives for one finds it not queued and stops. */
    private function unqueue(DocumentRead $read): void
    {
        $this->pages($read)->where('status', DocumentReadPage::QUEUED)->update(['status' => DocumentReadPage::WAITING, 'not_before' => null]);
    }

    /**
     * The next pages to send, in reading order: by page, then by the kind's order of passes, then top before bottom.
     *
     * @return list<int>
     */
    private function nextWaiting(DocumentRead $read, int $slots): array
    {
        $passes = array_flip($this->kinds->find($read->kind)?->passes() ?? []);
        $parts = ['full' => 0, 'top' => 1, 'bottom' => 2];

        return $this->pages($read)->where('status', DocumentReadPage::WAITING)->get(['id', 'page_no', 'pass', 'part'])
            ->sortBy(fn ($page) => [(int) $page->page_no, $passes[$page->pass] ?? 99, $parts[$page->part] ?? 9, (int) $page->id])
            ->take($slots)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** The read's own page rows, named by company as well as by read. */
    private function pages(DocumentRead $read)
    {
        return DocumentReadPage::withoutGlobalScopes()
            ->where('organization_id', $read->organization_id)
            ->where('document_read_id', $read->id);
    }

    /**
     * Tells the starter, after the surrounding work has committed. A kind that is watched on its own
     * form (a slip, a bill) sends nothing.
     *
     * @param  callable(string): array{0:string, 1:string}  $write  Given a language, the title and the body.
     */
    private function tell(DocumentRead $read, string $event, callable $write): void
    {
        if (! config("modules.document_reading.kinds.{$read->kind}.notify", true) || $read->user_id === null) {
            return;
        }

        $this->notifier->whenCommitted(function () use ($read, $event, $write) {
            $starter = User::where('organization_id', $read->organization_id)->find($read->user_id);

            if ($starter === null) {
                return;
            }

            $this->notifier->send($starter, function (string $locale) use ($read, $event, $write) {
                [$title, $body] = $write($locale);

                return new NotificationMessage(
                    event: $event,
                    title: $title,
                    body: $body,
                    push: new PushTarget(null, 'link', $read->id, (int) $read->organization_id, $read->project_id, ['agents.use']),
                );
            });
        });
    }
}
