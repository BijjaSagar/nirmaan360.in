<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Part of a worker's day spent on one BOQ activity. */
class LabourAllocation extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'man_days' => 'decimal:3'];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class)->withTrashed();
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class)->withTrashed();
    }
}
