<?php

namespace App\Http\Controllers\MaterialControl\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryChallan;
use App\Models\MaterialFlagReason;
use App\Models\MaterialIssue;
use App\Models\MaterialShortDelivery;
use App\Models\MaterialStockCheck;
use App\Models\Project;
use App\Services\MaterialControl\ConsumptionService;
use App\Services\MaterialControl\DeliveryChallanService;
use App\Services\MaterialControl\MaterialCheckService;
use App\Services\MaterialControl\MaterialControlPresenter;
use App\Services\MaterialControl\ShortDeliveryService;
use App\Services\MaterialControl\StockCheckService;
use App\Services\MaterialControl\SupplierRecordService;
use App\Services\Procurement\ProcurementSyncService;
use App\Support\ClientUuidRepeat;
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
        private ShortDeliveryService $shorts,
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

    /**
     * A written answer to a flag on the three-way check, the same as the web form. The gap is kept
     * as it stood, so the flag opens again if it grows. A gap that has closed is refused.
     */
    public function storeReason(Request $request, Project $project): JsonResponse
    {
        $uuid = $request->validate(['client_uuid' => ['required', 'uuid']])['client_uuid'];

        // Before the field rules, so a retry is answered with the first reason even once the gap has moved.
        $reason = $this->repeat(MaterialFlagReason::class, $project->organization_id, $uuid)
            ?? $this->checks->addReason($project, $request->validate($this->checks->reasonRules()), $request->user());

        $this->assertSameProject($reason, $project);

        return response()->json(['data' => $this->presenter->reason($reason->load('creator'))], $reason->wasRecentlyCreated ? 201 : 200);
    }

    public function showChallan(Request $request, DeliveryChallan $deliveryChallan): JsonResponse
    {
        return response()->json(['data' => $this->detail($deliveryChallan, $request)]);
    }

    /**
     * The supplier's bill, as a photo or PDF, as on the web. Anyone with material_control.bills may
     * attach one; its number, date and amount are read only from someone who also holds money.view.
     * Attaching again replaces the file, so a retried upload leaves one bill behind.
     */
    public function attachBill(Request $request, DeliveryChallan $deliveryChallan): JsonResponse
    {
        $money = $this->money($request);
        $data = $request->validate($this->challans->billRules($deliveryChallan->project, $money));

        $this->challans->attachBill($deliveryChallan, $data, $request->user());

        return response()->json(['data' => $this->detail($deliveryChallan->fresh(), $request)]);
    }

    /** The bill file itself. The route also needs money.view. */
    public function bill(DeliveryChallan $deliveryChallan): StreamedResponse
    {
        abort_unless($deliveryChallan->bill_path && Storage::exists($deliveryChallan->bill_path), 404);

        return Storage::response($deliveryChallan->bill_path);
    }

    /** Less arrived than the challan said, found after it was posted. Stock is corrected and the supplier marked. */
    public function storeShort(Request $request, DeliveryChallan $deliveryChallan): JsonResponse
    {
        $uuid = $request->validate(['client_uuid' => ['required', 'uuid']])['client_uuid'];

        // Before the field rules, so a retry sent the next day is not refused for its date.
        $short = $this->repeat(MaterialShortDelivery::class, $deliveryChallan->organization_id, $uuid)
            ?? $this->shorts->report($deliveryChallan, $request->validate($this->shorts->rules()), $request->user());

        if ((int) $short->delivery_challan_id !== $deliveryChallan->id) {
            throw ValidationException::withMessages(['client_uuid' => __('This record belongs to another challan.')]);
        }

        return response()->json([
            'data' => $this->presenter->shortDelivery($short->load('creator')),
            'challan' => $this->detail($deliveryChallan->fresh(), $request),
        ], $short->wasRecentlyCreated ? 201 : 200);
    }

    /** The accountant's list: posted challans still waiting for a bill, or with ?show=attached those that have one. */
    public function bills(Request $request, Project $project): JsonResponse
    {
        $pending = $request->query('show') !== 'attached';
        $days = (int) config('modules.site_alerts.challan_bill_days', 7);
        $today = now();
        $money = $this->money($request);
        $posted = DeliveryChallan::where('project_id', $project->id)->where('status', 'POSTED');

        $page = (clone $posted)->with('supplier', 'material', 'unit')
            ->where('bill_status', $pending ? 'pending' : 'attached')
            ->latest('id')
            ->paginate(25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (DeliveryChallan $challan) => $this->presenter->billRow($challan, $money, $today, $days))->values(),
            'meta' => [
                'show' => $pending ? 'pending' : 'attached',
                'pending_count' => (clone $posted)->where('bill_status', 'pending')->count(),
                'alert_after_days' => $days,
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * A repeated client_uuid, looked up across the company as the services do, then held to the project limit.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel|null
     */
    private function repeat(string $model, int $organizationId, string $uuid): ?Model
    {
        return ClientUuidRepeat::reachable($model::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }

    /** @return array<string, mixed> */
    private function detail(DeliveryChallan $challan, Request $request): array
    {
        $challan->loadMissing('supplier');

        return $this->presenter->challanDetail($challan, $this->money($request), $this->suppliers->summary($challan->supplier)['warning']);
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
