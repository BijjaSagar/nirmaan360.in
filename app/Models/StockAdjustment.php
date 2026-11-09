<?php

namespace App\Models;

use App\Models\Concerns\PostedStockDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustment extends Model
{
    use PostedStockDocument;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'base_qty' => 'decimal:3',
            'adjustment_date' => 'date',
            'reversed_at' => 'datetime',
        ];
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
}
