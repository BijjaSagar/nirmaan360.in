<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;

/** Writes an audit_logs row for every create, update and delete. */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => AuditLog::record('created', $model, null, $model->getAttributes()));

        static::updated(function ($model) {
            $changes = collect($model->getChanges())->except('updated_at');

            if ($changes->isNotEmpty()) {
                $old = collect($model->getOriginal())->only($changes->keys());
                AuditLog::record('updated', $model, $old->all(), $changes->all());
            }
        });

        static::deleted(fn ($model) => AuditLog::record('deleted', $model, $model->getOriginal(), null));
    }
}
