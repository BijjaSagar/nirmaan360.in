<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\RaBill;
use App\Models\WorkOrder;
use App\Services\ApprovalService;
use App\Services\RaBillService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RaBillController extends Controller
{
    public function __construct(private RaBillService $service) {}

    public function index(Project $project): View
    {
        return view('rabills.index', [
            'project' => $project,
            'bills' => $project->raBills()->with('party')->latest('id')->paginate(25),
        ]);
    }

    public function create(Project $project): View
    {
        return view('rabills.create', [
            'project' => $project,
            'workOrders' => $project->workOrders()->with('party')->where('status', 'ACTIVE')->get(),
            'unbilledClient' => $project->measurements()->where('status', 'APPROVED')->whereNull('billed_client_bill_id')->count(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['client', 'subcontractor'])],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'work_order_id' => ['nullable', 'required_if:type,subcontractor', Rule::exists('work_orders', 'id')->where('project_id', $project->id)],
            'material_recovery' => ['nullable', 'numeric', 'min:0'],
            'gst' => ['nullable', Rule::in(['with', 'without'])],
        ]);

        $bill = $this->service->generate(
            $project,
            $data['type'],
            $data['period_from'],
            $data['period_to'],
            $request->user(),
            $data['type'] === 'subcontractor' ? WorkOrder::findOrFail($data['work_order_id']) : null,
            $data['material_recovery'] ?? 0,
            ($data['gst'] ?? 'with') === 'with',
        );

        return redirect()->route('ra-bills.show', $bill)->with('success', __('RA bill generated as draft.'));
    }

    public function show(RaBill $bill): View
    {
        $bill->load('items', 'deductions', 'party', 'workOrder', 'approvals.steps.user', 'notes');
        $approval = $bill->approvals->first();

        return view('rabills.show', [
            'bill' => $bill,
            'project' => $bill->project,
            'clientLink' => $approval?->status === 'IN_REVIEW' && $approval->currentRole() === 'client'
                ? URL::temporarySignedRoute('client.bill', now()->addDays(14), ['bill' => $bill->id])
                : null,
        ]);
    }

    public function submit(Request $request, RaBill $bill, ApprovalService $approvals): RedirectResponse
    {
        $approvals->submit($bill, $request->user());

        return back()->with('success', __('Bill submitted for approval.'));
    }

    public function cancel(RaBill $bill): RedirectResponse
    {
        $this->service->cancel($bill);

        return back()->with('success', __('Bill cancelled. Its measurements can be billed again.'));
    }

    public function paid(RaBill $bill): RedirectResponse
    {
        $this->service->markPaid($bill);

        return back()->with('success', __('Bill marked as paid.'));
    }

    public function pdf(RaBill $bill): Response
    {
        $bill->load('items', 'deductions', 'party', 'workOrder', 'project', 'organization');

        return Pdf::loadView('pdf.ra-bill', ['bill' => $bill])
            ->setPaper('a4', 'landscape')
            ->download($bill->number.'.pdf');
    }
}
