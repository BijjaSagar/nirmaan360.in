<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** Client timestamps, normalised before they are stored or compared. */
final class ClientTime
{
    /**
     * Timestamp columns store wall-clock text in the app timezone and Eloquent does not
     * convert on write, so an offset has to be resolved here. Seconds are the resolution
     * the contract guarantees, so anything finer is dropped.
     */
    public static function parse(?string $value): ?Carbon
    {
        return filled($value)
            ? Carbon::parse($value)->setTimezone(config('app.timezone'))->startOfSecond()
            : null;
    }
}
