<?php

namespace App\Services\Procurement;

use App\Models\AuditLog;
use App\Models\Grn;
use App\Models\Material;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Goods received against a purchase order. Partial receipts are normal. */
class GrnService
{
    public function __construct(
        private StockLedgerService $ledger,
        private PurchaseOrderService $orders,
        private UnitConverter $units,
        private DocumentNumbers $numbers,
    ) {}

    public function rules(Project $project): array
    {
        return [
            'purchase_order_id' => ['bail', 'required', 'integer', Rule::exists('purchase_orders', 'id')->where('project_id', $project->id)],
            'store_id' => ['bail', 'nullable', 'integer', Rule::exists('material_stores', 'id')->where('project_id', $project->id)],
            'grn_date' => ['required', 'date', 'before_or_equal:today'],
            'challan_no' => ['nullable', 'string', 'max:60'],
            'vehicle_no' => ['nullable', 'string', 'max:30'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'client_uuid' => ProcurementRules::clientUuid(),
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.purchase_order_item_id' => ['bail', 'required', 'integer', org_exists('purchase_order_items')],
            'items.*.qty' => ProcurementRules::qty(),
            'items.*.unit_id' => ['bail', 'nullable', 'integer', org_exists('material_units')],
            'items.*.rejected_qty' => ProcurementRules::optionalQty(),
        ];
    }

    /** Post a receipt. A repeated client_uuid returns the original and adds no stock. */
    public function create(Project $project, array $data, User $by): Grn
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->find($project->organization_id, $uuid))) {
            return $existing;
        }

        return DB::transaction(fn () => Idempotent::create(
            fn () => $this->post($project, $data, $by),
            fn () => $this->find($project->organization_id, (string) $uuid),
        ), 3);
    }

    /**
     * Undo a receipt with reversal entries. Refused if the stock has since been used,
     * because the reversal would take the store negative.
     */
    public function reverse(Grn $grn, string $reason, User $by): Grn
    {
        return DB::transaction(function () use ($grn, $reason, $by) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($grn->purchase_order_id);
            $grn = Grn::lockForUpdate()->findOrFail($grn->id);

            if ($grn->isReversed()) {
                throw ValidationException::withMessages(['status' => __('This document has already been reversed.')]);
            }

            if ($grn->returns()->where('status', 'POSTED')->exists()) {
                throw ValidationException::withMessages(['status' => __('Reverse the vendor returns on this receipt first.')]);
            }

            $this->ledger->reverse($grn, $by);
            $grn->update(['status' => 'REVERSED', 'reversed_by' => $by->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);
            $this->orders->refreshStatus($order);

            return $grn;
        });
    }

    public function find(int $organizationId, string $uuid): ?Grn
    {
        return Grn::where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }

    private function post(Project $project, array $data, User $by): Grn
    {
        // The PO lock serialises receipts, so two cannot both fit under the ordered quantity.
        $order = PurchaseOrder::where('project_id', $project->id)->lockForUpdate()->findOrFail($data['purchase_order_id']);
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->find($project->organization_id, $uuid))) {
            return $existing;
        }

        if (! $order->canReceive()) {
            throw ValidationException::withMessages(['purchase_order' => __('This purchase order is not open for receipts.')]);
        }

        $lines = PurchaseOrderItem::with('material.conversions', 'material.baseUnit')
            ->where('purchase_order_id', $order->id)
            ->get()
            ->keyBy('id');

        $prepared = $this->prepareLines($order, $lines, $data['items']);

        $grn = Grn::create([
            'organization_id' => $order->organization_id,
            'project_id' => $order->project_id,
            'purchase_order_id' => $order->id,
            'store_id' => $data['store_id'] ?? $order->store_id,
            'number' => $this->numbers->next($order->organization_id, 'GRN'),
            'grn_date' => $data['grn_date'],
            'challan_no' => $data['challan_no'] ?? null,
            'vehicle_no' => $data['vehicle_no'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'status' => 'POSTED',
            'client_uuid' => $uuid,
            'created_by' => $by->id,
        ]);

        $movements = [];

        foreach ($prepared as $line) {
            $item = $grn->items()->create(['organization_id' => $order->organization_id, ...$line]);

            $movements[] = [
                'project_id' => $order->project_id,
                'store_id' => $grn->store_id,
                'material_id' => $item->material_id,
                'type' => StockLedgerService::GRN,
                'qty' => $line['base_qty'],
                'date' => $data['grn_date'],
                'source' => $grn,
                'key' => 'grn_item:'.$item->id,
            ];
        }

        $this->ledger->post($order->organization_id, $movements, $by);
        $this->orders->refreshStatus($order);
        AuditLog::record('grn.posted', $grn, null, ['lines' => count($prepared), 'purchase_order' => $order->number]);

        return $grn;
    }

    /** Convert each line and check the running total stays within what was ordered. */
    private function prepareLines(PurchaseOrder $order, $lines, array $items): array
    {
        $received = $this->orders->netReceived($order);
        $prepared = [];

        foreach ($items as $input) {
            $line = $lines->get($input['purchase_order_item_id']);

            if (! $line) {
                throw ValidationException::withMessages(['items' => __('A line on this receipt is not on the purchase order.')]);
            }

            /** @var Material $material */
            $material = $line->material;
            $unitId = $input['unit_id'] ?? $line->unit_id;
            $base = $this->units->toBase($material, $input['qty'], $unitId);
            $total = Dec::of($received[$line->id])->plus(Dec::of($base));

            if ($total->isGreaterThan(Dec::of($line->base_qty))) {
                throw ValidationException::withMessages(['over_receipt' => __('Only :qty :unit of :material is still due on this purchase order.', [
                    'qty' => fmt_qty(Dec::of($line->base_qty)->minus(Dec::of($received[$line->id]))),
                    'unit' => $material->baseUnit->code,
                    'material' => $material->name,
                ])]);
            }

            $received[$line->id] = Dec::qty($total);
            $prepared[] = [
                'purchase_order_item_id' => $line->id,
                'material_id' => $material->id,
                'qty' => Dec::qty($input['qty']),
                'unit_id' => $unitId,
                'base_qty' => $base,
                'rejected_qty' => Dec::qty($input['rejected_qty'] ?? 0),
            ];
        }

        return $prepared;
    }
}
