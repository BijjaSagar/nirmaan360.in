<?php

namespace App\Http\Presenters;

use App\Models\Approval;
use App\Models\Dpr;

final class DprPresenter
{
    public const RELATIONS = ['creator', 'manpower', 'machinery', 'workItems', 'photos', 'currentApproval.steps'];

    public static function present(Dpr $dpr): array
    {
        return [
            'id' => $dpr->id,
            'client_uuid' => $dpr->client_uuid,
            'project_id' => $dpr->project_id,
            'site_id' => $dpr->site_id,
            'report_date' => $dpr->report_date?->toDateString(),
            'weather' => $dpr->weather,
            'summary' => $dpr->summary,
            'hindrances' => $dpr->hindrances,
            'status' => $dpr->status,
            'created_by' => $dpr->created_by,
            'created_by_name' => $dpr->creator?->name,
            'manpower' => $dpr->manpower->map(fn ($row) => ['trade' => $row->trade, 'count' => (int) $row->count])->all(),
            'machinery' => $dpr->machinery->map(fn ($row) => ['name' => $row->name, 'hours' => $row->hours])->all(),
            'work_items' => $dpr->workItems->map(fn ($row) => ['boq_item_id' => $row->boq_item_id, 'description' => $row->description, 'qty' => $row->qty])->all(),
            'photos' => $dpr->photos->map(fn ($photo) => ['id' => $photo->id, 'caption' => $photo->caption])->all(),
            'return_remark' => ApprovalPresenter::returnRemark($dpr),
            'client_updated_at' => $dpr->client_updated_at?->toIso8601String(),
            'updated_at' => $dpr->updated_at?->toIso8601String(),
        ];
    }

    /** The full record, with every approval round's steps, oldest first. */
    public static function detail(Dpr $dpr): array
    {
        return [
            ...self::present($dpr),
            'steps' => $dpr->approvals()->with('steps.user')->get()
                ->sortBy('id')
                ->flatMap(fn (Approval $approval) => $approval->steps->map(ApprovalPresenter::step(...)))
                ->values()
                ->all(),
        ];
    }
}
