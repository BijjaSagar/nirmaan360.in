<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A letter drafted from the hindrance register or from unpaid client bills: a request for more
 * time, a notice of delay or a payment follow-up.
 *
 * Every date, number of days and amount in it is a fact the app read from the records when the
 * draft was prepared; the fact sheet and its fingerprint are kept beside the text. The sentences
 * around the figures are code text, or a person's own words. Once finalised the row can no longer
 * be changed or removed. The app sends nothing: the person prints or copies the letter.
 *
 * A payment follow-up carries rupee figures, so everything that reads one goes through
 * App\Services\SiteDiary\SiteLetters, which refuses a reader who may not see money.
 */
class SiteLetter extends Model
{
    use BelongsToOrganization, Immutable;

    public const EOT_REQUEST = 'eot_request';

    public const DELAY_NOTICE = 'delay_notice';

    public const PAYMENT_FOLLOWUP = 'payment_followup';

    public const TYPES = [self::EOT_REQUEST, self::DELAY_NOTICE, self::PAYMENT_FOLLOWUP];

    public const DRAFT = 'draft';

    public const FINALISED = 'finalised';

    public const STATUSES = [self::DRAFT, self::FINALISED];

    protected $guarded = [];

    /** The figures of a payment letter never leave through a plain toArray(). */
    protected $hidden = ['inputs', 'facts', 'slots', 'body_rendered', 'typed_numbers', 'number_ticks'];

    protected function casts(): array
    {
        return [
            'inputs' => 'array',
            'facts' => 'array',
            'slots' => 'array',
            'typed_numbers' => 'array',
            'number_ticks' => 'array',
            'finalised_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    public function lockedStatuses(): array
    {
        return [self::FINALISED];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function finalisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalised_by');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(SiteLetterHindrance::class);
    }

    /** The register entries the letter cites, oldest start first. A removed entry is still named. */
    public function hindrances(): BelongsToMany
    {
        return $this->belongsToMany(Hindrance::class, 'site_letter_hindrances')
            ->withTrashed()
            ->orderBy('hindrances.started_on')->orderBy('hindrances.id');
    }

    public function isFinalised(): bool
    {
        return $this->status === self::FINALISED;
    }

    /**
     * Changes with every figure and every word of the letter. The finalise form carries it, so a
     * draft that was prepared again in the meantime is not finalised by someone who read the earlier one.
     */
    public function seal(): string
    {
        return hash('sha256', $this->fact_fingerprint."\n".$this->body_rendered);
    }

    /** Whether the letter states rupee amounts. */
    public function carriesMoney(): bool
    {
        return $this->type === self::PAYMENT_FOLLOWUP;
    }

    /** @return list<int> The client bills a payment follow-up was prepared for. */
    public function billIds(): array
    {
        return array_values(array_map('intval', (array) ($this->inputs['bill_ids'] ?? [])));
    }
}
