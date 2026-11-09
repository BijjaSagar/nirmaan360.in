<?php

namespace App\Models\Concerns;

use App\Exceptions\ImmutableRecordException;

/** Stock history is only ever added to. Corrections are reversal entries. */
trait StockAppendOnly
{
    public static function bootStockAppendOnly(): void
    {
        static::updating(fn () => throw new ImmutableRecordException('Stock history cannot be changed. Post a reversal instead.'));
        static::deleting(fn () => throw new ImmutableRecordException('Stock history cannot be changed. Post a reversal instead.'));
    }
}
