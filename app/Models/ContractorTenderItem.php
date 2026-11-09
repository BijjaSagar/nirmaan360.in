<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractorTenderItem extends Model
{
    use BelongsToOrganization;

    public const PROJECT_VIA = 'tender';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'boq_rate' => 'decimal:2'];
    }

    public function amount(): string
    {
        return Dec::money(Dec::of($this->qty)->multipliedBy(Dec::of($this->boq_rate)));
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(ContractorTender::class, 'contractor_tender_id');
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class)->withTrashed();
    }
}
