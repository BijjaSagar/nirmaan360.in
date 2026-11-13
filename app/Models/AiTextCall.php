<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request to the model that sends text and gets a small answer back: the free sentences of a
 * letter, a ranking of rate candidates, a grouping of hindrance notes, a spoken report turned into
 * fields. The row records that it happened, who asked, what it cost and how it ended. It holds
 * neither what was sent nor what came back: the request is built again from the records each time
 * it is sent, and the answer goes to the feature that asked for it.
 *
 * A null project_id is a company-level call; every other call follows its project's access limits.
 *
 * While a call is queued or running its max_credits are held back in the company's wallet. Every
 * way out of those two states is final, so nothing stays held.
 */
class AiTextCall extends Model
{
    use BelongsToOrganization;

    /** Waiting for a worker. Also the state between a refused answer and the one corrective request. */
    public const QUEUED = 'queued';

    /** A worker has claimed it and is with the provider. */
    public const RUNNING = 'running';

    /** The answer was accepted, handed to the feature and charged. */
    public const DONE = 'done';

    /** No usable answer: the provider, the queue or a gate stopped it. Not charged. fail_reason says why. */
    public const FAILED = 'failed';

    /** The feature's own checks refused the answer, and the corrective request's answer too. Not charged. */
    public const REJECTED = 'rejected';

    /** Still holding credits. */
    public const OPEN = [self::QUEUED, self::RUNNING];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'correction' => 'array',
            'cost_estimated' => 'boolean',
            'max_credits' => 'decimal:4',
            'credits' => 'decimal:4',
            'cost_usd' => 'decimal:6',
            'absorbed_usd' => 'decimal:6',
            'claimed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** Whether an answer to this call was already refused once, so the next one is the corrective request. */
    public function isCorrective(): bool
    {
        return $this->correction !== null;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The person the call was made for. It is sent only while this person may still use the AI features. */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
