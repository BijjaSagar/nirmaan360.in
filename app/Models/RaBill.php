<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RaBill extends Model
{
    use Approvable, Auditable, BelongsToOrganization, Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'gross_amount' => 'decimal:2',
            'deduction_total' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'net_payable' => 'decimal:2',
            'terms' => 'array',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function approvalDocType(): string
    {
        return 'ra_bill';
    }

    public function approvalTitle(): string
    {
        return 'RA Bill '.$this->number.' · '.inr($this->net_payable);
    }

    public function lockedStatuses(): array
    {
        return ['APPROVED', 'PAID'];
    }

    public function mutableAfterApproval(): array
    {
        return ['status', 'paid_at'];
    }

    public function onApprovalApproved(): void
    {
        $this->update(['status' => 'APPROVED', 'approved_at' => now()]);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RaBillItem::class);
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(RaBillDeduction::class)->orderBy('sort');
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, ['DRAFT', 'RETURNED', 'REJECTED'], true);
    }
}
