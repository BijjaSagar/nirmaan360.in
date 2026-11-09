<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaBillDeduction extends Model
{
    use BelongsToOrganization;

    public const PROJECT_VIA = 'bill';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['pct' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(RaBill::class, 'ra_bill_id');
    }
}
