<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's own value for one of the typical figures the auto BOQ uses, such as steel per cubic
 * metre of slab. Keys with no row use the defaults in App\Services\AutoBoq\Assumptions.
 */
class AutoBoqAssumption extends Model
{
    use Auditable, BelongsToOrganization;

    public const COMPANY_WIDE = true;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'decimal:3'];
    }
}
