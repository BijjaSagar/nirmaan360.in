<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\Indent;
use App\Models\Party;
use App\Models\Project;
use App\Models\Rfq;
use App\Services\Procurement\RfqService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RfqController extends Controller
{
    public function __construct(private RfqService $service) {}

    public function index(Project $project): View
    {
        return view('modules.procurement.rfqs.index', [
            'project' => $project,
            'rfqs' => Rfq::with('indent')->withCount('quotations', 'items')->where('project_id', $project->id)->latest('id')->paginate(25),
        ]);
    }

    public function store(Request $request, Indent $indent): RedirectResponse
    {
        $rfq = $this->service->createFromIndent($indent, $request->validate($this->service->createRules()), $request->user());

        return redirect()->route('procurement.rfqs.show', $rfq)->with('success', __('RFQ created.'));
    }

    public function show(Rfq $rfq): View
    {
        $comparison = $this->service->compare($rfq);
        $rfq->loadMissing('project', 'indent', 'purchaseOrders.vendor');

        return view('modules.procurement.rfqs.show', [
            'rfq' => $rfq,
            'project' => $rfq->project,
            'comparison' => $comparison,
            'vendors' => Party::where('type', 'vendor')->orderBy('name')->get(),
        ]);
    }

    public function addVendor(Request $request, Rfq $rfq): RedirectResponse
    {
        $this->service->addVendor($rfq, (int) $request->validate($this->service->vendorRules())['party_id']);

        return back()->with('success', __('Vendor added.'));
    }

    public function saveQuotation(Request $request, Rfq $rfq): RedirectResponse
    {
        // A vendor need not quote every line: rows left blank are dropped.
        $request->merge(['items' => array_values(array_filter(
            (array) $request->input('items', []),
            fn ($line) => filled($line['rate'] ?? null),
        ))]);

        $this->service->saveQuotation($rfq, $request->validate($this->service->quotationRules()));

        return back()->with('success', __('Quotation saved.'));
    }

    public function award(Request $request, Rfq $rfq): RedirectResponse
    {
        // Lines with no vendor chosen stay open for a later award.
        $request->merge(['picks' => array_values(array_filter(
            (array) $request->input('picks', []),
            fn ($pick) => filled($pick['quotation_id'] ?? null),
        ))]);

        $data = $request->validate($this->service->awardRules());
        $orders = $this->service->award($rfq, $data['picks'], $data['client_uuid'], $request->user());

        return redirect()->route('procurement.purchase-orders.show', $orders->first())
            ->with('success', __('Purchase orders drafted: :count', ['count' => $orders->count()]));
    }

    public function close(Rfq $rfq): RedirectResponse
    {
        $this->service->close($rfq);

        return back()->with('success', __('RFQ closed.'));
    }
}
