<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A contractor's quote. Kept for good: a revised quote is a new row. */
class ContractorBid extends Model
{
    use Auditable, BelongsToOrganization;

    public const PROJECT_VIA = 'tender';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quoted_amount' => 'decimal:2', 'received_on' => 'date', 'is_selected' => 'boolean'];
    }

    public function isCurrent(): bool
    {
        return $this->superseded_by_id === null;
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(ContractorTender::class, 'contractor_tender_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContractorBidItem::class)->orderBy('id');
    }
}
