<?php

namespace App\Services\Workforce;

use App\Models\Attendance;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Support\Idempotent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Marks a worker present for today when a site user scans the worker's QR card. */
class QrAttendanceService
{
    public function __construct(private WorkerCardService $cards) {}

    /**
     * A scan marks present and never changes a mark someone already made, except absent.
     * Scanning twice, or repeating a client_uuid, returns the mark with already_marked set.
     *
     * @return array{worker: Worker, attendance: Attendance, already_marked: bool}
     */
    public function scan(Project $project, string $code, User $by, ?string $clientUuid = null): array
    {
        $worker = $this->cards->resolve($code, $project->organization_id);

        if (! $worker->is_active) {
            throw ValidationException::withMessages(['code' => __(':name is not an active worker.', ['name' => $worker->name])]);
        }

        return DB::transaction(function () use ($project, $worker, $by, $clientUuid) {
            $today = now()->toDateString();

            if ($clientUuid && ($repeat = $this->attendance($project->organization_id)->where('client_uuid', $clientUuid)->first())) {
                return ['worker' => $worker, 'attendance' => $repeat, 'already_marked' => true];
            }

            $elsewhere = $this->attendance($project->organization_id)
                ->where('worker_id', $worker->id)
                ->where('project_id', '!=', $project->id)
                ->whereDate('attendance_date', $today)
                ->where('status', '!=', 'absent')
                ->first();

            if ($elsewhere) {
                $other = Project::withoutGlobalScopes()->find($elsewhere->project_id);

                throw ValidationException::withMessages(['code' => __(':name is already marked present today at :project.', ['name' => $worker->name, 'project' => $other?->name ?? __('another project')])]);
            }

            $existing = $this->attendance($project->organization_id)
                ->where('project_id', $project->id)
                ->where('worker_id', $worker->id)
                ->whereDate('attendance_date', $today)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status !== 'absent') {
                return ['worker' => $worker, 'attendance' => $existing, 'already_marked' => true];
            }

            if ($existing) {
                $existing->update(['status' => 'present', 'ot_hours' => 0, 'method' => 'qr', 'marked_by' => $by->id, 'client_uuid' => $existing->client_uuid ?? $clientUuid]);

                return ['worker' => $worker, 'attendance' => $existing, 'already_marked' => false];
            }

            $attendance = Idempotent::create(
                fn () => Attendance::create([
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'worker_id' => $worker->id,
                    'attendance_date' => $today,
                    'status' => 'present',
                    'ot_hours' => 0,
                    'method' => 'qr',
                    'marked_by' => $by->id,
                    'client_uuid' => $clientUuid,
                ]),
                fn () => $this->attendance($project->organization_id)->where('client_uuid', (string) $clientUuid)->first(),
            );

            return ['worker' => $worker, 'attendance' => $attendance, 'already_marked' => ! $attendance->wasRecentlyCreated];
        });
    }

    private function attendance(int $organizationId): Builder
    {
        return Attendance::withoutGlobalScopes()->where('organization_id', $organizationId);
    }
}
