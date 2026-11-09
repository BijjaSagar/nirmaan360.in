<?php

namespace App\Services\Procurement;

use App\Models\AuditLog;
use App\Models\Grn;
use App\Models\GrnItem;
use App\Models\Material;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\MaterialReturn;
use App\Models\MaterialReturnItem;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Unused material back to the store, or accepted material back to the vendor. */
class MaterialReturnService
{
    public function __construct(
        private StockLedgerService $ledger,
        private PurchaseOrderService $orders,
        private UnitConverter $units,
        private DocumentNumbers $numbers,
    ) {}

    public function rules(string $lineTable): array
    {
        return [
            'return_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:255'],
            'client_uuid' => ProcurementRules::clientUuid(),
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.line_id' => ['bail', 'required', 'integer', org_exists($lineTable)],
            'items.*.qty' => ProcurementRules::qty(),
        ];
    }

    /** Stock comes back to the issuing store and the BOQ item's consumption goes down. */
    public function returnToStore(MaterialIssue $issue, array $data, User $by): MaterialReturn
    {
        return $this->once($issue->organization_id, $data, function () use ($issue, $data, $by) {
            $issue = MaterialIssue::with('items.material.conversions', 'items.material.baseUnit')->lockForUpdate()->findOrFail($issue->id);

            if ($existing = $this->replayed($issue->organization_id, $data)) {
                return $existing;
            }

            if ($issue->isReversed()) {
                throw ValidationException::withMessages(['status' => __('This issue has been reversed.')]);
            }

            return $this->post($issue, MaterialReturn::TO_STORE, $issue->items, 'material_issue_item_id', $data, $by, fn ($line, $base, $item) => [
                'type' => StockLedgerService::RETURN_IN,
                'qty' => $base,
                'key' => 'return_item:'.$item->id,
                'boq_item_id' => $line->boq_item_id,
                'activity' => $line->activity,
            ]);
        });
    }

    /** Stock leaves the store and the quantity becomes due on the purchase order again. */
    public function returnToVendor(Grn $grn, array $data, User $by): MaterialReturn
    {
        return $this->once($grn->organization_id, $data, function () use ($grn, $data, $by) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($grn->purchase_order_id);
            $grn = Grn::with('items.material.conversions', 'items.material.baseUnit')->lockForUpdate()->findOrFail($grn->id);

            if ($existing = $this->replayed($grn->organization_id, $data)) {
                return $existing;
            }

            if ($grn->isReversed()) {
                throw ValidationException::withMessages(['status' => __('This receipt has been reversed.')]);
            }

            $return = $this->post($grn, MaterialReturn::TO_VENDOR, $grn->items, 'grn_item_id', $data, $by, fn ($line, $base, $item) => [
                'type' => StockLedgerService::RETURN_OUT,
                'qty' => Dec::qty(Dec::of($base)->negated()),
                'key' => 'return_item:'.$item->id,
            ]);

            $this->orders->refreshStatus($order);

            return $return;
        });
    }

    public function reverse(MaterialReturn $return, string $reason, User $by): MaterialReturn
    {
        return DB::transaction(function () use ($return, $reason, $by) {
            $order = $return->isToVendor() ? PurchaseOrder::lockForUpdate()->findOrFail($return->grn->purchase_order_id) : null;
            $return = MaterialReturn::lockForUpdate()->findOrFail($return->id);

            if ($return->isReversed()) {
                throw ValidationException::withMessages(['status' => __('This document has already been reversed.')]);
            }

            $this->ledger->reverse($return, $by);
            $return->update(['status' => 'REVERSED', 'reversed_by' => $by->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);

            if ($order) {
                $this->orders->refreshStatus($order);
            }

            return $return;
        });
    }

    /**
     * Quantity already returned per source line, for posted returns.
     *
     * @return array<int, string>
     */
    public function returned(string $lineColumn, Collection $lineIds): array
    {
        $totals = [];

        MaterialReturnItem::whereIn($lineColumn, $lineIds)
            ->whereHas('materialReturn', fn ($q) => $q->where('status', 'POSTED'))
            ->get([$lineColumn, 'base_qty'])
            ->each(function ($row) use (&$totals, $lineColumn) {
                $totals[$row->{$lineColumn}] = Dec::qty(Dec::of($totals[$row->{$lineColumn}] ?? 0)->plus(Dec::of($row->base_qty)));
            });

        return $totals;
    }

    private function post(Model $source, string $type, Collection $lines, string $lineColumn, array $data, User $by, callable $movement): MaterialReturn
    {
        $byId = $lines->keyBy('id');
        $returned = $this->returned($lineColumn, $byId->keys());
        $isIssue = $source instanceof MaterialIssue;

        $return = MaterialReturn::create([
            'organization_id' => $source->organization_id,
            'project_id' => $source->project_id,
            'store_id' => $source->store_id,
            'return_type' => $type,
            'material_issue_id' => $isIssue ? $source->id : null,
            'grn_id' => $isIssue ? null : $source->id,
            'number' => $this->numbers->next($source->organization_id, 'MR'),
            'return_date' => $data['return_date'],
            'reason' => $data['reason'] ?? null,
            'status' => 'POSTED',
            'client_uuid' => $data['client_uuid'] ?? null,
            'created_by' => $by->id,
        ]);

        $movements = [];

        foreach ($data['items'] as $input) {
            /** @var MaterialIssueItem|GrnItem|null $line */
            $line = $byId->get($input['line_id']);

            if (! $line) {
                throw ValidationException::withMessages(['items' => __('A line on this return is not on the source document.')]);
            }

            /** @var Material $material */
            $material = $line->material;
            $base = $this->units->toBase($material, $input['qty'], $line->unit_id);
            $total = Dec::of($returned[$line->id] ?? 0)->plus(Dec::of($base));

            if ($total->isGreaterThan(Dec::of($line->base_qty))) {
                throw ValidationException::withMessages(['over_return' => __('Only :qty :unit of :material can still be returned.', [
                    'qty' => fmt_qty(Dec::of($line->base_qty)->minus(Dec::of($returned[$line->id] ?? 0))),
                    'unit' => $material->baseUnit->code,
                    'material' => $material->name,
                ])]);
            }

            $returned[$line->id] = Dec::qty($total);

            $item = $return->items()->create([
                'organization_id' => $source->organization_id,
                $lineColumn => $line->id,
                'material_id' => $material->id,
                'qty' => Dec::qty($input['qty']),
                'unit_id' => $line->unit_id,
                'base_qty' => $base,
            ]);

            $movements[] = [
                'project_id' => $source->project_id,
                'store_id' => $source->store_id,
                'material_id' => $material->id,
                'date' => $data['return_date'],
                'source' => $return,
                ...$movement($line, $base, $item),
            ];
        }

        $this->ledger->post($source->organization_id, $movements, $by);
        AuditLog::record('material_return.posted', $return, null, ['type' => $type, 'lines' => count($movements)]);

        return $return;
    }

    private function once(int $organizationId, array $data, callable $post): MaterialReturn
    {
        if ($existing = $this->replayed($organizationId, $data)) {
            return $existing;
        }

        return DB::transaction(fn () => Idempotent::create(
            $post,
            fn () => $this->replayed($organizationId, $data),
        ), 3);
    }

    private function replayed(int $organizationId, array $data): ?MaterialReturn
    {
        $uuid = $data['client_uuid'] ?? null;

        return $uuid ? MaterialReturn::where('organization_id', $organizationId)->where('client_uuid', $uuid)->first() : null;
    }
}
