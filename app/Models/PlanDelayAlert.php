<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanDelayAlert extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['first_seen_on' => 'date', 'resolved_on' => 'date', 'notified_at' => 'datetime', 'days_late' => 'integer'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(PlanActivity::class, 'plan_activity_id');
    }
}
