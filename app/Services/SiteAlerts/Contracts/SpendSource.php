<?php

namespace App\Services\SiteAlerts\Contracts;

use App\Models\Project;
use Brick\Math\BigDecimal;

/**
 * Money already gone on a project, from one module's records. The dashboard adds every source
 * registered under spend_sources to show spend against budget. Count each rupee once: a module
 * that records payments against bills another source already counts must register only one.
 */
interface SpendSource
{
    /** A short stable key, for example petty_cash. */
    public function key(): string;

    /** English label, passed through __() where shown. Add it to your module's hi and mr files. */
    public function label(): string;

    /** Exact decimal; reversals count as negative so they cancel out. */
    public function spent(Project $project): BigDecimal;
}
