<?php

namespace App\Services\OfflinePlan;

use App\Models\DeviceLicence;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Device licences for the offline plan: a phone activates once, then refreshes its signed token whenever
 * it is online. The token is what lets the app run with no internet, so it is verified on the phone with
 * the public key and never looked up here. Freeing a device opens its slot at once; the freed phone is
 * told the next time it comes online, and otherwise keeps working until its token runs out.
 */
class DeviceLicenceService
{
    public function __construct(private LicenceSigner $signer, private OfflinePlanPolicy $policy) {}

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'device_name' => ['required', 'string', 'max:120'],
            'platform' => ['nullable', 'string', Rule::in(['android', 'ios', 'other'])],
            'app_version' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * Give a phone a licence, or hand the same phone its licence again. A phone never takes a second slot.
     *
     * @param  array{device_id: string, device_name: string, platform?: ?string, app_version?: ?string}  $data
     * @return array{licence: DeviceLicence, token: string, created: bool}
     */
    public function activate(User $user, array $data): array
    {
        $organization = $this->offlineOrganization($user);

        try {
            return DB::transaction(function () use ($organization, $user, $data) {
                // Serialises activations for one company where the database supports row locks.
                $locked = Organization::lockForUpdate()->findOrFail($organization->id);
                $licence = $this->find($locked, $data['device_id'], lock: true);

                if ($licence?->isActive()) {
                    $this->touch($licence, $locked, $data);

                    return ['licence' => $licence, 'token' => $this->token($licence, $locked), 'created' => false];
                }

                $attributes = [
                    'user_id' => $user->id,
                    'device_name' => $data['device_name'],
                    'platform' => $data['platform'] ?? null,
                    'app_version' => $data['app_version'] ?? null,
                    'status' => DeviceLicence::ACTIVE,
                    'slot' => $this->freeSlot($locked),
                    'activated_at' => now(),
                    'last_seen_at' => now(),
                    'expires_at' => $this->expiryFor($locked),
                    'revoked_at' => null,
                    'revoked_by' => null,
                ];

                if ($licence) {
                    $licence->update($attributes);
                } else {
                    $licence = DeviceLicence::create(['organization_id' => $locked->id, 'device_id' => $data['device_id'], ...$attributes]);
                }

                return ['licence' => $licence, 'token' => $this->token($licence, $locked), 'created' => true];
            });
        } catch (UniqueConstraintViolationException) {
            // Another request got there first: either this same phone, which now simply has its licence,
            // or another phone that took the last slot, in which case the unique key kept the limit for us.
            $existing = $this->find($organization, $data['device_id']);

            if ($existing?->isActive()) {
                return ['licence' => $existing, 'token' => $this->token($existing, $organization), 'created' => false];
            }

            throw $this->limitReached($organization);
        }
    }

    /**
     * A new token for a phone that already holds a licence. The plan is checked first, so a phone whose
     * company moved online is told to start syncing rather than told its licence is missing.
     *
     * @param  array{device_id: string, device_name?: ?string, app_version?: ?string}  $data
     * @return array{licence: DeviceLicence, token: string}
     */
    public function refresh(User $user, array $data): array
    {
        $organization = $this->offlineOrganization($user);
        $licence = $this->find($organization, $data['device_id']);

        if (! $licence) {
            throw new OfflinePlanRefused('licence_missing', __('This phone has no licence yet. Activate it first.'), 404);
        }

        if (! $licence->isActive()) {
            throw new OfflinePlanRefused('licence_revoked', __('This phone was freed from the company. It can be activated again if a slot is free.'), 410, [
                'manage_url' => $this->manageUrl(),
            ]);
        }

        $this->touch($licence, $organization, $data);

        return ['licence' => $licence, 'token' => $this->token($licence, $organization)];
    }

    /** The phone gives its own slot back, for example before moving to a new phone. */
    public function release(User $user, string $deviceId): DeviceLicence
    {
        $licence = $this->find($user->organization, $deviceId);

        if (! $licence) {
            throw new OfflinePlanRefused('licence_missing', __('This phone has no licence yet. Activate it first.'), 404);
        }

        return $this->free($licence, $user);
    }

    /** The owner frees a phone from the web. Safe to repeat. */
    public function free(DeviceLicence $licence, User $by): DeviceLicence
    {
        if ($licence->isActive()) {
            $licence->update([
                'status' => DeviceLicence::REVOKED,
                'slot' => null,
                'revoked_at' => now(),
                'revoked_by' => $by->id,
            ]);
        }

        return $licence;
    }

    /** @return Collection<int, DeviceLicence> */
    public function list(Organization $organization): Collection
    {
        return DeviceLicence::query()
            ->where('organization_id', $organization->id)
            ->with(['user:id,name', 'revoker:id,name'])
            ->orderBy('status')
            ->orderByDesc('activated_at')
            ->orderByDesc('id')
            ->get();
    }

    public function activeCount(Organization $organization): int
    {
        return DeviceLicence::query()->where('organization_id', $organization->id)->active()->count();
    }

    /** What the app asks on sign-in: whether this company syncs or runs on the phone alone. */
    public function status(User $user): array
    {
        $organization = $user->organization;
        $offline = $this->policy->isOffline($organization);

        return [
            'plan' => $organization->effectivePlan(),
            'offline' => $offline,
            'syncs' => ! $offline,
            'max_devices' => $this->policy->maxDevices($organization),
            'active_devices' => $this->activeCount($organization),
            'licence_days' => $this->licenceDays(),
            'signing_key' => $this->signer->describe(),
        ];
    }

    /** The reply to an activation or a refresh. */
    public function issued(DeviceLicence $licence, string $token): array
    {
        $organization = Organization::findOrFail($licence->organization_id);

        return [
            'licence' => $this->present($licence),
            'token' => $token,
            'signing_key' => $this->signer->describe(),
            'max_devices' => $this->policy->maxDevices($organization),
            'active_devices' => $this->activeCount($organization),
        ];
    }

    public function present(DeviceLicence $licence): array
    {
        return [
            'id' => $licence->id,
            'device_id' => $licence->device_id,
            'device_name' => $licence->device_name,
            'platform' => $licence->platform,
            'app_version' => $licence->app_version,
            'status' => $licence->status,
            'activated_at' => $licence->activated_at?->toIso8601String(),
            'last_seen_at' => $licence->last_seen_at?->toIso8601String(),
            'expires_at' => $licence->expires_at?->toIso8601String(),
            'revoked_at' => $licence->revoked_at?->toIso8601String(),
        ];
    }

    /**
     * How long a token stays good without a refresh: until the paid period (plus grace) or the trial ends,
     * and never longer than the module's licence_days, so a freed phone cannot run on for a year.
     */
    public function expiryFor(Organization $organization): Carbon
    {
        $cap = now()->addDays($this->licenceDays());

        $planEnd = match (true) {
            $organization->plan_status === 'trial' && $organization->trial_ends_at !== null => $organization->trial_ends_at->copy(),
            $organization->paid_until !== null => $organization->paid_until->copy()->addDays((int) config('buildos.payment_grace_days')),
            default => null,
        };

        return $planEnd !== null && $planEnd->lessThan($cap) ? $planEnd : $cap;
    }

    private function licenceDays(): int
    {
        return max(1, (int) config('modules.offline_plan.licence_days', 90));
    }

    private function offlineOrganization(User $user): Organization
    {
        $organization = $user->organization;

        if (! $this->policy->isOffline($organization)) {
            throw new OfflinePlanRefused('not_offline_plan', __('Your plan syncs online, so the phone needs no licence. Sync as usual.'), 403, [
                'plan' => $organization->effectivePlan(),
                'syncs' => true,
            ]);
        }

        return $organization;
    }

    private function find(Organization $organization, string $deviceId, bool $lock = false): ?DeviceLicence
    {
        return DeviceLicence::query()
            ->where('organization_id', $organization->id)
            ->where('device_id', $deviceId)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /** The lowest slot nobody holds. Refuses when the company is at its limit, counting holes left by freed phones. */
    private function freeSlot(Organization $organization): int
    {
        $max = $this->policy->maxDevices($organization);
        $taken = DeviceLicence::query()->where('organization_id', $organization->id)->active()->pluck('slot')->filter()->map(fn ($slot) => (int) $slot)->all();

        if ($max !== null && count($taken) >= $max) {
            throw $this->limitReached($organization, count($taken));
        }

        for ($slot = 1; in_array($slot, $taken, true); $slot++) {
            // Walk up to the first free number.
        }

        if ($max !== null && $slot > $max) {
            throw $this->limitReached($organization, count($taken));
        }

        return $slot;
    }

    private function limitReached(Organization $organization, ?int $used = null): OfflinePlanRefused
    {
        $max = $this->policy->maxDevices($organization);

        return new OfflinePlanRefused('device_limit', __('Your plan allows :count phone(s) and every slot is taken. Free a phone at :url first.', [
            'count' => $max ?? '∞',
            'url' => $this->manageUrl(),
        ]), 409, [
            'max_devices' => $max,
            'active_devices' => $used ?? $this->activeCount($organization),
            'manage_url' => $this->manageUrl(),
        ]);
    }

    /**
     * Where a refusal sends the person to free a phone. The field app shows it, so it is the page with only the
     * phones, not the devices page with its plan prices and upgrade button (App Store guideline 3.1.1).
     */
    private function manageUrl(): string
    {
        return route('offline.devices.phones');
    }

    /** Seen again: move the expiry on and note the version, without an audit row for every ping. */
    private function touch(DeviceLicence $licence, Organization $organization, array $data): void
    {
        $licence->forceFill([
            'last_seen_at' => now(),
            'expires_at' => $this->expiryFor($organization),
            'device_name' => $data['device_name'] ?? $licence->device_name,
            'app_version' => $data['app_version'] ?? $licence->app_version,
        ])->saveQuietly();
    }

    private function token(DeviceLicence $licence, Organization $organization): string
    {
        return $this->signer->sign([
            'v' => 1,
            'kid' => $this->signer->keyId(),
            'lic' => $licence->id,
            'org' => $licence->organization_id,
            'usr' => $licence->user_id,
            'dev' => $licence->device_id,
            'plan' => $organization->effectivePlan(),
            'features' => array_values($organization->planConfig()['features']),
            'max_devices' => $this->policy->maxDevices($organization),
            'iat' => now()->getTimestamp(),
            'exp' => $licence->expires_at->getTimestamp(),
        ]);
    }
}
