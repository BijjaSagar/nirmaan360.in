<?php

namespace App\Http\Controllers\MaterialControl\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryChallan;
use App\Models\MaterialIssue;
use App\Models\MaterialStockCheck;
use App\Models\Project;
use App\Services\MaterialControl\ConsumptionService;
use App\Services\MaterialControl\DeliveryChallanService;
use App\Services\MaterialControl\MaterialCheckService;
use App\Services\MaterialControl\MaterialControlPresenter;
use App\Services\MaterialControl\StockCheckService;
use App\Services\MaterialControl\SupplierRecordService;
use App\Services\Procurement\ProcurementSyncService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Field app endpoints for material control. Every write needs a client_uuid. */
class MaterialControlApiController extends Controller
{
    private const RECENT = 50;

    public function __construct(
        private DeliveryChallanService $challans,
        private ConsumptionService $consumption,
        private StockCheckService $stockChecks,
        private MaterialCheckService $checks,
        private SupplierRecordService $suppliers,
        private ProcurementSyncService $procurement,
        private MaterialControlPresenter $presenter,
    ) {}

    public function check(Request $request, Project $project): JsonResponse
    {
        return response()->json(['data' => $this->checks->check($project, $request->user()->can('money.view'))]);
    }

    public function suppliers(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->suppliers->options($request->user()->organization_id)]);
    }

    public function challanIndex(Request $request, Project $project): JsonResponse
    {
        $challans = DeliveryChallan::with('supplier')->where('project_id', $project->id)->latest('id')->take(self::RECENT)->get();

        return response()->json(['data' => $challans->map(fn (DeliveryChallan $challan) => $this->presenter->challan($challan, $this->money($request)))]);
    }

    public function storeChallan(Request $request, Project $project): JsonResponse
    {
        $uuid = $request->validate(['client_uuid' => ['required', 'uuid']])['client_uuid'];

        // Before the field rules, so a retry succeeds even if a file is not resent.
        $challan = $this->challans->find($project->organization_id, $uuid)
            ?? $this->challans->create($project, $request->validate($this->challans->rules($project, $request->user()->can('material_control.bills'))), $request->user());

        $this->assertSameProject($challan, $project);

        return response()->json([
            'data' => $this->presenter->challan($challan->load('supplier'), $this->money($request)),
            'supplier_warning' => $this->suppliers->summary($challan->supplier)['warning'],
        ], $challan->wasRecentlyCreated ? 201 : 200);
    }

    public function photo(DeliveryChallan $deliveryChallan): StreamedResponse
    {
        abort_unless($deliveryChallan->photo_path && Storage::exists($deliveryChallan->photo_path), 404);

        return Storage::response($deliveryChallan->photo_path);
    }

    public function consumptionIndex(Project $project): JsonResponse
    {
        $issues = MaterialIssue::with('items')->where('project_id', $project->id)->latest('id')->take(self::RECENT)->get();

        return response()->json(['data' => $issues->map(fn (MaterialIssue $issue) => $this->procurement->presentIssue($issue))]);
    }

    public function storeConsumption(Request $request, Project $project): JsonResponse
    {
        $uuid = $request->validate(['client_uuid' => ['required', 'uuid']])['client_uuid'];

        $issue = $this->consumption->find($project->organization_id, $uuid)
            ?? $this->consumption->create($project, $request->validate($this->consumption->rules($project)), $request->user());

        $this->assertSameProject($issue, $project);

        return response()->json(['data' => $this->procurement->presentIssue($issue->load('items'))], $issue->wasRecentlyCreated ? 201 : 200);
    }

    public function stockCheckIndex(Project $project): JsonResponse
    {
        $checks = MaterialStockCheck::where('project_id', $project->id)->latest('id')->take(self::RECENT)->get();

        return response()->json(['data' => $checks->map(fn (MaterialStockCheck $check) => $this->presenter->stockCheck($check))]);
    }

    public function storeStockCheck(Request $request, Project $project): JsonResponse
    {
        $uuid = $request->validate(['client_uuid' => ['required', 'uuid']])['client_uuid'];

        $check = $this->stockChecks->find($project->organization_id, $uuid)
            ?? $this->stockChecks->create($project, $request->validate($this->stockChecks->rules($project)), $request->user());

        $this->assertSameProject($check, $project);

        return response()->json(['data' => $this->presenter->stockCheck($check)], $check->wasRecentlyCreated ? 201 : 200);
    }

    private function money(Request $request): bool
    {
        return $request->user()->can('money.view');
    }

    private function assertSameProject(Model $row, Project $project): void
    {
        if ((int) $row->project_id !== $project->id) {
            throw ValidationException::withMessages(['client_uuid' => __('This record belongs to another project.')]);
        }
    }
}
