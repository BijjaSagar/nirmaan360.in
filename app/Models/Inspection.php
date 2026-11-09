<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One filled-in checklist for a place and a day. Locked once it is completed. */
class Inspection extends Model
{
    use Auditable, BelongsToOrganization, Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['inspected_on' => 'date', 'completed_at' => 'datetime'];
    }

    public function lockedStatuses(): array
    {
        return ['passed', 'failed'];
    }

    public function isOpen(): bool
    {
        return $this->status === 'in_progress';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InspectionItem::class)->orderBy('sort');
    }
}
