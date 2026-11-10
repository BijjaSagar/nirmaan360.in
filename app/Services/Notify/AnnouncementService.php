<?php

namespace App\Services\Notify;

use App\Jobs\Notify\SendAnnouncement;
use App\Jobs\Notify\SendAnnouncementBatch;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\ContractorPortal\ContractorPortalService;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Broadcasts and reminders pushed to the field app.
 *
 * Platform staff write to every company, one company, some roles or one project's people. A company's
 * own admin writes to their company only. Each one is sent at once, at a set time, or again every day
 * or week as a reminder, through the scheduler (announcements:send-due). Sending goes through the
 * ordinary push channel, so it obeys the person's main push switch, their "Announcements" switch and
 * their quiet hours, and each phone gets the text in the language its app is set to.
 *
 * Everyone it is meant for is remembered in announcement_recipients, with or without a phone, so the
 * app's list (GET /announcements) shows each person what was sent to them and nothing else.
 */
class AnnouncementService
{
    public const TITLE_MAX = 100;

    public const BODY_MAX = 1000;

    /** Kilobytes. Android shows a notification picture up to about 1 MB. */
    public const IMAGE_MAX_KB = 1024;

    public const TRANSLATED = ['mr', 'hi'];

    /** People per push batch job. Kept small so each job ends well inside the queue's retry window. */
    public const BATCH = 200;

    /** Pixels. Wider pictures are scaled down: phones show a notification picture at about this width. */
    public const IMAGE_WIDTH = 1200;

    /** A company's scheduled announcements and running reminders at one time. Platform staff have no limit. */
    public const COMPANY_ACTIVE_MAX = 20;

    /** Announcements a company can write in a day. Platform staff have no limit. */
    public const COMPANY_DAILY_MAX = 20;

    /** Minutes after which a one-off still "sending" with no run recorded is taken to be lost, and is due again. */
    public const STUCK_MINUTES = 15;

    /** Seconds one scheduler pass may spend starting runs before it leaves the rest to the next minute. */
    public const PASS_SECONDS = 45;

    public function __construct(private Notifier $notifier, private PushDispatcher $push, private ContractorPortalService $portal) {}

    /**
     * @param  ?Organization  $company  The company of a company admin's page. Null on the platform admin's page.
     * @return array<string, mixed>
     */
    public function rules(?Organization $company): array
    {
        $time = 'regex:'.QuietHours::TIME;

        return [
            'client_uuid' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:'.self::TITLE_MAX],
            'body' => ['required', 'string', 'max:'.self::BODY_MAX],
            ...collect(self::TRANSLATED)->flatMap(fn (string $locale) => [
                'title_'.$locale => ['nullable', 'string', 'max:'.self::TITLE_MAX],
                'body_'.$locale => ['nullable', 'string', 'max:'.self::BODY_MAX],
            ])->all(),
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:'.self::IMAGE_MAX_KB],
            // Asked every time: only an explicit "no" lets it reach people without money.view.
            'mentions_money' => ['required', Rule::in(['yes', 'no'])],
            'audience' => ['required', Rule::in($company === null ? Announcement::AUDIENCES : ['all', 'roles', 'project'])],
            'organization_id' => $company === null
                ? ['nullable', 'required_if:audience,company', 'integer', Rule::exists('organizations', 'id')]
                : ['prohibited'],
            'roles' => ['nullable', 'required_if:audience,roles', 'array'],
            'roles.*' => ['string', Rule::in(array_keys($this->roleOptions($company)))],
            'project_id' => ['nullable', 'required_if:audience,project', 'integer', $company === null ? Rule::exists('projects', 'id') : org_exists('projects')],
            'schedule' => ['required', Rule::in(Announcement::SCHEDULES)],
            'send_at' => ['nullable', 'required_if:schedule,once', 'date', 'after:now'],
            'repeat_time' => ['nullable', 'required_if:schedule,daily', 'required_if:schedule,weekly', 'string', $time],
            'repeat_weekday' => ['nullable', 'required_if:schedule,weekly', 'integer', 'between:1,7'],
            'repeat_until' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    /**
     * The roles that can be picked: the built-in ones, and on a company's page its own roles too.
     *
     * @return array<string, string> Role key => label.
     */
    public function roleOptions(?Organization $company): array
    {
        $options = collect(config('buildos.roles'))->map(fn (array $role) => __($role['label']))->all();

        if ($company !== null) {
            foreach (CustomRole::withoutGlobalScopes()->where('organization_id', $company->id)->orderBy('name')->get() as $role) {
                $options[CustomRole::keyFor($role->id)] = $role->name;
            }
        }

        return $options;
    }

    /**
     * Saves a new announcement and, for "now", starts sending it. The same client_uuid twice
     * returns the first one, so a double tap or a resent form sends nothing more.
     *
     * @param  array<string, mixed>  $data  Validated with rules().
     */
    public function create(User $by, array $data, ?UploadedFile $image, ?Organization $company): Announcement
    {
        $existing = Announcement::where('client_uuid', $data['client_uuid'])->first();

        if ($existing !== null) {
            if ($existing->organization_id !== $company?->id) {
                throw ValidationException::withMessages(['client_uuid' => __('This form was already used. Reload the page and try again.')]);
            }

            return $existing;
        }

        $schedule = $data['schedule'];
        $next = match ($schedule) {
            'now' => null,
            'once' => Carbon::parse($data['send_at'], config('app.timezone')),
            default => $this->nextOccurrence($schedule, $data['repeat_time'], $data['repeat_weekday'] ?? null, $data['repeat_until'] ?? null, now()),
        };

        if (in_array($schedule, ['daily', 'weekly'], true) && $next === null) {
            throw ValidationException::withMessages(['repeat_until' => __('The last day comes before the first reminder. Choose a later last day.')]);
        }

        $translations = $this->translations($data);
        // The writer can say so for a figure the words do not show, such as one in the picture.
        // The writer's answer, unless the words plainly show money: then a "no" is not taken.
        $mentionsMoney = $data['mentions_money'] === 'yes' || MoneyMention::inAnnouncement((string) $data['title'], (string) $data['body'], $translations ?? []);

        $path = $image === null ? null : $this->storeImage($image);

        try {
            // One company's limits are checked and counted under a lock, so two posts at once cannot both slip in.
            $announcement = $company === null
                ? $this->save($by, $data, null, $schedule, $next, $path, $translations, $mentionsMoney)
                : Cache::lock('announcements:create:'.$company->id, 10)->block(5, function () use ($by, $data, $company, $schedule, $next, $path, $translations, $mentionsMoney) {
                    // The same form sent twice at once: the second finds the first here.
                    if ($same = Announcement::where('client_uuid', $data['client_uuid'])->where('organization_id', $company->id)->first()) {
                        return $same;
                    }

                    $this->withinCompanyLimits($company);
                    $announcement = $this->save($by, $data, $company, $schedule, $next, $path, $translations, $mentionsMoney);
                    RateLimiter::hit($this->dailyKey($company), $this->secondsLeftToday());

                    return $announcement;
                });
        } catch (Throwable $exception) {
            // Only when nothing was saved: a saved announcement keeps its picture.
            if ($path !== null) {
                Storage::delete($path);
            }

            // Two at once on the platform page: the database kept one, which is the answer to both.
            if ($exception instanceof UniqueConstraintViolationException
                && ($same = Announcement::where('client_uuid', $data['client_uuid'])->first()) !== null
                && $same->organization_id === $company?->id) {
                return $same;
            }

            if ($exception instanceof LockTimeoutException) {
                throw ValidationException::withMessages(['title' => __('Someone else in your company is sending an announcement right now. Try again in a moment.')]);
            }

            throw $exception;
        }

        // The same form sent twice: this request's copy of the picture is not the one kept.
        if ($path !== null && $announcement->image_path !== $path) {
            Storage::delete($path);
        }

        if ($schedule === 'now') {
            $this->claim($announcement->refresh());
        }

        return $announcement->refresh();
    }

    /** @param  array<string, mixed>  $data */
    private function save(User $by, array $data, ?Organization $company, string $schedule, ?Carbon $next, ?string $path, ?array $translations, bool $mentionsMoney): Announcement
    {
        return DB::transaction(function () use ($by, $data, $company, $schedule, $next, $path, $translations, $mentionsMoney) {
            $announcement = Announcement::create([
                'organization_id' => $company?->id,
                'created_by' => $by->id,
                'client_uuid' => $data['client_uuid'],
                'title' => trim($data['title']),
                'body' => trim($data['body']),
                'translations' => $translations,
                'mentions_money' => $mentionsMoney,
                'image_path' => $path,
                'image_token' => $path === null ? null : Str::random(40),
                'audience' => $data['audience'],
                'target_organization_id' => $company?->id ?? $this->targetCompany($data),
                'roles' => $data['audience'] === 'roles' ? array_values(array_unique($data['roles'])) : null,
                'project_id' => $data['audience'] === 'project' ? (int) $data['project_id'] : null,
                'schedule' => $schedule,
                'repeat_time' => in_array($schedule, ['daily', 'weekly'], true) ? $data['repeat_time'] : null,
                'repeat_weekday' => $schedule === 'weekly' ? (int) $data['repeat_weekday'] : null,
                'repeat_until' => in_array($schedule, ['daily', 'weekly'], true) ? ($data['repeat_until'] ?? null) : null,
                'scheduled_for' => $next ?? now(),
                // "Now" is due at once and goes through the same claim as a scheduled one, so if it cannot be
                // queued this instant the scheduler picks it up within a minute instead of it being lost.
                'next_run_at' => $next ?? now(),
                'status' => Announcement::SCHEDULED,
            ]);

            // The words are in the row itself. The log keeps who, to whom and when.
            AuditLog::record('announcement.created', $announcement, null, [
                'title' => $announcement->title,
                'audience' => $this->describeAudience($announcement),
                'schedule' => $schedule,
                'next_run_at' => $next?->toIso8601String(),
                'image' => $path !== null,
                'mentions_money' => $mentionsMoney,
            ]);

            return $announcement;
        });
    }

    /** @throws ValidationException when the company already has too many waiting, or wrote too many today. */
    private function withinCompanyLimits(Organization $company): void
    {
        $active = Announcement::forCompany($company->id)->where('status', Announcement::SCHEDULED)->count();

        if ($active >= self::COMPANY_ACTIVE_MAX) {
            throw ValidationException::withMessages(['schedule' => __('Your company already has :count announcements and reminders waiting. Stop one before adding another.', ['count' => self::COMPANY_ACTIVE_MAX])]);
        }

        if (RateLimiter::attempts($this->dailyKey($company)) >= self::COMPANY_DAILY_MAX) {
            throw ValidationException::withMessages(['title' => __('Your company has written :count announcements today, the most for one day. Try again tomorrow.', ['count' => self::COMPANY_DAILY_MAX])]);
        }
    }

    /** One count per company and India calendar day, which is what "today" in the message means. */
    private function dailyKey(Organization $company): string
    {
        return 'announcements:company:'.$company->id.':'.now(QuietHours::timezone())->toDateString();
    }

    private function secondsLeftToday(): int
    {
        return max(60, (int) now(QuietHours::timezone())->diffInSeconds(now(QuietHours::timezone())->endOfDay()) + 60);
    }

    /**
     * Stops it: nothing more is sent, and copies still waiting for people's quiet hours are dropped
     * when their time comes (PushDispatcher checks before each one). Phones that already showed it
     * keep it, and it stays in the app's list for the people it was sent to.
     */
    public function cancel(Announcement $announcement, User $by): void
    {
        $was = $announcement->status;

        // Only from a state that can be stopped, so a one-off that failed meanwhile is not marked stopped.
        $stopped = $announcement->canBeCancelled() && Announcement::whereKey($announcement->id)
            ->whereIn('status', [Announcement::SCHEDULED, ...Announcement::STOPPABLE_AFTER_SENDING])->update([
                'status' => Announcement::CANCELLED,
                'next_run_at' => null,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                // Addresses already handed out for the picture stop working. The app's list asks for a new one.
                'image_token' => $announcement->image_token === null ? null : Str::random(40),
                'updated_at' => now(),
            ]) === 1;

        if (! $stopped) {
            throw ValidationException::withMessages(['announcement' => __('This can no longer be stopped. Reload the page to see where it stands.')]);
        }

        AuditLog::record('announcement.cancelled', $announcement->refresh(), ['status' => $was], ['status' => Announcement::CANCELLED]);
    }

    /**
     * Sends a failed or partly sent one-off again, under its own run: everyone already pushed is
     * passed over, so only the people it did not reach get it.
     */
    public function retry(Announcement $announcement, User $by): void
    {
        $was = $announcement->status;

        $token = (string) Str::uuid();
        $before = $announcement->only(['status', 'run_token', 'started_by', 'run_started_at']);
        $again = $announcement->canBeRetried() && Announcement::whereKey($announcement->id)->where('status', $was)
            ->update(['status' => Announcement::SENDING, 'run_token' => $token, 'started_by' => null, 'run_started_at' => null, 'updated_at' => now()]) === 1;

        if (! $again) {
            throw ValidationException::withMessages(['announcement' => __('Only a failed or partly sent announcement can be sent again.')]);
        }

        AuditLog::record('announcement.retried', $announcement->refresh(), ['status' => $was], ['status' => Announcement::SENDING]);

        try {
            SendAnnouncement::dispatch($announcement->id, $announcement->last_run_key ?? 'once', $token);
        } catch (Throwable $exception) {
            // Back exactly as it was, so its Send again window and run are unchanged.
            Announcement::whereKey($announcement->id)->where('status', Announcement::SENDING)->where('run_token', $token)->update($before);

            throw $exception;
        }
    }

    /**
     * Starts every announcement whose time has come, the oldest first, until none is left or the
     * pass has run PASS_SECONDS, so one company's backlog cannot hold the others back for long.
     * A one-off lost between claim and run (still "sending", no run recorded) is made due again.
     *
     * @return int Runs started.
     */
    public function dispatchDue(): int
    {
        $this->requeueLost();

        $started = 0;
        $tried = [];
        $until = microtime(true) + self::PASS_SECONDS;

        do {
            $due = Announcement::due()->whereNotIn('id', $tried ?: [0])->orderBy('next_run_at')->orderBy('id')->limit(50)->get();

            foreach ($due as $announcement) {
                $tried[] = $announcement->id;
                $started += $this->claim($announcement) ? 1 : 0;
            }
        } while ($due->isNotEmpty() && microtime(true) < $until);

        return $started;
    }

    /**
     * A run queued but not started after STUCK_MINUTES (a one-off, or a reminder's last run) is queued
     * once more under the same run. The first copy to start takes it, the other does nothing, so a job
     * that was lost is replaced and one that is only waiting in a long queue keeps its place.
     */
    private function requeueLost(): void
    {
        $lost = Announcement::where('status', Announcement::SENDING)
            ->whereNull('started_by')
            ->whereNotNull('run_token')
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->limit(50)
            ->get(['id', 'run_token', 'last_run_key', 'updated_at']);

        foreach ($lost as $announcement) {
            // Touched first, so the next pass waits another STUCK_MINUTES before queueing yet another copy.
            $taken = Announcement::whereKey($announcement->id)->where('status', Announcement::SENDING)->whereNull('started_by')
                ->where('updated_at', $announcement->updated_at)->update(['updated_at' => now()]);

            if ($taken === 1) {
                try {
                    SendAnnouncement::dispatch($announcement->id, $announcement->last_run_key ?? 'once', $announcement->run_token);
                } catch (Throwable $exception) {
                    Log::warning('Announcement run not queued again', ['announcement_id' => $announcement->id, 'error' => $exception->getMessage()]);
                }
            }
        }
    }

    /**
     * Claims the run that is due by moving its next time on in the same statement, so two
     * schedulers, or a slow run, never start the same run twice, and queues it with a fresh
     * token. Not queued means due again: the next minute tries once more.
     */
    private function claim(Announcement $announcement): bool
    {
        $runAt = $announcement->next_run_at;

        if ($announcement->status !== Announcement::SCHEDULED || $runAt === null) {
            return false;
        }

        // A reminder late because the scheduler was down goes once, then keeps its usual time.
        $next = $announcement->repeats() ? $this->nextFor($announcement, $runAt->max(now())) : null;

        $token = (string) Str::uuid();
        $runKey = $this->runKey($announcement, $runAt);
        $claimed = Announcement::whereKey($announcement->id)
            ->where('status', Announcement::SCHEDULED)
            ->where('next_run_at', $runAt)
            ->update([
                'next_run_at' => $next,
                'status' => $next === null ? Announcement::SENDING : Announcement::SCHEDULED,
                'run_token' => $token,
                'started_by' => null,
                'run_started_at' => null,
                'last_run_key' => $runKey,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return false;
        }

        try {
            SendAnnouncement::dispatch($announcement->id, $runKey, $token);

            return true;
        } catch (Throwable $exception) {
            // Put back only the claim this call made: a Stop since then stays stopped.
            Announcement::whereKey($announcement->id)->where('run_token', $token)->whereIn('status', [Announcement::SENDING, Announcement::SCHEDULED])
                ->update(['next_run_at' => $runAt, 'status' => Announcement::SCHEDULED, 'run_token' => null]);
            Log::warning('Announcement run not queued', ['announcement_id' => $announcement->id, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * One run, on the queue: remembers everyone it is meant for, so their app lists it, and hands
     * their phones to batch jobs of BATCH people each. Each job is short, well inside the queue's
     * retry window, and a retried run or batch pushes nobody twice: each person is claimed once
     * under the run's key.
     *
     * @return array{recipients: int, batches: int}
     */
    /**
     * Whether it mentions money is settled when it is written, but the detector grows. A reminder or a
     * send-again saved earlier is read again before each run, and can only ever move to "mentions money".
     */
    private function recheckMoney(Announcement $announcement): void
    {
        if ($announcement->mentions_money || ! MoneyMention::inAnnouncement((string) $announcement->title, (string) $announcement->body, $announcement->translations ?? [])) {
            return;
        }

        Announcement::whereKey($announcement->id)->update(['mentions_money' => true]);
        $announcement->mentions_money = true;
    }

    public function startRun(Announcement $announcement, string $runKey, ?string $token, string $copy): array
    {
        // The first copy of the run's job to get here takes the run. Another copy (queued again by the
        // sweep), a job from an older run, or one for a run that was stopped or has failed does nothing.
        // The same copy, retried by the queue, carries on the run it began.
        $taken = Announcement::whereKey($announcement->id)
            ->where('run_token', $token)
            ->whereIn('status', [Announcement::SCHEDULED, Announcement::SENDING])
            ->whereNull('started_by')
            ->update(['started_by' => $copy, 'run_started_at' => now(), 'last_run_key' => $runKey]) === 1;
        $mine = $taken || Announcement::whereKey($announcement->id)->where('run_token', $token)->where('started_by', $copy)
            ->whereIn('status', [Announcement::SCHEDULED, Announcement::SENDING])->exists();

        if (! $mine) {
            return ['recipients' => 0, 'batches' => 0];
        }

        $this->recheckMoney($announcement);

        $now = now();
        $connected = $this->notifier->connected('push');
        $recipients = 0;
        $batches = 0;

        foreach ($this->audience($announcement)->chunk(self::BATCH) as $people) {
            $people = $people->values()->all();
            $this->remember($announcement, $people, $now);
            $recipients += count($people);

            if ($connected) {
                SendAnnouncementBatch::dispatch($announcement->id, $runKey, array_map(fn (User $user) => $user->id, $people));
                // Counted as each goes, so a run that fails halfway is known to have reached some people.
                Announcement::whereKey($announcement->id)->increment('batches_count');
                Announcement::whereKey($announcement->id)->whereNull('first_batch_at')->update(['first_batch_at' => now()]);
                $batches++;
            }
        }

        Announcement::whereKey($announcement->id)->incrementEach(['runs' => 1], [
            'last_sent_at' => $now,
            // People, not sends: a reminder that reaches the same team every day still counts each person once.
            'recipients_count' => AnnouncementRecipient::withoutGlobalScopes()->where('announcement_id', $announcement->id)->count(),
        ]);
        Announcement::whereKey($announcement->id)->where('status', Announcement::SENDING)->update(['status' => Announcement::SENT]);

        AuditLog::record('announcement.sent', $announcement, null, [
            'run' => $runKey,
            'recipients' => $recipients,
            'batches' => $batches,
            'push_connected' => $connected,
        ]);

        return ['recipients' => $recipients, 'batches' => $batches];
    }

    /**
     * Queues the push for one batch of a run. People who left, were switched off or lost the
     * permission since the run started are passed over by the usual push rules.
     *
     * @param  list<int>  $userIds
     * @return int Phones a push was queued for.
     */
    public function sendBatch(Announcement $announcement, string $runKey, array $userIds): int
    {
        if ($announcement->status === Announcement::CANCELLED || ! $this->notifier->connected('push')) {
            return 0;
        }

        $phones = 0;
        $missed = [];
        $error = null;

        foreach (User::query()->whereKey($userIds)->where('is_active', true)->orderBy('id')->get() as $user) {
            try {
                $queued = $this->pushTo($announcement, $user, $runKey);
            } catch (Throwable $exception) {
                // One person's phone must not stop everyone else's. They are tried again with the batch below:
                // PushDispatcher::queue gives up their claim on the run when it could not queue them.
                $missed[] = $user->id;
                $error = $exception;

                continue;
            }

            // Counted person by person, so a batch that fails later still counts what it queued.
            if ($queued > 0) {
                Announcement::whereKey($announcement->id)->increment('phones_count', $queued);
                $phones += $queued;
            }
        }

        if ($missed !== []) {
            // The queue tries the batch again; whoever was already queued is passed over by their claim.
            throw new RuntimeException('Announcement '.$announcement->id.' not queued for '.count($missed).' people: '.$error?->getMessage(), 0, $error);
        }

        return $phones;
    }

    private function dedupeKey(Announcement $announcement, string $runKey): string
    {
        return 'announcement:'.$announcement->id.':'.$runKey;
    }

    /**
     * A batch that still missed people after its retries. A one-off becomes partly sent, so it can be
     * sent again to the people it missed; a reminder's next run comes anyway.
     */
    public function batchFailed(int $announcementId, string $runKey, ?Throwable $exception): void
    {
        $announcement = Announcement::find($announcementId);

        if ($announcement === null) {
            return;
        }

        if (! $announcement->repeats()) {
            Announcement::whereKey($announcementId)->whereIn('status', [Announcement::SENDING, Announcement::SENT])->update(['status' => Announcement::PARTIAL]);
        }

        AuditLog::record('announcement.batch_failed', $announcement, null, ['run' => $runKey, 'error' => Str::limit((string) $exception?->getMessage(), 200)]);
    }

    /**
     * A run that could not finish after its retries. A one-off that queued nobody is marked failed,
     * one that reached some people partly sent; either can be sent again from the history. A reminder
     * keeps its next time.
     */
    public function runFailed(int $announcementId, string $runKey, ?Throwable $exception, ?string $token, string $copy): void
    {
        $announcement = Announcement::find($announcementId);

        // A job from an older run, or a copy whose run another copy took, has nothing to report.
        if ($announcement === null || $announcement->run_token !== $token || ! in_array($announcement->started_by, [null, $copy], true)) {
            return;
        }

        Announcement::whereKey($announcementId)->where('status', Announcement::SENDING)->where('run_token', $token)
            ->update(['status' => $announcement->batches_count > 0 ? Announcement::PARTIAL : Announcement::FAILED]);

        AuditLog::record('announcement.failed', $announcement, null, ['run' => $runKey, 'error' => Str::limit((string) $exception?->getMessage(), 200)]);
    }

    /**
     * Everyone the announcement is meant for, as it stands now: active logins, in the company
     * it names when it names one. Read without any tenant scope, so a platform announcement
     * reaches every company even when it is sent from the admin's own signed-in request.
     *
     * @return LazyCollection<int, User>
     */
    public function audience(Announcement $announcement): LazyCollection
    {
        $company = $announcement->organization_id ?? $announcement->target_organization_id;
        $project = $announcement->project_id === null ? null : Project::withoutGlobalScopes()->find($announcement->project_id);

        if ($announcement->audience === 'project' && $project === null) {
            return LazyCollection::empty();
        }

        return User::query()
            ->where('is_active', true)
            ->whereHas('organization')
            ->when($company, fn ($query, $id) => $query->where('organization_id', $id))
            ->when($project, fn ($query, Project $project) => $query->where('organization_id', $project->organization_id))
            ->lazyById(200)
            // The money rule holds here too: nobody without money.view is sent, or shown in the app, a rupee figure.
            ->filter(fn (User $user) => ! $announcement->mentions_money || Money::visible($user))
            // A contractor login counts only while its company has the portal and it still leads to a subcontractor, as for its other pushes.
            ->filter(fn (User $user) => ! $user->isContractor() || ($this->hasPortal((int) $user->organization_id) && $this->portal->partyFor($user) !== null))
            ->filter(fn (User $user) => match ($announcement->audience) {
                // A contractor login is reached only when the contractor role is ticked.
                'roles' => in_array($user->roleKey(), $announcement->roles ?? [], true),
                // The people who can open the project: assigned to it, or seeing every project.
                'project' => ! $user->isContractor() && $user->hasPermission('project.view') && Audience::seesProject($user, $project->id),
                // "Everyone" is the team: contractor logins are outside people.
                default => ! $user->isContractor(),
            });
    }

    /** @var array<int, bool> */
    private array $portals = [];

    private function hasPortal(int $organizationId): bool
    {
        return $this->portals[$organizationId] ??= (bool) Organization::find($organizationId)?->allowsFeature('contractor_portal');
    }

    /**
     * What one person's app lists: the announcements sent to them, the latest first.
     */
    public function forUser(User $user, string $locale): LengthAwarePaginator
    {
        return AnnouncementRecipient::query()
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->with('announcement.organization:id,name')
            // Checked again when read: someone who has lost money.view no longer sees a rupee figure, and a
            // contractor login sees only what was written for contractors.
            ->whereHas('announcement', fn ($query) => $query
                ->when(! Money::visible($user), fn ($query) => $query->where('mentions_money', false))
                ->when($user->isContractor(), fn ($query) => $query->where('audience', 'roles')->whereJsonContains('roles', User::CONTRACTOR_ROLE)))
            ->orderByDesc('last_sent_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AnnouncementRecipient $row) => $this->present($row->announcement, $row, $locale));
    }

    /** @return array<string, mixed> */
    public function present(Announcement $announcement, AnnouncementRecipient $row, string $locale): array
    {
        return [
            'id' => $announcement->id,
            'title' => $announcement->titleIn($locale),
            'body' => $announcement->bodyIn($locale),
            'image_url' => $announcement->imageUrl(),
            'from' => $announcement->isPlatform() ? (string) config('buildos.brand') : (string) $announcement->organization?->name,
            'repeats' => $announcement->repeats(),
            'sent_at' => $row->last_sent_at->toIso8601String(),
            'first_sent_at' => $row->first_sent_at->toIso8601String(),
        ];
    }

    /** Who an announcement goes to, in words for the history list. */
    public function describeAudience(Announcement $announcement): string
    {
        $company = $announcement->targetOrganization?->name;

        $who = match ($announcement->audience) {
            'roles' => __('Roles: :roles', ['roles' => collect($announcement->roles ?? [])->map(fn (string $key) => $this->roleLabel($key, $announcement))->implode(', ')]),
            'project' => __('People on :project', ['project' => $announcement->project?->name ?? __('a removed project')]),
            default => __('Everyone'),
        };

        if ($announcement->isPlatform()) {
            return $company === null ? __(':who, every company', ['who' => $who]) : __(':who at :company', ['who' => $who, 'company' => $company]);
        }

        return $who;
    }

    /** When it goes, in words for the history list. */
    public function describeTiming(Announcement $announcement): string
    {
        $until = $announcement->repeat_until === null ? '' : ' '.__('until :date', ['date' => $announcement->repeat_until->format('d M Y')]);

        return match ($announcement->schedule) {
            'now' => __('Sent at once'),
            'once' => __('Once, on :date', ['date' => ($announcement->scheduled_for ?? $announcement->created_at)->format('d M Y, H:i')]),
            'daily' => __('Every day at :time', ['time' => $announcement->repeat_time]).$until,
            'weekly' => __('Every :day at :time', ['day' => $this->weekdayName((int) $announcement->repeat_weekday), 'time' => $announcement->repeat_time]).$until,
            default => $announcement->schedule,
        };
    }

    /** @return array<int, string> ISO weekday (1 is Monday) => name. */
    public function weekdays(): array
    {
        return collect(range(1, 7))->mapWithKeys(fn (int $day) => [$day => $this->weekdayName($day)])->all();
    }

    /**
     * The next time a daily or weekly reminder goes after $after, on the Asia/Kolkata clock.
     * Null once that would be after the last day.
     */
    public function nextOccurrence(string $schedule, string $time, int|string|null $weekday, CarbonInterface|string|null $until, CarbonInterface $after): ?Carbon
    {
        $zone = QuietHours::timezone();
        $local = Carbon::instance($after)->setTimezone($zone);
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $candidate = $local->copy()->setTime($hour, $minute);

        while ($candidate->lessThanOrEqualTo($local) || ($schedule === 'weekly' && $candidate->dayOfWeekIso !== (int) $weekday)) {
            $candidate->addDay();
        }

        $lastDay = $until instanceof CarbonInterface ? $until->toDateString() : ($until === null ? null : Carbon::parse($until, $zone)->toDateString());

        if ($lastDay !== null && $candidate->toDateString() > $lastDay) {
            return null;
        }

        return $candidate->setTimezone(config('app.timezone'));
    }

    private function nextFor(Announcement $announcement, CarbonInterface $after): ?Carbon
    {
        return $this->nextOccurrence($announcement->schedule, (string) $announcement->repeat_time, $announcement->repeat_weekday, $announcement->repeat_until, $after);
    }

    /** One key per run, so a person is pushed each reminder once however often the run is retried. */
    private function runKey(Announcement $announcement, CarbonInterface $runAt): string
    {
        return $announcement->repeats() ? Carbon::instance($runAt)->setTimezone(QuietHours::timezone())->toDateString() : 'once';
    }

    /** @return int Phones a push was queued for. */
    private function pushTo(Announcement $announcement, User $user, string $runKey): int
    {
        $preference = NotificationPreference::for($user);

        if (! $user->is_active || ! $preference->allows('push')) {
            return 0;
        }

        // A picture of a message with money goes out on an address that lasts as long as Firebase keeps its copy,
        // counted from the end of the longest quiet hours the push can be held for, and not much longer.
        $image = $announcement->imageUrl((int) config('modules.notify.push.ttl_hours', 48) + 24 + 1);

        return $this->push->queue($user, fn (string $locale) => new NotificationMessage(
            event: $announcement->repeats() ? 'announcement.reminder' : 'announcement.sent',
            title: $announcement->titleIn($locale),
            body: $announcement->bodyIn($locale),
            push: new PushTarget(
                category: PushCategory::ANNOUNCEMENTS,
                type: 'announcement',
                id: $announcement->id,
                organizationId: (int) $user->organization_id,
                projectId: $announcement->audience === 'project' ? $announcement->project_id : null,
                permissions: match (true) {
                    $user->isContractor() => ['contractor_portal.view'],
                    $announcement->audience === 'project' => ['project.view'],
                    default => [],
                },
                dedupeKey: $this->dedupeKey($announcement, $runKey),
                extra: ['repeats' => $announcement->repeats() ? '1' : '0'],
            ),
            image: $image,
        ), $preference);
    }

    /** @param  list<User>  $people */
    private function remember(Announcement $announcement, array $people, CarbonInterface $now): void
    {
        if ($people === []) {
            return;
        }

        AnnouncementRecipient::withoutGlobalScopes()->upsert(
            array_map(fn (User $user) => [
                'organization_id' => $user->organization_id,
                'announcement_id' => $announcement->id,
                'user_id' => $user->id,
                'first_sent_at' => $now,
                'last_sent_at' => $now,
            ], $people),
            ['announcement_id', 'user_id'],
            ['last_sent_at'],
        );
    }

    /**
     * Keeps the picture as a JPEG redrawn from its pixels, at most IMAGE_WIDTH wide. What the camera
     * wrote into the file, such as where it was taken, does not travel with it to every phone, and the
     * file stays small enough for Android's notification picture.
     */
    private function storeImage(UploadedFile $image): string
    {
        $bytes = (string) file_get_contents($image->getRealPath());

        if (! function_exists('imagecreatefromstring')) {
            // Without GD the file is kept as sent. Laravel Cloud's PHP has GD.
            return $image->store('announcements');
        }

        $picture = @imagecreatefromstring($bytes);

        if ($picture === false) {
            throw ValidationException::withMessages(['image' => __('This picture could not be read. Use a JPG or PNG file.')]);
        }

        $picture = $this->upright($picture, $bytes);
        [$width, $height] = [imagesx($picture), imagesy($picture)];
        $scale = min(1, self::IMAGE_WIDTH / $width);
        [$toWidth, $toHeight] = [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];

        // Onto white: JPEG has no transparency, and phones would show a transparent PNG's background black.
        $canvas = imagecreatetruecolor($toWidth, $toHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $picture, 0, 0, 0, 0, $toWidth, $toHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, 85);
        $jpeg = (string) ob_get_clean();

        $path = 'announcements/'.Str::random(40).'.jpg';
        Storage::put($path, $jpeg);

        return $path;
    }

    /** A phone photo can be stored sideways with a note to turn it. The note is dropped on redrawing, so turn it here. */
    private function upright(\GdImage $picture, string $bytes): \GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($bytes, "\xFF\xD8")) {
            return $picture;
        }

        $orientation = (int) (@exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes))['Orientation'] ?? 1);

        return match ($orientation) {
            3 => imagerotate($picture, 180, 0),
            6 => imagerotate($picture, -90, 0),
            8 => imagerotate($picture, 90, 0),
            default => $picture,
        };
    }

    /** @param  array<string, mixed>  $data */
    private function translations(array $data): ?array
    {
        $translations = [];

        foreach (self::TRANSLATED as $locale) {
            $text = array_filter([
                'title' => trim((string) ($data['title_'.$locale] ?? '')),
                'body' => trim((string) ($data['body_'.$locale] ?? '')),
            ], fn (string $value) => $value !== '');

            if ($text !== []) {
                $translations[$locale] = $text;
            }
        }

        return $translations === [] ? null : $translations;
    }

    /** The one company a platform announcement is limited to, if any. */
    private function targetCompany(array $data): ?int
    {
        return match ($data['audience']) {
            'company', 'roles' => isset($data['organization_id']) ? (int) $data['organization_id'] : null,
            'project' => (int) Project::withoutGlobalScopes()->whereKey($data['project_id'])->value('organization_id'),
            default => null,
        };
    }

    private function roleLabel(string $key, Announcement $announcement): string
    {
        if (str_starts_with($key, CustomRole::KEY_PREFIX)) {
            return (string) (CustomRole::withoutGlobalScopes()->whereKey((int) substr($key, strlen(CustomRole::KEY_PREFIX)))->where('organization_id', $announcement->organization_id)->value('name') ?? __('Removed role'));
        }

        return __((string) config("buildos.roles.{$key}.label", $key));
    }

    private function weekdayName(int $day): string
    {
        return __(match ($day) {
            1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', default => 'Sunday',
        });
    }
}
