<?php

namespace App\Services\MaterialControl;

use App\Models\DeliveryChallan;
use App\Models\MaterialStockCheck;

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
}
