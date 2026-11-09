<?php

namespace App\Http\Presenters;

use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\InspectionItem;

final class InspectionPresenter
{
    public static function template(ChecklistTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'category' => $template->category,
            'description' => $template->description,
            'items' => array_values($template->items),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }

    public static function inspection(Inspection $inspection): array
    {
        return [
            'id' => $inspection->id,
            'client_uuid' => $inspection->client_uuid,
            'project_id' => $inspection->project_id,
            'site_id' => $inspection->site_id,
            'checklist_template_id' => $inspection->checklist_template_id,
            'title' => $inspection->title,
            'category' => $inspection->category,
            'location' => $inspection->location,
            'inspected_on' => $inspection->inspected_on?->toDateString(),
            'status' => $inspection->status,
            'completed_at' => $inspection->completed_at?->toIso8601String(),
            'updated_at' => $inspection->updated_at?->toIso8601String(),
        ];
    }

    public static function detail(Inspection $inspection): array
    {
        return [
            ...self::inspection($inspection),
            'items' => $inspection->items->map(fn (InspectionItem $item) => [
                'id' => $item->id,
                'position' => $item->sort,
                'text' => $item->text,
                'result' => $item->result,
                'remark' => $item->remark,
            ])->all(),
        ];
    }
}
