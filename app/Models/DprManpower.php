<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DprManpower extends Model
{
    use BelongsToOrganization;

    public const PROJECT_VIA = 'dpr';

    protected $table = 'dpr_manpower';

    protected $guarded = [];

    public function dpr(): BelongsTo
    {
        return $this->belongsTo(Dpr::class);
    }
}
