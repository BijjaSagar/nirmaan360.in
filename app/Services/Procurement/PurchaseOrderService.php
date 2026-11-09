<?php

namespace App\Services\Procurement;

use App\Models\GrnItem;
use App\Models\MaterialReturnItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function termsRules(): array
    {
        return [
            'delivery_by' => ['nullable', 'date'],
            'terms' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** Terms can change only while the PO is a draft. */
    public function updateTerms(PurchaseOrder $order, array $data): PurchaseOrder
    {
        $this->assertDraft($order);
        $order->update($data);

        return $order;
    }

    /** Sends the PO out. It is locked from here on, and goods can be received against it. */
    public function issue(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            $this->assertDraft($order);
            $order->update(['status' => 'ISSUED', 'issued_at' => now()]);

            return $order;
        });
    }

    /** Stops further receipts: cancelled if nothing arrived, otherwise closed short. */
    public function close(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            $order = PurchaseOrder::with('items')->lockForUpdate()->findOrFail($order->id);

            if (in_array($order->status, ['RECEIVED', 'CLOSED', 'CANCELLED'], true)) {
                throw ValidationException::withMessages(['status' => __('This purchase order is already complete.')]);
            }

            $received = collect($this->netReceived($order))->contains(fn ($qty) => Dec::of($qty)->isPositive());
            $order->update(['status' => $received ? 'CLOSED' : 'CANCELLED', 'closed_at' => now()]);

            return $order;
        });
    }

    /**
     * Accepted quantity per PO line in base units: posted receipts less posted vendor returns.
     *
     * @return array<int, string>
     */
    public function netReceived(PurchaseOrder $order): array
    {
        $itemIds = PurchaseOrderItem::where('purchase_order_id', $order->id)->pluck('id');
        $net = $itemIds->mapWithKeys(fn ($id) => [$id => Dec::of(0)])->all();

        GrnItem::whereIn('purchase_order_item_id', $itemIds)
            ->whereHas('grn', fn ($q) => $q->where('status', 'POSTED'))
            ->get(['purchase_order_item_id', 'base_qty'])
            ->each(function (GrnItem $line) use (&$net) {
                $net[$line->purchase_order_item_id] = $net[$line->purchase_order_item_id]->plus(Dec::of($line->base_qty));
            });

        MaterialReturnItem::with('grnItem:id,purchase_order_item_id')
            ->whereHas('materialReturn', fn ($q) => $q->where('status', 'POSTED'))
            ->whereHas('grnItem', fn ($q) => $q->whereIn('purchase_order_item_id', $itemIds))
            ->get(['grn_item_id', 'base_qty'])
            ->each(function (MaterialReturnItem $line) use (&$net) {
                $id = $line->grnItem->purchase_order_item_id;
                $net[$id] = $net[$id]->minus(Dec::of($line->base_qty));
            });

        return array_map(Dec::qty(...), $net);
    }

    /** Work out receipt status from the lines. A closed or cancelled PO stays that way. */
    public function refreshStatus(PurchaseOrder $order): void
    {
        if (! in_array($order->status, ['ISSUED', 'PARTIAL', 'RECEIVED'], true)) {
            return;
        }

        $received = $this->netReceived($order);
        $lines = PurchaseOrderItem::where('purchase_order_id', $order->id)->get();

        $complete = $lines->every(fn (PurchaseOrderItem $line) => Dec::of($received[$line->id])->isGreaterThanOrEqualTo(Dec::of($line->base_qty)));
        $started = collect($received)->contains(fn ($qty) => Dec::of($qty)->isPositive());

        $order->update(['status' => $complete ? 'RECEIVED' : ($started ? 'PARTIAL' : 'ISSUED')]);
    }

    private function assertDraft(PurchaseOrder $order): void
    {
        if ($order->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => __('Only a draft purchase order can do this.')]);
        }
    }
}
