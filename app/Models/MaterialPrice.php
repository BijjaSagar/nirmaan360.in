<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dated price of a watched material: rate per unit, the day it held, and where it came from.
 * The newest row by date is the current price. Nothing here is a live feed: a row exists only
 * because a person typed it, uploaded it, or took it from the shared list.
 */
class MaterialPrice extends Model
{
    use Auditable, BelongsToOrganization;

    public const COMPANY_WIDE = true;

    public const MANUAL = 'manual';

    public const EXCEL = 'excel';

    public const SHARED = 'shared';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rate' => 'decimal:2', 'priced_on' => 'date'];
    }

    public function watchMaterial(): BelongsTo
    {
        return $this->belongsTo(PriceWatchMaterial::class, 'price_watch_material_id');
    }

    public function notedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'noted_by');
    }
}
