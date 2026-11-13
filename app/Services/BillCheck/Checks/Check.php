<?php

namespace App\Services\BillCheck\Checks;

use App\Services\BillCheck\BillFacts;
use App\Services\BillCheck\Flag;
use App\Services\BillCheck\FlagCatalog;
use App\Support\Dec;

/**
 * One group of checks. It reads the facts of a bill and returns what it found. It never writes.
 */
abstract class Check
{
    /** @return list<Flag> */
    abstract public function run(BillFacts $facts): array;

    /**
     * @param  string  $key  The check and the row it concerns, the same on every run.
     * @param  array<string, string|int|null>  $params
     */
    protected function flag(string $code, string $key, array $params = [], ?string $level = null): Flag
    {
        return new Flag($key, $code, $level ?? FlagCatalog::level($code), $params);
    }

    protected function sameMoney(mixed $one, mixed $other): bool
    {
        return Dec::money($one) === Dec::money($other);
    }

    protected function sameQty(mixed $one, mixed $other): bool
    {
        return Dec::qty($one) === Dec::qty($other);
    }
}
