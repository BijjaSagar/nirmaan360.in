<?php

namespace App\Models;

use App\Models\Concerns\PostedStockDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grn extends Model
{
    use PostedStockDocument;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['grn_date' => 'date', 'reversed_at' => 'datetime'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(MaterialStore::class, 'store_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GrnItem::class)->orderBy('id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(MaterialReturn::class);
    }
}
