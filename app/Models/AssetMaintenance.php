<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A recurring service for an asset, due by date, by hours or km of work, or both. */
class AssetMaintenance extends Model
{
    use Auditable, BelongsToOrganization;

    protected $table = 'asset_maintenance';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'interval_usage' => 'decimal:3',
            'last_done_on' => 'date',
            'next_due_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(AssetServiceRecord::class, 'maintenance_id')->latest('done_on');
    }
}
