<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A private photo on a progress entry or on a stage cell. The file is read only through a route that checks the
 * signed-in person's company and project. There is no touch on the entry: entries are never written to again.
 */
class ProgressPhoto extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(ProgressEntry::class, 'progress_entry_id');
    }

    public function cell(): BelongsTo
    {
        return $this->belongsTo(MepStageCell::class, 'mep_stage_cell_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
