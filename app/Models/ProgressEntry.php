<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\ProgressAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Work done on one BOQ line on one day. A correction is a new entry with a negative quantity. */
class ProgressEntry extends Model
{
    use Auditable, BelongsToOrganization, ProgressAppendOnly;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'qty_done' => 'decimal:3',
            'is_pending' => 'boolean',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(ProjectFloor::class, 'project_floor_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /** Corrections made to this entry, oldest first. */
    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'corrects_entry_id')->orderBy('id');
    }

    /** The entries that closed this pending remark. */
    public function resolutions(): HasMany
    {
        return $this->hasMany(self::class, 'resolves_entry_id')->orderBy('id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ProgressPhoto::class);
    }

    public function isCorrection(): bool
    {
        return $this->corrects_entry_id !== null;
    }

    public function isResolution(): bool
    {
        return $this->resolves_entry_id !== null;
    }
}
