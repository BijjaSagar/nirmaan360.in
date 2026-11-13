<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use App\Services\ContractorControl\PaymentAheadGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Money to a contractor against a contract. Only APPROVED rows count as paid, and a
 * correction is a reversal row with a negative amount. These rows are not vendor_payments
 * or RA bill receipts: the registers are separate and no total reads more than one of them.
 */
class ContractorPayment extends Model
{
    use Approvable, Auditable, BelongsToOrganization, Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount' => 'decimal:2', 'is_advance' => 'boolean', 'override_at' => 'datetime'];
    }

    public function approvalDocType(): string
    {
        return 'contractor';
    }

    /** No payment beyond the work done, past the project's limit, unless the owner overrides in writing. */
    public function approvalGuard(): ?string
    {
        return PaymentAheadGuard::class;
    }

    /** Shown to everyone who can open the approvals page, so it must never carry an amount. */
    public function approvalTitle(): string
    {
        return 'Contractor payment '.$this->number;
    }

    public function lockedStatuses(): array
    {
        return ['APPROVED', 'REJECTED'];
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    /** The one definition of "paid". */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->where('status', 'APPROVED');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class)->withTrashed();
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class)->withTrashed();
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ContractorMilestone::class, 'contractor_milestone_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /** The owner who approved this payment ahead of the work done, when one had to. */
    public function overrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by');
    }
}
