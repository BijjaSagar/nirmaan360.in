<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaBillItem extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'prev_qty' => 'decimal:3',
            'current_qty' => 'decimal:3',
            'cumulative_qty' => 'decimal:3',
            'prev_amount' => 'decimal:2',
            'current_amount' => 'decimal:2',
            'cumulative_amount' => 'decimal:2',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(RaBill::class, 'ra_bill_id');
    }
}
