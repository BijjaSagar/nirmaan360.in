<?php

namespace App\Models;

use App\Models\Concerns\PostedStockDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Material that arrived on site with no purchase order. Posts to the stock ledger like a receipt. */
class DeliveryChallan extends Model
{
    use PostedStockDocument;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'challan_date' => 'date',
            'bill_date' => 'date',
            'qty' => 'decimal:3',
            'base_qty' => 'decimal:3',
            'challan_qty' => 'decimal:3',
            'bill_amount' => 'decimal:2',
            'bill_attached_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /** @return list<string> The stock side is fixed once posted; the bill arrives later. */
    public function mutableAfterApproval(): array
    {
        return ['status', 'reversed_by', 'reversed_at', 'reversal_reason', 'bill_status', 'bill_path', 'bill_no', 'bill_date', 'bill_amount', 'vendor_bill_id', 'bill_attached_by', 'bill_attached_at'];
    }

    public function billPending(): bool
    {
        return $this->bill_status === 'pending';
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id')->withTrashed();
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(MaterialStore::class, 'store_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(MaterialUnit::class, 'unit_id');
    }

    public function shortDeliveries(): HasMany
    {
        return $this->hasMany(MaterialShortDelivery::class)->orderBy('id');
    }
}
