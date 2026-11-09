<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the company owes the owner of a hired machine for a period.
 * A draft can be cancelled freely. Once final the figures are locked: only payment
 * or cancellation can follow, and a cancelled bill releases its machine logs.
 */
class AssetHireBill extends Model
{
    use Auditable, BelongsToOrganization, Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'rate' => 'decimal:2',
            'actual_quantity' => 'decimal:3',
            'quantity' => 'decimal:3',
            'gross_amount' => 'decimal:2',
            'fuel_litres' => 'decimal:3',
            'fuel_recovery' => 'decimal:2',
            'other_deduction' => 'decimal:2',
            'gst_pct' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'tds_pct' => 'decimal:2',
            'tds_amount' => 'decimal:2',
            'net_payable' => 'decimal:2',
            'finalized_at' => 'datetime',
            'paid_on' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lockedStatuses(): array
    {
        return ['FINAL', 'PAID', 'CANCELLED'];
    }

    public function mutableAfterApproval(): array
    {
        return ['status', 'paid_on', 'payment_reference', 'cancelled_at', 'cancel_reason'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'vendor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MachineLog::class, 'hire_bill_id')->orderBy('log_date');
    }
}
