<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrder extends Model
{
    use Auditable, BelongsToOrganization, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'retention_pct' => 'decimal:2',
            'tds_pct' => 'decimal:2',
            'gst_pct' => 'decimal:2',
            'advance_amount' => 'decimal:2',
            'advance_recovery_pct' => 'decimal:2',
            'deduction_order' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === 'CLOSED';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class);
    }

    public function billingTerms(): array
    {
        return [
            'retention_pct' => $this->retention_pct,
            'tds_pct' => $this->tds_pct,
            'gst_pct' => $this->gst_pct,
            'advance_amount' => $this->advance_amount,
            'advance_recovery_pct' => $this->advance_recovery_pct,
            'order' => $this->deduction_order ?: config('buildos.default_deduction_order'),
            'basis' => $this->deduction_basis,
        ];
    }

    public function totalValue(): string
    {
        $total = Dec::of(0);

        foreach ($this->items as $item) {
            $total = $total->plus(Dec::of($item->qty)->multipliedBy(Dec::of($item->rate)));
        }

        return Dec::money($total);
    }
}
