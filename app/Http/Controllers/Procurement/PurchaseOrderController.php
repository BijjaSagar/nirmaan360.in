<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Services\Procurement\PurchaseOrderService;
use App\Support\Dec;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchaseOrderService $service) {}

    public function index(Project $project): View
    {
        return view('modules.procurement.purchase-orders.index', [
            'project' => $project,
            'orders' => PurchaseOrder::with('vendor', 'store')->where('project_id', $project->id)->latest('id')->paginate(25),
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder): View
    {
        $purchaseOrder->load('project', 'vendor', 'store', 'rfq', 'items.material.baseUnit', 'items.unit', 'grns');
        $received = $this->service->netReceived($purchaseOrder);

        return view('modules.procurement.purchase-orders.show', [
            'order' => $purchaseOrder,
            'project' => $purchaseOrder->project,
            'received' => $received,
            'due' => $purchaseOrder->items->mapWithKeys(fn ($item) => [
                $item->id => Dec::qty(Dec::of($item->base_qty)->minus(Dec::of($received[$item->id]))),
            ]),
        ]);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->service->updateTerms($purchaseOrder, $request->validate($this->service->termsRules()));

        return back()->with('success', __('Purchase order saved.'));
    }

    public function issue(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->service->issue($purchaseOrder);

        return back()->with('success', __('Purchase order issued. Goods can now be received against it.'));
    }

    public function close(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $order = $this->service->close($purchaseOrder);

        return back()->with('success', $order->status === 'CANCELLED' ? __('Purchase order cancelled.') : __('Purchase order closed short.'));
    }

    public function pdf(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        // A purchase order PDF is rates and amounts, so there is no quantities-only version of it.
        abort_unless(Money::visible($request->user()), 403, Money::refusal());

        $purchaseOrder->load('organization', 'project', 'vendor', 'store.site', 'items.material', 'items.unit');

        return Pdf::loadView('modules.procurement.pdf.purchase-order', ['order' => $purchaseOrder])
            ->setPaper('a4')
            ->download($purchaseOrder->number.'.pdf');
    }
}
