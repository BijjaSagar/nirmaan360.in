<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A wage calculation for a pay period. Once approved it is locked, and corrections go in the next run. */
class WageRun extends Model
{
    use Auditable, BelongsToOrganization, Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'terms' => 'array',
            'total_days' => 'decimal:3',
            'total_gross' => 'decimal:2',
            'total_recovery' => 'decimal:2',
            'total_net' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function lockedStatuses(): array
    {
        return ['APPROVED', 'PAID'];
    }

    public function mutableAfterApproval(): array
    {
        return ['status', 'paid_at', 'payment_note'];
    }

    public function isLocked(): bool
    {
        return in_array($this->status, $this->lockedStatuses(), true);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WageRunLine::class)->orderBy('worker_name');
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(AdvanceRecovery::class);
    }
}
