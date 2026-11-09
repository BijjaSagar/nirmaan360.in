<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How much of a BOQ line is meant to be done on one floor. Floor percentages are measured against it. */
class FloorWorkPlan extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['planned_qty' => 'decimal:3'];
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(ProjectFloor::class, 'project_floor_id');
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }
}
