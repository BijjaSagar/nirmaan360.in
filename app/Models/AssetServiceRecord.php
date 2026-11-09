<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A service or repair that was carried out, with what it cost. */
class AssetServiceRecord extends Model
{
    use Auditable, BelongsToOrganization;

    public const PROJECT_VIA = 'asset';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['done_on' => 'date', 'cost' => 'decimal:2'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(AssetMaintenance::class, 'maintenance_id');
    }
}
