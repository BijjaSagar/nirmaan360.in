<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One document handed to a reader. A null project_id is a company-level read, such as a tender
 * document; every other read belongs to a project and follows its access limits.
 *
 * Only a running read holds credits. Whoever moves a read out of running releases its hold in the
 * same transaction (CreditService::releaseRead).
 */
class DocumentRead extends Model
{
    use BelongsToOrganization;

    public const DRAFT = 'draft';

    public const PREPARING = 'preparing';

    public const PREPARED = 'prepared';

    public const QUOTED = 'quoted';

    public const RUNNING = 'running';

    public const PAUSED = 'paused';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const CONFIRMED = 'confirmed';

    public const DISCARDED = 'discarded';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** Not yet finished: these are the reads cancelled when a company withdraws its consent. */
    public const OPEN = [self::DRAFT, self::PREPARING, self::PREPARED, self::QUOTED, self::RUNNING, self::PAUSED];

    /** Nothing more is ever read, and no credits can be held. */
    public const CLOSED = [self::CONFIRMED, self::DISCARDED, self::CANCELLED, self::EXPIRED];

    /** The read's own limit stopped it: its hold is used up. */
    public const PAUSE_CREDIT_CAP = 'credit_cap';

    /** The company's wallet could not pay for a page. */
    public const PAUSE_WALLET = 'wallet';

    /** The person who started the read is gone, inactive, or no longer allowed to use this reader. */
    public const PAUSE_STARTER_GONE = 'starter_gone';

    /** The company's consent is off, or was given to an older notice. */
    public const PAUSE_CONSENT_OFF = 'consent_off';

    /** The company's plan no longer includes the AI features. */
    public const PAUSE_PLAN = 'plan';

    /** No key is saved, or the provider is refusing every call for now. */
    public const PAUSE_NOT_CONNECTED = 'not_connected';

    /** The platform admin has switched the readers off again. */
    public const PAUSE_NOT_RELEASED = 'not_released';

    /** A platform-wide spending limit for the day or the month is reached. */
    public const PAUSE_PLATFORM_CEILING = 'platform_ceiling';

    /** One of this company's own limits is reached: its share of the day, its unpaid cost, its failures, its trial. */
    public const PAUSE_COMPANY_LIMIT = 'company_limit';

    /** The provider kept answering "too many requests". */
    public const PAUSE_PROVIDER_LIMIT = 'provider_limit';

    /** Calls keep failing for reasons that are nobody's fault here, or nothing has moved for a long time. */
    public const PAUSE_INFRA = 'infra';

    /** Too many pages came back unusable. The starter may continue such a read once. */
    public const PAUSE_MANY_FAILURES = 'many_failures';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'review' => 'array',
            'credits_estimate' => 'decimal:4',
            'credits_cap' => 'decimal:4',
            'credits_held' => 'decimal:4',
            'credits_used' => 'decimal:4',
            'credits_burned' => 'decimal:4',
            'cost_usd' => 'decimal:6',
            'absorbed_usd' => 'decimal:6',
            'quoted_at' => 'datetime',
            'prepare_started_at' => 'datetime',
            'started_at' => 'datetime',
            'last_dispatched_at' => 'datetime',
            'last_progress_at' => 'datetime',
            'finished_at' => 'datetime',
            'files_purged_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED, true);
    }

    /**
     * What is left of this read's limit: the cap less what was charged and what failed pages used up.
     * A resumed read may hold no more than this.
     */
    public function capLeft(): string
    {
        $left = Dec::of($this->credits_cap)->minus(Dec::of($this->credits_used))->minus(Dec::of($this->credits_burned));

        return (string) ($left->isNegative() ? BigDecimal::zero() : $left)->toScale(4, RoundingMode::Down);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(DocumentReadPage::class)->orderBy('page_no')->orderBy('id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The person who started the read. Pages are sent only while this person may still use the reader. */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
