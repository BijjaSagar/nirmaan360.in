<?php

namespace App\Http\Presenters;

use App\Models\Project;
use App\Models\User;
use App\Support\Dec;
use App\Support\Money;

final class ProjectPresenter
{
    public static function present(Project $project): array
    {
        return [
            'id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'location' => $project->location,
            'status' => $project->status,
            'start_date' => $project->start_date?->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            'measurement_tolerance_pct' => $project->measurement_tolerance_pct,
            'image_url' => $project->image_path === null ? null : url('/api/v1/projects/'.$project->id.'/image?v='.$project->imageVersion()),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Everything the edit screen shows: the list row and the fields the website's form edits.
     * Money is null to anyone who may not see it, and the budget to anyone without the budget.
     *
     * @param  list<array{id:int,name:string,amount:string,share_pct:?string}>  $phases
     * @param  list<array{id:int,amount:string,received_on:?string,note:?string}>  $advances
     */
    public static function detail(Project $project, User $user, array $phases, array $advances): array
    {
        $money = Money::visible($user);
        $budget = Money::overview($user);

        return [
            ...self::present($project),
            'client_id' => $project->client_id,
            'state' => $project->state,
            'plot_size' => $project->plot_size,
            'built_up_area' => $project->built_up_area,
            'planned_days' => $project->planned_days,
            'deadline_date' => $project->deadline_date?->toDateString(),
            'retention_pct' => $project->retention_pct,
            'tds_pct' => $project->tds_pct,
            'gst_pct' => $project->gst_pct,
            'advance_recovery_pct' => $project->advance_recovery_pct,
            'deduction_basis' => $project->deduction_basis,
            'deduction_order' => implode(',', $project->deduction_order ?: config('buildos.default_deduction_order')),
            'contract_value' => $money ? Dec::money($project->contract_value) : null,
            'advance_amount' => $money ? Dec::money($project->advance_amount) : null,
            // The contract value split into phases, in order, each with its share of the total. Empty when it is one figure.
            'phases' => $money ? $phases : null,
            'budget_amount' => $budget && $project->budget_amount !== null ? Dec::money($project->budget_amount) : null,
            'budget_margin_pct' => $budget ? $project->budget_margin_pct : null,
            // Added after the 1.0 apps: the advance as lines, in order, which add up to advance_amount.
            // Empty when the advance is one figure. Money, notes included, as a note may quote a sum.
            'advances' => $money ? $advances : null,
            // True while advance_recovery_pct is worked out from advance_amount and contract_value.
            'advance_recovery_auto' => (bool) $project->advance_recovery_auto,
        ];
    }
}
