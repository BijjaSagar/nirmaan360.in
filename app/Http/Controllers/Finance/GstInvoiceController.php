<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\GstInvoice;
use App\Models\Project;
use App\Models\RaBill;
use App\Services\Finance\GstInvoiceService;
use App\Services\Finance\SettlementService;
use App\Support\Dec;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class GstInvoiceController extends Controller
{
    public function __construct(private GstInvoiceService $service) {}

    public function index(Project $project): View
    {
        $invoices = GstInvoice::with('raBill')->where('project_id', $project->id)->latest('id')->get();
        $invoiced = $invoices->where('status', 'ISSUED')->pluck('ra_bill_id');

        return view('modules.finance.invoices', [
            'project' => $project,
            'invoices' => $invoices,
            'bills' => RaBill::with('party')->where('project_id', $project->id)->where('type', 'client')
                ->whereIn('status', SettlementService::BILLED)
                ->whereNotIn('id', $invoiced)
                ->orderBy('id')
                ->get()
                ->filter(fn (RaBill $bill) => Dec::of($bill->gst_amount)->isPositive()),
        ]);
    }

    public function store(Request $request, RaBill $raBill): RedirectResponse
    {
        $this->service->issue($raBill, $request->validate($this->service->issueRules()), $request->user());

        return back()->with('success', __('Tax invoice issued.'));
    }

    public function pdf(GstInvoice $invoice): Response
    {
        $invoice->load('raBill.items', 'raBill.deductions', 'project');

        return Pdf::loadView('modules.finance.invoice-pdf', ['invoice' => $invoice])
            ->setPaper('a4')
            ->download(Str::of($invoice->invoice_no)->replace('/', '-').'.pdf');
    }

    public function irn(Request $request, GstInvoice $invoice): RedirectResponse
    {
        $this->service->recordIrn($invoice, $request->validate($this->service->irnRules()));

        return back()->with('success', __('IRN saved.'));
    }

    public function cancel(GstInvoice $invoice): RedirectResponse
    {
        $this->service->cancel($invoice);

        return back()->with('success', __('Invoice cancelled.'));
    }
}
