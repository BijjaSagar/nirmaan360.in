<?php

namespace App\Services\Notify;

use App\Jobs\Notify\DeliverNotification;
use App\Mail\Notify\WorkflowNotificationMail;
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

    public function __construct(private FcmGateway $fcm, private WhatsAppGateway $whatsapp, private PushDispatcher $push) {}

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

    /**
     * @param  NotificationMessage|Closure(string): NotificationMessage  $message  A closure is handed a language and writes
     *                                                                             the update in it, so each phone is pushed in the language its app is set to.
     */
    public function send(User $user, NotificationMessage|Closure $message): void
    {
        $write = $this->writer($message);
        $own = $this->written($write, $user);

        if ($own === null) {
            return;
        }

        // The in-app class is named for approvals, but it carries any title, body and link.
        $this->attempt('in-app', fn () => $user->notify(new ApprovalUpdate($own->title, $own->body, $own->url)));

        if (! $user->is_active) {
            return;
        }

        $this->attempt('channels', function () use ($user, $own, $write) {
            $preference = NotificationPreference::for($user);

            foreach (self::CHANNELS as $channel) {
                if ($this->connected($channel) && ($preference->allows($channel) || ($own->critical && $channel === 'email'))) {
                    $this->attempt($channel, fn () => $channel === 'push' ? $this->push->queue($user, $write, $preference) : $this->queue($user, $channel, $own));
                }
            }
        });
    }

    /**
     * Push and nothing else: no bell, email or WhatsApp. For people the other channels never told
     * about this kind of thing, such as a contractor login or whoever must write a reason.
     *
     * @param  NotificationMessage|Closure(string): NotificationMessage  $message
     */
    public function pushOnly(User $user, NotificationMessage|Closure $message): void
    {
        if (! $user->is_active) {
            return;
        }

        $this->attempt('push', function () use ($user, $message) {
            $preference = NotificationPreference::for($user);

            if ($this->connected('push') && $preference->allows('push')) {
                $this->push->queue($user, $this->writer($message), $preference);
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
            'push' => $this->push->deliver($user, $deviceId, $message),
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

    /**
     * @param  array<string, mixed>  $choices  Only what is to change: a channel name => bool, "categories" => [name => bool]
     *                                         for the kinds of push, and "quiet_hours" => [enabled, from, until] with "HH:MM" times.
     */
    public function savePreferences(User $user, array $choices): NotificationPreference
    {
        if (($choices['whatsapp'] ?? false) && Phone::digits($user->phone) === null) {
            throw ValidationException::withMessages(['whatsapp' => __('Add a valid mobile number to your profile before turning on WhatsApp.')]);
        }

        $preference = NotificationPreference::for($user);
        $preference->fill(array_intersect_key($choices, array_flip($this->channelsFor($user))));

        if ($preference->isDirty('whatsapp')) {
            $preference->whatsapp_opted_in_at = $preference->whatsapp ? now() : null;
        }

        if (is_array($choices['categories'] ?? null)) {
            // Only the switches this person is offered: a contractor login cannot store a staff switch, nor staff a contractor's.
            $offered = array_intersect_key($choices['categories'], array_flip(PushCategory::namesFor($user)));
            $preference->push_categories = [...($preference->push_categories ?? []), ...array_map(fn ($on) => (bool) $on, $offered)];
        }

        $quiet = is_array($choices['quiet_hours'] ?? null) ? $choices['quiet_hours'] : [];

        if (array_key_exists('enabled', $quiet)) {
            $preference->quiet_hours = (bool) $quiet['enabled'];
        }

        foreach (['from' => 'quiet_from', 'until' => 'quiet_until'] as $key => $column) {
            if (filled($quiet[$key] ?? null)) {
                $preference->setAttribute($column, $quiet[$key]);
            }
        }

        $preference->save();

        return $preference;
    }

    /**
     * Each channel with its switch and whether it is connected. Push also lists its kinds of update
     * and the quiet hours. A contractor login is told by push only, so that is all it is shown.
     *
     * @return array<string, array<string, mixed>>
     */
    public function channelSummary(User $user): array
    {
        $preference = NotificationPreference::for($user);
        [$from, $until] = $preference->quietWindow();

        $summary = collect($this->channelsFor($user))->mapWithKeys(fn (string $channel) => [
            $channel => ['enabled' => $preference->allows($channel), 'connected' => $this->connected($channel)],
        ])->all();

        $summary['push'] += [
            'categories' => array_map(fn (string $name) => [
                'key' => $name,
                'label' => PushCategory::label($name),
                'hint' => PushCategory::hint($name),
                'enabled' => $preference->allowsCategory($name),
            ], PushCategory::namesFor($user)),
            'quiet_hours' => ['enabled' => (bool) $preference->quiet_hours, 'from' => $from, 'until' => $until, 'timezone' => QuietHours::timezone()],
        ];

        return $user->isContractor() ? $summary : ['in_app' => ['enabled' => true, 'connected' => true]] + $summary;
    }

    /** @return list<string> */
    public function channelsFor(User $user): array
    {
        return $user->isContractor() ? ['push'] : self::CHANNELS;
    }

    private function queue(User $user, string $channel, NotificationMessage $message): void
    {
        match ($channel) {
            'email' => DeliverNotification::dispatch($user->id, $channel, $message),
            'whatsapp' => $this->whatsapp->templateFor($message->event) && Phone::digits($user->phone)
                ? DeliverNotification::dispatch($user->id, $channel, $message)
                : null,
        };
    }

    /**
     * One update, written once per language however many channels and phones ask for it.
     *
     * @return Closure(string): NotificationMessage
     */
    private function writer(NotificationMessage|Closure $message): Closure
    {
        if (! $message instanceof Closure) {
            return fn (string $locale) => $message;
        }

        $written = [];

        return function (string $locale) use ($message, &$written) {
            return $written[$locale] ??= $message($locale);
        };
    }

    /** The update in the person's own language, or null when it could not be written. */
    private function written(Closure $write, User $user): ?NotificationMessage
    {
        $own = null;

        $this->attempt('message', function () use (&$own, $write, $user) {
            $own = $write($user->locale ?: config('app.locale'));
        });

        return $own;
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
