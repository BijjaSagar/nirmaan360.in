<?php

namespace App\Services\Notify;

use App\Models\DeviceToken;
use App\Models\PushReceipt;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The phones that can be pushed to. A token belongs to one person and to the sign-in that
 * registered it. The app registers on every start and whenever Firebase hands it a new token,
 * and unregisters on sign-out. Tokens Firebase reports as gone are removed when a send finds
 * them (PushDispatcher); the rest of the tidying is here.
 */
class DeviceRegistry
{
    /**
     * Register a phone, or refresh the one already known by this token.
     *
     * @param  array{token: string, platform: string, app_version?: ?string, locale?: ?string, device_name?: ?string, previous_token?: ?string}  $data
     */
    public function register(User $user, array $data, ?PersonalAccessToken $signIn): DeviceToken
    {
        // Firebase replaced the app's token: the old one is dead, and only its owner can retire it.
        if (filled($data['previous_token'] ?? null) && $data['previous_token'] !== $data['token']) {
            $this->forget($user, $data['previous_token']);
        }

        $hash = DeviceToken::hashOf($data['token']);

        $values = [
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'token' => $data['token'],
            'platform' => $data['platform'],
            'device_name' => $data['device_name'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'locale' => $this->language($data['locale'] ?? null),
            // A mocked or cookie sign-in has no row to follow, and such a phone is kept until it is unregistered.
            'access_token_id' => $signIn?->exists ? $signIn->getKey() : null,
            'last_seen_at' => now(),
        ];

        // Not limited to this company: a phone handed to someone elsewhere has to move with them.
        try {
            $device = DB::transaction(fn () => DeviceToken::withoutGlobalScopes()->updateOrCreate(['token_hash' => $hash], $values));
        } catch (UniqueConstraintViolationException) {
            // The app registered twice at once and the other request made the row.
            $device = DeviceToken::withoutGlobalScopes()->where('token_hash', $hash)->firstOrFail();
            $device->update($values);
        }

        $this->keepNewest($user);

        return $device;
    }

    /** Called on sign-out. Removes only this person's own phone. */
    public function forget(User $user, string $token): void
    {
        DeviceToken::withoutGlobalScopes()->where('user_id', $user->id)->where('token_hash', DeviceToken::hashOf($token))->delete();
    }

    /**
     * Daily tidy-up: phones whose sign-in has ended, phones the app has not registered from for
     * months, and proofs of push older than anything they could still stop.
     *
     * @return array{phones: int, receipts: int}
     */
    public function prune(): array
    {
        $signedOut = DeviceToken::withoutGlobalScopes()
            ->whereNotNull('access_token_id')
            ->whereNotIn('access_token_id', PersonalAccessToken::query()->select('id')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->delete();

        $cutoff = now()->subDays((int) config('modules.notify.push.stale_device_days', 270));

        $stale = DeviceToken::withoutGlobalScopes()
            ->where(fn ($query) => $query->where('last_seen_at', '<', $cutoff)->orWhere(fn ($never) => $never->whereNull('last_seen_at')->where('created_at', '<', $cutoff)))
            ->delete();

        $receipts = PushReceipt::withoutGlobalScopes()
            ->where('claimed_at', '<', now()->subHours(max(24, (int) config('modules.notify.push.dedupe_hours', 24)) * 2))
            ->delete();

        return ['phones' => $signedOut + $stale, 'receipts' => $receipts];
    }

    /** The app may send hi, hi_IN or hi-IN. Only the language is kept, as that is all a push is written in. */
    private function language(?string $locale): ?string
    {
        $language = strtolower(substr((string) preg_split('/[-_]/', trim((string) $locale))[0], 0, 8));

        return $language === '' ? null : $language;
    }

    /** One person keeps a handful of phones. Beyond that the ones unseen for longest are dropped. */
    private function keepNewest(User $user): void
    {
        $keep = max(1, (int) config('modules.notify.push.max_devices', 10));

        // A phone never seen sorts last on every database, whichever way it orders nulls.
        $extra = DeviceToken::withoutGlobalScopes()->where('user_id', $user->id)
            ->orderByRaw('last_seen_at is null')->orderByDesc('last_seen_at')->orderByDesc('id')
            ->pluck('id')->slice($keep);

        if ($extra->isNotEmpty()) {
            DeviceToken::withoutGlobalScopes()->whereIn('id', $extra)->delete();
        }
    }
}
