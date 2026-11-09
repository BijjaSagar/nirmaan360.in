<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A BOQ line priced for a tender. It becomes a project BOQ item when the tender is converted. */
class CrmTenderBoqItem extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'rate' => 'decimal:2'];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(CrmTender::class, 'tender_id');
    }

    public function amount(): string
    {
        return Dec::money(Dec::of($this->qty)->multipliedBy(Dec::of($this->rate)));
    }
}
