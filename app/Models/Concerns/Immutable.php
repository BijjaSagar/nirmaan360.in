<?php

namespace App\Models\Concerns;

use App\Exceptions\ImmutableRecordException;

/** Blocks updates and deletes once a record is APPROVED. */
trait Immutable
{
    public static function bootImmutable(): void
    {
        static::updating(function ($model) {
            if (! in_array($model->getOriginal('status'), $model->lockedStatuses(), true)) {
                return;
            }

            $allowed = [...$model->mutableAfterApproval(), 'updated_at'];

            if (array_diff(array_keys($model->getDirty()), $allowed) !== []) {
                throw new ImmutableRecordException;
            }
        });

        static::deleting(function ($model) {
            if (in_array($model->getOriginal('status'), $model->lockedStatuses(), true)) {
                throw new ImmutableRecordException;
            }
        });
    }

    /** @return list<string> */
    public function lockedStatuses(): array
    {
        return ['APPROVED'];
    }

    /** @return list<string> Columns that may still change on a locked record. */
    public function mutableAfterApproval(): array
    {
        return [];
    }
}
