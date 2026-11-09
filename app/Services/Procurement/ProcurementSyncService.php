<?php

namespace App\Services\Procurement;

use App\Models\Grn;
use App\Models\Material;
use App\Models\MaterialIssue;
use App\Models\MaterialStore;
use App\Models\MaterialUnit;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The field app's outbox for receipts and issues. Same contract as /sync/push:
 * items apply in order, one bad item is reported in its own result and the rest still run.
 *
 * These are create-only records, so a replay is always a duplicate, never a conflict. A
 * device that was offline can be refused on stock or PO balance: the server wins, and the
 * reason comes back under errors.stock or errors.over_receipt for the person to fix.
 */
class ProcurementSyncService
{
    private const TYPES = ['grn', 'material_issue'];

    public function __construct(
        private GrnService $grns,
        private MaterialIssueService $issues,
        private StockLedgerService $ledger,
        private PurchaseOrderService $orders,
    ) {}

    /** @return list<array<string, mixed>> */
    public function push(array $items, User $user): array
    {
        return array_map(fn ($item) => $this->pushItem($item, $user), array_values($items));
    }

    /** What the device needs before it goes offline. */
    public function snapshot(Project $project): array
    {
        $orders = PurchaseOrder::with('items', 'vendor')
            ->where('project_id', $project->id)
            ->whereIn('status', ['ISSUED', 'PARTIAL'])
            ->orderBy('id')
            ->get();

        return [
            'server_time' => now()->toIso8601String(),
            'stores' => MaterialStore::where('project_id', $project->id)->orderBy('name')->get(['id', 'name', 'site_id']),
            'units' => MaterialUnit::orderBy('code')->get(['id', 'code']),
            'materials' => Material::with('conversions:id,material_id,unit_id,factor')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'base_unit_id']),
            'stock' => $this->ledger->projectBalances($project)
                ->map(function (string $balance, string $key) {
                    [$storeId, $materialId] = explode(':', $key);

                    return ['store_id' => (int) $storeId, 'material_id' => (int) $materialId, 'balance' => $balance];
                })->values(),
            'purchase_orders' => $orders->map(fn (PurchaseOrder $order) => $this->openOrder($order))->values(),
        ];
    }

    /** @return array<string, mixed> */
    public function presentGrn(Grn $grn): array
    {
        return [
            'id' => $grn->id,
            'number' => $grn->number,
            'client_uuid' => $grn->client_uuid,
            'purchase_order_id' => $grn->purchase_order_id,
            'store_id' => $grn->store_id,
            'grn_date' => $grn->grn_date?->toDateString(),
            'challan_no' => $grn->challan_no,
            'status' => $grn->status,
            'items' => $grn->items->map(fn ($line) => [
                'id' => $line->id,
                'purchase_order_item_id' => $line->purchase_order_item_id,
                'material_id' => $line->material_id,
                'qty' => $line->qty,
                'unit_id' => $line->unit_id,
                'base_qty' => $line->base_qty,
                'rejected_qty' => $line->rejected_qty,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    public function presentIssue(MaterialIssue $issue): array
    {
        return [
            'id' => $issue->id,
            'number' => $issue->number,
            'client_uuid' => $issue->client_uuid,
            'store_id' => $issue->store_id,
            'issue_date' => $issue->issue_date?->toDateString(),
            'issued_to' => $issue->issued_to,
            'status' => $issue->status,
            'items' => $issue->items->map(fn ($line) => [
                'id' => $line->id,
                'material_id' => $line->material_id,
                'qty' => $line->qty,
                'unit_id' => $line->unit_id,
                'base_qty' => $line->base_qty,
                'boq_item_id' => $line->boq_item_id,
                'activity' => $line->activity,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function openOrder(PurchaseOrder $order): array
    {
        $received = $this->orders->netReceived($order);

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'vendor' => $order->vendor?->name,
            'store_id' => $order->store_id,
            'items' => $order->items->map(fn ($line) => [
                'id' => $line->id,
                'material_id' => $line->material_id,
                'qty' => $line->qty,
                'unit_id' => $line->unit_id,
                'base_qty' => $line->base_qty,
                'received_base_qty' => $received[$line->id],
                'remaining_base_qty' => Dec::qty(Dec::of($line->base_qty)->minus(Dec::of($received[$line->id]))),
            ])->values(),
        ];
    }

    private function pushItem(mixed $item, User $user): array
    {
        $result = [
            'client_uuid' => is_array($item) && is_string($item['client_uuid'] ?? null) ? $item['client_uuid'] : null,
            'status' => 'error',
            'id' => null,
            'server_status' => null,
            'reason' => null,
            'errors' => null,
        ];

        try {
            // Per item, so a refused one leaves nothing behind and cannot affect the rest.
            return [...$result, ...DB::transaction(fn () => $this->apply(is_array($item) ? $item : [], $user))];
        } catch (ValidationException $exception) {
            return [...$result, 'errors' => $exception->errors()];
        } catch (Throwable $exception) {
            report($exception);

            return [...$result, 'errors' => ['item' => [__('This record could not be saved.')]]];
        }
    }

    private function apply(array $item, User $user): array
    {
        $item = Validator::make($item, [
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'client_uuid' => ['required', 'uuid'],
            'project_id' => ['required', 'integer'],
            'data' => ['required', 'array'],
        ])->validate();

        if (! $user->hasPermission('procurement.stock')) {
            return ['status' => 'forbidden', 'reason' => 'permission'];
        }

        if (! $user->organization->allowsFeature('procurement')) {
            return ['status' => 'forbidden', 'reason' => 'feature'];
        }

        $project = Project::find($item['project_id']) ?? throw ValidationException::withMessages(['project_id' => [__('Project not found.')]]);
        $isGrn = $item['type'] === 'grn';
        $service = $isGrn ? $this->grns : $this->issues;

        // Before validating, because a replay can be valid even after its PO has closed.
        if ($existing = $service->find($project->organization_id, $item['client_uuid'])) {
            $this->assertSameProject($existing, $project);

            return $this->result('duplicate', $existing);
        }

        $data = Validator::make($item['data'], $service->rules($project))->validate();
        $data['client_uuid'] = $item['client_uuid'];

        $row = $isGrn ? $this->grns->create($project, $data, $user) : $this->issues->create($project, $data, $user);

        return $this->result($row->wasRecentlyCreated ? 'created' : 'duplicate', $row);
    }

    private function assertSameProject(Model $row, Project $project): void
    {
        if ((int) $row->project_id !== $project->id) {
            throw ValidationException::withMessages(['project_id' => [__('This record belongs to another project.')]]);
        }
    }

    private function result(string $status, Model $row): array
    {
        return ['status' => $status, 'id' => $row->getKey(), 'server_status' => $row->getAttribute('status'), 'reason' => null];
    }
}
