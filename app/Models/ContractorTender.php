<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One or more BOQ items put out to several contractors. */
class ContractorTender extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'awarded_at' => 'datetime'];
    }

    public function isOpen(): bool
    {
        return $this->status === 'OPEN';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContractorTenderItem::class)->orderBy('id');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(ContractorBid::class)->orderBy('id');
    }

    public function boqAmount(): string
    {
        $total = Dec::of(0);

        foreach ($this->items as $item) {
            $total = $total->plus(Dec::of($item->amount()));
        }

        return Dec::money($total);
    }
}
