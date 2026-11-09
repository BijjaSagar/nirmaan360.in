<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use App\Support\Dec;
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
            'is_final' => 'boolean',
            'retention_released' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function approvalDocType(): string
    {
        return 'ra_bill';
    }

    public function approvalTitle(): string
    {
        return ($this->is_final ? 'Final Bill ' : 'RA Bill ').$this->number;
    }

    public function approvalAmount(): ?string
    {
        return $this->net_payable;
    }

    public function lockedStatuses(): array
    {
        return ['APPROVED', 'PAID'];
    }

    public function mutableAfterApproval(): array
    {
        return ['status', 'paid_at', 'paid_amount'];
    }

    /** Approving a final bill is what closes the contract. Runs for client sign-off too, with no user signed in. */
    public function onApprovalApproved(): void
    {
        $this->update(['status' => 'APPROVED', 'approved_at' => now()]);

        if ($this->is_final) {
            WorkOrder::withoutGlobalScopes()->find($this->work_order_id)?->update(['status' => 'CLOSED', 'closed_at' => now()]);
            AuditLog::record('contract.closed', $this, null, ['work_order_id' => $this->work_order_id, 'retention_released' => $this->retention_released]);
        }
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

    public function notes(): HasMany
    {
        return $this->hasMany(BillNote::class)->latest('id');
    }

    /** Net payable after every approved credit and debit note. */
    public function adjustedNet(): string
    {
        $net = Dec::of($this->net_payable);

        foreach ($this->notes()->where('status', 'APPROVED')->pluck('net_effect') as $effect) {
            $net = $net->plus(Dec::of($effect));
        }

        return Dec::money($net);
    }

    /** Whether the bill can carry a credit or debit note. Unapproved bills are cancelled and raised again instead. */
    public function acceptsNotes(): bool
    {
        return in_array($this->status, ['APPROVED', 'PAID'], true);
    }

    public function hasGst(): bool
    {
        return Dec::of($this->gst_amount)->isPositive();
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, ['DRAFT', 'RETURNED', 'REJECTED'], true);
    }
}
