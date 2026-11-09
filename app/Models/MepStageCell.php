<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Where one plumbing or electrical stage stands on one floor or flat. */
class MepStageCell extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status_at' => 'datetime'];
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(ProjectFloor::class, 'project_floor_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MepStageEvent::class)->orderBy('id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ProgressPhoto::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
