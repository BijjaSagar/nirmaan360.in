<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentCreditWallet extends Model
{
    use BelongsToOrganization;

    protected $table = 'credit_wallets';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['included_balance' => 'decimal:4', 'purchased_balance' => 'decimal:4'];
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(AgentCreditLedger::class, 'credit_wallet_id');
    }

    public function available(): string
    {
        return (string) Dec::of($this->included_balance)->plus(Dec::of($this->purchased_balance))->toScale(4);
    }
}
