<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    use BelongsToOrganization;

    public const PROJECT_VIA = 'transfer';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'base_qty' => 'decimal:3', 'received_base_qty' => 'decimal:3'];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(MaterialUnit::class, 'unit_id');
    }

    /** Lost in transit, in base units. Zero until the transfer is received. */
    public function shortQty(): string
    {
        return $this->received_base_qty === null
            ? Dec::qty(0)
            : Dec::qty(Dec::of($this->base_qty)->minus(Dec::of($this->received_base_qty)));
    }
}
