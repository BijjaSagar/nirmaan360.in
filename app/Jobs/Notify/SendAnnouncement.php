<?php

namespace App\Jobs\Notify;

use App\Models\Announcement;
use App\Services\Notify\AnnouncementService;
use App\Support\Modules;
use App\Support\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * One run of an announcement: works out who it is for, remembers them, and hands their phones to
 * SendAnnouncementBatch jobs. It only reads people and queues, so it ends well inside the queue's
 * 90 second retry window even for every company. A retry is safe: nobody is pushed twice under one run's key.
 */
class SendAnnouncement implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 75;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** This queued copy of the run's job. Kept across the queue's retries; a copy queued again by the sweep has its own. */
    public string $copy;

    public function __construct(public int $announcementId, public string $runKey, public ?string $token = null)
    {
        $this->copy = (string) Str::uuid();
    }

    public function handle(AnnouncementService $announcements, PlatformSettings $settings): void
    {
        // The Firebase key saved in /admin must reach a worker that started before it was saved.
        $settings->fresh();
        $settings->applyToConfig();
        Modules::apply();

        $announcement = Announcement::find($this->announcementId);

        if ($announcement !== null) {
            $announcements->startRun($announcement, $this->runKey, $this->token, $this->copy);
        }
    }

    /** After the last retry: a one-off is marked failed in the history instead of staying "sending". */
    public function failed(?Throwable $exception): void
    {
        app(AnnouncementService::class)->runFailed($this->announcementId, $this->runKey, $exception, $this->token, $this->copy);
    }
}
