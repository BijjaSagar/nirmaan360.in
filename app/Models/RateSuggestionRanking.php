<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One part of a press of "Rank with AI" on a run: a few of its lines, sent in one text call.
 *
 * It has a project_id, so a person limited to chosen projects never reaches a part of another
 * project. What is sent (descriptions and units, never a rate) is kept in "material" only while the
 * call is open, because the call runs on the queue with nobody signed in and may not read the
 * sources itself. The answer is kept on the run's lines; App\Services\RateHelper\Ranking\RateRanking
 * is the way in and out.
 */
class RateSuggestionRanking extends Model
{
    use BelongsToOrganization;

    /** Sent to the model, or about to be. */
    public const ASKED = 'asked';

    /** The answer was kept on the lines. */
    public const RANKED = 'ranked';

    /** Nothing was kept. reason says why. */
    public const FAILED = 'failed';

    protected $guarded = [];

    protected $hidden = ['material'];

    protected function casts(): array
    {
        return [
            'material' => 'array',
            'ranked_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(RateSuggestionRun::class, 'run_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isAsked(): bool
    {
        return $this->status === self::ASKED;
    }
}
