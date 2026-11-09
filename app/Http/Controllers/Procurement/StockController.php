<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\MaterialStore;
use App\Models\Project;
use App\Models\StockAdjustment;
use App\Models\StockLevel;
use App\Services\Procurement\MaterialCatalogService;
use App\Services\Procurement\ReconciliationService;
use App\Services\Procurement\StockAdjustmentService;
use App\Services\Procurement\StockLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockController extends Controller
{
    use PicksMaterials;

    public function __construct(
        private StockLedgerService $ledger,
        private MaterialCatalogService $catalog,
        private ReconciliationService $reconciliation,
        private StockAdjustmentService $adjustments,
    ) {}

    public function index(Project $project): View
    {
        $stores = $this->projectStores($project)->load('site');
        $balances = $this->ledger->projectBalances($project);
        $materials = Material::with('baseUnit')->whereIn('id', $balances->keys()->map(fn ($key) => (int) explode(':', $key)[1]))->get()->keyBy('id');

        // Reorder levels matter even at zero stock, so the rows come from the levels too.
        $levels = StockLevel::whereIn('store_id', $stores->pluck('id'))->get()->keyBy(fn ($level) => $level->store_id.':'.$level->material_id);
        $materials = $materials->union(Material::with('baseUnit')->whereIn('id', $levels->pluck('material_id'))->get()->keyBy('id'));

        $rows = $stores->mapWithKeys(function (MaterialStore $store) use ($balances, $levels, $materials) {
            $keys = $balances->keys()->merge($levels->keys())->unique()->filter(fn ($key) => (int) explode(':', $key)[0] === $store->id);

            return [$store->id => $keys
                ->map(function ($key) use ($balances, $levels, $materials) {
                    $material = $materials->get((int) explode(':', $key)[1]);

                    return ['material' => $material, 'balance' => $balances->get($key, '0.000'), 'level' => $levels->get($key)];
                })
                ->sortBy(fn ($row) => $row['material']->name)
                ->values()];
        });

        return view('modules.procurement.stock.index', [
            'project' => $project,
            'stores' => $stores,
            'rows' => $rows,
            'alerts' => $this->reconciliation->belowReorder($project, $balances),
            'sites' => $project->sites()->orderBy('name')->get(),
            'materialOptions' => $this->materialOptions(),
            'adjustments' => StockAdjustment::with('store', 'material', 'unit')->where('project_id', $project->id)->latest('id')->take(15)->get(),
        ]);
    }

    public function card(MaterialStore $store, Material $material): View
    {
        return view('modules.procurement.stock.card', [
            'project' => $store->project,
            'store' => $store,
            'material' => $material->load('baseUnit'),
            'balance' => $this->ledger->balance($store->id, $material->id),
            'level' => StockLevel::where('store_id', $store->id)->where('material_id', $material->id)->first(),
            'entries' => $this->ledger->stockCard($store, $material),
        ]);
    }

    public function storeStore(Request $request, Project $project): RedirectResponse
    {
        $this->catalog->createStore($project, $request->validate($this->catalog->storeRules($project)));

        return back()->with('success', __('Store added.'));
    }

    public function reorder(Request $request, MaterialStore $store, Material $material): RedirectResponse
    {
        $this->catalog->setReorderLevel($store, $material, $request->validate($this->catalog->reorderRules())['reorder_level'] ?? null);

        return back()->with('success', __('Reorder level saved.'));
    }

    public function adjust(Request $request, Project $project): RedirectResponse
    {
        $this->adjustments->create($project, $request->validate($this->adjustments->rules($project)), $request->user());

        return back()->with('success', __('Stock adjustment posted.'));
    }

    public function reverseAdjustment(Request $request, StockAdjustment $adjustment): RedirectResponse
    {
        $this->adjustments->reverse($adjustment, $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'], $request->user());

        return back()->with('success', __('Adjustment reversed.'));
    }
}
