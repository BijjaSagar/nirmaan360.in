<?php

namespace App\Http\Controllers\Planning;

use App\Http\Controllers\Controller;
use App\Models\PlanCostEntry;
use App\Models\Project;
use App\Services\Planning\BudgetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BudgetController extends Controller
{
    public function __construct(private BudgetService $budget) {}

    public function index(Project $project): View
    {
        return view('modules.planning.budget', [
            'project' => $project,
            'report' => $this->budget->report($project),
            'heads' => $this->budget->heads(),
            'entries' => PlanCostEntry::with('creator')->where('project_id', $project->id)->latest('entry_date')->latest('id')->paginate(20),
        ]);
    }

    public function storeLine(Request $request, Project $project): RedirectResponse
    {
        $this->budget->saveLine($project, $request->validate($this->budget->lineRules()));

        return back()->with('success', __('Budget saved.'));
    }

    public function storeCost(Request $request, Project $project): RedirectResponse
    {
        $this->budget->addCost($project, $request->validate($this->budget->costRules()), $request->user());

        return back()->with('success', __('Cost recorded.'));
    }

    public function destroyCost(PlanCostEntry $entry): RedirectResponse
    {
        $entry->delete();

        return back()->with('success', __('Cost removed.'));
    }
}
