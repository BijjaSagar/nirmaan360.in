<?php

namespace App\Services\SiteAlerts\Spend;

use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\Project;
use App\Services\SiteAlerts\Contracts\SpendSource;
use App\Support\Dec;
use Brick\Math\BigDecimal;

/** Expenses paid from the project's petty cash wallets. Top-ups are not spend. */
class PettyCashSpend implements SpendSource
{
    public function key(): string
    {
        return 'petty_cash';
    }

    public function label(): string
    {
        return 'Petty cash';
    }

    public function spent(Project $project): BigDecimal
    {
        $sum = PettyCashEntry::whereIn('petty_cash_wallet_id', PettyCashWallet::where('project_id', $project->id)->select('id'))
            ->where('type', 'expense')
            ->sum('amount');

        return Dec::of(Dec::money($sum));
    }
}
