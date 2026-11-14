<?php

namespace App\Services\DocumentReading;

use App\Models\ReaderTrial;
use App\Models\ReaderTrialPage;
use App\Models\User;
use App\Services\Agents\ModelGatewayException;
use App\Services\DocumentReading\Contracts\ReadKind;
use App\Services\DocumentReading\Contracts\TriesPastedText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The platform admin's dry runs: a reader or a text call tried on a document the admin uploaded
 * for the test, to measure it before any company is offered it.
 *
 * A dry run belongs to no company. Its material comes only from an upload or a paste on the
 * admin's own screen; there is no way to point one at a company's file. It creates nothing in any
 * company and charges nobody. Its files and what the model returned are deleted 24 hours after it
 * was made; the counts, the tokens and the cost stay.
 */
class ReaderTrials
{
    /** The most characters one pasted text may have. The same as the most a text call may send. */
    public const MOST_TEXT_CHARS = 40000;

    /** Pasted texts one dry run of a reader may hold, each read with one call. */
    public const MOST_TEXTS = 20;

    public const EFFORTS = ['low', 'medium', 'high'];

    public const LANGUAGES = ['en', 'hi', 'mr'];

    public const LEAST_TOKENS = 200;

    public const MOST_TOKENS = 16000;

    public const SHORTEST_TIMEOUT = 10;

    /** The job that makes the call is stopped at 270 seconds, so no call may be allowed longer than this. */
    public const LONGEST_TIMEOUT = 240;

    /** Nothing that could be sent is stored yet. */
    public const NOTHING_STORED = 'nothing_stored';

    private const MOST_PURGES = 50;

    public function __construct(
        private ReadKinds $kinds,
        private TextCallHandlers $handlers,
        private TrialRunner $runner,
    ) {}

    /**
     * Everything that can be tried in this deployment: each reader with its passes, and each text
     * call whose handler can work from pasted text.
     *
     * @return array{kinds: array<string, array{label: string, max_pages: int, passes: array<string, array{model: string, effort: string, max_tokens: int, timeout: int}>}>, purposes: list<array{purpose: string, subject_type: ?string, label: string, model: string, effort: string, max_tokens: int, timeout: int}>}
     */
    public function targets(): array
    {
        $kinds = [];

        foreach ($this->kinds->all() as $key => $kind) {
            $passes = [];

            foreach ($kind->passes() as $pass) {
                $passes[$pass] = $this->usual(ReaderRequest::figures($key, $pass));
            }

            $kinds[$key] = ['label' => AiSpend::label($key), 'max_pages' => (int) $kind->limits()['max_pages'], 'passes' => $passes];
        }

        $purposes = [];

        foreach ($this->handlers->all() as $purpose => $listed) {
            foreach ($listed as $subject => $class) {
                if (is_subclass_of($class, TriesPastedText::class)) {
                    $purposes[] = ['purpose' => $purpose, 'subject_type' => $subject === '*' ? null : $subject, 'label' => AiSpend::label($purpose), ...$this->usual(TextRequest::figures($purpose))];
                }
            }
        }

        return ['kinds' => $kinds, 'purposes' => $purposes];
    }

    /**
     * Makes a dry run. Nothing is sent until pages are uploaded or text is pasted and Start is pressed.
     *
     * @param  array{allowed?: mixed, kind?: ?string, pass?: ?string, material?: ?string, purpose?: ?string, subject_type?: ?string, model?: ?string, effort?: ?string, max_tokens?: int|string|null, timeout?: int|string|null, pages_total?: int|string|null, language?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function create(User $by, array $data): ReaderTrial
    {
        // The one thing no caller can leave out: the admin said the document may be used for testing.
        if (($data['allowed'] ?? false) !== true) {
            throw ValidationException::withMessages(['allowed' => [__('Tick the box to confirm that you are allowed to use this document for testing.')]]);
        }

        $kind = filled($data['kind'] ?? null) ? (string) $data['kind'] : null;
        $purpose = filled($data['purpose'] ?? null) ? (string) $data['purpose'] : null;

        if (($kind === null) === ($purpose === null)) {
            throw ValidationException::withMessages(['kind' => [__('Choose one reader or one text call to try.')]]);
        }

        $values = $kind !== null ? $this->forKind($kind, $data) : $this->forPurpose($purpose, $data);
        $usual = $values['usual'];
        unset($values['usual']);

        $model = filled($data['model'] ?? null) ? (string) $data['model'] : $usual['model'];
        $effort = filled($data['effort'] ?? null) ? (string) $data['effort'] : $usual['effort'];
        $maxTokens = (int) (filled($data['max_tokens'] ?? null) ? $data['max_tokens'] : $usual['max_tokens']);
        $timeout = (int) (filled($data['timeout'] ?? null) ? $data['timeout'] : $usual['timeout']);
        $language = filled($data['language'] ?? null) ? (string) $data['language'] : null;

        $errors = array_filter([
            'model' => is_array(config("modules.agents.pricing.{$model}")) ? null : __('This model has no price on record, so it cannot be tried.'),
            'effort' => in_array($effort, self::EFFORTS, true) ? null : __('Choose low, medium or high.'),
            'max_tokens' => $maxTokens >= self::LEAST_TOKENS && $maxTokens <= self::MOST_TOKENS ? null
                : __('The output limit must be between :min and :max tokens.', ['min' => self::LEAST_TOKENS, 'max' => self::MOST_TOKENS]),
            'timeout' => $timeout >= self::SHORTEST_TIMEOUT && $timeout <= self::LONGEST_TIMEOUT ? null
                : __('The time limit must be between :min and :max seconds.', ['min' => self::SHORTEST_TIMEOUT, 'max' => self::LONGEST_TIMEOUT]),
            'language' => $language === null || in_array($language, self::LANGUAGES, true) ? null : __('Choose English, Hindi or Marathi.'),
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn (string $message) => [$message], $errors));
        }

        return DB::transaction(function () use ($by, $values, $model, $effort, $maxTokens, $timeout, $language) {
            $trial = ReaderTrial::query()->create([
                ...$values,
                'uuid' => (string) Str::uuid(),
                'user_id' => $by->id,
                'model' => $model,
                'effort' => $effort,
                'max_tokens' => $maxTokens,
                'timeout' => $timeout,
                'options' => $language === null ? null : ['language' => $language],
                'status' => ReaderTrial::DRAFT,
                'allowed_at' => now(),
                'purge_after' => now()->addHours(ReaderTrial::KEEP_HOURS),
            ])->refresh();

            PlatformAudit::record('ai.trial_created', $trial, [
                'kind' => $trial->kind, 'purpose' => $trial->purpose, 'pass' => $trial->pass, 'material' => $trial->material,
                'model' => $model, 'effort' => $effort, 'max_tokens' => $maxTokens, 'timeout' => $timeout,
                'pages_total' => (int) $trial->pages_total, 'allowed' => true,
            ], $by);

            return $trial;
        });
    }

    /**
     * Stores one uploaded page picture of a dry run, redrawn by the server like every page picture.
     * A page sent again before the start replaces the earlier one.
     *
     * @throws ImageRefused The picture cannot be used. Nothing was stored.
     * @throws ReaderUnavailable The server cannot redraw pictures. Nothing was stored.
     * @throws ReadRefused wrong_state or not_stored.
     * @throws ValidationException A page number the trial does not have.
     */
    public function storePicture(ReaderTrial $trial, int $pageNo, string $bytes, User $by): ReaderTrialPage
    {
        $kind = $this->assertTakes($trial, ReaderTrial::PICTURES, $pageNo);
        $image = app(PageImage::class)::normalise($bytes, (int) $kind->limits()['min_short_side']);

        return $this->store($trial, ReaderTrial::PICTURES, $pageNo, $by, TrialFiles::picture($trial, $pageNo), $image->bytes, [
            'image_sha256' => $image->sha256,
            'image_width' => $image->width,
            'image_height' => $image->height,
            'image_bytes' => strlen($image->bytes),
        ]);
    }

    /**
     * Stores one pasted text of a dry run: page text for a reader, or a transcript or a list of
     * facts for a text call.
     *
     * @throws ReadRefused wrong_state or not_stored.
     * @throws ValidationException
     */
    public function storeText(ReaderTrial $trial, int $pageNo, string $text, User $by): ReaderTrialPage
    {
        $this->assertTakes($trial, ReaderTrial::TEXT, $pageNo);

        $text = str_replace(["\r\n", "\r"], "\n", $text);

        if (trim($text) === '' || ! mb_check_encoding($text, 'UTF-8')) {
            throw ValidationException::withMessages(['text' => [__('Paste the text to try.')]]);
        }

        if (mb_strlen($text) > self::MOST_TEXT_CHARS) {
            throw ValidationException::withMessages(['text' => [__('The text may have :max characters at most.', ['max' => self::MOST_TEXT_CHARS])]]);
        }

        return $this->store($trial, ReaderTrial::TEXT, $pageNo, $by, TrialFiles::text($trial, $pageNo), $text, ['text_chars' => mb_strlen($text)]);
    }

    /**
     * Starts a dry run, or goes on with one that stopped. Pages whose call went wrong for a reason
     * that says nothing about the reader (a timeout, an outage, a lost worker) are sent again; pages
     * the model answered, usably or not, are measurements and stay as they are.
     *
     * @throws ReadRefused wrong_state, nothing_stored, or the reason nothing may be sent right now.
     */
    public function start(ReaderTrial $trial, User $by): ReaderTrial
    {
        return DB::transaction(function () use ($trial, $by) {
            $locked = ReaderTrial::query()->lockForUpdate()->findOrFail($trial->id);

            if ($locked->isPurged() || ! in_array($locked->status, [ReaderTrial::DRAFT, ReaderTrial::PAUSED, ReaderTrial::DONE], true)) {
                throw $this->wrongState($locked);
            }

            $pages = ReaderTrialPage::query()->where('reader_trial_id', $locked->id)->lockForUpdate()->get();

            foreach ($pages->filter->mayBeSentAgain() as $page) {
                $page->forceFill(['status' => ReaderTrialPage::WAITING, 'fail_reason' => null, 'error_detail' => null, 'requeues' => 0, 'not_before' => null])->save();
            }

            $waiting = $pages->where('status', ReaderTrialPage::WAITING)->count();

            if ($waiting === 0) {
                throw $locked->status === ReaderTrial::DRAFT
                    ? new ReadRefused(self::NOTHING_STORED, $locked->sendsText() ? __('Paste the text to try first.') : __('Upload at least one page first.'))
                    : $this->wrongState($locked);
            }

            if (($reason = $this->runner->reasonNotToSend()) !== null) {
                throw new ReadRefused($reason, self::pauseWords($reason));
            }

            $locked->forceFill([
                'status' => ReaderTrial::RUNNING,
                'pause_reason' => null,
                'started_at' => $locked->started_at ?? now(),
                'finished_at' => null,
            ])->save();

            PlatformAudit::record('ai.trial_started', $locked, ['pages' => $waiting, 'kind' => $locked->kind, 'purpose' => $locked->purpose, 'model' => $locked->model], $by);

            $this->runner->advance($locked);

            return $locked->refresh();
        });
    }

    /**
     * Marks one cell of an answer, or the whole answer, as wrong or as right again, and counts the
     * trial's score anew.
     *
     * @param  string  $field  A cell's field, or TrialScore::WHOLE_ANSWER.
     * @return array<string, mixed> The score.
     *
     * @throws ReadRefused wrong_state when the answers are already deleted.
     * @throws ValidationException When there is no such page or cell.
     */
    public function mark(ReaderTrial $trial, int $pageNo, ?string $pass, string $row, string $field, bool $wrong): array
    {
        return DB::transaction(function () use ($trial, $pageNo, $pass, $row, $field, $wrong) {
            $locked = ReaderTrial::query()->lockForUpdate()->findOrFail($trial->id);

            if ($locked->isPurged() || $locked->status === ReaderTrial::DISCARDED) {
                throw $this->wrongState($locked);
            }

            $page = ReaderTrialPage::query()->where('reader_trial_id', $locked->id)->where('page_no', $pageNo)
                ->where('pass', $pass ?? $locked->pass ?? 'main')->lockForUpdate()->first();

            if ($page === null || $page->status !== ReaderTrialPage::READ || $page->result === null) {
                throw ValidationException::withMessages(['page_no' => [__('This page has no answer to mark.')]]);
            }

            $whole = $field === TrialScore::WHOLE_ANSWER && $row === '';
            $found = $whole || collect(TrialCells::of($page->result))->contains(fn (array $cell) => $cell['field'] === $field && $cell['row'] === $row);

            if (! $found) {
                throw ValidationException::withMessages(['field' => [__('This answer has no such cell.')]]);
            }

            $key = TrialCells::key($field, $row);
            $marks = array_values(array_diff(array_filter((array) $page->marks, is_string(...)), [$key]));

            if ($wrong) {
                $marks[] = $key;
            }

            $page->forceFill(['marks' => $marks === [] ? null : $marks])->save();

            return TrialScore::refresh($locked);
        });
    }

    /**
     * Where a dry run stands, for the admin's screen. Asking also moves the trial along, so one that
     * is being watched never waits for the scheduler.
     *
     * @return array<string, mixed>
     */
    public function status(ReaderTrial $trial): array
    {
        if ($trial->purged_at === null && $trial->isPurged()) {
            $this->purge($trial);
        }

        $this->runner->tend($trial);

        return $this->body($trial->refresh());
    }

    /** @return array<string, mixed> */
    public function body(ReaderTrial $trial): array
    {
        $pages = ReaderTrialPage::query()->where('reader_trial_id', $trial->id)->get(['page_no', 'pass', 'status', 'image_path', 'text_path']);
        $counts = array_fill_keys([ReaderTrialPage::WAITING, ReaderTrialPage::QUEUED, ReaderTrialPage::RUNNING, ReaderTrialPage::READ, ReaderTrialPage::FAILED, ReaderTrialPage::SKIPPED], 0);

        foreach ($pages as $page) {
            $counts[$page->status] = ($counts[$page->status] ?? 0) + 1;
        }

        return [
            'id' => $trial->id,
            'status' => $trial->status,
            'pause_reason' => $trial->pause_reason,
            'message' => $trial->status === ReaderTrial::PAUSED ? self::pauseWords($trial->pause_reason) : null,
            'pages_total' => (int) $trial->pages_total,
            'stored_pages' => $pages->filter(fn (ReaderTrialPage $page) => filled($page->image_path) || filled($page->text_path))
                ->pluck('page_no')->map(fn ($number) => (int) $number)->unique()->sort()->values()->all(),
            'pages' => $counts,
            'input_tokens' => (int) $trial->input_tokens,
            'output_tokens' => (int) $trial->output_tokens,
            'cost_usd' => (string) $trial->cost_usd,
            'purge_after' => $trial->purge_after?->toIso8601String(),
            'purged' => $trial->purged_at !== null,
            'moving' => $trial->status === ReaderTrial::RUNNING,
        ];
    }

    /**
     * Deletes a dry run's files and what the model returned, now. The row and its pages stay with
     * their counts, tokens and cost, because the month's spending is added up from them; the trial
     * is no longer listed.
     */
    public function discard(ReaderTrial $trial, User $by): void
    {
        $this->purge($trial, $by, discard: true);
    }

    /**
     * Deletes the files and blanks what was read from them. The folder goes first: if the rows cannot
     * be cleared afterwards, the next round finds the same trial again and nothing is left half done.
     *
     * @return bool False when the files could not be deleted, or nothing was left to do.
     */
    public function purge(ReaderTrial $trial, ?User $by = null, bool $discard = false): bool
    {
        if (! TrialFiles::delete($trial)) {
            return false;
        }

        return DB::transaction(function () use ($trial, $by, $discard) {
            $locked = ReaderTrial::query()->lockForUpdate()->find($trial->id);

            if ($locked === null || ($locked->purged_at !== null && (! $discard || $locked->status === ReaderTrial::DISCARDED))) {
                return false;
            }

            $pages = ReaderTrialPage::query()->where('reader_trial_id', $locked->id);
            $pages->clone()->whereIn('status', [ReaderTrialPage::WAITING, ReaderTrialPage::QUEUED])->update(['status' => ReaderTrialPage::SKIPPED, 'not_before' => null]);
            $pages->clone()->update(['image_path' => null, 'text_path' => null, 'result' => null, 'marks' => null, 'correction' => null]);

            $before = $locked->status;

            $locked->forceFill([
                'status' => match (true) {
                    $discard => ReaderTrial::DISCARDED,
                    $locked->isOpen() => ReaderTrial::EXPIRED,
                    default => $locked->status,
                },
                'pause_reason' => null,
                'purged_at' => $locked->purged_at ?? now(),
                'purge_after' => $locked->purge_after->isPast() ? $locked->purge_after : now(),
            ])->save();

            PlatformAudit::record($discard ? 'ai.trial_discarded' : 'ai.trial_purged', $locked, ['status' => $before], $by);

            return true;
        });
    }

    /** Purges every dry run whose 24 hours are over. One that fails is reported and does not stop the others. */
    public function purgeDue(): int
    {
        $purged = 0;

        $due = ReaderTrial::query()->whereNull('purged_at')->where('purge_after', '<=', now())->orderBy('id')->limit(self::MOST_PURGES)->get();

        foreach ($due as $trial) {
            try {
                $purged += $this->purge($trial) ? 1 : 0;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $purged;
    }

    /** Why a dry run stopped, in words for the admin. */
    public static function pauseWords(?string $reason): string
    {
        return match ($reason) {
            ReaderTrial::PAUSE_ALLOWANCE => __('Today\'s allowance for dry runs is used. Raise it under Spending limits, or go on tomorrow.'),
            ReaderTrial::PAUSE_MONTH => __('This month\'s limit for the readers is reached. Nothing more is sent until it is raised or the month ends.'),
            ReaderTrial::PAUSE_PROVIDER_LIMIT => __('The AI service kept answering that it is busy. Press Start again in a few minutes.'),
            ModelGatewayException::SPEND_LIMIT => __('The provider account has reached its spending limit.'),
            ModelGatewayException::BILLING => __('The provider reports a billing problem on the account.'),
            ModelGatewayException::REJECTED_KEY => __('The provider rejected the saved API key.'),
            default => __('The AI service is not connected, or it is refusing every request for now. Check the key and the stop shown on the AI reader page.'),
        };
    }

    /** Why a page of a dry run failed, in words for the admin. */
    public static function failureWords(?string $reason): string
    {
        return match ($reason) {
            PageOutcome::INVALID_OUTPUT => __('The answer did not have the agreed form.'),
            PageOutcome::CUT_OFF => __('The answer was cut off at the output limit.'),
            PageOutcome::DECLINED => __('The model declined to answer.'),
            PageOutcome::TIMEOUT => __('No answer came within the time limit.'),
            PageOutcome::UNAVAILABLE => __('The AI service was not available.'),
            PageOutcome::WORKER_LOST => __('The worker that was sending this page was lost.'),
            TrialRunner::REJECTED => __('The feature\'s own checks refused the answer twice.'),
            TrialRunner::BAD_REQUEST => __('The AI service would not take this request.'),
            TrialRunner::NOTHING_TO_SEND => __('There was nothing to send.'),
            default => __('Something went wrong on our side.'),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> The columns that say what is tried, and under "usual" the figures it normally runs with.
     */
    private function forKind(string $key, array $data): array
    {
        $kind = $this->kinds->find($key);

        if ($kind === null) {
            throw ValidationException::withMessages(['kind' => [__('This reader is not part of this deployment.')]]);
        }

        $pass = (string) ($data['pass'] ?? '');

        if (! in_array($pass, $kind->passes(), true)) {
            throw ValidationException::withMessages(['pass' => [__('Choose one of this reader\'s passes.')]]);
        }

        $material = ($data['material'] ?? ReaderTrial::PICTURES) === ReaderTrial::TEXT ? ReaderTrial::TEXT : ReaderTrial::PICTURES;
        $most = $material === ReaderTrial::TEXT ? self::MOST_TEXTS : (int) $kind->limits()['max_pages'];
        $pages = (int) ($data['pages_total'] ?? 0);

        if ($pages < 1 || $pages > $most) {
            throw ValidationException::withMessages(['pages_total' => [__('Give the number of pages, from 1 to :max.', ['max' => $most])]]);
        }

        return ['kind' => $key, 'pass' => $pass, 'material' => $material, 'pages_total' => $pages, 'usual' => $this->usual(ReaderRequest::figures($key, $pass))];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function forPurpose(string $purpose, array $data): array
    {
        $subject = filled($data['subject_type'] ?? null) ? (string) $data['subject_type'] : null;
        $offered = collect($this->targets()['purposes'])->contains(fn (array $target) => $target['purpose'] === $purpose && $target['subject_type'] === $subject);

        if (! $offered) {
            throw ValidationException::withMessages(['purpose' => [__('This text call cannot be tried on pasted text in this deployment.')]]);
        }

        return ['purpose' => $purpose, 'subject_type' => $subject, 'material' => ReaderTrial::TEXT, 'pages_total' => 1, 'usual' => $this->usual(TextRequest::figures($purpose))];
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return array{model: string, effort: string, max_tokens: int, timeout: int}
     */
    private function usual(array $figures): array
    {
        return ['model' => (string) $figures['model'], 'effort' => (string) $figures['effort'], 'max_tokens' => (int) $figures['max_tokens'], 'timeout' => (int) $figures['timeout']];
    }

    /** A first look, before any work is done on what was sent. The same is checked again under the lock. */
    private function assertTakes(ReaderTrial $trial, string $material, int $pageNo): ?ReadKind
    {
        if ($trial->status !== ReaderTrial::DRAFT || $trial->isPurged() || ($trial->sendsText() ? ReaderTrial::TEXT : ReaderTrial::PICTURES) !== $material) {
            throw $this->wrongState($trial);
        }

        if ($pageNo < 1 || $pageNo > (int) $trial->pages_total) {
            throw ValidationException::withMessages(['page_no' => [__('This dry run has no page :number.', ['number' => $pageNo])]]);
        }

        return $trial->isTextCall() ? null : $this->kinds->get((string) $trial->kind);
    }

    /**
     * Writes the row, then the file. If the file cannot be put, the row points at nothing.
     *
     * @param  array<string, mixed>  $facts  What is recorded about the file: its size and fingerprint, never its content.
     */
    private function store(ReaderTrial $trial, string $material, int $pageNo, User $by, string $path, string $bytes, array $facts): ReaderTrialPage
    {
        $column = $material === ReaderTrial::TEXT ? 'text_path' : 'image_path';

        $stored = DB::transaction(function () use ($trial, $material, $pageNo, $by, $path, $bytes, $facts, $column) {
            $locked = ReaderTrial::query()->lockForUpdate()->findOrFail($trial->id);
            $this->assertTakes($locked, $material, $pageNo);

            $pass = $locked->pass ?? 'main';
            $row = ReaderTrialPage::query()->where('reader_trial_id', $locked->id)->where('page_no', $pageNo)->where('pass', $pass)->lockForUpdate()->first();
            $values = ['status' => ReaderTrialPage::WAITING, 'model' => $locked->model, $column => $path, ...$facts];

            $row === null
                ? $row = ReaderTrialPage::query()->create(['reader_trial_id' => $locked->id, 'page_no' => $pageNo, 'pass' => $pass, ...$values])
                : $row->forceFill($values)->save();

            if (! $this->put($path, $bytes)) {
                $row->forceFill([$column => null, ...array_fill_keys(array_keys($facts), null)])->save();

                return null;
            }

            $locked->touch();

            // Who put which file in, by its size and fingerprint. Never what is in it.
            PlatformAudit::record('ai.trial_page_stored', $row, ['trial_id' => $locked->id, 'page_no' => $pageNo, 'material' => $material, ...$facts], $by);

            return $row;
        });

        if ($stored === null) {
            throw new ReadRefused(ReadRefused::NOT_STORED, __('The page could not be stored. Send it again.'));
        }

        return $stored;
    }

    private function put(string $path, string $bytes): bool
    {
        try {
            return Storage::put($path, $bytes) === true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function wrongState(ReaderTrial $trial): ReadRefused
    {
        return new ReadRefused(ReadRefused::WRONG_STATE, $trial->isPurged()
            ? __('The files of this dry run have been deleted. Start a new one.')
            : __('This cannot be done with the dry run as it is now. Reload the page and try again.'), ['status' => $trial->status]);
    }
}
