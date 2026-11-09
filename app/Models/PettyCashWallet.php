<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PettyCashWallet extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PettyCashEntry::class);
    }

    public function balance(): string
    {
        $balance = Dec::of(0);

        foreach ($this->entries as $entry) {
            $balance = $entry->type === 'topup' ? $balance->plus($entry->amount) : $balance->minus($entry->amount);
        }

        return Dec::money($balance);
    }
}
