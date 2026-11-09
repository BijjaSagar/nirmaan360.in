<?php

namespace App\Http\Controllers\MaterialControl;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\PicksMaterials;
use App\Models\MaterialStockCheck;
use App\Models\Project;
use App\Services\MaterialControl\SiteStores;
use App\Services\MaterialControl\StockCheckService;
use App\Services\Procurement\StockLedgerService;
use App\Support\Dec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockCheckController extends Controller
{
    use PicksMaterials;

    public function __construct(
        private StockCheckService $service,
        private SiteStores $stores,
        private StockLedgerService $ledger,
    ) {}

    public function index(Project $project): View
    {
        $stores = $this->stores->forProject($project)->keyBy('id');
        $materials = collect($this->materialOptions(false))->keyBy('id');

        return view('modules.material_control.stock.index', [
            'project' => $project,
            'stores' => $stores,
            'materialOptions' => $materials->values()->all(),
            'balances' => $this->ledger->projectBalances($project)->map(function (string $balance, string $key) use ($stores, $materials) {
                [$storeId, $materialId] = array_map('intval', explode(':', $key));

                return ['store' => $stores->get($storeId), 'material' => $materials->get($materialId), 'balance' => $balance];
            })->filter(fn ($row) => $row['store'] && $row['material'])->values(),
            'checks' => MaterialStockCheck::with('store', 'material.baseUnit', 'creator')->where('project_id', $project->id)->latest('id')->paginate(25),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $check = $this->service->create($project, $request->validate($this->service->rules($project)), $request->user());

        return back()->with('success', Dec::of($check->variance_qty)->isZero()
            ? __('Count saved. It matches the book.')
            : __('Count saved. Difference from the book: :variance :unit.', ['variance' => fmt_qty($check->variance_qty), 'unit' => $check->material->baseUnit->code]));
    }

    public function reverse(Request $request, MaterialStockCheck $stockCheck): RedirectResponse
    {
        $this->service->reverse($stockCheck, $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'], $request->user());

        return back()->with('success', __('Stock check reversed.'));
    }
}
