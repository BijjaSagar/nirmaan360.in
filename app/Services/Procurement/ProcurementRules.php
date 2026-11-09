<?php

namespace App\Services\Procurement;

/** Field rules shared by the web forms, the REST API and offline push. */
final class ProcurementRules
{
    /** Largest value decimal(14,3) can hold, in whole units. */
    private const MAX_QTY = 99999999999;

    /** @return list<string> */
    public static function qty(): array
    {
        return ['required', 'numeric', 'gt:0', 'decimal:0,3', 'max:'.self::MAX_QTY];
    }

    /** @return list<string> */
    public static function optionalQty(): array
    {
        return ['nullable', 'numeric', 'min:0', 'decimal:0,3', 'max:'.self::MAX_QTY];
    }

    /** @return list<string> */
    public static function clientUuid(): array
    {
        return ['nullable', 'uuid'];
    }
}
