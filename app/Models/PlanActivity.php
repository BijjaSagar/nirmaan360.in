<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanActivity extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'planned_start' => 'date',
            'planned_end' => 'date',
            'baseline_start' => 'date',
            'baseline_end' => 'date',
            'is_milestone' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function wbsNode(): BelongsTo
    {
        return $this->belongsTo(PlanWbsNode::class, 'wbs_node_id');
    }

    public function boqLinks(): HasMany
    {
        return $this->hasMany(PlanBoqLink::class)->orderBy('id');
    }

    /** Links to the activities that must come before this one. */
    public function predecessorLinks(): HasMany
    {
        return $this->hasMany(PlanDependency::class, 'successor_id');
    }

    /** Links to the activities that wait on this one. */
    public function successorLinks(): HasMany
    {
        return $this->hasMany(PlanDependency::class, 'predecessor_id');
    }
}
