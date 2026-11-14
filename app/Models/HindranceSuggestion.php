<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to have the AI group the hindrance notes of a project's daily reports.
 *
 * dpr_ids are the reports that were sent, in order: the AI knows them as D1, D2 and so on, and its
 * answer is read against these reports alone. What it proposes goes into the register as entries
 * waiting to be confirmed; this row keeps how many, and how many parts of the answer could not be
 * tied to the words of a report and were left out. No word of a note or of the answer is kept here.
 */
class HindranceSuggestion extends Model
{
    use BelongsToOrganization;

    public const WAITING = 'waiting';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const STATUSES = [self::WAITING, self::DONE, self::FAILED];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'dpr_ids' => 'array',
            'from_date' => 'date',
            'to_date' => 'date',
            'finished_at' => 'datetime',
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

    public function isWaiting(): bool
    {
        return $this->status === self::WAITING;
    }

    /** @return list<int> The reports sent, in the order of their refs. */
    public function reportIds(): array
    {
        return array_values(array_map('intval', (array) $this->dpr_ids));
    }
}
