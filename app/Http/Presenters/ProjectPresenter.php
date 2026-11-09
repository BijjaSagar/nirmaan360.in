<?php

namespace App\Http\Presenters;

use App\Models\Project;

final class ProjectPresenter
{
    public static function present(Project $project): array
    {
        return [
            'id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'location' => $project->location,
            'status' => $project->status,
            'start_date' => $project->start_date?->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            'measurement_tolerance_pct' => $project->measurement_tolerance_pct,
            'image_url' => $project->image_path === null ? null : url('/api/v1/projects/'.$project->id.'/image?v='.$project->imageVersion()),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
    }
}
