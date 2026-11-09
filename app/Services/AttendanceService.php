<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Support\Idempotent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttendanceService
{
    /** Rules for one mark, whichever path it arrives by. */
    public function markRules(): array
    {
        return [
            'status' => ['required', Rule::in(config('buildos.attendance_statuses'))],
            'ot_hours' => ['nullable', 'numeric', 'min:0', 'max:16'],
        ];
    }

    public function workerRule(): array
    {
        return ['bail', 'required', 'integer', org_exists('workers')->whereNull('deleted_at')];
    }

    /**
     * Save a day's marks. Later calls overwrite earlier ones. Ids outside the
     * tenant and rows with no status are skipped, as the web register expects.
     *
     * @param  list<array{worker_id:int, status:?string, ot_hours?:mixed, client_uuid?:?string}>  $marks
     * @return Collection<int, Attendance>
     */
    public function markDay(Project $project, string $date, array $marks, User $user): Collection
    {
        $workerIds = Worker::whereIn('id', array_column($marks, 'worker_id'))->pluck('id')->all();

        return DB::transaction(fn () => collect($marks)
            ->filter(fn ($mark) => filled($mark['status'] ?? null) && in_array((int) $mark['worker_id'], $workerIds, true))
            ->map(fn ($mark) => $this->mark($project, $date, $mark, $user))
            ->values());
    }

    public function findForDay(Project $project, int $workerId, string $date): ?Attendance
    {
        // whereDate, because SQLite stores the date cast as "Y-m-d 00:00:00" and = would miss it.
        return Attendance::where('project_id', $project->id)
            ->where('worker_id', $workerId)
            ->whereDate('attendance_date', $date)
            ->lockForUpdate()
            ->first();
    }

    /** Insert the mark, or write over $existing and fill its client_uuid if it had none. */
    public function save(Project $project, string $date, array $mark, User $user, ?Attendance $existing = null): Attendance
    {
        $values = [
            'status' => $mark['status'],
            'ot_hours' => $mark['ot_hours'] ?? 0,
            'method' => $mark['method'] ?? 'manual',
            'marked_by' => $user->id,
            'client_updated_at' => $mark['client_updated_at'] ?? null,
        ];

        if ($existing) {
            $existing->update([...$values, 'client_uuid' => $existing->client_uuid ?? ($mark['client_uuid'] ?? null)]);

            return $existing;
        }

        return Idempotent::create(
            fn () => Attendance::create([
                ...$values,
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'worker_id' => $mark['worker_id'],
                'attendance_date' => $date,
                'client_uuid' => $mark['client_uuid'] ?? null,
            ]),
            fn () => $this->findForDay($project, (int) $mark['worker_id'], $date),
        );
    }

    private function mark(Project $project, string $date, array $mark, User $user): Attendance
    {
        $existing = $this->findForDay($project, (int) $mark['worker_id'], $date);
        $uuid = $mark['client_uuid'] ?? null;

        if ($existing && $uuid && $existing->client_uuid === $uuid) {
            return $existing;
        }

        return $this->save($project, $date, $mark, $user, $existing);
    }
}
