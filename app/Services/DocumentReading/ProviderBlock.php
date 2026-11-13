<?php

namespace App\Services\DocumentReading;

use App\Services\Agents\ModelGatewayException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A short stop on every reader call, set when the provider has refused a call for a reason that
 * the next call would meet as well: the saved key is rejected, the account has a billing problem
 * or has reached its spending limit, or no key is saved. While it stands no page is sent, so no
 * call is wasted finding out the same thing again.
 *
 * A spending limit stands until the platform admin clears it. The others lift by themselves.
 */
final class ProviderBlock
{
    public const KEY = 'ai.provider_block';

    public const MINUTES = 15;

    public const REASONS = [
        ModelGatewayException::SPEND_LIMIT,
        ModelGatewayException::BILLING,
        ModelGatewayException::REJECTED_KEY,
        ModelGatewayException::NOT_CONNECTED,
    ];

    /** @return bool Whether this call set it. False when a stop was already standing. */
    public static function set(string $reason): bool
    {
        $standing = self::current();

        // A stop that needs the admin is never replaced by one that lifts by itself.
        if ($standing !== null && ($standing['until'] === null || $standing['reason'] === $reason)) {
            return false;
        }

        if ($reason === ModelGatewayException::SPEND_LIMIT) {
            Cache::forever(self::KEY, ['reason' => $reason, 'until' => null]);
        } else {
            $until = now()->addMinutes(self::MINUTES);
            Cache::put(self::KEY, ['reason' => $reason, 'until' => $until->getTimestamp()], $until);
        }

        return true;
    }

    /** @return array{reason:string, until:?int}|null "until" is a Unix time, or null when only the platform admin can clear it. */
    public static function current(): ?array
    {
        $block = Cache::get(self::KEY);

        if (! is_array($block) || ! is_string($block['reason'] ?? null)) {
            return null;
        }

        $until = $block['until'] ?? null;

        if (is_int($until) && $until <= now()->getTimestamp()) {
            return null;
        }

        return ['reason' => $block['reason'], 'until' => is_int($until) ? $until : null];
    }

    public static function until(): ?Carbon
    {
        $until = self::current()['until'] ?? null;

        return $until === null ? null : Carbon::createFromTimestamp($until, config('app.timezone'));
    }

    public static function clear(): void
    {
        Cache::forget(self::KEY);
    }
}
