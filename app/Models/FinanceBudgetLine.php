<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class FinanceBudgetLine extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
