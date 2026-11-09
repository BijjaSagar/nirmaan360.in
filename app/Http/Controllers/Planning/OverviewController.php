<?php

namespace App\Http\Controllers\Planning;

use App\Http\Controllers\Controller;
use App\Models\PlanBaseline;
use App\Models\Project;
use App\Services\Planning\BudgetService;
use App\Services\Planning\DelayAlertService;
use App\Services\Planning\ProgressService;
use App\Services\Planning\ScheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OverviewController extends Controller
{
    public function index(Project $project, ProgressService $progress, BudgetService $budget): View
    {
        $plan = $progress->snapshot($project);

        return view('modules.planning.index', [
            'project' => $project,
            'plan' => $plan,
            'summary' => $plan->summary(),
            'curve' => $plan->sCurve(),
            'delayed' => $plan->delayed(),
            'upcoming' => $plan->upcoming(),
            'budget' => $budget->report($project)['totals'],
            'baseline' => PlanBaseline::with('taker')->where('project_id', $project->id)->latest('taken_at')->latest('id')->first(),
        ]);
    }

    public function baseline(Request $request, Project $project, ScheduleService $schedule): RedirectResponse
    {
        $schedule->takeBaseline($project, $request->user());

        return back()->with('success', __('Baseline saved.'));
    }

    public function scan(Project $project, DelayAlertService $alerts): RedirectResponse
    {
        $result = $alerts->scanProject($project);

        return back()->with('success', __('Delay check finished. New delays: :new. Cleared: :resolved.', [
            'new' => $result['new'],
            'resolved' => $result['resolved'],
        ]));
    }
}
