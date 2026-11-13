<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A daily report a hindrance entry rests on. The report's date, its status and what it said under
 * hindrances are copied here as they stood when the report was cited, so the entry keeps its
 * grounds even if the report is changed or removed later. The register compares the copy with
 * the report as it is today and says when they differ.
 *
 * Rows are replaced together with their entry, and the entry's audit log records the change.
 */
class HindranceSource extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['report_date' => 'date'];
    }

    public function hindrance(): BelongsTo
    {
        return $this->belongsTo(Hindrance::class);
    }

    /** The report itself, if it is still there. A removed report is found too, so the register can say so. */
    public function dpr(): BelongsTo
    {
        return $this->belongsTo(Dpr::class)->withTrashed();
    }
}
