<?php

namespace App\Services\Notify;

use App\Jobs\Notify\DeliverNotification;
use App\Models\Alert;
use App\Models\Announcement;
use App\Models\Approval;
use App\Models\DeviceToken;
use App\Models\NotificationPreference;
use App\Models\PushReceipt;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\ContractorPortal\ContractorPortalService;
use App\Support\Money;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The push channel. For one person it decides whether an update is pushed now, held until the
 * morning or not sent at all, and queues one job for each of their phones.
 *
 * The same checks run when the update is written and again when its job runs, which after quiet
 * hours can be hours later: the person belongs to the company, still holds the permissions, still
 * sees the project, has that kind of push switched on, and is not being told about something
 * already dealt with. Nobody without money.view is sent a rupee figure, whoever wrote the text.
 */
class PushDispatcher
{
    /** A rupee sign, Rs or INR in front of a number. MoneyMention, for words people write, is never narrower than this. */
    public const MONEY = '/(₹|\bRs\.?|\bINR)\s*-?\d/iu';

    public function __construct(private FcmGateway $fcm) {}

    /**
     * @param  Closure(string): NotificationMessage  $write  Writes the update in the language given.
     * @return int Phones a push was queued for.
     */
    public function queue(User $user, Closure $write, NotificationPreference $preference): int
    {
        $ownLocale = $this->localeOf($user);
        $own = $write($ownLocale);
        $target = $own->push ?? null;

        if ($target !== null && ! $this->mayReceive($user, $target, $preference)) {
            return 0;
        }

        $messages = [];

        foreach (DeviceToken::withoutGlobalScopes()->where('user_id', $user->id)->orderBy('id')->get(['id', 'locale']) as $device) {
            // Each phone is written to in the language its app is set to.
            $locale = $this->supported($device->locale) ?? $ownLocale;
            $messages[$device->id] = $locale === $ownLocale ? $own : $write($locale);
        }

        if ($messages === [] || $this->withheldForMoney($user, $messages)) {
            return 0;
        }

        $key = $target?->dedupeKey;

        // Null by day and for a critical push. Otherwise the job waits in the queue until quiet hours end.
        // (The sync queue of local development cannot wait, so there it is sent at once.)
        // Worked out before the claim, so nothing between the claim and the queueing can fail outside the rollback below.
        $until = QuietHours::heldUntil($preference, $own->critical);

        if ($key !== null && ! $this->claim($user, $key, $own->event)) {
            return 0;
        }

        try {
            foreach ($messages as $deviceId => $message) {
                DeliverNotification::dispatch($user->id, 'push', $message, $deviceId)->delay($until);
            }
        } catch (Throwable $exception) {
            // Nothing was queued for certain, so the next attempt must not be taken for a repeat.
            if ($key !== null) {
                $this->receipt($user, $key)->delete();
            }

            throw $exception;
        }

        return count($messages);
    }

    /** Runs on the queue worker. Throws when the push should be retried. */
    public function deliver(User $user, ?int $deviceId, NotificationMessage $message): void
    {
        // Looked up through its owner: a phone that changed hands while the job waited gets nothing of the last person's.
        $device = DeviceToken::withoutGlobalScopes()->where('user_id', $user->id)->find($deviceId);

        if ($device === null) {
            AnnouncementTally::count($message, AnnouncementTally::SKIPPED);

            return;
        }

        if (! $device->isSignedIn()) {
            $device->delete();
            AnnouncementTally::count($message, AnnouncementTally::SKIPPED);

            return;
        }

        $preference = NotificationPreference::for($user);
        $target = $message->push ?? null;

        if (! $preference->allows('push') || $this->withheldForMoney($user, [$message])) {
            AnnouncementTally::count($message, AnnouncementTally::SKIPPED);

            return;
        }

        if ($target !== null && (! $this->mayReceive($user, $target, $preference) || ! $this->stillMatters($user, $message, $target))) {
            AnnouncementTally::count($message, AnnouncementTally::SKIPPED);

            return;
        }

        if ($this->fcm->send($device->token, $message)) {
            AnnouncementTally::count($message, AnnouncementTally::SENT);
        } else {
            $device->delete();
            AnnouncementTally::count($message, AnnouncementTally::FAILED);
        }
    }

    /**
     * Sends a real push to this person's own phones straight away, for the admin's test button.
     * It skips the queue, the switches and quiet hours so the answer can be shown on the page.
     *
     * @return array{phones: int, sent: int, removed: int, error: ?string}
     */
    public function test(User $user): array
    {
        $result = ['phones' => 0, 'sent' => 0, 'removed' => 0, 'error' => null];

        foreach (DeviceToken::withoutGlobalScopes()->where('user_id', $user->id)->orderBy('id')->get() as $device) {
            if (! $device->isSignedIn()) {
                $device->delete();

                continue;
            }

            $result['phones']++;
            $locale = $this->supported($device->locale) ?? $this->localeOf($user);

            $message = new NotificationMessage(
                event: 'push.test',
                title: __('Test notification', [], $locale),
                body: __('Push notifications from :app are working on this phone.', ['app' => config('buildos.brand')], $locale),
                push: new PushTarget(category: null, type: 'test', id: 0, organizationId: (int) $user->organization_id),
            );

            try {
                if ($this->fcm->send($device->token, $message)) {
                    $result['sent']++;
                } else {
                    // Firebase no longer knows this phone.
                    $device->delete();
                    $result['removed']++;
                }
            } catch (Throwable $exception) {
                // The gateway's messages name the HTTP status and Google's error code, never the key or the token.
                $result['error'] = $exception->getMessage();
            }
        }

        return $result;
    }

    /** True the first time this person is to be pushed this key. A second claim inside the window is refused. */
    public function claim(User $user, string $key, string $event): bool
    {
        $now = now();
        $key = $this->receiptKey($key);

        $inserted = PushReceipt::withoutGlobalScopes()->insertOrIgnore([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'dedupe_key' => $key,
            'event' => Str::limit($event, 64, ''),
            'claimed_at' => $now,
        ]);

        if ($inserted === 1) {
            return true;
        }

        // A claim older than the window is taken over, so the same cause can be told again another day.
        return $this->receipt($user, $key)
            ->where('claimed_at', '<=', $now->copy()->subHours((int) config('modules.notify.push.dedupe_hours', 24)))
            ->update(['claimed_at' => $now]) === 1;
    }

    private function receipt(User $user, string $key)
    {
        return PushReceipt::withoutGlobalScopes()->where('user_id', $user->id)->where('dedupe_key', $this->receiptKey($key));
    }

    /** Keys are short by design. One that is not is hashed, so two long keys never collide by being cut. */
    private function receiptKey(string $key): string
    {
        return strlen($key) <= 190 ? $key : substr($key, 0, 149).':'.sha1($key);
    }

    private function mayReceive(User $user, PushTarget $target, NotificationPreference $preference): bool
    {
        return (int) $user->organization_id === $target->organizationId
            && ($target->category === null || $preference->allowsCategory($target->category))
            && Audience::holds($user, $target->permissions)
            && Audience::seesProject($user, $target->projectId)
            && $this->contractorStillLinked($user);
    }

    /**
     * A contractor login hears nothing once its company no longer has the portal (a plan or trial
     * ended) or it no longer leads to a subcontractor, checked when the push is written and again when
     * it is sent, which can be hours later after quiet hours.
     */
    private function contractorStillLinked(User $user): bool
    {
        return ! $user->isContractor()
            || ((bool) $user->organization?->allowsFeature('contractor_portal') && app(ContractorPortalService::class)->partyFor($user) !== null);
    }

    /**
     * A push held overnight is dropped when the alert has closed, when it has since turned critical
     * (that was pushed at once, and this older one would replace it on the phone), or when the
     * approval has moved on without this person.
     */
    private function stillMatters(User $user, NotificationMessage $message, PushTarget $target): bool
    {
        // An announcement that was stopped, or removed, sends nothing more: not even copies held for quiet hours.
        // One with a rupee figure is checked again against money.view, which can be lost while it waits.
        if ($target->type === 'announcement') {
            $announcement = Announcement::find($target->id, ['id', 'status', 'mentions_money']);

            return $announcement !== null
                && $announcement->status !== Announcement::CANCELLED
                && (! $announcement->mentions_money || Money::visible($user));
        }

        $alertId = $target->type === 'alert' ? $target->id : ($target->extra['alert_id'] ?? null);

        if ($alertId !== null) {
            return Alert::withoutGlobalScopes()->whereKey($alertId)->where('status', Alert::OPEN)
                ->when(! $message->critical, fn ($query) => $query->where('severity', '!=', Alert::CRITICAL))
                ->exists();
        }

        if ($message->event !== 'approval.waiting' || ! isset($target->extra['approval_id'])) {
            return true;
        }

        $approval = Approval::withoutGlobalScopes()->find($target->extra['approval_id']);

        return $approval !== null
            && (int) $approval->current_level === (int) ($target->extra['level'] ?? $approval->current_level)
            && app(ApprovalService::class)->canAct($approval, $user);
    }

    /**
     * The last guard of the money rule. The writers already leave amounts out for anyone without
     * money.view. If a figure still reaches such a person, from a new module's alert for instance,
     * the push is not sent. Only the fact is logged, never the text.
     *
     * @param  array<int, NotificationMessage>  $messages
     */
    private function withheldForMoney(User $user, array $messages): bool
    {
        foreach ($messages as $message) {
            if ((($message->push ?? null)?->ownMoney ?? false) || ! preg_match(self::MONEY, $message->title.' '.$message->body)) {
                continue;
            }

            if (! Money::visible($user)) {
                Log::warning('Push withheld: it carries a money figure for someone without money.view', ['user_id' => $user->id, 'event' => $message->event]);

                return true;
            }
        }

        return false;
    }

    /** The app sends its language as hi, hi_IN or hi-IN. Null when it is not one the server writes in. */
    private function supported(?string $locale): ?string
    {
        $language = strtolower((string) preg_split('/[-_]/', (string) $locale)[0]);

        return array_key_exists($language, config('buildos.locales', [])) ? $language : null;
    }

    private function localeOf(User $user): string
    {
        return $this->supported($user->locale) ?? config('app.locale');
    }
}
