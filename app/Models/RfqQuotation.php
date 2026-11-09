<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RfqQuotation extends Model
{
    use Auditable, BelongsToOrganization;

    public const PROJECT_VIA = 'rfq';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quote_date' => 'date', 'valid_until' => 'date', 'total' => 'decimal:2'];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(RfqQuotationItem::class);
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->lt(today());
    }
}
