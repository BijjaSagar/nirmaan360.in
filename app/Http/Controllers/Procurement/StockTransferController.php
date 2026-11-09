<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\MaterialStore;
use App\Models\Project;
use App\Models\StockTransfer;
use App\Services\Procurement\StockTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockTransferController extends Controller
{
    use PicksMaterials;

    public function __construct(private StockTransferService $service) {}

    public function index(Project $project): View
    {
        return view('modules.procurement.transfers.index', [
            'project' => $project,
            'transfers' => StockTransfer::with('fromStore', 'toStore')
                ->withCount('items')
                ->where(fn ($query) => $query->where('from_project_id', $project->id)->orWhere('to_project_id', $project->id))
                ->latest('id')
                ->paginate(25),
        ]);
    }

    public function create(Project $project): View
    {
        return view('modules.procurement.transfers.create', [
            'project' => $project,
            'stores' => $this->projectStores($project),
            'destinations' => MaterialStore::with('project')->orderBy('name')->get(),
            'materialOptions' => $this->materialOptions(false),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $transfer = $this->service->dispatch($project, $request->validate($this->service->rules($project)), $request->user());

        return redirect()->route('procurement.transfers.show', $transfer)->with('success', __('Transfer dispatched.'));
    }

    public function show(StockTransfer $stockTransfer): View
    {
        $stockTransfer->load('fromStore', 'toStore', 'fromProject', 'toProject', 'items.material.baseUnit', 'items.unit', 'creator', 'receiver');

        return view('modules.procurement.transfers.show', ['transfer' => $stockTransfer, 'project' => $stockTransfer->fromProject]);
    }

    public function receive(Request $request, StockTransfer $stockTransfer): RedirectResponse
    {
        $data = $request->validate($this->service->receiveRules());

        $received = collect($data['items'] ?? [])
            ->filter(fn ($line) => filled($line['received_qty'] ?? null))
            ->mapWithKeys(fn ($line) => [(int) $line['line_id'] => $line['received_qty']])
            ->all();

        $this->service->receive($stockTransfer, $data['receive_date'], $received, $request->user());

        return back()->with('success', __('Transfer received into stock.'));
    }

    public function reverse(Request $request, StockTransfer $stockTransfer): RedirectResponse
    {
        $this->service->reverse($stockTransfer, $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'], $request->user());

        return back()->with('success', __('Transfer reversed.'));
    }
}
