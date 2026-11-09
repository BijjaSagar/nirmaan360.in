<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Money paid against a vendor bill or a subcontractor RA bill. Corrected only by a reversal. */
class VendorPayment extends Model
{
    use Auditable, BelongsToOrganization, Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount' => 'decimal:2'];
    }

    public function lockedStatuses(): array
    {
        return ['POSTED'];
    }

    public function voucherNo(): string
    {
        return 'PAY-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    public function documentNo(): string
    {
        return $this->vendorBill?->bill_no ?? $this->raBill?->number ?? '—';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function vendorBill(): BelongsTo
    {
        return $this->belongsTo(VendorBill::class);
    }

    public function raBill(): BelongsTo
    {
        return $this->belongsTo(RaBill::class);
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
