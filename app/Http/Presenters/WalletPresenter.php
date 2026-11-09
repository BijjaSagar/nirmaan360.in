<?php

namespace App\Http\Presenters;

use App\Models\PettyCashWallet;

final class WalletPresenter
{
    public static function present(PettyCashWallet $wallet): array
    {
        return [
            'id' => $wallet->id,
            'project_id' => $wallet->project_id,
            'name' => $wallet->name,
            'user_id' => $wallet->user_id,
            'balance' => $wallet->balance(),
            'updated_at' => $wallet->updated_at?->toIso8601String(),
        ];
    }
}
