<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\ProgressAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One change of a stage cell's status. Only ever added to. */
class MepStageEvent extends Model
{
    use BelongsToOrganization, ProgressAppendOnly;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['applied' => 'boolean', 'occurred_at' => 'datetime'];
    }

    public function cell(): BelongsTo
    {
        return $this->belongsTo(MepStageCell::class, 'mep_stage_cell_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
