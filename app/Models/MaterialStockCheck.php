<?php

namespace App\Models;

use App\Models\Concerns\PostedStockDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A physical count. The difference from the book goes to the ledger as an adjustment. */
class MaterialStockCheck extends Model
{
    use PostedStockDocument;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'check_date' => 'date',
            'physical_qty' => 'decimal:3',
            'physical_base_qty' => 'decimal:3',
            'book_qty' => 'decimal:3',
            'variance_qty' => 'decimal:3',
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
