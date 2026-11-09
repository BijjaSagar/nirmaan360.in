<?php

namespace App\Http\Controllers\MaterialControl;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\PicksMaterials;
use App\Models\DeliveryChallan;
use App\Models\MaterialShortDelivery;
use App\Models\Project;
use App\Models\VendorBill;
use App\Services\MaterialControl\DeliveryChallanService;
use App\Services\MaterialControl\ShortDeliveryService;
use App\Services\MaterialControl\SiteStores;
use App\Services\MaterialControl\SupplierRecordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliveryChallanController extends Controller
{
    use PicksMaterials;

    public function __construct(
        private DeliveryChallanService $service,
        private ShortDeliveryService $shorts,
        private SupplierRecordService $suppliers,
        private SiteStores $stores,
    ) {}

    public function index(Project $project): View
    {
        return view('modules.material_control.challans.index', [
            'project' => $project,
            'challans' => DeliveryChallan::with('supplier', 'material.baseUnit', 'unit')->where('project_id', $project->id)->latest('id')->paginate(25),
        ]);
    }

    public function create(Request $request, Project $project): View
    {
        return view('modules.material_control.challans.create', [
            'project' => $project,
            'stores' => $this->stores->forProject($project),
            'materialOptions' => $this->materialOptions(),
            'suppliers' => $this->suppliers->options($project->organization_id),
            'canAttachBill' => $request->user()->can('material_control.bills'),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate($this->service->rules($project, $request->user()->can('material_control.bills')));
        $challan = $this->service->create($project, $data, $request->user());
        $warning = $this->suppliers->summary($challan->supplier)['warning'];

        return redirect()->route('material_control.challans.show', $challan)
            ->with('success', $warning ? __('Delivery saved. Note: :warning', ['warning' => $warning]) : __('Delivery saved to stock.'));
    }

    public function show(Request $request, DeliveryChallan $deliveryChallan): View
    {
        $deliveryChallan->load('project', 'supplier', 'material.baseUnit', 'unit', 'store', 'creator', 'reverser', 'shortDeliveries');

        return view('modules.material_control.challans.show', [
            'project' => $deliveryChallan->project,
            'challan' => $deliveryChallan,
            'warning' => $this->suppliers->summary($deliveryChallan->supplier)['warning'],
            'vendorBills' => $request->user()->can('money.view')
                ? VendorBill::where('project_id', $deliveryChallan->project_id)->where('party_id', $deliveryChallan->party_id)->latest('id')->get(['id', 'bill_no', 'total_amount'])
                : collect(),
        ]);
    }

    public function photo(DeliveryChallan $deliveryChallan): StreamedResponse
    {
        abort_unless($deliveryChallan->photo_path && Storage::exists($deliveryChallan->photo_path), 404);

        return Storage::response($deliveryChallan->photo_path);
    }

    public function bill(DeliveryChallan $deliveryChallan): StreamedResponse
    {
        abort_unless($deliveryChallan->bill_path && Storage::exists($deliveryChallan->bill_path), 404);

        return Storage::response($deliveryChallan->bill_path);
    }

    public function attachBill(Request $request, DeliveryChallan $deliveryChallan): RedirectResponse
    {
        $data = $request->validate($this->service->billRules($deliveryChallan->project, $request->user()->can('money.view')));
        $this->service->attachBill($deliveryChallan, $data, $request->user());

        return back()->with('success', __('Bill attached.'));
    }

    public function reportShort(Request $request, DeliveryChallan $deliveryChallan): RedirectResponse
    {
        $this->shorts->report($deliveryChallan, $request->validate($this->shorts->rules()), $request->user());

        return back()->with('success', __('Short delivery recorded against the supplier.'));
    }

    public function reverse(Request $request, DeliveryChallan $deliveryChallan): RedirectResponse
    {
        $this->service->reverse($deliveryChallan, $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'], $request->user());

        return back()->with('success', __('Delivery reversed.'));
    }

    public function reverseShort(Request $request, MaterialShortDelivery $shortDelivery): RedirectResponse
    {
        $this->shorts->reverse($shortDelivery, $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'], $request->user());

        return back()->with('success', __('Short delivery reversed.'));
    }

    public function bills(Request $request, Project $project): View
    {
        $pending = $request->query('show') !== 'attached';

        return view('modules.material_control.bills.index', [
            'project' => $project,
            'pending' => $pending,
            'challans' => DeliveryChallan::with('supplier', 'material.baseUnit', 'unit')
                ->where('project_id', $project->id)
                ->where('status', 'POSTED')
                ->where('bill_status', $pending ? 'pending' : 'attached')
                ->latest('id')
                ->paginate(25)
                ->withQueryString(),
        ]);
    }
}
