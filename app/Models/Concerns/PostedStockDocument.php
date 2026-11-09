<?php

namespace App\Models\Concerns;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A document that has posted to the stock ledger. It is never edited, only reversed. */
trait PostedStockDocument
{
    use Auditable, BelongsToOrganization, Immutable;

    /** @return list<string> */
    public function lockedStatuses(): array
    {
        return ['POSTED', 'REVERSED'];
    }

    /** @return list<string> */
    public function mutableAfterApproval(): array
    {
        return ['status', 'reversed_by', 'reversed_at', 'reversal_reason'];
    }

    public function isReversed(): bool
    {
        return $this->status === 'REVERSED';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
