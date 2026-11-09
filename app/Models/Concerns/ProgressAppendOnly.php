<?php

namespace App\Models\Concerns;

use App\Exceptions\ImmutableRecordException;

/** Site progress history is only ever added to. A mistake is answered by a correction entry. */
trait ProgressAppendOnly
{
    public static function bootProgressAppendOnly(): void
    {
        static::updating(fn () => throw new ImmutableRecordException(__('Progress history cannot be changed. Add a correction instead.')));
        static::deleting(fn () => throw new ImmutableRecordException(__('Progress history cannot be deleted. Add a correction instead.')));
    }
}
