<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A non-conformance report: what went wrong, why, how it was put right and what that cost. */
class Ncr extends Model
{
    use Auditable, BelongsToOrganization, Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raised_on' => 'date',
            'due_date' => 'date',
            'rework_cost' => 'decimal:2',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** A closed NCR is the QA record. A repeat problem gets a new NCR. */
    public function lockedStatuses(): array
    {
        return ['closed'];
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null && $this->due_date->isPast() && ! in_array($this->status, ['resolved', 'closed'], true);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(FieldAttachment::class, 'attachable');
    }
}
