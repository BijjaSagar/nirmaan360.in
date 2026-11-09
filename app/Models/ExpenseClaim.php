<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money a staff member spent and wants back. An approved claim can only move on to paid. */
class ExpenseClaim extends Model
{
    use Auditable, BelongsToOrganization, Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['claim_date' => 'date', 'amount' => 'decimal:2', 'decided_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function lockedStatuses(): array
    {
        return ['APPROVED', 'PAID'];
    }

    public function mutableAfterApproval(): array
    {
        return ['status', 'paid_at'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
