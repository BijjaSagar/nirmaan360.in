<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmTender;
use App\Models\CrmTenderPayment;
use App\Services\Crm\TenderPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenderPaymentController extends Controller
{
    public function __construct(private TenderPaymentService $service) {}

    /** EMD and fees across all tenders: what is held, what should be coming back, what has come back. */
    public function index(Request $request): View
    {
        $organizationId = $request->user()->organization_id;
        $filters = $request->only(['view', 'kind']);

        return view('modules.crm.payments.index', [
            'filters' => $filters,
            'payments' => $this->service->query($organizationId, $filters)->paginate(30)->withQueryString(),
            'totals' => $this->service->totals($this->service->query($organizationId)->get()),
        ]);
    }

    public function store(Request $request, CrmTender $tender): RedirectResponse
    {
        $this->service->record($tender, $request->validate($this->service->rules()), $request->user());

        return redirect()->to(route('crm.tenders.show', $tender).'#payments')->with('success', __('Payment recorded.'));
    }

    public function update(Request $request, CrmTenderPayment $payment): RedirectResponse
    {
        $this->service->update($payment, $request->validate($this->service->rules()));

        return redirect()->to(route('crm.tenders.show', $payment->tender_id).'#payments')->with('success', __('Payment updated.'));
    }

    public function settle(Request $request, CrmTenderPayment $payment): RedirectResponse
    {
        $data = $request->validate($this->service->settleRules());

        $this->service->settle($payment, $data['outcome'], $data, $request->user());

        return back()->with('success', __('Payment updated.'));
    }

    public function undo(Request $request, CrmTenderPayment $payment): RedirectResponse
    {
        $this->service->undoSettlement($payment, $request->user());

        return back()->with('success', __('Settlement undone.'));
    }

    public function destroy(CrmTenderPayment $payment): RedirectResponse
    {
        $this->service->delete($payment);

        return back()->with('success', __('Payment removed.'));
    }
}
