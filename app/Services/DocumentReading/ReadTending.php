<?php

namespace App\Services\DocumentReading;

use App\Models\AuditLog;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\Agents\ModelGateway;
use App\Services\DocumentReading\Contracts\Retains;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps reads moving when nothing else does: a page whose worker died, a read whose next page was
 * never handed to the queue, a read nobody came back to. It also deletes old files.
 *
 * It runs every minute from the scheduler for every company, and for one read each time that
 * read's status is asked for, so a read someone is watching moves even if the scheduler is down.
 * Nobody is signed in, so every query names its company or is meant for all of them.
 */
class ReadTending
{
    /** A page still "running" this long after it was claimed has lost its worker: the longest call is 240 seconds and the job is stopped at 270. */
    public const LOST_PAGE_SECONDS = 330;

    /** A running read with nothing queued is given its next page once this long has passed since the last one was sent. */
    public const IDLE_SECONDS = 30;

    /** A page that has sat "queued" this long is handed to the queue once more, in case its job was lost. A second job does no harm. */
    public const QUEUED_AGAIN_SECONDS = 300;

    /** Taking the text out of a PDF may take this long. */
    public const PREPARING_SECONDS = 240;

    public const DRAFT_HOURS = 2;

    public const QUOTED_HOURS = 24;

    /** A running read on which nothing has moved for this long is paused. */
    public const STALLED_MINUTES = 30;

    private const LAST_RUN = 'ai.tend.last_run';

    private const MOST_READS = 500;

    private const MOST_PURGES = 50;

    /** Prompt counts asked for in one round. A count can wait on the provider, and the round must stay short. */
    private const MOST_COUNTS = 2;

    private const FINAL = [DocumentRead::CONFIRMED, DocumentRead::DISCARDED, DocumentRead::CANCELLED, DocumentRead::FAILED, DocumentRead::EXPIRED];

    public function __construct(
        private ReadLifecycle $lifecycle,
        private ReadKinds $kinds,
        private ReadQuote $quote,
        private PageCall $call,
        private ModelGateway $gateway,
    ) {}

    /**
     * One round over every company. One read that fails is reported and does not stop the others.
     *
     * @return array{reads:int, purged:int, retained:int}
     */
    public function all(): array
    {
        $tended = 0;

        foreach ($this->due() as $id) {
            $read = DocumentRead::withoutGlobalScopes()->find($id);

            if ($read === null) {
                continue;
            }

            try {
                $this->one($read);
                $tended++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        // Text calls have no read to hang on, so they are looked after here: one whose worker died, one nobody picked up.
        try {
            app(TextCallRunner::class)->tendAll();
        } catch (Throwable $exception) {
            report($exception);
        }

        // The platform admin's dry runs are looked after in the same round: a page whose worker died, a page nobody picked up.
        try {
            app(TrialRunner::class)->tendAll();
        } catch (Throwable $exception) {
            report($exception);
        }

        $purged = $this->purgeOld();
        $retained = $this->runRetention();

        try {
            $this->measurePrompts();
        } catch (Throwable $exception) {
            report($exception);
        }

        Cache::forever(self::LAST_RUN, now()->getTimestamp());

        return ['reads' => $tended, 'purged' => $purged, 'retained' => $retained];
    }

    /** Applies every rule to one read. Cheap when nothing is due, so a status request can call it each time. */
    public function one(DocumentRead $read): void
    {
        $read = DocumentRead::withoutGlobalScopes()->find($read->id);

        if ($read === null) {
            return;
        }

        $this->recoverLostPages($read);
        $read->refresh();

        match ($read->status) {
            DocumentRead::RUNNING => $this->tendRunning($read),
            DocumentRead::PAUSED => $this->older($read->updated_at, hours: ReadLifecycle::PAUSED_HOURS)
                ? $this->lifecycle->cancel($read, null, ReadWords::PAUSED_TOO_LONG)
                : $this->lifecycle->advance($read),
            DocumentRead::PREPARING => $this->older($read->prepare_started_at ?? $read->updated_at, seconds: self::PREPARING_SECONDS)
                && $this->lifecycle->failPreparing($read, ReadWords::TOO_LARGE_FOR_TEXT),
            DocumentRead::DRAFT => $this->older($read->updated_at, hours: self::DRAFT_HOURS) && ! $this->pagesTouchedSince($read, now()->subHours(self::DRAFT_HOURS))
                && $this->lifecycle->cancel($read, null, ReadWords::NOT_STARTED),
            DocumentRead::PREPARED, DocumentRead::QUOTED => $this->older($read->updated_at, hours: self::QUOTED_HOURS)
                && $this->lifecycle->cancel($read, null, ReadWords::NOT_STARTED),
            // Counted from the last change, not from the finish: someone still checking the pages keeps it open.
            DocumentRead::READY => $this->older($read->updated_at, hours: 24 * $this->days('abandon_days', 14))
                && $this->lifecycle->expire($read),
            default => null,
        };
    }

    /**
     * Deletes the files of reads that ended long enough ago, and what the reader returned for them.
     * Counts, tokens and costs stay. The folder goes first: if the rows cannot be cleared afterwards,
     * the next round finds the same read again and nothing is left half done.
     */
    public function purgeOld(): int
    {
        $purged = 0;

        $reads = DocumentRead::withoutGlobalScopes()
            ->whereIn('status', self::FINAL)
            ->whereNull('files_purged_at')
            ->where('updated_at', '<', now()->subDays($this->days('keep_days', 30)))
            ->orderBy('id')->limit(self::MOST_PURGES)->get();

        foreach ($reads as $read) {
            try {
                $purged += $this->purge($read) ? 1 : 0;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $purged;
    }

    /** Runs every class a module has listed under "retention". Returns how many records they changed. */
    public function runRetention(): int
    {
        $changed = 0;

        foreach (config('modules', []) as $module) {
            foreach ($module['retention'] ?? [] as $class) {
                try {
                    $keeper = app($class);
                    $changed += $keeper instanceof Retains ? $keeper->purgeOld() : 0;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        return $changed;
    }

    /**
     * Has the provider count the fixed part of each kind's prompt, so quotes use the real figure
     * instead of the assumed one. Counting spends no tokens. Each kind and pass is looked at once
     * an hour at most, a count is asked for again only when the wording has changed, and one round
     * looks at two of them at most.
     */
    public function measurePrompts(): int
    {
        if (! $this->gateway->isConnected()) {
            return 0;
        }

        $measured = 0;

        foreach ($this->kinds->all() as $key => $kind) {
            foreach ($kind->passes() as $pass) {
                if ($measured >= self::MOST_COUNTS) {
                    return $measured;
                }

                if (! Cache::add("ai.prompt_measure_tried:{$key}:{$pass}", 1, now()->addHour())) {
                    continue;
                }

                try {
                    $read = new DocumentRead(['kind' => $key, 'pages_total' => 1]);
                    $page = new DocumentReadPage(['page_no' => 1, 'pass' => $pass, 'part' => 'full', 'model' => ReaderRequest::figures($key, $pass)['model']]);
                    $page->setRelation('documentRead', $read);

                    // Each pass is counted with what it is really sent: a picture for a pass that reads one, text for
                    // any other, since a kind can word the two differently (the tender reader does). The page itself is
                    // taken out again before counting, so an empty picture or an empty text stands in for it.
                    $body = in_array($pass, ReadIntake::picturePasses($key), true)
                        ? $this->call->body($kind, $page, '', null)
                        : $this->call->body($kind, $page, null, ReaderRequest::pages([1 => '']));

                    $this->quote->measurePrompt($key, $pass, ReaderRequest::withoutPage($body));
                    $measured++;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        return $measured;
    }

    /** When the scheduled round last finished. Null means it has never run, or the cache was cleared. */
    public static function lastRun(): ?Carbon
    {
        $at = Cache::get(self::LAST_RUN);

        return is_int($at) ? Carbon::createFromTimestamp($at, config('app.timezone')) : null;
    }

    /** Since when the longest-waiting page has been queued. A large age means no worker is listening. */
    public static function oldestQueuedSince(): ?Carbon
    {
        $at = DocumentReadPage::withoutGlobalScopes()
            ->where('status', DocumentReadPage::QUEUED)
            ->where(fn ($query) => $query->whereNull('not_before')->orWhere('not_before', '<=', now()))
            ->min('updated_at');

        return $at === null ? null : Carbon::parse($at, config('app.timezone'));
    }

    private function tendRunning(DocumentRead $read): void
    {
        $this->queueAgain($read);

        $moved = collect([$read->last_progress_at, $read->last_dispatched_at, $read->started_at])->filter()->max() ?? $read->updated_at;

        if ($this->older($moved, minutes: self::STALLED_MINUTES)) {
            $this->lifecycle->pause($read, DocumentRead::PAUSE_INFRA);

            return;
        }

        if ($read->last_dispatched_at === null || $this->older($read->last_dispatched_at, seconds: self::IDLE_SECONDS)) {
            $this->lifecycle->advance($read);
        }
    }

    /**
     * A page whose worker died is given one more try by itself. The second time it fails as
     * worker_lost. Nothing is charged either way. Written only where the lost claim still stands,
     * so a worker that was merely slow and a second round of tending cannot both act.
     */
    private function recoverLostPages(DocumentRead $read): void
    {
        $lost = $this->pages($read)->where('status', DocumentReadPage::RUNNING)
            ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subSeconds(self::LOST_PAGE_SECONDS)))
            ->get();

        foreach ($lost as $page) {
            $failed = DB::transaction(function () use ($read, $page) {
                // The read first, then its page: the same order as everywhere else.
                $read = DocumentRead::withoutGlobalScopes()->lockForUpdate()->findOrFail($read->id);

                $stillLost = fn () => $this->pages($read)->whereKey($page->id)->where('status', DocumentReadPage::RUNNING)
                    ->where(fn ($query) => $page->attempt_uuid === null ? $query->whereNull('attempt_uuid') : $query->where('attempt_uuid', $page->attempt_uuid));

                if ($read->status === DocumentRead::RUNNING && (int) $page->auto_requeued === 0) {
                    $changed = $stillLost()->update(['status' => DocumentReadPage::QUEUED, 'auto_requeued' => 1, 'attempt_uuid' => null, 'not_before' => null]);

                    if ($changed === 1) {
                        $this->lifecycle->queuePage($page->id, $read->kind);
                    }

                    return false;
                }

                $changed = $stillLost()->update(['status' => DocumentReadPage::FAILED, 'fail_reason' => PageOutcome::WORKER_LOST, 'error_detail' => null, 'requeues' => 0, 'finished_at' => now()]);

                if ($changed !== 1) {
                    return false;
                }

                $locked = $this->lifecycle->tally($read, true);
                $pause = $locked->status === DocumentRead::RUNNING ? $this->lifecycle->stopAfter($locked, $page->refresh()) : null;

                if ($pause !== null) {
                    $this->lifecycle->pause($locked, $pause);
                }

                return true;
            });

            if ($failed) {
                $this->lifecycle->advance($read);
            }
        }
    }

    /** Hands a page that has been queued for a long time to the queue once more. */
    private function queueAgain(DocumentRead $read): void
    {
        $cutoff = now()->subSeconds(self::QUEUED_AGAIN_SECONDS);

        $stale = $this->pages($read)->where('status', DocumentReadPage::QUEUED)
            ->where('updated_at', '<', $cutoff)
            ->where(fn ($query) => $query->whereNull('not_before')->orWhere('not_before', '<=', now()))
            ->pluck('id');

        foreach ($stale as $pageId) {
            // Touching the row is the claim: a second round that comes at the same time changes nothing.
            $changed = $this->pages($read)->whereKey($pageId)->where('status', DocumentReadPage::QUEUED)->where('updated_at', '<', $cutoff)
                ->update(['updated_at' => now()]);

            if ($changed === 1) {
                $this->lifecycle->queuePage((int) $pageId, $read->kind);
            }
        }
    }

    private function purge(DocumentRead $read): bool
    {
        if (! ReadFiles::delete($read)) {
            return false;
        }

        return DB::transaction(function () use ($read) {
            $locked = DocumentRead::withoutGlobalScopes()->lockForUpdate()->find($read->id);

            if ($locked === null || $locked->files_purged_at !== null || ! in_array($locked->status, self::FINAL, true)) {
                return false;
            }

            $this->pages($locked)->update(['image_path' => null, 'result' => null]);
            $locked->forceFill(['source_path' => null, 'text_path' => null, 'files_purged_at' => now()])->save();
            $this->kinds->find($locked->kind)?->purge($locked);

            AuditLog::record('ai.read_purged', $locked, null, ['status' => $locked->status], null, $locked->organization_id);

            return true;
        });
    }

    /**
     * The reads that may need something, across every company.
     *
     * @return list<int>
     */
    private function due(): array
    {
        $reads = fn () => DocumentRead::withoutGlobalScopes()->orderBy('id')->limit(self::MOST_READS);
        $pages = fn () => DocumentReadPage::withoutGlobalScopes()->orderBy('id')->limit(self::MOST_READS);

        $unfinished = fn ($query) => $query->selectRaw('1')->from('document_read_pages')
            ->whereColumn('document_read_pages.document_read_id', 'document_reads.id')
            ->whereIn('document_read_pages.status', [DocumentReadPage::WAITING, DocumentReadPage::QUEUED, DocumentReadPage::RUNNING]);

        return collect()
            ->merge($pages()->where('status', DocumentReadPage::RUNNING)
                ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subSeconds(self::LOST_PAGE_SECONDS)))
                ->pluck('document_read_id'))
            ->merge($reads()->where('status', DocumentRead::RUNNING)->pluck('id'))
            ->merge($reads()->where('status', DocumentRead::PAUSED)
                ->where(fn ($query) => $query->where('updated_at', '<', now()->subHours(ReadLifecycle::PAUSED_HOURS))->orWhereNotExists($unfinished))
                ->pluck('id'))
            ->merge($reads()->where('status', DocumentRead::PREPARING)->where('updated_at', '<', now()->subSeconds(self::PREPARING_SECONDS))->pluck('id'))
            ->merge($reads()->where('status', DocumentRead::DRAFT)->where('updated_at', '<', now()->subHours(self::DRAFT_HOURS))->pluck('id'))
            ->merge($reads()->whereIn('status', [DocumentRead::PREPARED, DocumentRead::QUOTED])->where('updated_at', '<', now()->subHours(self::QUOTED_HOURS))->pluck('id'))
            ->merge($reads()->where('status', DocumentRead::READY)->where('updated_at', '<', now()->subDays($this->days('abandon_days', 14)))->pluck('id'))
            ->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function pagesTouchedSince(DocumentRead $read, Carbon $since): bool
    {
        return $this->pages($read)->where('updated_at', '>=', $since)->exists();
    }

    private function older(?Carbon $moment, int $seconds = 0, int $minutes = 0, int $hours = 0): bool
    {
        return $moment !== null && $moment->lt(now()->subSeconds($seconds + 60 * $minutes + 3600 * $hours));
    }

    private function days(string $key, int $default): int
    {
        return max(1, (int) config("modules.document_reading.{$key}", $default));
    }

    private function pages(DocumentRead $read)
    {
        return DocumentReadPage::withoutGlobalScopes()
            ->where('organization_id', $read->organization_id)
            ->where('document_read_id', $read->id);
    }
}
