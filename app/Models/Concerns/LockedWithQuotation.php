<?php

namespace App\Models\Concerns;

use App\Exceptions\ImmutableRecordException;
use App\Models\ClientQuotation;

/**
 * A line or a payment stage of a quotation is as fixed as the quotation itself: once the quotation
 * is issued the row cannot be added, changed or removed, except for the columns the model names in
 * changeableOnceIssued().
 */
trait LockedWithQuotation
{
    public static function bootLockedWithQuotation(): void
    {
        static::creating(function ($model) {
            if ($model->quotationIsIssued()) {
                throw new ImmutableRecordException(ClientQuotation::lockedMessage());
            }
        });

        static::updating(function ($model) {
            if ($model->quotationIsIssued() && array_diff(array_keys($model->getDirty()), [...$model->changeableOnceIssued(), 'updated_at']) !== []) {
                throw new ImmutableRecordException(ClientQuotation::lockedMessage());
            }
        });

        static::deleting(function ($model) {
            if ($model->quotationIsIssued()) {
                throw new ImmutableRecordException(ClientQuotation::lockedMessage());
            }
        });
    }

    /** @return list<string> */
    public function changeableOnceIssued(): array
    {
        return [];
    }

    /** Read without the tenant scopes: the lock must hold on a console run and on a shared link too. */
    public function quotationIsIssued(): bool
    {
        $id = $this->getOriginal('quotation_id') ?? $this->quotation_id;

        return $id !== null && ClientQuotation::withoutGlobalScopes()->whereKey($id)->value('status') === ClientQuotation::ISSUED;
    }
}
