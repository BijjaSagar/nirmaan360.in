<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Why a tender was won, lost or not bid. Editable per company. */
class CrmOutcomeReason extends Model
{
    use BelongsToOrganization;

    public const COMPANY_WIDE = true;

    public const KINDS = ['won', 'lost', 'no_bid'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
