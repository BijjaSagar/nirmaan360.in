<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One move of an asset between sites, yards or stores. History is never edited. */
class AssetMovement extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['moved_on' => 'date'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function fromProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'from_project_id');
    }

    public function toProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'to_project_id');
    }

    public function mover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }

    /** Where it went, for display. */
    public function destination(): string
    {
        return collect([$this->toProject?->name, $this->to_location])->filter()->join(' · ') ?: '—';
    }

    public function origin(): string
    {
        return collect([$this->fromProject?->name, $this->from_location])->filter()->join(' · ') ?: '—';
    }
}
