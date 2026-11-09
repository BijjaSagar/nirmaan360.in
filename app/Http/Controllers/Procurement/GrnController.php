<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\Grn;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Services\Procurement\GrnService;
use App\Services\Procurement\MaterialReturnService;
use App\Services\Procurement\PurchaseOrderService;
use App\Support\Dec;
use Brick\Math\RoundingMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GrnController extends Controller
{
    use PicksMaterials;

    public function __construct(
        private GrnService $service,
        private PurchaseOrderService $orders,
        private MaterialReturnService $returns,
    ) {}

    public function index(Project $project): View
    {
        return view('modules.procurement.grns.index', [
            'project' => $project,
            'grns' => Grn::with('purchaseOrder.vendor', 'store')->where('project_id', $project->id)->latest('id')->paginate(25),
            'openOrders' => PurchaseOrder::with('vendor')->where('project_id', $project->id)->whereIn('status', ['ISSUED', 'PARTIAL'])->orderBy('id')->get(),
        ]);
    }

    public function create(Request $request, Project $project): View
    {
        $order = PurchaseOrder::with('vendor', 'store', 'items.material.baseUnit', 'items.unit')
            ->where('project_id', $project->id)
            ->whereIn('status', ['ISSUED', 'PARTIAL'])
            ->findOrFail($request->integer('purchase_order'));

        $received = $this->orders->netReceived($order);

        return view('modules.procurement.grns.create', [
            'project' => $project,
            'order' => $order,
            'stores' => $this->projectStores($project),
            'lines' => $order->items->map(function ($item) use ($received) {
                $dueBase = Dec::of($item->base_qty)->minus(Dec::of($received[$item->id]));

                return [
                    'item' => $item,
                    'due_base' => Dec::qty($dueBase),
                    'due_ordered' => Dec::qty($dueBase->multipliedBy(Dec::of($item->qty))->dividedBy(Dec::of($item->base_qty), 6, RoundingMode::HalfUp)),
                ];
            }),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        // Only the lines that actually arrived are filled in.
        $request->merge(['items' => array_values(array_filter(
            (array) $request->input('items', []),
            fn ($line) => filled($line['qty'] ?? null),
        ))]);

        $grn = $this->service->create($project, $request->validate($this->service->rules($project)), $request->user());

        return redirect()->route('procurement.grns.show', $grn)->with('success', __('Goods receipt posted to stock.'));
    }

    public function show(Grn $grn): View
    {
        $grn->load('project', 'store', 'purchaseOrder.vendor', 'items.material.baseUnit', 'items.unit', 'creator', 'reverser', 'returns');

        return view('modules.procurement.grns.show', ['grn' => $grn, 'project' => $grn->project]);
    }

    public function reverse(Request $request, Grn $grn): RedirectResponse
    {
        $this->service->reverse($grn, $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'], $request->user());

        return back()->with('success', __('Goods receipt reversed.'));
    }
}
