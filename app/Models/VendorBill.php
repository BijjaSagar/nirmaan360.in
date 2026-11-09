<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorBill extends Model
{
    use Approvable, Auditable, BelongsToOrganization, Immutable;

    /** Statuses that count in the ledger and reports. A reversal cancels its original there. */
    public const POSTED = ['APPROVED', 'PART_PAID', 'PAID', 'REVERSED', 'REVERSAL'];

    /** Statuses that can still take a payment. */
    public const OPEN = ['APPROVED', 'PART_PAID'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'due_date' => 'date',
            'taxable_amount' => 'decimal:2',
            'gst_pct' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'tds_pct' => 'decimal:2',
            'tds_amount' => 'decimal:2',
            'net_payable' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function approvalDocType(): string
    {
        return 'vendor_bill';
    }

    public function approvalTitle(): string
    {
        return 'Vendor bill '.$this->bill_no.' · '.inr($this->total_amount);
    }

    public function lockedStatuses(): array
    {
        return self::POSTED;
    }

    public function mutableAfterApproval(): array
    {
        return ['status', 'paid_at'];
    }

    public function onApprovalApproved(): void
    {
        $this->update(['status' => 'APPROVED', 'approved_at' => now()]);
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, ['DRAFT', 'RETURNED', 'REJECTED'], true);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VendorPayment::class)->orderBy('id');
    }
}
