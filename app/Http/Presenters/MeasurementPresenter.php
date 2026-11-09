<?php

namespace App\Http\Presenters;

use App\Models\MeasurementAttachment;
use App\Models\MeasurementEntry;

final class MeasurementPresenter
{
    public const RELATIONS = ['currentApproval.steps'];

    public static function attachment(MeasurementAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'measurement_entry_id' => $attachment->measurement_entry_id,
            'kind' => $attachment->kind,
            'caption' => $attachment->caption,
            'is_image' => $attachment->isImage(),
        ];
    }

    public static function present(MeasurementEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'client_uuid' => $entry->client_uuid,
            'project_id' => $entry->project_id,
            'boq_item_id' => $entry->boq_item_id,
            'work_order_id' => $entry->work_order_id,
            'measured_on' => $entry->measured_on?->toDateString(),
            'description' => $entry->description,
            'location' => $entry->location,
            'nos' => $entry->nos,
            'length' => $entry->length,
            'breadth' => $entry->breadth,
            'depth' => $entry->depth,
            'is_deduction' => (bool) $entry->is_deduction,
            'qty' => $entry->qty,
            'status' => $entry->status,
            'deviation' => (bool) $entry->deviation,
            'remarks' => $entry->remarks,
            'return_remark' => ApprovalPresenter::returnRemark($entry),
            'created_by' => $entry->created_by,
            'client_updated_at' => $entry->client_updated_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];
    }
}
