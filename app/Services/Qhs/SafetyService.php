<?php

namespace App\Services\Qhs;

use App\Exceptions\ImmutableRecordException;
use App\Models\Project;
use App\Models\SafetyIncident;
use App\Models\ToolboxTalk;
use App\Models\User;
use App\Services\FieldOps\CreatesOnce;
use App\Support\ClientTime;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Safety incidents and near misses, and the toolbox talks held on site. */
class SafetyService
{
    use CreatesOnce;

    private const INCIDENT_MOVES = [
        'open' => ['investigating', 'closed'],
        'investigating' => ['closed'],
        'closed' => [],
    ];

    public function incidentRules(Project $project): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(config('modules.qhs.incident_kinds')))],
            'severity' => ['required', Rule::in(array_keys(config('modules.qhs.incident_severities')))],
            'injury' => ['nullable', Rule::in(array_keys(config('modules.qhs.injuries')))],
            'lost_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'location' => ['nullable', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
            'persons' => ['nullable', 'string', 'max:2000'],
            'immediate_action' => ['nullable', 'string', 'max:3000'],
            'root_cause' => ['nullable', 'string', 'max:3000'],
            'corrective_action' => ['nullable', 'string', 'max:3000'],
            'site_id' => ['bail', 'nullable', 'integer', $this->siteOfProjectRule($project->id)],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** Only the investigation can change after the report is made. */
    public function updateRules(): array
    {
        return [
            'immediate_action' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'root_cause' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'corrective_action' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'persons' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(array_keys(config('modules.qhs.incident_statuses')))],
        ];
    }

    public function toolboxRules(Project $project): array
    {
        return [
            'talk_date' => ['required', 'date', 'before_or_equal:today'],
            'topic' => ['required', 'string', 'max:160'],
            'conducted_by' => ['required', 'string', 'max:80'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            'attendees' => ['nullable', 'string', 'max:5000'],
            'attendee_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'site_id' => ['bail', 'nullable', 'integer', $this->siteOfProjectRule($project->id)],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** Report an incident or near miss. A repeated client_uuid returns the original row. */
    public function report(Project $project, array $data, User $user): SafetyIncident
    {
        return $this->createOnce(SafetyIncident::class, $project->organization_id, $data['client_uuid'] ?? null, function () use ($project, $data, $user) {
            $this->lockProject($project);
            $seq = $this->nextSequence(SafetyIncident::class, 'project_id', $project->id);

            $nearMiss = $data['kind'] === 'near_miss';
            $injury = $nearMiss ? 'none' : ($data['injury'] ?? 'none');

            return SafetyIncident::create([
                ...Arr::only($data, ['kind', 'severity', 'location', 'description', 'persons', 'immediate_action', 'root_cause', 'corrective_action', 'site_id']),
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'seq' => $seq,
                'number' => sprintf('%s-%s-%03d', $nearMiss ? 'NM' : 'INC', $project->code, $seq),
                'injury' => $injury,
                'lost_days' => $injury === 'lost_time' ? (int) ($data['lost_days'] ?? 0) : 0,
                'occurred_at' => ClientTime::parse($data['occurred_at']),
                'status' => 'open',
                'reported_by' => $user->id,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);
        });
    }

    public function update(SafetyIncident $incident, array $data, User $user): SafetyIncident
    {
        if ($incident->isClosed()) {
            throw new ImmutableRecordException(__('A closed incident cannot be changed.'));
        }

        $changes = Arr::only($data, ['immediate_action', 'root_cause', 'corrective_action', 'persons']);
        $status = $data['status'] ?? $incident->status;

        if ($status !== $incident->status) {
            if (! in_array($status, self::INCIDENT_MOVES[$incident->status], true)) {
                throw ValidationException::withMessages(['status' => __('An incident cannot move from :from to :to.', [
                    'from' => __(config('modules.qhs.incident_statuses.'.$incident->status)),
                    'to' => __(config('modules.qhs.incident_statuses.'.$status)),
                ])]);
            }

            if ($status === 'closed') {
                $errors = [];

                if (blank($changes['root_cause'] ?? $incident->root_cause)) {
                    $errors['root_cause'] = __('Record the root cause before closing.');
                }

                if (blank($changes['corrective_action'] ?? $incident->corrective_action)) {
                    $errors['corrective_action'] = __('Record the corrective action before closing.');
                }

                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }

                $changes['closed_at'] = now();
                $changes['closed_by'] = $user->id;
            }

            $changes['status'] = $status;
        }

        $incident->update($changes);

        return $incident;
    }

    public function holdToolboxTalk(Project $project, array $data, User $user): ToolboxTalk
    {
        return $this->createOnce(ToolboxTalk::class, $project->organization_id, $data['client_uuid'] ?? null, function () use ($project, $data, $user) {
            $names = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($data['attendees'] ?? '')) ?: [])));

            return ToolboxTalk::create([
                ...Arr::only($data, ['talk_date', 'topic', 'conducted_by', 'duration_minutes', 'attendees', 'notes', 'site_id']),
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'attendee_count' => $names !== [] ? count($names) : (int) ($data['attendee_count'] ?? 0),
                'created_by' => $user->id,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);
        });
    }
}
