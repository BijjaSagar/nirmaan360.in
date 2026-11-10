<?php

namespace App\Jobs\Notify;

use App\Models\Announcement;
use App\Services\Notify\AnnouncementService;
use App\Support\Modules;
use App\Support\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One run of an announcement: works out who it is for and queues a push for each of their phones.
 * A retry is safe, since each run has its own key and nobody is pushed twice under one key.
 */
class SendAnnouncement implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $announcementId, public string $runKey) {}

    public function handle(AnnouncementService $announcements, PlatformSettings $settings): void
    {
        // The Firebase key saved in /admin must reach a worker that started before it was saved.
        $settings->fresh();
        $settings->applyToConfig();
        Modules::apply();

        $announcement = Announcement::find($this->announcementId);

        if ($announcement !== null) {
            $announcements->send($announcement, $this->runKey);
        }
    }
}
