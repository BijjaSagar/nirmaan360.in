<?php

namespace App\Services\Notify;

use App\Jobs\Notify\DeliverNotification;
use App\Mail\Notify\WorkflowNotificationMail;
use App\Models\DeviceToken;
use App\Models\NotificationFailure;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\ApprovalUpdate;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sends one update down every channel a person has chosen. In-app is written at once
 * and the rest go through the queue, one job per recipient and device, so a retry
 * never repeats a message that already arrived.
 *
 * Nothing here may ever break the action that caused the notification.
 */
class Notifier
{
    public const CHANNELS = ['email', 'push', 'whatsapp'];

    public function __construct(private FcmGateway $fcm, private WhatsAppGateway $whatsapp) {}

    public function connected(string $channel): bool
    {
        return match ($channel) {
            'email' => config('mail.default') !== 'log',
            'push' => $this->fcm->isLive(),
            'whatsapp' => $this->whatsapp->isLive(),
            default => false,
        };
    }

    /**
     * Runs after the current transaction commits. A statement that fails inside a
     * PostgreSQL transaction aborts all of it, so catching the error there would be too late.
     */
    public function whenCommitted(Closure $work): void
    {
        DB::afterCommit(fn () => $this->attempt('notification', $work));
    }

    public function send(User $user, NotificationMessage $message): void
    {
        // The in-app class is named for approvals, but it carries any title, body and link.
        $this->attempt('in-app', fn () => $user->notify(new ApprovalUpdate($message->title, $message->body, $message->url)));

        if (! $user->is_active) {
            return;
        }

        $this->attempt('channels', function () use ($user, $message) {
            $preference = NotificationPreference::for($user);

            foreach (self::CHANNELS as $channel) {
                if ($this->connected($channel) && ($preference->allows($channel) || ($message->critical && $channel === 'email'))) {
                    $this->attempt($channel, fn () => $this->queue($user, $channel, $message));
                }
            }
        });
    }

    /** Runs on the queue worker. Throws when the message should be retried. */
    public function deliver(User $user, string $channel, NotificationMessage $message, ?int $deviceId): void
    {
        if (! $this->connected($channel)) {
            return;
        }

        match ($channel) {
            'email' => Mail::to($user->email)->locale($user->locale ?: config('app.locale'))->send(new WorkflowNotificationMail($message)),
            'whatsapp' => $this->whatsapp->send((string) Phone::digits($user->phone), $message),
            'push' => $this->deliverPush($deviceId, $message),
        };
    }

    public function recordFailure(int $userId, string $channel, string $event, ?Throwable $exception): void
    {
        $error = Str::limit($this->withoutPersonalDetails((string) $exception?->getMessage()), 240);

        Log::warning('Notification not delivered', ['user_id' => $userId, 'channel' => $channel, 'event' => $event, 'error' => $error]);

        $this->attempt('failure log', fn () => NotificationFailure::create([
            'organization_id' => User::find($userId)?->organization_id,
            'user_id' => $userId,
            'channel' => $channel,
            'event' => $event,
            'error' => $error ?: 'Unknown error',
        ]));
    }

    /** @param array<string,bool> $choices Only the channels to change. */
    public function savePreferences(User $user, array $choices): NotificationPreference
    {
        if (($choices['whatsapp'] ?? false) && Phone::digits($user->phone) === null) {
            throw ValidationException::withMessages(['whatsapp' => __('Add a valid mobile number to your profile before turning on WhatsApp.')]);
        }

        $preference = NotificationPreference::for($user);
        $preference->fill(array_intersect_key($choices, array_flip(self::CHANNELS)));

        if ($preference->isDirty('whatsapp')) {
            $preference->whatsapp_opted_in_at = $preference->whatsapp ? now() : null;
        }

        $preference->save();

        return $preference;
    }

    /** @return array<string, array{enabled:bool, connected:bool}> */
    public function channelSummary(User $user): array
    {
        $preference = NotificationPreference::for($user);

        return ['in_app' => ['enabled' => true, 'connected' => true]] + collect(self::CHANNELS)->mapWithKeys(fn (string $channel) => [
            $channel => ['enabled' => $preference->allows($channel), 'connected' => $this->connected($channel)],
        ])->all();
    }

    private function queue(User $user, string $channel, NotificationMessage $message): void
    {
        match ($channel) {
            'email' => DeliverNotification::dispatch($user->id, $channel, $message),
            'whatsapp' => $this->whatsapp->templateFor($message->event) && Phone::digits($user->phone)
                ? DeliverNotification::dispatch($user->id, $channel, $message)
                : null,
            'push' => DeviceToken::withoutGlobalScopes()->where('user_id', $user->id)->pluck('id')
                ->each(fn (int $deviceId) => DeliverNotification::dispatch($user->id, $channel, $message, $deviceId)),
        };
    }

    private function deliverPush(?int $deviceId, NotificationMessage $message): void
    {
        $device = DeviceToken::withoutGlobalScopes()->find($deviceId);

        if ($device && ! $this->fcm->send($device->token, $message)) {
            $device->delete();
        }
    }

    private function attempt(string $step, Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            Log::warning('Notification step failed', ['step' => $step, 'error' => $this->withoutPersonalDetails($exception->getMessage())]);
        }
    }

    /** Provider and mail errors can repeat the address or number they were given. */
    private function withoutPersonalDetails(string $text): string
    {
        return (string) preg_replace(['/[\w.+-]+@[\w-]+\.[\w.-]+/', '/\+?\d{10,15}/'], '[hidden]', $text);
    }
}
