<?php

namespace App\Services\DocumentReading;

use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\Organization;
use App\Services\DocumentReading\Contracts\ReadKind;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Where a read stands, for a screen or the phone that is watching it: how many pages are stored,
 * read, failed and left, what it has used of its limit, and what can be done about each page that
 * failed. The web and the API answer with the same body.
 *
 * Asking also tends the read, so a read someone is watching keeps moving even when the scheduler
 * is not running. Nothing here depends on the provider, the limits or the consent switch.
 */
class ReadStatus
{
    /** Everything about a page row except what the reader returned for it, which can be large and is not needed here. */
    private const COLUMNS = [
        'id', 'organization_id', 'document_read_id', 'page_no', 'page_to', 'pass', 'part', 'status', 'fail_reason',
        'image_path', 'text_chars', 'needs_picture', 'attempts', 'behaviour_failures',
    ];

    private const UNFINISHED = [DocumentReadPage::WAITING, DocumentReadPage::QUEUED, DocumentReadPage::RUNNING];

    /** A page can be read again, or read as halves, while its read is in one of these states. */
    private const CAN_SEND_MORE = [DocumentRead::RUNNING, DocumentRead::READY, DocumentRead::FAILED];

    public function __construct(
        private ReadTending $tending,
        private ReadKinds $kinds,
        private ReadQuote $quotes,
        private ReadDesk $desk,
    ) {}

    /**
     * Tends the read, then reports on it.
     *
     * @return array<string, mixed>
     */
    public function of(DocumentRead $read): array
    {
        try {
            $this->tending->one($read);
        } catch (Throwable $exception) {
            // Tending is a help, not a condition: the person still gets the status.
            report($exception);
        }

        return $this->body($read);
    }

    /**
     * The status body, without tending first. For the answer to a step that has just been taken.
     *
     * @return array<string, mixed>
     */
    public function body(DocumentRead $read): array
    {
        $read = DocumentRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->findOrFail($read->id);
        $kind = $this->kinds->find($read->kind);
        $organization = Organization::find($read->organization_id);

        $rows = DocumentReadPage::withoutGlobalScopes()
            ->where('organization_id', $read->organization_id)->where('document_read_id', $read->id)
            ->orderBy('page_no')->orderBy('id')->get(self::COLUMNS)
            ->each->setRelation('documentRead', $read);

        $unfinished = $rows->whereIn('status', self::UNFINISHED)->values();
        $canSendMore = in_array($read->status, self::CAN_SEND_MORE, true) && $read->files_purged_at === null;

        return [
            'id' => $read->id,
            'kind' => $read->kind,
            'status' => $read->status,
            'pause_reason' => $read->pause_reason,
            'error' => $read->error,
            'message' => $this->message($read),
            'pages_total' => (int) $read->pages_total,
            'pages_read' => (int) $read->pages_read,
            'pages_failed' => (int) $read->pages_failed,
            'pages_left' => count($this->numbers($unfinished)),
            'stored_pages' => $rows->where('part', ReadIntake::FULL)->whereNotNull('image_path')->pluck('page_no')->map(fn ($number) => (int) $number)->unique()->sort()->values()->all(),
            'credits_used' => (string) $read->credits_used,
            'credits_burned' => (string) $read->credits_burned,
            'credits_cap' => (string) $read->credits_cap,
            'eta_minutes' => $read->status === DocumentRead::RUNNING ? $this->minutesLeft($read, $rows, $unfinished) : null,
            'stalled' => $this->stalled($read, $rows),
            'can_cancel' => in_array($read->status, DocumentRead::OPEN, true),
            'can_resume' => $read->status === DocumentRead::PAUSED && $read->files_purged_at === null
                && ! ($read->pause_reason === DocumentRead::PAUSE_MANY_FAILURES && (int) $read->resumes >= 1),
            'failed_pages' => $rows->where('status', DocumentReadPage::FAILED)->values()
                ->map(fn (DocumentReadPage $page) => $this->failedPage($read, $page, $kind, $organization, $canSendMore))->all(),
            'left_out_pages' => $canSendMore
                ? $rows->filter(fn (DocumentReadPage $page) => $page->status === DocumentReadPage::SKIPPED && ReadDesk::canBeReadAgain($page))
                    ->map(fn (DocumentReadPage $page) => ['id' => $page->id, 'page_no' => (int) $page->page_no])->values()->all()
                : [],
        ];
    }

    /**
     * One failed page and what can be done about it. "can_split" offers reading it as two halves;
     * "can_stronger" offers a second try with the stronger model.
     *
     * @return array<string, mixed>
     */
    private function failedPage(DocumentRead $read, DocumentReadPage $page, ?ReadKind $kind, ?Organization $organization, bool $canSendMore): array
    {
        $canRetry = $canSendMore && ReadDesk::canRetry($page);

        return [
            'id' => $page->id,
            'page_no' => (int) $page->page_no,
            'page_to' => $page->page_to === null ? null : (int) $page->page_to,
            'pass' => $page->pass,
            'part' => $page->part,
            'reason' => $page->fail_reason,
            'can_retry' => $canRetry,
            'can_split' => $canSendMore && $kind !== null && ReadIntake::canBeSplit($page, $kind),
            'can_stronger' => $canRetry && $organization !== null && $this->desk->strongerOffered($organization, $read, $page),
            'needs_picture' => (bool) $page->needs_picture,
        ];
    }

    /** The sentence that goes with a pause or with an ending that was not a reading, in the reader's language. */
    private function message(DocumentRead $read): ?string
    {
        if ($read->status === DocumentRead::PAUSED) {
            return ReadWords::pause($read->pause_reason);
        }

        $words = ReadWords::error($read->error);

        return $words === '' ? null : $words;
    }

    /**
     * Running, started a while ago, and no page has gone to the reader yet: the server has not
     * picked the work up. Cancelling at this point costs nothing.
     *
     * @param  Collection<int, DocumentReadPage>  $rows
     */
    private function stalled(DocumentRead $read, Collection $rows): bool
    {
        $after = max(1, (int) config('modules.document_reading.stalled_seconds', 180));

        return $read->status === DocumentRead::RUNNING
            && $read->started_at !== null
            && $read->started_at->lt(now()->subSeconds($after))
            && (int) $rows->max('attempts') === 0;
    }

    /**
     * Minutes until the pages still to be read are likely done: the usual time of each call that is
     * left, including the passes that normally follow on a page. A guide, not a promise.
     *
     * @param  Collection<int, DocumentReadPage>  $rows
     * @param  Collection<int, DocumentReadPage>  $unfinished
     */
    private function minutesLeft(DocumentRead $read, Collection $rows, Collection $unfinished): int
    {
        if ($unfinished->isEmpty()) {
            return 0;
        }

        $exists = $rows->mapWithKeys(fn (DocumentReadPage $page) => ["{$page->page_no}|{$page->pass}|{$page->part}" => true]);
        $seconds = 0;

        foreach ($unfinished as $page) {
            $following = array_filter(
                (array) config("modules.document_reading.kinds.{$read->kind}.passes.{$page->pass}.then", []),
                fn ($pass) => ! isset($exists["{$page->page_no}|{$pass}|{$page->part}"]),
            );

            foreach ([$page->pass, ...$following] as $pass) {
                try {
                    $seconds += $this->quotes->typical($read->kind, (string) $pass)['seconds'];
                } catch (Throwable) {
                    // A pass without figures adds nothing to the guess.
                }
            }
        }

        return max(1, (int) ceil($seconds / max(1, (int) config('modules.document_reading.parallel_pages')) / 60));
    }

    /**
     * The page numbers a set of rows covers. A run of text pages covers every page in it.
     *
     * @param  Collection<int, DocumentReadPage>  $rows
     * @return list<int>
     */
    private function numbers(Collection $rows): array
    {
        $numbers = [];

        foreach ($rows as $page) {
            foreach (range((int) $page->page_no, max((int) $page->page_no, (int) $page->page_to)) as $number) {
                $numbers[$number] = true;
            }
        }

        return array_keys($numbers);
    }
}
