<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A daily report spoken on the phone: the words heard, and the proposal made from them. Only the
 * person who spoke it reads it. It is never a DPR: the phone fills its own form from the proposal
 * after the person has checked it, and sends the DPR the usual way.
 *
 * Its project_id puts it under the project limit of people restricted to chosen projects.
 */
class DprVoiceDraft extends Model
{
    use BelongsToOrganization;

    /** Waiting for the model. */
    public const QUEUED = 'queued';

    /** The proposal is there to be checked. */
    public const READY = 'ready';

    /** No proposal could be made. fail_reason says why; the words are still there. */
    public const FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'speech' => 'array',
            'proposal' => 'array',
            'checks' => 'array',
            'changed' => 'array',
            'recorded_at' => 'datetime',
            'text_purged_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
