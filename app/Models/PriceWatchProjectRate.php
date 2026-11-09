<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one project's BOQ assumed for a watched material: the rate it was priced at, and, when
 * no material norm gives it, the quantity the BOQ needs. The water tank quoted at ₹6 a litre.
 */
class PriceWatchProjectRate extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['boq_rate' => 'decimal:2', 'boq_qty' => 'decimal:3'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function watchMaterial(): BelongsTo
    {
        return $this->belongsTo(PriceWatchMaterial::class, 'price_watch_material_id');
    }
}
