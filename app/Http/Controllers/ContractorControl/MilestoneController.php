<?php

namespace App\Http\Controllers\ContractorControl;

use App\Http\Controllers\Controller;
use App\Models\ContractorMilestone;
use App\Models\WorkOrder;
use App\Services\ContractorControl\MilestoneService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MilestoneController extends Controller
{
    public function __construct(private MilestoneService $service) {}

    public function store(Request $request, WorkOrder $workOrder): RedirectResponse
    {
        $this->service->add($workOrder, $request->validate($this->service->rules()), $request->user());

        return back()->with('success', __('Part added.'));
    }

    public function toggle(Request $request, ContractorMilestone $milestone): RedirectResponse
    {
        $this->service->toggleDone($milestone, $request->user());

        return back()->with('success', __('Saved.'));
    }

    public function destroy(ContractorMilestone $milestone): RedirectResponse
    {
        $this->service->remove($milestone);

        return back()->with('success', __('Part removed.'));
    }
}
