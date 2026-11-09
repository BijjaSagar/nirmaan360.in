<?php

namespace Tests\Feature\Planning;

use App\Models\BoqItem;
use App\Models\MeasurementEntry;
use App\Models\Organization;
use App\Models\PlanActivity;
use App\Models\PlanBoqLink;
use App\Models\Project;
use App\Models\User;
use App\Services\Planning\PlanSnapshot;
use App\Services\Planning\ProgressService;
use App\Services\Planning\ScheduleService;
use Illuminate\Support\Carbon;
use Tests\BuildsTenant;

trait BuildsPlan
{
    use BuildsTenant;

    protected Organization $organization;

    protected User $engineer;

    protected User $owner;

    protected Project $project;

    protected function setUpPlan(string $plan = 'business'): void
    {
        $this->organization = $this->tenant('Acme Infra', $plan);
        $this->engineer = $this->member($this->organization, 'engineer');
        $this->owner = $this->member($this->organization, 'owner');
        $this->actingAs($this->owner);
        $this->project = $this->makeProject($this->organization);
    }

    protected function schedule(): ScheduleService
    {
        return app(ScheduleService::class);
    }

    protected function activity(string $start, string $end, array $overrides = [], ?Project $project = null): PlanActivity
    {
        return $this->schedule()->createActivity($project ?? $this->project, [
            'name' => 'Work package',
            'planned_start' => $start,
            'planned_end' => $end,
            ...$overrides,
        ]);
    }

    protected function link(PlanActivity $activity, BoqItem $item, string $qty): PlanBoqLink
    {
        return $this->schedule()->linkBoqItem($activity, ['boq_item_id' => $item->id, 'qty' => $qty]);
    }

    /** Takes a measurement through the real submit and approve flow. */
    protected function approved(BoqItem $item, string $qty, string $date): MeasurementEntry
    {
        $entry = $this->draftEntry($item->project, $item, $this->engineer, [
            'measured_on' => $date,
            'nos' => $qty,
            'length' => null,
            'breadth' => null,
            'depth' => null,
        ]);

        return $this->approveEntry($entry, $this->engineer, $this->owner);
    }

    protected function snapshot(string $today, ?Project $project = null): PlanSnapshot
    {
        return app(ProgressService::class)->snapshot($project ?? $this->project, Carbon::parse($today));
    }

    protected function dates(PlanActivity $activity): array
    {
        $activity->refresh();

        return [$activity->planned_start->toDateString(), $activity->planned_end->toDateString()];
    }
}
