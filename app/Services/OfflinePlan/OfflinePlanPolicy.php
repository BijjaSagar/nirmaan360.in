<?php

namespace App\Services\OfflinePlan;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * What a company on the offline plan may still do on the server: sign in, hold device licences and pay.
 * Everything that moves data is refused, so the plan is honest: nothing leaves the phone.
 */
final class OfflinePlanPolicy
{
    public const PLAN = 'offline';

    /** The machine-readable code every refused request carries. */
    public const CODE = 'offline_plan';

    /** API paths a phone on the offline plan may call. Everything under /sync and every other write is refused. */
    private const API_ALLOWED = ['api/v1/auth/*', 'api/v1/me', 'api/v1/offline/*'];

    /** Web pages that stay open on the offline plan: account, billing, devices and signing out. */
    private const WEB_ALLOWED = ['offline.*', 'billing.*', 'settings.*', 'devices.*', 'logout', 'locale', 'notifications.read', 'admin.*'];

    public static function plan(): string
    {
        return (string) config('modules.offline_plan.plan', self::PLAN);
    }

    /** An expired trial or a lapsed payment falls back to the free plan, which syncs, so this follows effectivePlan(). */
    public function isOffline(Organization $organization): bool
    {
        return $organization->effectivePlan() === self::plan();
    }

    /** Phones that may hold a licence at the same time. Null means no limit. */
    public function maxDevices(Organization $organization): ?int
    {
        $plan = $organization->planConfig();

        if (! array_key_exists('max_devices', $plan)) {
            return (int) config('modules.offline_plan.default_max_devices', 1);
        }

        return $plan['max_devices'] === null ? null : (int) $plan['max_devices'];
    }

    public function apiAllowed(Request $request): bool
    {
        return $request->is(...self::API_ALLOWED);
    }

    /**
     * Only routes that sign a person in with a Sanctum token are gated. Sign-in itself, webhooks, the public
     * API (company keys) and the signing-key endpoint have no such person, so the token guard is left alone.
     */
    public function signsInWithSanctum(Route $route): bool
    {
        return in_array('auth:sanctum', $route->gatherMiddleware(), true)
            && ! in_array('auth:sanctum', $route->excludedMiddleware(), true);
    }

    /** Public pages stay public. Signed-in pages are limited to the short list above. */
    public function webAllowed(Route $route): bool
    {
        if (! in_array('auth', $route->middleware(), true)) {
            return true;
        }

        $name = (string) $route->getName();

        return $name !== '' && Str::is(self::WEB_ALLOWED, $name);
    }

    /** @return array<string, mixed> */
    public function refusal(Organization $organization): array
    {
        return [
            'code' => self::CODE,
            'message' => __('Your company is on the offline plan: data stays on the phone and nothing syncs. Move to an online plan to sync.'),
            'plan' => $organization->effectivePlan(),
            'upgrade_url' => route('billing.index'),
        ];
    }
}
