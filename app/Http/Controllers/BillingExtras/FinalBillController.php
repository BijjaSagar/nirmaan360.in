<?php

namespace App\Http\Controllers\BillingExtras;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\WorkOrder;
use App\Services\BillingExtras\ContractPositionService;
use App\Services\RaBillService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinalBillController extends Controller
{
    public function create(Request $request, Project $project, ContractPositionService $positions): View
    {
        $workOrder = $request->filled('work_order_id') ? $project->workOrders()->findOrFail($request->integer('work_order_id')) : null;
        $type = $workOrder ? 'subcontractor' : 'client';

        return view('modules.billing_extras.final-bill', [
            'project' => $project,
            'workOrder' => $workOrder,
            'type' => $type,
            'position' => $positions->forContract($project, $type, $workOrder),
        ]);
    }

    public function store(Request $request, Project $project, RaBillService $service): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['client', 'subcontractor'])],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'work_order_id' => ['nullable', 'required_if:type,subcontractor', Rule::exists('work_orders', 'id')->where('project_id', $project->id)],
            'material_recovery' => ['nullable', 'numeric', 'min:0'],
            'gst' => ['nullable', Rule::in(['with', 'without'])],
        ]);

        $bill = $service->generateFinal(
            $project,
            $data['type'],
            $data['period_from'],
            $data['period_to'],
            $request->user(),
            $data['type'] === 'subcontractor' ? WorkOrder::findOrFail($data['work_order_id']) : null,
            $data['material_recovery'] ?? 0,
            ($data['gst'] ?? 'with') === 'with',
        );

        return redirect()->route('ra-bills.show', $bill)->with('success', __('Final bill generated as draft. Approving it closes the contract.'));
    }
}
