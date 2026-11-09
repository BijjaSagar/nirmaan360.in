<?php

namespace App\Jobs\Notify;

use App\Models\User;
use App\Services\Notify\NotificationMessage;
use App\Services\Notify\Notifier;
use App\Services\Notify\PermanentDeliveryFailure;
use App\Support\Modules;
use App\Support\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public int $userId,
        public string $channel,
        public NotificationMessage $message,
        public ?int $deviceId = null,
    ) {}

    public function handle(Notifier $notifier, PlatformSettings $settings): void
    {
        $this->reloadSettings($settings);

        $user = User::find($this->userId);

        if (! $user || ! $user->is_active) {
            return;
        }

        try {
            $notifier->deliver($user, $this->channel, $this->message, $this->deviceId);
        } catch (PermanentDeliveryFailure $exception) {
            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(Notifier::class)->recordFailure($this->userId, $this->channel, $this->message->event, $exception);
    }

    /** Keys and mail settings saved in /admin must reach a worker that started before they were saved. */
    private function reloadSettings(PlatformSettings $settings): void
    {
        $settings->fresh();
        $settings->applyToConfig();
        Modules::apply();
        Mail::purge();
    }
}
