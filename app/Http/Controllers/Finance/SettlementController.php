<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\ClientReceipt;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\VendorBill;
use App\Models\VendorPayment;
use App\Services\Finance\SettlementService;
use App\Support\Dec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettlementController extends Controller
{
    public function __construct(private SettlementService $service) {}

    public function payments(Project $project): View
    {
        return view('modules.finance.payments', [
            'project' => $project,
            'bills' => $this->service->payableBills($project->organization_id, $project->id)
                ->filter(fn ($row) => $row['open'] && Dec::of($row['outstanding'])->isPositive())
                ->values(),
            'payments' => VendorPayment::with('party', 'vendorBill', 'raBill', 'reversal')->where('project_id', $project->id)->latest('paid_on')->latest('id')->paginate(25),
        ]);
    }

    public function receipts(Project $project): View
    {
        return view('modules.finance.receipts', [
            'project' => $project,
            'bills' => $this->service->clientBills($project->organization_id, $project->id)
                ->filter(fn ($row) => Dec::of($row['outstanding'])->isPositive())
                ->values(),
            'receipts' => ClientReceipt::with('party', 'raBill', 'reversal')->where('project_id', $project->id)->latest('received_on')->latest('id')->paginate(25),
        ]);
    }

    public function payVendorBill(Request $request, VendorBill $vendorBill): RedirectResponse
    {
        $this->service->payVendorBill($vendorBill, $request->validate($this->service->paymentRules()), $request->user());

        return back()->with('success', __('Payment recorded.'));
    }

    public function paySubcontractorBill(Request $request, RaBill $raBill): RedirectResponse
    {
        $this->service->paySubcontractorBill($raBill, $request->validate($this->service->paymentRules()), $request->user());

        return back()->with('success', __('Payment recorded.'));
    }

    public function reversePayment(Request $request, VendorPayment $payment): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->service->reversePayment($payment, $data['reason'], $request->user());

        return back()->with('success', __('Payment reversed.'));
    }

    public function storeReceipt(Request $request, RaBill $raBill): RedirectResponse
    {
        $this->service->recordReceipt($raBill, $request->validate($this->service->receiptRules()), $request->user());

        return back()->with('success', __('Receipt recorded.'));
    }

    public function reverseReceipt(Request $request, ClientReceipt $receipt): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->service->reverseReceipt($receipt, $data['reason'], $request->user());

        return back()->with('success', __('Receipt reversed.'));
    }
}
