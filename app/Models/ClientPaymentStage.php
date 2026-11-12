<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\LockedWithQuotation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One stage of a quotation's payment schedule: a share of the total or a typed amount, and when it was reached. */
class ClientPaymentStage extends Model
{
    use Auditable, BelongsToOrganization, LockedWithQuotation;

    public const PERCENT = 'percent';

    public const AMOUNT = 'amount';

    public const BASES = [self::PERCENT, self::AMOUNT];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'percent' => 'decimal:4',
            'amount' => 'decimal:2',
            'reached_on' => 'date',
        ];
    }

    /** Reaching a stage is recorded after the quotation is issued. Nothing else about it changes then. */
    public function changeableOnceIssued(): array
    {
        return ['reached_on', 'reached_by'];
    }

    public function isPercent(): bool
    {
        return $this->basis === self::PERCENT;
    }

    public function isReached(): bool
    {
        return $this->reached_on !== null;
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(ClientQuotation::class, 'quotation_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reachedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reached_by');
    }
}
