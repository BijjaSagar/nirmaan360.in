<?php

namespace App\Http\Controllers\MaterialControl;

use App\Http\Controllers\Controller;
use App\Models\DeliveryChallan;
use App\Models\MaterialShortDelivery;
use App\Models\Party;
use App\Services\MaterialControl\SupplierRecordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function __construct(private SupplierRecordService $suppliers) {}

    public function index(Request $request): View
    {
        $vendors = Party::where('type', 'vendor')->orderBy('name')->paginate(50);
        $summaries = collect($this->suppliers->summaries(collect($vendors->items()), $request->user()->organization_id));

        return view('modules.material_control.suppliers.index', [
            'vendors' => $vendors,
            'summaries' => $summaries,
        ]);
    }

    public function show(Party $party): View
    {
        abort_unless($party->type === 'vendor', 404);

        return view('modules.material_control.suppliers.show', [
            'party' => $party,
            'summary' => $this->suppliers->summary($party),
            'shorts' => MaterialShortDelivery::with('project', 'material.baseUnit', 'unit', 'challan')->where('party_id', $party->id)->where('status', 'POSTED')->latest('id')->paginate(25),
            'deliveries' => DeliveryChallan::where('party_id', $party->id)->where('status', 'POSTED')->count(),
        ]);
    }

    public function update(Request $request, Party $party): RedirectResponse
    {
        abort_unless($party->type === 'vendor', 404);

        $this->suppliers->save($party, $request->validate($this->suppliers->rules()), $request->user());

        return back()->with('success', __('Supplier record saved.'));
    }
}
