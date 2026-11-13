<?php

namespace App\Services\DocumentReading;

use App\Models\AuditLog;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Agents\CreditService;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Idempotent;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * What a person does with a read: open it, ask its price, start it, continue it after a pause, have
 * one page read again, have a cut-off page read as two halves, cancel it. The shared routes and
 * each feature's own routes come here, so every read is started under the same rules.
 *
 * A read's limit is set when it starts and is only ever changed by a request that names the new
 * amount. Continuing, reading a page again or reading halves uses what is left of the limit; when
 * that is not enough, the answer says what is needed and nothing is sent.
 *
 * Rows are locked in the order everything else uses: the company's wallet, then the read, then pages.
 */
class ReadDesk
{
    /** A page that was read, failed or was left out can be read again while its read is in one of these states. */
    private const RETRY_IN = [DocumentRead::RUNNING, DocumentRead::READY, DocumentRead::FAILED];

    /** The limit set for the read does not cover what is asked. The answer carries the amounts; sending "cap" sets a new limit. */
    public const LIMIT_REACHED = 'limit_reached';

    /** A read that paused because too many pages came back unusable has already been continued once. */
    public const RESUME_USED = 'resume_used';

    /** The company has not agreed to the current notice. The same code the route guard answers with. */
    public const CONSENT_REQUIRED = 'consent_required';

    public function __construct(
        private ReadQuote $quotes,
        private CreditService $credits,
        private AiSpend $spend,
        private ReadLifecycle $lifecycle,
        private ReadKinds $kinds,
    ) {}

    /**
     * Opens a read as a draft, for a feature's own "create" route. Pages are then uploaded, the
     * price asked and the read started through the shared routes, or straight through this class.
     *
     * A repeated client_uuid gives back the read it made the first time, when this person can reach
     * it, it is of the same kind and, for a kind that is one person's own, it is theirs.
     *
     * @param  array{project?: ?Project, client_uuid?: ?string, pages_total?: ?int, channel?: ?string, source_name?: ?string, options?: ?array<string, mixed>, subject?: ?Model}  $with
     *
     * @throws ValidationException On pages_total above what the kind reads, or a client_uuid that belongs to something else.
     */
    public function open(User $by, string $kind, array $with = []): DocumentRead
    {
        $reader = $this->kinds->get($kind);
        $project = $with['project'] ?? null;
        $uuid = $with['client_uuid'] ?? null;
        $pages = max(0, (int) ($with['pages_total'] ?? 0));
        $subject = $with['subject'] ?? null;

        if ($project !== null && (int) $project->organization_id !== (int) $by->organization_id) {
            throw new LogicException('A read is opened in a project of the person\'s own company.');
        }

        if ($subject !== null && strlen($subject->getMorphClass()) > 40) {
            throw new LogicException('The subject of a read needs a morph name of at most 40 characters.');
        }

        if ($pages > (int) $reader->limits()['max_pages']) {
            throw ValidationException::withMessages(['pages_total' => [__('This document can have :pages pages at most.', ['pages' => (int) $reader->limits()['max_pages']])]]);
        }

        $find = fn () => $uuid === null ? null : DocumentRead::withoutGlobalScopes()->where('organization_id', $by->organization_id)->where('client_uuid', $uuid)->first();
        $earlier = fn () => $this->sameRead(ClientUuidRepeat::reachable($find()), $by, $kind);

        return $earlier() ?? Idempotent::create(fn () => DocumentRead::withoutGlobalScopes()->create([
            'organization_id' => $by->organization_id,
            'project_id' => $project?->id,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'kind' => $kind,
            'status' => DocumentRead::DRAFT,
            'channel' => ($with['channel'] ?? 'web') === 'mobile' ? 'mobile' : 'web',
            'user_id' => $by->id,
            'client_uuid' => $uuid,
            'source_name' => blank($with['source_name'] ?? null) ? null : Str::limit((string) $with['source_name'], 150, ''),
            'pages_total' => $pages,
            'consent_version' => AiDocumentConsent::agreedVersion((int) $by->organization_id),
            'options' => $with['options'] ?? null,
        ])->refresh(), $earlier);
    }

    /**
     * What reading the waiting pages is likely to cost, optionally only those in a page range. An
     * estimate, not a price. A read that has not started is marked as quoted.
     *
     * @return array<string, mixed> The quote of ReadQuote::forRead. "rupees" and "free" are null for someone who may not open the credits page.
     *
     * @throws ReadRefused wrong_state
     */
    public function quote(DocumentRead $read, User $by, ?int $pageFrom = null, ?int $pageTo = null): array
    {
        $read = $this->fresh($read);

        if (! in_array($read->status, [...self::beforeStart(), DocumentRead::PAUSED], true) || $read->files_purged_at !== null) {
            throw ReadRefused::wrongState($read);
        }

        $quote = $this->quotes->forRead($read, $by, $pageFrom, $pageTo);

        // What the credits cost in rupees is shown to the people who may open the credits page, like the balance. Everyone else sees credits only.
        if (! $by->hasPermission('agents.credits')) {
            $quote['rupees'] = null;
        }

        if ($quote['pages'] > 0 && in_array($read->status, self::beforeStart(), true)) {
            DocumentRead::withoutGlobalScopes()->whereKey($read->id)->where('organization_id', $read->organization_id)->whereIn('status', self::beforeStart())
                ->update(['status' => DocumentRead::QUOTED, 'quoted_at' => now(), 'credits_estimate' => $quote['estimate'], 'updated_at' => now()]);
        }

        return $quote;
    }

    /**
     * Starts a read: puts its limit on hold and hands the first page to the queue. The price is
     * worked out again here; a limit sent by the person may only lower the offered one, never below
     * the floor. Pages outside the range, and pages the person chose to leave out, are set aside.
     *
     * @param  ?string  $cap  The limit the person accepted, or null for the offered one.
     * @param  list<int>  $leaveOut  Page numbers the person chose not to have read, such as blank pages.
     *
     * @throws ReadRefused wrong_state, pages_missing (missing), over_limit (pages_that_fit) or no_credits with status 402 (limit, pages_that_fit).
     * @throws SpendRefused A limit, a budget or the wallet refused the hold. Nothing was started.
     */
    public function start(DocumentRead $read, User $by, ?string $cap = null, ?int $pageFrom = null, ?int $pageTo = null, array $leaveOut = []): DocumentRead
    {
        $started = DB::transaction(function () use ($read, $by, $cap, $pageFrom, $pageTo, $leaveOut) {
            [$organization, $locked] = $this->lock($read, $by);

            if (! in_array($locked->status, self::beforeStart(), true) || $locked->files_purged_at !== null) {
                throw ReadRefused::wrongState($locked);
            }

            $this->assertConsent($organization);

            $waiting = $this->pages($locked)->where('status', DocumentReadPage::WAITING)->orderBy('page_no')->orderBy('id')->lockForUpdate()->get();
            $wanted = $waiting->filter(fn (DocumentReadPage $page) => ($pageFrom === null || (int) $page->page_no >= $pageFrom)
                && ($pageTo === null || (int) $page->page_no <= $pageTo)
                && ! in_array((int) $page->page_no, $leaveOut, true)
                && $this->hasMaterial($locked, $page))->values();

            $missing = $this->missing($locked, $wanted, $pageFrom, $pageTo, $leaveOut);

            if ($wanted->isEmpty() || $missing !== []) {
                throw new ReadRefused(ReadRefused::PAGES_MISSING, __('Some pages are not uploaded yet. Upload them, then start again.'), ['missing' => $missing]);
            }

            $quote = $this->quotes->forPages($locked, $wanted, $by);
            $this->assertFits($organization, $locked, $by, $quote, wholeOrNothing: true);

            $limit = $this->quotes->capFor($quote, $cap);

            // The read goes out in the starter's name. One person's own read (a slip, a bill) stays theirs when an owner or admin starts it for them.
            $bookedTo = config("modules.document_reading.kinds.{$locked->kind}.own_only", false) ? $this->bookedTo($locked, $by) : $by;

            // The hold and the pages count against the daily budget of the person the read is booked to, so that budget is the one checked.
            $this->credits->hold($locked, $limit, $by, $bookedTo);

            $locked->forceFill([
                'credits_cap' => $limit,
                'credits_estimate' => $quote['estimate'],
                'quoted_at' => $locked->quoted_at ?? now(),
                'started_at' => now(),
                'user_id' => $bookedTo->id,
                'consent_version' => AiDocumentConsent::agreedVersion($organization),
                'error' => null,
            ])->save();

            $this->pages($locked)->where('status', DocumentReadPage::WAITING)->whereNotIn('id', $wanted->modelKeys())
                ->update(['status' => DocumentReadPage::SKIPPED, 'not_before' => null]);

            ReadAudit::started($locked, $by, $quote['pages']);

            return $locked;
        });

        $this->lifecycle->advance($started->id);

        return $this->fresh($started);
    }

    /**
     * Continues a paused read with the pages that are still waiting. It holds what is left of the
     * read's limit, up to the most those pages can cost. When the limit does not cover them, nothing
     * is sent until the request names a new limit for the rest.
     *
     * A read that paused because too many pages came back unusable can be continued once.
     *
     * @param  ?string  $cap  A new limit for the pages that are left.
     *
     * @throws ReadRefused wrong_state, resume_used, limit_reached (cap, cap_floor, estimate, pages), over_limit or no_credits (402).
     * @throws SpendRefused
     */
    public function resume(DocumentRead $read, User $by, ?string $cap = null): DocumentRead
    {
        $resumed = DB::transaction(function () use ($read, $by, $cap) {
            [$organization, $locked] = $this->lock($read, $by);

            if ($locked->status !== DocumentRead::PAUSED || $locked->files_purged_at !== null) {
                throw ReadRefused::wrongState($locked);
            }

            $reason = $locked->pause_reason;

            if ($reason === DocumentRead::PAUSE_MANY_FAILURES && (int) $locked->resumes >= 1) {
                throw new ReadRefused(self::RESUME_USED, __('This document was already continued once after too many pages could not be read. Type the remaining pages by hand, or start again with clearer pages.'));
            }

            // A page that was queued when the read paused is waiting again.
            $this->pages($locked)->where('status', DocumentReadPage::QUEUED)->update(['status' => DocumentReadPage::WAITING, 'not_before' => null]);
            $waiting = $this->pages($locked)->where('status', DocumentReadPage::WAITING)->orderBy('page_no')->orderBy('id')->lockForUpdate()->get();

            if ($waiting->isEmpty()) {
                // Nothing is left to send: moving the read along ends it as ready or failed.
                return $locked;
            }

            $this->assertConsent($organization);
            $bookedTo = $this->bookedTo($locked, $by);
            $held = $this->holdFor($organization, $locked, $waiting, $by, $cap, $bookedTo);

            $locked->forceFill([
                'resumes' => min(250, (int) $locked->resumes + ($reason === DocumentRead::PAUSE_MANY_FAILURES ? 1 : 0)),
                'consent_version' => AiDocumentConsent::agreedVersion($organization),
                'user_id' => $bookedTo->id,
            ])->save();

            AuditLog::record('ai.read_resumed', $locked, ['status' => DocumentRead::PAUSED, 'pause_reason' => ReadAudit::code($reason)], [
                'status' => $locked->status,
                'kind' => ReadAudit::code($locked->kind),
                'pages' => $held['pages'],
                'held' => $held['hold'],
                'cap' => (string) $locked->credits_cap,
                'consent_version' => ReadAudit::code($locked->consent_version),
            ], $by, (int) $locked->organization_id);

            return $locked;
        });

        $this->lifecycle->advance($resumed->id);

        return $this->fresh($resumed);
    }

    /**
     * Has one page read again: a page that failed, a page that was read and that a person wants
     * read once more, or a whole page that was left out when the read started.
     *
     * A page whose answer was unusable can be read again a set number of times; a page lost to a
     * timeout or an outage as often as needed. A cut-off page cannot: reading it the same way would
     * be cut off again. The stronger model is offered only for an unusable answer or a page that was
     * read, only where the pass has one, and never to a company on a trial.
     *
     * @param  ?string  $cap  A new limit for this page, when the read's own limit no longer covers it.
     *
     * @throws ReadRefused wrong_state, cut_off, retries_used, not_offered, limit_reached, over_limit or no_credits (402).
     * @throws SpendRefused
     */
    public function retry(DocumentRead $read, DocumentReadPage $page, User $by, bool $stronger = false, ?string $cap = null): DocumentRead
    {
        // A read whose reader is no longer deployed cannot be sent anywhere.
        $this->kinds->get($read->kind);

        $retried = DB::transaction(function () use ($read, $page, $by, $stronger, $cap) {
            [$organization, $locked] = $this->lock($read, $by);
            $row = $this->pages($locked)->lockForUpdate()->find($page->id) ?? throw ReadRefused::wrongState($locked);
            $row->setRelation('documentRead', $locked);

            if (! in_array($locked->status, self::RETRY_IN, true) || $locked->files_purged_at !== null || ! self::canBeReadAgain($row)) {
                throw ReadRefused::wrongState($locked, $row->status);
            }

            if ($row->status === DocumentReadPage::FAILED && ($row->fail_reason === PageOutcome::CUT_OFF || $row->needs_picture)) {
                throw new ReadRefused(ReadRefused::CUT_OFF, __('The answer for this page was too long to fit, so reading it again the same way would not help.'));
            }

            if ($row->failedByBehaviour() && (int) $row->behaviour_failures > self::manualRetries()) {
                throw new ReadRefused(ReadRefused::RETRIES_USED, __('This page has been read again as often as is offered. Type this page by hand.'));
            }

            if ($stronger && ! $this->strongerOffered($organization, $locked, $row)) {
                throw new ReadRefused(ReadRefused::NOT_OFFERED, __('The stronger model is not offered for this page.'));
            }

            $figures = ReaderRequest::figures($locked->kind, (string) $row->pass);
            $before = ['status' => $row->status, 'fail_reason' => ReadAudit::code($row->fail_reason)];

            $row->forceFill([
                'status' => DocumentReadPage::WAITING,
                'fail_reason' => null,
                'error_detail' => null,
                'requeues' => 0,
                'auto_requeued' => 0,
                'not_before' => null,
                'needs_picture' => false,
                'model' => (string) ($stronger ? $figures['stronger'] : $figures['model']),
            ])->save();

            $this->sendMore($organization, $locked, collect([$row]), $by, $cap);

            AuditLog::record('ai.page_retry', $row, $before, [
                'read_id' => $locked->id,
                'kind' => ReadAudit::code($locked->kind),
                'page_no' => (int) $row->page_no,
                'pass' => ReadAudit::code($row->pass),
                'part' => ReadAudit::code($row->part),
                'model' => ReadAudit::code($row->model),
                'stronger' => $stronger,
                'attempts_so_far' => (int) $row->attempts,
            ], $by, (int) $locked->organization_id);

            return $locked;
        });

        $this->lifecycle->advance($retried->id);

        return $this->fresh($retried);
    }

    /**
     * Has a cut-off page read as its top half and its bottom half. Both halves must have been
     * uploaded first. The whole page's failed attempt is set aside, so the page counts as read once
     * both halves are.
     *
     * @param  ?string  $cap  A new limit for the two halves, when the read's own limit no longer covers them.
     *
     * @throws ReadRefused wrong_state, pages_missing (missing: the parts not uploaded), limit_reached, over_limit or no_credits (402).
     * @throws SpendRefused
     */
    public function split(DocumentRead $read, DocumentReadPage $page, User $by, ?string $cap = null): DocumentRead
    {
        $kind = $this->kinds->get($read->kind);

        $split = DB::transaction(function () use ($read, $page, $by, $cap, $kind) {
            [$organization, $locked] = $this->lock($read, $by);
            $whole = $this->pages($locked)->lockForUpdate()->find($page->id) ?? throw ReadRefused::wrongState($locked);
            $whole->setRelation('documentRead', $locked);

            if (! in_array($locked->status, self::RETRY_IN, true) || $locked->files_purged_at !== null || ! ReadIntake::canBeSplit($whole, $kind)) {
                throw ReadRefused::wrongState($locked, $whole->status);
            }

            $halves = $this->pages($locked)->where('page_no', $whole->page_no)->where('pass', $whole->pass)
                ->whereIn('part', [ReadIntake::TOP, ReadIntake::BOTTOM])->where('status', DocumentReadPage::SKIPPED)->whereNotNull('image_path')
                ->orderBy('part_top')->lockForUpdate()->get();

            $missing = array_values(array_diff([ReadIntake::TOP, ReadIntake::BOTTOM], $halves->pluck('part')->all()));

            if ($missing !== []) {
                throw new ReadRefused(ReadRefused::PAGES_MISSING, __('Both halves of the page must be uploaded before they can be read.'), ['missing' => $missing]);
            }

            $whole->forceFill(['status' => DocumentReadPage::SUPERSEDED])->save();

            foreach ($halves as $half) {
                $half->forceFill([
                    'status' => DocumentReadPage::WAITING, 'fail_reason' => null, 'error_detail' => null, 'requeues' => 0, 'auto_requeued' => 0, 'not_before' => null,
                    'model' => (string) ReaderRequest::figures($locked->kind, (string) $half->pass)['model'],
                ])->save();
            }

            $this->sendMore($organization, $locked, $halves, $by, $cap);

            AuditLog::record('ai.page_split', $whole, ['status' => DocumentReadPage::FAILED], [
                'read_id' => $locked->id,
                'kind' => ReadAudit::code($locked->kind),
                'page_no' => (int) $whole->page_no,
                'pass' => ReadAudit::code($whole->pass),
                'status' => DocumentReadPage::SUPERSEDED,
            ], $by, (int) $locked->organization_id);

            return $locked;
        });

        $this->lifecycle->advance($split->id);

        return $this->fresh($split);
    }

    /**
     * Cancels a read that is not finished. Its hold is given back and nothing more is sent. Asking
     * again for a read that is already cancelled changes nothing.
     *
     * @throws ReadRefused wrong_state for a read that has finished or was closed another way.
     */
    public function cancel(DocumentRead $read, User $by): DocumentRead
    {
        if (! $this->lifecycle->cancel($read, $by) && $this->fresh($read)->status !== DocumentRead::CANCELLED) {
            throw ReadRefused::wrongState($this->fresh($read));
        }

        return $this->fresh($read);
    }

    /** Whether "Read this page again" applies to a row at all: it failed, it was read, or it is a whole page that was left out. */
    public static function canBeReadAgain(DocumentReadPage $page): bool
    {
        return match ($page->status) {
            DocumentReadPage::FAILED, DocumentReadPage::READ => true,
            // A half that is set aside is waiting for its other half, not for a second try.
            DocumentReadPage::SKIPPED => $page->part === ReadIntake::FULL && (filled($page->image_path) || $page->text_chars !== null),
            default => false,
        };
    }

    /** Whether a failed page may be sent again as it is. A cut-off page and a page with too many unusable answers may not. */
    public static function canRetry(DocumentReadPage $page): bool
    {
        if ($page->status !== DocumentReadPage::FAILED || $page->fail_reason === PageOutcome::CUT_OFF || $page->needs_picture) {
            return false;
        }

        return ! $page->failedByBehaviour() || (int) $page->behaviour_failures <= self::manualRetries();
    }

    /**
     * Whether the stronger model may be asked for on this page: the pass has one, the company is not
     * on a trial, and the page either came back unusable or was read and is wanted again.
     */
    public function strongerOffered(Organization $organization, DocumentRead $read, DocumentReadPage $page): bool
    {
        $figures = config("modules.document_reading.kinds.{$read->kind}.passes.{$page->pass}");

        if (! is_array($figures) || blank($figures['stronger'] ?? null) || ! $this->spend->strongerModelAllowed($organization)) {
            return false;
        }

        return $page->status === DocumentReadPage::READ
            || ($page->status === DocumentReadPage::FAILED && $page->fail_reason === PageOutcome::INVALID_OUTPUT && self::canRetry($page));
    }

    /** @return list<string> */
    public static function beforeStart(): array
    {
        return [DocumentRead::DRAFT, DocumentRead::PREPARED, DocumentRead::QUOTED];
    }

    private static function manualRetries(): int
    {
        return max(0, (int) config('modules.document_reading.manual_retries', 2));
    }

    /**
     * Rows that were just put back to waiting on a read that has started. A running read reads them
     * out of what it already holds; a finished one takes a fresh hold for them.
     *
     * @param  Collection<int, DocumentReadPage>  $rows
     */
    private function sendMore(Organization $organization, DocumentRead $locked, Collection $rows, User $by, ?string $cap): void
    {
        $this->assertConsent($organization);

        // A page that is on its way again no longer counts as failed.
        $counts = $this->lifecycle->count($locked);
        $locked->forceFill(['pages_read' => $counts['read'], 'pages_failed' => $counts['failed']]);

        if ($locked->status === DocumentRead::RUNNING) {
            $locked->save();

            // No new hold, so the limits a hold would have checked are checked here: the day's limits, the company's brakes, the budgets.
            // The pages are charged to the person the running read is booked to, so that person's budget is the one checked.
            $bookedTo = ($locked->user_id === null ? null : User::where('organization_id', $locked->organization_id)->find($locked->user_id)) ?? $by;
            $this->spend->assertRoom($organization, $bookedTo, $locked->kind, $this->quotes->forPages($locked, $rows, $by)['cap_floor'], $locked, $by);

            return;
        }

        $bookedTo = $this->bookedTo($locked, $by);
        $this->holdFor($organization, $locked, $rows, $by, $cap, $bookedTo);

        $locked->forceFill([
            'consent_version' => AiDocumentConsent::agreedVersion($organization),
            'user_id' => $bookedTo->id,
        ])->save();
    }

    /**
     * Puts credits on hold for rows of a read that has started before, out of what is left of its
     * limit. The limit is changed only when the request names a new amount for these rows.
     *
     * @param  Collection<int, DocumentReadPage>  $rows
     * @param  User  $bookedTo  The person the read is booked to from now on, whose daily budget the hold counts against.
     * @return array{hold:string, pages:int}
     */
    private function holdFor(Organization $organization, DocumentRead $locked, Collection $rows, User $by, ?string $cap, User $bookedTo): array
    {
        $quote = $this->quotes->forPages($locked, $rows, $by);
        $this->assertFits($organization, $locked, $by, $quote, wholeOrNothing: false);

        $floor = Dec::of($quote['cap_floor']);
        $left = Dec::of($locked->capLeft());
        $spent = Dec::of($locked->credits_used)->plus(Dec::of($locked->credits_burned));

        // The most one document may use counts everything the read has used so far, not only what is asked for now.
        $most = Dec::of($quote['company_max'])->minus($spent);

        if ($most->isLessThan($floor)) {
            throw new ReadRefused(ReadRefused::OVER_LIMIT, __('This document has reached the most one document may use.'), ['pages_that_fit' => 0]);
        }

        $offer = $this->smallest(Dec::of($quote['cap']), $most);

        if ($cap !== null && is_numeric($cap)) {
            $hold = $this->smallest(Dec::of($this->quotes->capFor($quote, $cap)), $offer);
            $locked->credits_cap = (string) $spent->plus($hold)->toScale(4, RoundingMode::Up);
        } elseif ($left->isLessThan($floor)) {
            throw new ReadRefused(self::LIMIT_REACHED, __('The credit limit set for this document does not cover this. Set a new limit to go on.'), [
                'pages' => $quote['pages'],
                'estimate' => $quote['estimate'],
                'cap' => (string) $offer->toScale(4, RoundingMode::Up),
                'cap_floor' => $quote['cap_floor'],
                'cap_left' => $locked->capLeft(),
            ]);
        } else {
            // What is left of the limit, but no more than these rows can cost, and no more than this person can hold when less will do.
            $room = Dec::of($this->credits->room($organization, $by, $locked));
            $hold = $this->smallest($left, Dec::of($quote['worst']), $room->isLessThan($floor) ? $floor : $room, $most);
        }

        $hold = (string) $hold->toScale(4, RoundingMode::Up);
        $limit = $locked->credits_cap;

        $this->credits->hold($locked, $hold, $by, $bookedTo);
        $locked->forceFill(['credits_cap' => $limit, 'error' => null])->save();

        return ['hold' => $hold, 'pages' => (int) $quote['pages']];
    }

    /**
     * Stops when the rows cannot be read as asked: over the most one read may cost, or more than
     * the wallet or this person's share of it can hold.
     *
     * @param  array<string, mixed>  $quote
     * @param  bool  $wholeOrNothing  At a start, a trial allowance that covers only some of the pages
     *                                stops it too, so the person can choose a range. Later the read
     *                                goes on and pauses where the allowance ends.
     */
    private function assertFits(Organization $organization, DocumentRead $read, User $by, array $quote, bool $wholeOrNothing): void
    {
        if ($quote['enough']) {
            return;
        }

        $floor = Dec::of($quote['cap_floor']);
        $fit = ['pages_that_fit' => (int) $quote['pages_that_fit']];

        if ($floor->isGreaterThan(Dec::of($quote['company_max']))) {
            throw new ReadRefused(ReadRefused::OVER_LIMIT, __('This is more than one document may use. Choose fewer pages.'), $fit);
        }

        if ($floor->isGreaterThan(Dec::of($this->credits->room($organization, $by, $read)))) {
            $refusal = new InsufficientCredits($floor->isGreaterThan(Dec::of($this->credits->free($organization))) ? InsufficientCredits::WALLET : InsufficientCredits::HOLD_SHARE);

            throw new ReadRefused($refusal->reason, $refusal->getMessage(), [...$refusal->details, ...$fit], $refusal->status);
        }

        if ($wholeOrNothing) {
            throw new ReadRefused(ReadRefused::OVER_LIMIT, __('Your trial allows fewer pages than this. Choose fewer pages.'), $fit);
        }
    }

    /**
     * Nothing is put to work for a company that has not agreed to the current notice. The routes
     * are guarded for this already; a feature that starts a read from its own code is held to it here.
     */
    private function assertConsent(Organization $organization): void
    {
        if (! AiDocumentConsent::current($organization)) {
            throw new ReadRefused(self::CONSENT_REQUIRED, __('Your company has not agreed to the current notice for sending documents to the AI service. An owner or admin can do that in Settings.'));
        }
    }

    /**
     * The page numbers of the range that have nothing stored to read.
     *
     * @param  Collection<int, DocumentReadPage>  $wanted
     * @param  list<int>  $leaveOut
     * @return list<int> At most fifty of them.
     */
    private function missing(DocumentRead $read, Collection $wanted, ?int $pageFrom, ?int $pageTo, array $leaveOut): array
    {
        $total = (int) $read->pages_total;

        if ($total < 1) {
            return [];
        }

        $covered = [];

        foreach ($wanted as $page) {
            foreach (range((int) $page->page_no, max((int) $page->page_no, (int) $page->page_to)) as $number) {
                $covered[$number] = true;
            }
        }

        $missing = [];

        for ($number = max(1, $pageFrom ?? 1); $number <= min($total, $pageTo ?? $total) && count($missing) < 50; $number++) {
            if (! isset($covered[$number]) && ! in_array($number, $leaveOut, true)) {
                $missing[] = $number;
            }
        }

        return $missing;
    }

    /** A page row has something to send: a stored picture, or its part of the stored text. */
    private function hasMaterial(DocumentRead $read, DocumentReadPage $page): bool
    {
        return filled($page->image_path) || ($page->text_chars !== null && filled($read->text_path));
    }

    /**
     * In whose name the pages go out from now on. The person who started the read stays, as long as
     * they could still start it today; otherwise the person acting now takes it over.
     */
    private function bookedTo(DocumentRead $read, User $by): User
    {
        $starter = $read->user_id === null ? null : User::where('organization_id', $read->organization_id)->find($read->user_id);
        $kind = $this->kinds->find($read->kind);

        $stillCan = $starter !== null && $kind !== null && $starter->is_active && $starter->anonymised_at === null
            && $starter->hasPermission('agents.use') && ReadAccess::holds($starter, $read, $kind);

        return $stillCan ? $starter : $by;
    }

    /** The earlier read of a repeated client_uuid, when it is the same thing being asked for again. */
    private function sameRead(?Model $earlier, User $by, string $kind): ?DocumentRead
    {
        if ($earlier === null) {
            return null;
        }

        if (! $earlier instanceof DocumentRead || $earlier->kind !== $kind || ! ReadAccess::mayOpen($by, $earlier)) {
            throw ValidationException::withMessages(['client_uuid' => [__('Record not found on the server.')]]);
        }

        return $earlier;
    }

    /**
     * The wallet first, then the read: the order every hold and charge uses.
     *
     * @return array{0: Organization, 1: DocumentRead}
     */
    private function lock(DocumentRead $read, User $by): array
    {
        if ((int) $by->organization_id !== (int) $read->organization_id) {
            throw new LogicException('A read is worked on by someone of its own company.');
        }

        $organization = Organization::findOrFail($read->organization_id);
        $this->credits->wallet($organization);

        return [$organization, DocumentRead::withoutGlobalScopes()->where('organization_id', $organization->id)->lockForUpdate()->findOrFail($read->id)];
    }

    private function fresh(DocumentRead $read): DocumentRead
    {
        return DocumentRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->findOrFail($read->id);
    }

    private function pages(DocumentRead $read)
    {
        return DocumentReadPage::withoutGlobalScopes()
            ->where('organization_id', $read->organization_id)
            ->where('document_read_id', $read->id);
    }

    private function smallest(BigDecimal ...$values): BigDecimal
    {
        $least = array_shift($values);

        foreach ($values as $value) {
            $least = $value->isLessThan($least) ? $value : $least;
        }

        return $least;
    }
}
