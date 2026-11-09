<?php

namespace App\Http\Controllers;

use App\Models\Party;
use App\Models\Project;
use App\Models\WorkOrder;
use App\Support\Dec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WorkOrderController extends Controller
{
    public function index(Project $project): View
    {
        return view('workorders.index', [
            'project' => $project,
            'workOrders' => $project->workOrders()->with('party', 'items')->latest('id')->get(),
        ]);
    }

    public function create(Project $project): View
    {
        return view('workorders.create', [
            'project' => $project,
            'parties' => Party::where('type', 'subcontractor')->orderBy('name')->get(),
            'boqItems' => $project->boqItems()->orderBy('sort')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'party_id' => ['required', Rule::exists('parties', 'id')->where('organization_id', $project->organization_id)->where('type', 'subcontractor')],
            'title' => ['required', 'string', 'max:160'],
            'scope' => ['nullable', 'string', 'max:5000'],
            'rate_type' => ['required', Rule::in(['item_rate', 'lump_sum', 'labour_only'])],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'retention_pct' => ['required', 'numeric', 'between:0,100'],
            'tds_pct' => ['required', 'numeric', 'between:0,100'],
            'gst_pct' => ['required', 'numeric', 'between:0,100'],
            'advance_amount' => ['required', 'numeric', 'min:0'],
            'advance_recovery_pct' => ['required', 'numeric', 'between:0,100'],
            'deduction_basis' => ['required', Rule::in(['gross', 'running'])],
            'deduction_order' => ['required', Rule::in(array_keys(config('buildos.deduction_orders')))],
            'items' => ['required', 'array'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        $validItems = $project->boqItems()->pluck('id')->all();

        $lines = collect($data['items'])
            ->filter(fn ($line, $itemId) => in_array((int) $itemId, $validItems, true) && Dec::of($line['rate'] ?? 0)->isPositive());

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['items' => __('Enter a rate for at least one BOQ item.')]);
        }

        // A lump sum line is worth quantity × rate, so it cannot be billed without a quantity.
        if ($data['rate_type'] === 'lump_sum' && $lines->contains(fn ($line) => ! Dec::of($line['qty'] ?? 0)->isPositive())) {
            throw ValidationException::withMessages(['items' => __('Enter a quantity for every item on a lump sum work order.')]);
        }

        $workOrder = DB::transaction(function () use ($data, $lines, $project, $request) {
            $sequence = WorkOrder::withTrashed()->where('project_id', $project->id)->count() + 1;
            unset($data['items']);

            $workOrder = WorkOrder::create([
                ...$data,
                'deduction_order' => explode(',', $data['deduction_order']),
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'number' => sprintf('WO-%s-%03d', $project->code, $sequence),
                'status' => 'ACTIVE',
                'created_by' => $request->user()->id,
            ]);

            foreach ($lines as $itemId => $line) {
                $workOrder->items()->create([
                    'organization_id' => $project->organization_id,
                    'boq_item_id' => $itemId,
                    'qty' => $line['qty'] ?? 0,
                    'rate' => $line['rate'],
                ]);
            }

            return $workOrder;
        });

        return redirect()->route('work-orders.show', $workOrder)->with('success', __('Work order created.'));
    }

    public function show(WorkOrder $workOrder): View
    {
        return view('workorders.show', [
            'workOrder' => $workOrder->load('party', 'items.boqItem'),
            'project' => $workOrder->project,
            'bills' => $workOrder->project->raBills()->where('work_order_id', $workOrder->id)->latest('id')->get(),
        ]);
    }
}
