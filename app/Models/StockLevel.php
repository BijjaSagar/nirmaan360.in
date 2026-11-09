<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The lock row for one store and material, and its reorder level.
 * It holds no quantity on purpose: stock is always the sum of stock_ledger.
 */
class StockLevel extends Model
{
    use Auditable, BelongsToOrganization;

    public const PROJECT_VIA = 'store';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['reorder_level' => 'decimal:3'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(MaterialStore::class, 'store_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
