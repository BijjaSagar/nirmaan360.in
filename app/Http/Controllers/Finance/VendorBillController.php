<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\Project;
use App\Models\VendorBill;
use App\Services\Finance\VendorBillService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class VendorBillController extends Controller
{
    public function __construct(private VendorBillService $service) {}

    public function index(Project $project): View
    {
        return view('modules.finance.vendor-bills.index', [
            'project' => $project,
            'bills' => VendorBill::with('party')->where('project_id', $project->id)->latest('id')->paginate(25),
        ]);
    }

    public function create(Project $project): View
    {
        return view('modules.finance.vendor-bills.form', ['project' => $project, 'bill' => new VendorBill, 'vendors' => $this->vendors()]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $bill = $this->service->create($project, $request->validate($this->service->rules()), $request->user());

        return redirect()->route('finance.project.vendor-bills.show', $bill)->with('success', __('Vendor bill saved as draft.'));
    }

    public function show(VendorBill $vendorBill): View
    {
        $vendorBill->load('party', 'approvals.steps.user', 'payments');

        return view('modules.finance.vendor-bills.show', ['bill' => $vendorBill, 'project' => $vendorBill->project]);
    }

    public function edit(VendorBill $vendorBill): View
    {
        abort_unless($vendorBill->isEditable(), 409, __('Only draft or returned bills can be changed.'));

        return view('modules.finance.vendor-bills.form', ['project' => $vendorBill->project, 'bill' => $vendorBill, 'vendors' => $this->vendors()]);
    }

    public function update(Request $request, VendorBill $vendorBill): RedirectResponse
    {
        $this->service->update($vendorBill, $request->validate($this->service->rules()));

        return redirect()->route('finance.project.vendor-bills.show', $vendorBill)->with('success', __('Vendor bill updated.'));
    }

    public function submit(Request $request, VendorBill $vendorBill): RedirectResponse
    {
        $this->service->submit($vendorBill, $request->user());

        return back()->with('success', __('Bill submitted for approval.'));
    }

    public function cancel(VendorBill $vendorBill): RedirectResponse
    {
        $this->service->cancel($vendorBill);

        return back()->with('success', __('Bill cancelled.'));
    }

    public function reverse(Request $request, VendorBill $vendorBill): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->service->reverse($vendorBill, $data['reason'], $request->user());

        return back()->with('success', __('Bill reversed.'));
    }

    private function vendors()
    {
        return Party::whereIn('type', ['vendor', 'subcontractor'])->orderBy('name')->get();
    }
}
