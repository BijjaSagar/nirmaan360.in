<?php

namespace App\Jobs\Notify;

use App\Models\Announcement;
use App\Services\Notify\AnnouncementService;
use App\Support\Modules;
use App\Support\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Queues the push for one batch of an announcement run (AnnouncementService::BATCH people).
 * Short by design, so it ends well inside the queue's 90 second retry window. A retry pushes
 * nobody twice: each person is claimed once under the run's key.
 */
class SendAnnouncementBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 75;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** @param  list<int>  $userIds */
    public function __construct(public int $announcementId, public string $runKey, public array $userIds) {}

    public function handle(AnnouncementService $announcements, PlatformSettings $settings): void
    {
        // The Firebase key saved in /admin must reach a worker that started before it was saved.
        $settings->fresh();
        $settings->applyToConfig();
        Modules::apply();

        $announcement = Announcement::find($this->announcementId);

        if ($announcement !== null) {
            $announcements->sendBatch($announcement, $this->runKey, $this->userIds);
        }
    }

    /** After the last retry: a one-off is marked partly sent, so it can be sent again to the people it missed. */
    public function failed(?Throwable $exception): void
    {
        app(AnnouncementService::class)->batchFailed($this->announcementId, $this->runKey, $exception);
    }
}
