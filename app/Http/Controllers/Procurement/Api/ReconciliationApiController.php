<?php

namespace App\Http\Controllers\Procurement\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Procurement\ReconciliationPresenter;
use App\Services\Procurement\ReconciliationService;
use App\Services\Procurement\StockLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The web's reconciliation page for the field app, read only: material used on each BOQ line against
 * the work done times the norm, and the wastage the norm allows. Quantities only. Norms stay on the web.
 */
class ReconciliationApiController extends Controller
{
    public function __construct(
        private ReconciliationService $service,
        private StockLedgerService $ledger,
        private ReconciliationPresenter $presenter,
    ) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $basis = in_array($request->query('basis'), array_keys(ReconciliationService::BASES), true) ? $request->query('basis') : 'measured';

        return response()->json(['data' => $this->presenter->present(
            $basis,
            $this->service->consumption($project, $basis),
            $this->service->unallocated($project),
            $this->service->belowReorder($project, $this->ledger->projectBalances($project)),
        )]);
    }
}
