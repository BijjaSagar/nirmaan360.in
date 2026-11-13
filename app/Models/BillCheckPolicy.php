<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's choice on the bill pre-check: whether every finding must be acknowledged before a bill
 * can be submitted. One row per company at most, and no row means the check is not required.
 */
class BillCheckPolicy extends Model
{
    use Auditable, BelongsToOrganization;

    public const COMPANY_WIDE = true;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['required' => 'boolean'];
    }
}
