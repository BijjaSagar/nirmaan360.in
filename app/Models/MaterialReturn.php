<?php

namespace App\Models;

use App\Models\Concerns\PostedStockDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialReturn extends Model
{
    use PostedStockDocument;

    public const TO_STORE = 'to_store';

    public const TO_VENDOR = 'to_vendor';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['return_date' => 'date', 'reversed_at' => 'datetime'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(MaterialStore::class, 'store_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(MaterialIssue::class, 'material_issue_id');
    }

    public function grn(): BelongsTo
    {
        return $this->belongsTo(Grn::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaterialReturnItem::class)->orderBy('id');
    }

    public function isToVendor(): bool
    {
        return $this->return_type === self::TO_VENDOR;
    }
}
