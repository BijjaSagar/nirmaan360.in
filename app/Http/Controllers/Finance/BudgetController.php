<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Finance\BudgetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BudgetController extends Controller
{
    public function __construct(private BudgetService $service) {}

    public function index(Project $project): View
    {
        return view('modules.finance.budget', ['project' => $project, 'report' => $this->service->report($project)]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'budget' => ['required', 'array'],
            'budget.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
        ]);

        $this->service->save($project, $data['budget']);

        return back()->with('success', __('Budget saved.'));
    }
}
