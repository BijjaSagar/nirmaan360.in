<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class MaterialUnit extends Model
{
    use Auditable, BelongsToOrganization;

    public const COMPANY_WIDE = true;

    protected $guarded = [];
}
