<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** An incident or a near miss. Closing needs the root cause and the corrective action. */
class SafetyIncident extends Model
{
    use Auditable, BelongsToOrganization, Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function lockedStatuses(): array
    {
        return ['closed'];
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function isNearMiss(): bool
    {
        return $this->kind === 'near_miss';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(FieldAttachment::class, 'attachable');
    }
}
