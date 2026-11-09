<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Worker extends Model
{
    use Auditable, BelongsToOrganization, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['daily_wage' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'contractor_id');
    }
}
