<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dry run by the platform's own staff: a reader or a text call tried on a document that was
 * uploaded for the test. It belongs to no company, so it has no organization_id and no tenant
 * scope. Only platform admin routes ever load one.
 *
 * Its files and what the model returned are deleted at purge_after. The counts, the tokens and the
 * cost stay, because the month's spending is added up from them.
 */
class ReaderTrial extends Model
{
    /** Pages are being uploaded or text is being pasted. Nothing has been sent. */
    public const DRAFT = 'draft';

    public const RUNNING = 'running';

    /** Stopped before every page was sent: no key, the provider refuses, or an allowance is used. It can be started again. */
    public const PAUSED = 'paused';

    public const DONE = 'done';

    /** Its files were deleted before it finished. */
    public const EXPIRED = 'expired';

    /** The admin deleted it. The row stays only for the totals and is no longer listed. */
    public const DISCARDED = 'discarded';

    /** Still able to take pages or send them. */
    public const OPEN = [self::DRAFT, self::RUNNING, self::PAUSED];

    public const PICTURES = 'pictures';

    public const TEXT = 'text';

    /** Hours a test document and what was read from it stay on the server. */
    public const KEEP_HOURS = 24;

    /** No key is saved, or the provider is refusing every call for now. */
    public const PAUSE_NOT_CONNECTED = 'not_connected';

    /** Today's allowance for dry runs is used. */
    public const PAUSE_ALLOWANCE = 'trial_allowance';

    /** The month's limit for the readers is reached. */
    public const PAUSE_MONTH = 'month_limit';

    /** The provider kept answering "too many requests". */
    public const PAUSE_PROVIDER_LIMIT = 'provider_limit';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'score' => 'array',
            'options' => 'array',
            'cost_usd' => 'decimal:6',
            'allowed_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'purge_after' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** Whether its files and results are gone, or due to go. Nothing more is stored or sent for it. */
    public function isPurged(): bool
    {
        return $this->purged_at !== null || $this->purge_after === null || $this->purge_after->isPast();
    }

    /** A dry run of a text call rather than of a reader. */
    public function isTextCall(): bool
    {
        return $this->purpose !== null;
    }

    public function sendsText(): bool
    {
        return $this->isTextCall() || $this->material === self::TEXT;
    }

    public function pages(): HasMany
    {
        return $this->hasMany(ReaderTrialPage::class)->orderBy('page_no')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
