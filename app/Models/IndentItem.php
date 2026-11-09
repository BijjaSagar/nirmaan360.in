<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndentItem extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'base_qty' => 'decimal:3'];
    }

    public function indent(): BelongsTo
    {
        return $this->belongsTo(Indent::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(MaterialUnit::class, 'unit_id');
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class)->withTrashed();
    }
}
