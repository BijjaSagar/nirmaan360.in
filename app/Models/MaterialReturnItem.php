<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\StockAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialReturnItem extends Model
{
    use BelongsToOrganization, StockAppendOnly;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'base_qty' => 'decimal:3'];
    }

    public function materialReturn(): BelongsTo
    {
        return $this->belongsTo(MaterialReturn::class);
    }

    public function issueItem(): BelongsTo
    {
        return $this->belongsTo(MaterialIssueItem::class, 'material_issue_item_id');
    }

    public function grnItem(): BelongsTo
    {
        return $this->belongsTo(GrnItem::class);
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
