<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfqQuotationItem extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rate' => 'decimal:2', 'gst_pct' => 'decimal:2', 'amount' => 'decimal:2', 'gst_amount' => 'decimal:2'];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(RfqQuotation::class, 'rfq_quotation_id');
    }

    public function rfqItem(): BelongsTo
    {
        return $this->belongsTo(RfqItem::class);
    }
}
