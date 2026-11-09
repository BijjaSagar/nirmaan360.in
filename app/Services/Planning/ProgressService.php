<?php

namespace App\Services\Planning;

use App\Models\BoqItem;
use App\Models\MeasurementEntry;
use App\Models\PlanActivity;
use App\Models\PlanDependency;
use App\Models\PlanWbsNode;
use App\Models\Project;
use App\Support\Dec;
use Carbon\CarbonInterface;

class ProgressService
{
    /** Load a project's plan once, so one page's figures all come from the same rows. */
    public function snapshot(Project $project, ?CarbonInterface $today = null): PlanSnapshot
    {
        $activities = PlanActivity::with('boqLinks')
            ->where('project_id', $project->id)
            ->orderBy('planned_start')
            ->orderBy('id')
            ->get();

        $items = BoqItem::withLedger()->where('project_id', $project->id)->get()->keyBy('id');

        $entries = MeasurementEntry::where('project_id', $project->id)
            ->where('status', 'APPROVED')
            ->orderBy('measured_on')
            ->orderBy('id')
            ->get(['boq_item_id', 'measured_on', 'qty'])
            ->map(fn (MeasurementEntry $entry) => [
                'item' => (int) $entry->boq_item_id,
                'day' => Days::of($entry->measured_on),
                'qty' => Dec::of($entry->qty),
            ])
            ->all();

        return new PlanSnapshot(
            project: $project,
            activities: $activities,
            items: $items,
            entries: $entries,
            nodes: PlanWbsNode::where('project_id', $project->id)->get(),
            dependencies: PlanDependency::where('project_id', $project->id)->get(),
            today: $today ? Days::of($today) : Days::today(),
        );
    }
}
