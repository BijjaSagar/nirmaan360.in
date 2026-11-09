<?php

namespace App\Services;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Support\Idempotent;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class IssueService
{
    /** One rule set for the web form, the REST API and offline sync. */
    public function rules(Project $project, User $user): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(config('buildos.issue_priorities'))],
            'location_note' => ['nullable', 'string', 'max:160'],
            'assigned_to' => ['bail', 'nullable', 'integer', $this->sameOrganizationUser($user)],
            'due_date' => ['nullable', 'date'],
            'site_id' => ['bail', 'nullable', 'integer', Rule::exists('sites', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function statusRules(User $user): array
    {
        return [
            'status' => ['required', Rule::in(config('buildos.issue_statuses'))],
            'assigned_to' => ['bail', 'nullable', 'integer', $this->sameOrganizationUser($user)],
        ];
    }

    /** Raise an issue. A repeated client_uuid returns the original row. */
    public function create(Project $project, array $data, User $user): Issue
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findByClientUuid($project->organization_id, $uuid))) {
            return $existing;
        }

        return Idempotent::create(
            fn () => Issue::create([
                ...$data,
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'status' => 'open',
                'raised_by' => $user->id,
            ]),
            fn () => $this->findByClientUuid($project->organization_id, (string) $uuid),
        );
    }

    /** Without a client stamp, this edit's updated_at is what later sync writes compare against. */
    public function update(Issue $issue, array $data): Issue
    {
        $changes = ['client_updated_at' => null, ...$data];

        if (array_key_exists('status', $data)) {
            $changes['resolved_at'] = in_array($data['status'], ['resolved', 'closed'], true)
                ? ($issue->resolved_at ?? now())
                : null;
        }

        $issue->update($changes);

        return $issue;
    }

    /** Trashed rows count: the unique key still holds their client_uuid. */
    private function findByClientUuid(int $organizationId, string $uuid): ?Issue
    {
        return Issue::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }

    private function sameOrganizationUser(User $user): Exists
    {
        return Rule::exists('users', 'id')->where('organization_id', $user->organization_id);
    }
}
