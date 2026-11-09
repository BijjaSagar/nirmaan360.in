<?php

namespace App\Http\Controllers\MaterialControl;

use App\Http\Controllers\Controller;
use App\Models\DeliveryChallan;
use App\Models\Project;
use App\Services\MaterialControl\MaterialCheckService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MaterialCheckController extends Controller
{
    public function __construct(private MaterialCheckService $checks) {}

    public function index(Request $request, Project $project): View
    {
        return view('modules.material_control.index', [
            'project' => $project,
            'check' => $this->checks->check($project, $request->user()->can('money.view')),
            'billsPending' => $request->user()->can('material_control.bills')
                ? DeliveryChallan::where('project_id', $project->id)->where('status', 'POSTED')->where('bill_status', 'pending')->count()
                : null,
        ]);
    }

    public function storeReason(Request $request, Project $project): RedirectResponse
    {
        $this->checks->addReason($project, $request->validate($this->checks->reasonRules()), $request->user());

        return back()->with('success', __('Reason saved.'));
    }
}
