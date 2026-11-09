<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use Auditable, BelongsToOrganization, Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'po_date' => 'date',
            'delivery_by' => 'date',
            'subtotal' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'issued_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    public function lockedStatuses(): array
    {
        return ['ISSUED', 'PARTIAL', 'RECEIVED', 'CLOSED', 'CANCELLED'];
    }

    /** @return list<string> */
    public function mutableAfterApproval(): array
    {
        return ['status', 'closed_at'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id')->withTrashed();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(MaterialStore::class, 'store_id');
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('id');
    }

    public function grns(): HasMany
    {
        return $this->hasMany(Grn::class)->latest('id');
    }

    public function canReceive(): bool
    {
        return in_array($this->status, ['ISSUED', 'PARTIAL'], true);
    }
}
