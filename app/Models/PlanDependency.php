<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanDependency extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['lag_days' => 'integer'];
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(PlanActivity::class, 'predecessor_id');
    }

    public function successor(): BelongsTo
    {
        return $this->belongsTo(PlanActivity::class, 'successor_id');
    }
}
