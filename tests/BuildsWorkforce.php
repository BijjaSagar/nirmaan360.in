<?php

namespace Tests;

use App\Models\Attendance;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Worker;

/** Workers and attendance for the workforce tests. Use together with BuildsTenant. */
trait BuildsWorkforce
{
    protected function makeWorker(Organization $organization, string $name = 'Ramesh', string $wage = '800.00', array $overrides = []): Worker
    {
        return Worker::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'trade' => 'Mason',
            'type' => 'own',
            'daily_wage' => $wage,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    protected function mark(Project $project, Worker $worker, string $date, string $status = 'present', float|int $overtime = 0): Attendance
    {
        return Attendance::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'worker_id' => $worker->id,
            'attendance_date' => $date,
            'status' => $status,
            'ot_hours' => $overtime,
            'method' => 'manual',
        ]);
    }

    /** Several days of attendance for one worker: a list of [status, overtime] in date order from $from. */
    protected function markDays(Project $project, Worker $worker, string $from, array $days): void
    {
        foreach ($days as $i => $day) {
            $this->mark($project, $worker, now()->parse($from)->addDays($i)->toDateString(), $day[0], $day[1] ?? 0);
        }
    }
}
