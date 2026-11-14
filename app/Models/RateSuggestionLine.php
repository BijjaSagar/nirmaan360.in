<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One picked BOQ line of a run and the shortlist found for it. The shortlist holds refs and row ids
 * only: no rate, no description, no project or tender name. Accepting writes the rate that was read
 * from the source row at that moment, with who accepted it and when.
 */
class RateSuggestionLine extends Model
{
    use Auditable, BelongsToOrganization;

    /** The rate becomes the BOQ line's rate, as a revision with a reason. */
    public const SET_BOQ_RATE = 'set_boq_rate';

    /** The BOQ rate stays; the rate is noted as today's current rate on the budget screen. */
    public const NOTE_CURRENT_RATE = 'note_current_rate';

    public const MODES = [self::SET_BOQ_RATE, self::NOTE_CURRENT_RATE];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'candidates' => 'array',
            'accepted_rate' => 'decimal:2',
            'accepted_at' => 'datetime',
            'skipped_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(RateSuggestionRun::class, 'run_id');
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isSkipped(): bool
    {
        return $this->accepted_at === null && $this->skipped_at !== null;
    }

    /** Still waits for a person to accept a rate or skip the line. */
    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->skipped_at === null;
    }

    /**
     * Whether the AI has ranked this line's candidates. Only candidates that were sent are marked, and
     * a line is marked only when the answer said something usable about at least one of them.
     */
    public function isRanked(): bool
    {
        foreach ($this->candidates ?? [] as $candidate) {
            if (($candidate['ranked'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * One entry of the shortlist. same_work, note, ranked and rank are filled in by the AI ranking
     * (App\Services\RateHelper\Ranking): what the model said about the row, never a rate.
     *
     * @return array{ref:string,source:string,row_id:int,score:?int,same_work:?string,note:?string,note_locale?:?string,ranked?:bool,rank?:?int}|null
     */
    public function candidate(string $ref): ?array
    {
        foreach ($this->candidates ?? [] as $candidate) {
            if (($candidate['ref'] ?? null) === $ref) {
                return $candidate;
            }
        }

        return null;
    }
}
