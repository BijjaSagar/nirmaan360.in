<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class AgentCreditLedger extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $table = 'credit_ledger';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['credits' => 'decimal:4', 'balance_after' => 'decimal:4', 'created_at' => 'datetime'];
    }
}
