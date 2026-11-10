<?php

namespace App\Services\Notify;

use App\Models\NotificationPreference;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Quiet hours for push, on the Asia/Kolkata clock whatever timezone the server runs in.
 * Inside them a push that is not critical waits for the morning; a critical one goes at once.
 */
final class QuietHours
{
    public const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public static function timezone(): string
    {
        return (string) config('modules.notify.push.timezone', 'Asia/Kolkata');
    }

    /** When a push written now may be sent, or null when it can go straight away. */
    public static function heldUntil(NotificationPreference $preference, bool $critical, ?CarbonInterface $now = null): ?Carbon
    {
        if ($critical || ! $preference->quiet_hours) {
            return null;
        }

        [$from, $until] = array_map(self::minutes(...), $preference->quietWindow());

        if ($from === null || $until === null || $from === $until) {
            return null;
        }

        $now = Carbon::instance($now ?? now())->setTimezone(self::timezone());
        $minute = $now->hour * 60 + $now->minute;

        // 22:00 to 07:00 runs over midnight, 13:00 to 15:00 does not.
        $inside = $from < $until ? ($minute >= $from && $minute < $until) : ($minute >= $from || $minute < $until);

        if (! $inside) {
            return null;
        }

        $end = $now->copy()->startOfDay()->addMinutes($until);

        return ($end->lessThanOrEqualTo($now) ? $end->addDay() : $end)->setTimezone(config('app.timezone'));
    }

    private static function minutes(?string $time): ?int
    {
        if ($time === null || ! preg_match(self::TIME, $time)) {
            return null;
        }

        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
