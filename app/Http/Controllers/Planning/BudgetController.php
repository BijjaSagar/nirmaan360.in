<?php

namespace App\Http\Controllers\Planning;

use App\Http\Controllers\Controller;
use App\Models\PlanCostEntry;
use App\Models\Project;
use App\Services\Planning\BudgetService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BudgetController extends Controller
{
    public function __construct(private BudgetService $budget) {}

    public function index(Request $request, Project $project): View
    {
        // Budget and actual cost are the company's total spend, which needs money.overview.
        abort_unless(Money::overview($request->user()), 403, Money::refusal());

        return view('modules.planning.budget', [
            'project' => $project,
            'report' => $this->budget->report($project),
            'heads' => $this->budget->heads(),
            'entries' => PlanCostEntry::with('creator', 'reversal', 'reversalOf')->where('project_id', $project->id)->latest('entry_date')->latest('id')->paginate(20),
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

    /** A cost is never deleted. It is cancelled by an opposite entry, with the reason on record. */
    public function reverseCost(Request $request, PlanCostEntry $entry): RedirectResponse
    {
        $data = $request->validate($this->budget->reversalRules());

        $this->budget->reverseCost($entry, $data['reason'], $request->user());

        return back()->with('success', __('Cost reversed.'));
    }
}
