<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A report that is emailed on a schedule by the Laravel scheduler. */
class ReportSchedule extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
