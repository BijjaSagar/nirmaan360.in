<?php

namespace App\Services\RateHelper\Contracts;

use App\Models\User;

/** A source that can read many rows in one query, so a review screen with many lines stays quick. */
interface BatchedRateSource extends RateSource
{
    /**
     * Reads these rows now, as this person, so the rateOf() calls that follow on this instance need
     * no further query. Rows the person may not see are simply not read.
     *
     * @param  list<int>  $rowIds
     */
    public function prime(User $viewer, array $rowIds): void;
}
