<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionItem extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }
}
