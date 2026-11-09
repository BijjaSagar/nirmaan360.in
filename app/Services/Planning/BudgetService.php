<?php

namespace App\Services\Planning;

use App\Models\Attendance;
use App\Models\PettyCashEntry;
use App\Models\PlanBudgetLine;
use App\Models\PlanCostEntry;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\User;
use App\Support\Dec;
use App\Support\Idempotent;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Budget per cost head against what the project has actually spent. Actuals come from
 * approved subcontractor bills, own workers' attendance, petty cash, and costs entered here.
 */
class BudgetService
{
    private const OT_HOURS_PER_DAY = 8;

    /** @return array<string, string> Cost head keys and labels. */
    public function heads(): array
    {
        return array_map(fn (array $head) => $head['label'], config('modules.planning.cost_heads'));
    }

    public function lineRules(): array
    {
        return [
            'head' => ['required', Rule::in(array_keys($this->heads()))],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function costRules(): array
    {
        return [
            'head' => ['required', Rule::in(array_keys($this->heads()))],
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'description' => ['nullable', 'string', 'max:255'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** Set the budget for one head. Saving a head again changes its amount. */
    public function saveLine(Project $project, array $data): PlanBudgetLine
    {
        return DB::transaction(function () use ($project, $data) {
            $project = Project::lockForUpdate()->findOrFail($project->id);

            return PlanBudgetLine::updateOrCreate(
                ['project_id' => $project->id, 'head' => $data['head']],
                [
                    'organization_id' => $project->organization_id,
                    'amount' => Dec::money($data['amount']),
                    'notes' => $data['notes'] ?? null,
                ],
            );
        });
    }

    /** Record a cost no other screen captures. A repeated client_uuid returns the original row. */
    public function addCost(Project $project, array $data, User $by): PlanCostEntry
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findCost($project->organization_id, $uuid))) {
            return $existing;
        }

        return Idempotent::create(
            fn () => PlanCostEntry::create([
                ...$data,
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'amount' => Dec::money($data['amount']),
                'created_by' => $by->id,
            ]),
            fn () => $this->findCost($project->organization_id, (string) $uuid),
        );
    }

    /**
     * One row per cost head with baseline, budget, actual and variance. Variance is measured
     * against the baseline when one exists, and against the current budget until then.
     *
     * @return array{rows:list<array<string, mixed>>, totals:array<string, mixed>, has_baseline:bool}
     */
    public function report(Project $project): array
    {
        $lines = PlanBudgetLine::where('project_id', $project->id)->get()->keyBy('head');
        $actuals = $this->actuals($project);

        $rows = [];
        $totalBudget = $totalBaseline = $totalActual = $totalReference = BigDecimal::zero();
        $hasBaseline = false;

        foreach ($this->heads() as $head => $label) {
            $line = $lines->get($head);
            $budget = Dec::of($line?->amount ?? 0);
            $baseline = $line?->baseline_amount === null ? null : Dec::of($line->baseline_amount);
            $actual = $actuals[$head];
            $reference = $baseline ?? $budget;

            $hasBaseline = $hasBaseline || $baseline !== null;
            $totalBudget = $totalBudget->plus($budget);
            $totalBaseline = $totalBaseline->plus($baseline ?? BigDecimal::zero());
            $totalActual = $totalActual->plus($actual);
            $totalReference = $totalReference->plus($reference);

            $rows[] = [
                'head' => $head,
                'label' => $label,
                'budget' => Dec::money($budget),
                'baseline' => $baseline === null ? null : Dec::money($baseline),
                'actual' => Dec::money($actual),
                'variance' => Dec::money($reference->minus($actual)),
                'used_pct' => $this->usedPercent($actual, $reference),
                'over' => $actual->isGreaterThan($reference),
                'notes' => $line?->notes,
            ];
        }

        return [
            'rows' => $rows,
            'has_baseline' => $hasBaseline,
            'totals' => [
                'budget' => Dec::money($totalBudget),
                'baseline' => $hasBaseline ? Dec::money($totalBaseline) : null,
                'actual' => Dec::money($totalActual),
                'variance' => Dec::money($totalReference->minus($totalActual)),
                'used_pct' => $this->usedPercent($totalActual, $totalReference),
                'over' => $totalActual->isGreaterThan($totalReference),
            ],
        ];
    }

    /** @return array<string, BigDecimal> Actual cost by head. */
    public function actuals(Project $project): array
    {
        $actuals = array_map(fn () => BigDecimal::zero(), $this->heads());

        foreach (PlanCostEntry::where('project_id', $project->id)->get(['head', 'amount']) as $entry) {
            $actuals[$entry->head] = ($actuals[$entry->head] ?? BigDecimal::zero())->plus(Dec::of($entry->amount));
        }

        foreach ($this->pettyCash($project) as $head => $amount) {
            $actuals[$head] = $actuals[$head]->plus($amount);
        }

        $actuals['labour'] = $actuals['labour']->plus($this->wages($project));
        $actuals['subcontract'] = $actuals['subcontract']->plus($this->subcontractBills($project));

        return $actuals;
    }

    /** @return array<string, BigDecimal> */
    private function pettyCash(Project $project): array
    {
        $byCategory = [];

        foreach (config('modules.planning.cost_heads') as $head => $config) {
            foreach ($config['petty'] as $category) {
                $byCategory[$category] = $head;
            }
        }

        $totals = [];

        $entries = PettyCashEntry::whereHas('wallet', fn ($wallet) => $wallet->where('project_id', $project->id))
            ->where('type', 'expense')
            ->get(['category', 'amount']);

        foreach ($entries as $entry) {
            $head = $byCategory[$entry->category ?? ''] ?? 'other';
            $totals[$head] = ($totals[$head] ?? BigDecimal::zero())->plus(Dec::of($entry->amount));
        }

        return $totals;
    }

    /** Wages of the company's own workers. Contract labour is paid through its contractor's bills. */
    private function wages(Project $project): BigDecimal
    {
        $groups = Attendance::query()
            ->join('workers', 'workers.id', '=', 'attendance.worker_id')
            ->where('attendance.project_id', $project->id)
            ->where('workers.type', 'own')
            ->whereIn('attendance.status', ['present', 'half', 'overtime'])
            ->selectRaw('attendance.status, attendance.ot_hours, workers.daily_wage, COUNT(*) as marks')
            ->groupBy('attendance.status', 'attendance.ot_hours', 'workers.daily_wage')
            ->get();

        $total = BigDecimal::zero();

        foreach ($groups as $group) {
            $days = Dec::of($group->status === 'half' ? '0.5' : '1')
                ->plus(Dec::of($group->ot_hours)->dividedBy(self::OT_HOURS_PER_DAY, 6, RoundingMode::HalfUp));

            $total = $total->plus(Dec::of($group->daily_wage)->multipliedBy($days)->multipliedBy((int) $group->marks));
        }

        return $total;
    }

    private function subcontractBills(Project $project): BigDecimal
    {
        return RaBill::where('project_id', $project->id)
            ->where('type', 'subcontractor')
            ->whereIn('status', ['APPROVED', 'PAID'])
            ->pluck('gross_amount')
            ->reduce(fn (BigDecimal $sum, $amount) => $sum->plus(Dec::of($amount)), BigDecimal::zero());
    }

    private function usedPercent(BigDecimal $actual, BigDecimal $reference): ?string
    {
        return $reference->isZero() ? null : (string) $actual->multipliedBy(100)->dividedBy($reference, 2, RoundingMode::HalfUp);
    }

    private function findCost(int $organizationId, string $uuid): ?PlanCostEntry
    {
        return PlanCostEntry::where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }
}
