<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StockAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** One movement. Positive adds stock, negative removes it. Quantities are in base units. */
class StockLedgerEntry extends Model
{
    use BelongsToOrganization, StockAppendOnly;

    public const UPDATED_AT = null;

    protected $table = 'stock_ledger';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'movement_date' => 'date', 'created_at' => 'datetime'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(MaterialStore::class, 'store_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class)->withTrashed();
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
