<?php

namespace App\Services\MaterialControl;

use App\Models\DeliveryChallan;
use App\Models\MaterialFlagReason;
use App\Models\MaterialShortDelivery;
use App\Models\MaterialStockCheck;
use Carbon\CarbonInterface;

/** Shapes records for the mobile API. Bill details leave the server only with money.view. */
class MaterialControlPresenter
{
    /** @return array<string, mixed> */
    public function challan(DeliveryChallan $challan, bool $withMoney): array
    {
        $data = [
            'id' => $challan->id,
            'number' => $challan->number,
            'client_uuid' => $challan->client_uuid,
            'project_id' => $challan->project_id,
            'party_id' => $challan->party_id,
            'supplier' => $challan->supplier?->name,
            'store_id' => $challan->store_id,
            'material_id' => $challan->material_id,
            'unit_id' => $challan->unit_id,
            'qty' => $challan->qty,
            'base_qty' => $challan->base_qty,
            'challan_qty' => $challan->challan_qty,
            'challan_no' => $challan->challan_no,
            'challan_date' => $challan->challan_date?->toDateString(),
            'vehicle_no' => $challan->vehicle_no,
            'has_photo' => $challan->photo_path !== null,
            'bill_status' => $challan->bill_status,
            'status' => $challan->status,
        ];

        if ($withMoney) {
            $data += [
                'bill_no' => $challan->bill_no,
                'bill_date' => $challan->bill_date?->toDateString(),
                'bill_amount' => $challan->bill_amount,
                'vendor_bill_id' => $challan->vendor_bill_id,
            ];
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function stockCheck(MaterialStockCheck $check): array
    {
        return [
            'id' => $check->id,
            'client_uuid' => $check->client_uuid,
            'project_id' => $check->project_id,
            'store_id' => $check->store_id,
            'material_id' => $check->material_id,
            'unit_id' => $check->unit_id,
            'check_date' => $check->check_date?->toDateString(),
            'physical_qty' => $check->physical_qty,
            'physical_base_qty' => $check->physical_base_qty,
            'book_qty' => $check->book_qty,
            'variance_qty' => $check->variance_qty,
            'status' => $check->status,
        ];
    }

    /**
     * One challan in full for the field app: names, remarks, who did what, the shortages found on it
     * and whether its bill has come. Whether a bill file exists is for money.view only, like the file.
     *
     * @return array<string, mixed>
     */
    public function challanDetail(DeliveryChallan $challan, bool $withMoney, ?string $supplierWarning = null): array
    {
        $challan->loadMissing('supplier', 'material', 'unit', 'store', 'creator', 'reverser', 'billAttacher', 'shortDeliveries.creator');

        $data = [
            ...$this->challan($challan, $withMoney),
            'material' => $challan->material?->name,
            'unit' => $challan->unit?->code,
            'store' => $challan->store?->name,
            'remarks' => $challan->remarks,
            'created_by' => $challan->creator?->name,
            'created_at' => $challan->created_at?->toIso8601String(),
            'bill_attached_by' => $challan->billAttacher?->name,
            'bill_attached_at' => $challan->bill_attached_at?->toIso8601String(),
            'reversed_by' => $challan->reverser?->name,
            'reversed_at' => $challan->reversed_at?->toDateString(),
            'reversal_reason' => $challan->reversal_reason,
            'supplier_warning' => $supplierWarning,
            'shorts' => $challan->shortDeliveries->map(fn (MaterialShortDelivery $short) => $this->shortDelivery($short))->values()->all(),
        ];

        if ($withMoney) {
            $data['has_bill_file'] = $challan->bill_path !== null;
        }

        return $data;
    }

    /**
     * A row of the bills list: the challan, its names, and how long the bill has been waiting.
     * The alert counts from the challan date, so the days here do too.
     *
     * @return array<string, mixed>
     */
    public function billRow(DeliveryChallan $challan, bool $withMoney, CarbonInterface $today, int $alertAfterDays): array
    {
        $days = $challan->billPending() && $challan->challan_date
            ? (int) max(0, $challan->challan_date->copy()->startOfDay()->diffInDays($today->copy()->startOfDay(), false))
            : null;

        return [
            ...$this->challan($challan, $withMoney),
            'material' => $challan->material?->name,
            'unit' => $challan->unit?->code,
            'days_waiting' => $days,
            'overdue' => $days !== null && $days >= $alertAfterDays,
        ];
    }

    /** @return array<string, mixed> */
    public function shortDelivery(MaterialShortDelivery $short): array
    {
        return [
            'id' => $short->id,
            'client_uuid' => $short->client_uuid,
            'project_id' => $short->project_id,
            'delivery_challan_id' => $short->delivery_challan_id,
            'material_id' => $short->material_id,
            'unit_id' => $short->unit_id,
            'short_qty' => $short->short_qty,
            'short_base_qty' => $short->short_base_qty,
            'found_on' => $short->found_on?->toDateString(),
            'note' => $short->note,
            // Found at the gate, it was never in stock. Found later, stock was corrected.
            'at_entry' => ! $short->ledger_posted,
            'status' => $short->status,
            'created_by' => $short->creator?->name,
        ];
    }

    /** @return array<string, mixed> */
    public function reason(MaterialFlagReason $reason): array
    {
        return [
            'id' => $reason->id,
            'client_uuid' => $reason->client_uuid,
            'project_id' => $reason->project_id,
            'material_id' => $reason->material_id,
            'flag_type' => $reason->flag_type,
            'gap_qty' => $reason->gap_qty,
            'reason' => $reason->reason,
            'created_by' => $reason->creator?->name,
            'created_at' => $reason->created_at?->toIso8601String(),
        ];
    }
}
