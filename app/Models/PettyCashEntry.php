<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashEntry extends Model
{
    use Auditable, BelongsToOrganization;

    public const PROJECT_VIA = 'wallet';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'entry_date' => 'date'];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(PettyCashWallet::class, 'petty_cash_wallet_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
