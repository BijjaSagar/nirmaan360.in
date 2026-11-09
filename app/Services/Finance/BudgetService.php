<?php

namespace App\Services\Finance;

use App\Models\FinanceBudgetLine;
use App\Models\PettyCashEntry;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\VendorBill;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/** Budget per cost head against what has actually been billed or spent, before GST. */
class BudgetService
{
    /** @param array<string, mixed> $amounts Cost head => budget. Blank or zero removes the line. */
    public function save(Project $project, array $amounts): void
    {
        DB::transaction(function () use ($project, $amounts) {
            foreach (config('modules.finance.cost_heads') as $head) {
                $amount = Dec::money($amounts[$head] ?? 0);
                $line = FinanceBudgetLine::where('project_id', $project->id)->where('cost_head', $head)->first();

                if (Dec::of($amount)->isZero()) {
                    $line?->delete();
                } elseif ($line) {
                    $line->update(['amount' => $amount]);
                } else {
                    FinanceBudgetLine::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'cost_head' => $head, 'amount' => $amount]);
                }
            }
        });
    }

    /**
     * @return array{rows:list<array{head:string,budget:string,actual:string,variance:string,used_pct:?string}>, total_budget:string, total_actual:string, total_variance:string, billed:string, margin:string, contract_value:string}
     */
    public function report(Project $project): array
    {
        $budgets = FinanceBudgetLine::where('project_id', $project->id)->pluck('amount', 'cost_head');
        $actuals = $this->actuals($project);
        $heads = collect(config('modules.finance.cost_heads'))->concat($budgets->keys())->concat(array_keys($actuals))->unique()->values();

        $totalBudget = BigDecimal::zero();
        $totalActual = BigDecimal::zero();
        $rows = [];

        foreach ($heads as $head) {
            $budget = Dec::of($budgets[$head] ?? 0);
            $actual = $actuals[$head] ?? BigDecimal::zero();
            $totalBudget = $totalBudget->plus($budget);
            $totalActual = $totalActual->plus($actual);

            $rows[] = [
                'head' => $head,
                'budget' => Dec::money($budget),
                'actual' => Dec::money($actual),
                'variance' => Dec::money($budget->minus($actual)),
                'used_pct' => $budget->isPositive() ? (string) $actual->multipliedBy(100)->dividedBy($budget, 1, RoundingMode::HalfUp) : null,
            ];
        }

        $billed = RaBill::where('project_id', $project->id)->where('type', 'client')->whereIn('status', SettlementService::BILLED)->get()
            ->reduce(fn (BigDecimal $sum, RaBill $bill) => $sum->plus(Dec::of($bill->gross_amount)), BigDecimal::zero());

        return [
            'rows' => $rows,
            'total_budget' => Dec::money($totalBudget),
            'total_actual' => Dec::money($totalActual),
            'total_variance' => Dec::money($totalBudget->minus($totalActual)),
            'billed' => Dec::money($billed),
            'margin' => Dec::money($billed->minus($totalActual)),
            'contract_value' => Dec::money($project->contract_value),
        ];
    }

    /** @return array<string, BigDecimal> */
    private function actuals(Project $project): array
    {
        $actuals = [];
        $add = function (string $head, mixed $amount) use (&$actuals) {
            $actuals[$head] = ($actuals[$head] ?? BigDecimal::zero())->plus(Dec::of($amount));
        };

        foreach (VendorBill::where('project_id', $project->id)->whereIn('status', VendorBill::POSTED)->get() as $bill) {
            $add($bill->cost_head, $bill->taxable_amount);
        }

        foreach (RaBill::where('project_id', $project->id)->where('type', 'subcontractor')->whereIn('status', SettlementService::BILLED)->get() as $bill) {
            $add('Subcontractors', $bill->gross_amount);
        }

        $map = config('modules.finance.petty_cost_heads');
        $fallback = config('modules.finance.petty_default_cost_head');

        foreach (PettyCashEntry::whereHas('wallet', fn ($q) => $q->where('project_id', $project->id))->where('type', 'expense')->get() as $entry) {
            $add($map[$entry->category] ?? $fallback, $entry->amount);
        }

        return $actuals;
    }
}
