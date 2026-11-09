<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractorBidItem extends Model
{
    use BelongsToOrganization;

    public const PROJECT_VIA = 'bid';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rate' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(ContractorBid::class, 'contractor_bid_id');
    }

    public function tenderItem(): BelongsTo
    {
        return $this->belongsTo(ContractorTenderItem::class, 'contractor_tender_item_id');
    }
}
