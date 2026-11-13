<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call to the model in a dry run: a page picture read with one pass, or one pasted text.
 * Like its trial it belongs to no company.
 */
class ReaderTrialPage extends Model
{
    public const WAITING = 'waiting';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const READ = 'read';

    public const FAILED = 'failed';

    /** Never sent: the trial was deleted or its files were removed first. */
    public const SKIPPED = 'skipped';

    /** The model answered, but not usably. These are what a dry run is there to count, so they are not sent again. */
    public const BEHAVIOUR_FAILURES = ['invalid_output', 'cut_off', 'declined', 'rejected'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'correction' => 'array',
            'result' => 'array',
            'marks' => 'array',
            'cost_estimated' => 'boolean',
            'cost_usd' => 'decimal:6',
            'claimed_at' => 'datetime',
            'not_before' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** A failure that says nothing about the reader: the call itself went wrong, so it may be sent again. */
    public function mayBeSentAgain(): bool
    {
        return $this->status === self::FAILED && ! in_array($this->fail_reason, self::BEHAVIOUR_FAILURES, true);
    }

    /** Output tokens a second of the last answer, or null when there was none. */
    public function tokensPerSecond(): ?string
    {
        if ((int) $this->latency_ms <= 0 || (int) $this->output_tokens <= 0) {
            return null;
        }

        return number_format((int) $this->output_tokens * 1000 / (int) $this->latency_ms, 1, '.', '');
    }

    public function trial(): BelongsTo
    {
        return $this->belongsTo(ReaderTrial::class, 'reader_trial_id');
    }
}
