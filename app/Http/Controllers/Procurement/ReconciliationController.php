<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\BoqMaterialNorm;
use App\Models\Project;
use App\Services\Procurement\MaterialCatalogService;
use App\Services\Procurement\ReconciliationService;
use App\Services\Procurement\StockLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReconciliationController extends Controller
{
    use PicksMaterials;

    public function __construct(
        private ReconciliationService $service,
        private MaterialCatalogService $catalog,
        private StockLedgerService $ledger,
    ) {}

    public function index(Request $request, Project $project): View
    {
        $basis = in_array($request->query('basis'), array_keys(ReconciliationService::BASES), true) ? $request->query('basis') : 'measured';
        $rows = $this->service->consumption($project, $basis);

        return view('modules.procurement.reconciliation.index', [
            'project' => $project,
            'basis' => $basis,
            'rows' => $rows,
            'wastage' => array_values(array_filter($rows, fn ($row) => $row['status'] === 'wastage')),
            'unallocated' => $this->service->unallocated($project),
            'belowReorder' => $this->service->belowReorder($project, $this->ledger->projectBalances($project)),
            'norms' => BoqMaterialNorm::with('boqItem', 'material.baseUnit')->where('project_id', $project->id)->get(),
            'materialOptions' => $this->materialOptions(),
            'boqItems' => $this->boqOptions($project),
        ]);
    }

    public function storeNorm(Request $request, Project $project): RedirectResponse
    {
        $this->catalog->saveNorm($project, $request->validate($this->catalog->normRules($project)));

        return back()->with('success', __('Norm saved.'));
    }

    public function destroyNorm(BoqMaterialNorm $norm): RedirectResponse
    {
        $norm->delete();

        return back()->with('success', __('Norm removed.'));
    }
}
