<?php

namespace App\Services\RateHelper\Sources;

use App\Models\BoqItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\RateHelper\Contracts\RateSource;
use App\Services\RateHelper\RateFigure;

/**
 * The place a government schedule of rates would plug in. None is loaded: there is no table, row,
 * item code or edition behind this class, so it reports that it is not connected, offers nothing
 * and knows no rate. The screen says so in plain words.
 */
class ScheduleSource implements RateSource
{
    public const KEY = 'schedule';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Government schedule';
    }

    public function isConnected(Organization $organization): bool
    {
        return false;
    }

    public function allows(User $viewer): bool
    {
        return false;
    }

    public function candidates(User $viewer, BoqItem $item): iterable
    {
        return [];
    }

    public function rateOf(User $viewer, int $rowId): ?RateFigure
    {
        return null;
    }
}
