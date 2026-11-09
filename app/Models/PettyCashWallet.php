<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Dec;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PettyCashWallet extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Someone who may not see money still sees the wallet they hold, because they enter
     * those expenses themselves. Other people's wallets, and so the company's totals, stay out of reach.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return Money::visible($user) ? $query : $query->where('user_id', $user->id);
    }

    public function isVisibleTo(User $user): bool
    {
        return Money::visible($user) || (int) $this->user_id === (int) $user->id;
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
