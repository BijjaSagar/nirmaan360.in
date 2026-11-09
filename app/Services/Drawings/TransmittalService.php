<?php

namespace App\Services\Drawings;

use App\Models\AuditLog;
use App\Models\DrawingTransmittal;
use App\Models\DrawingVersion;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransmittalService
{
    /** @return array<string, list<mixed>> */
    public function rules(Project $project): array
    {
        return [
            'party_id' => ['bail', 'nullable', 'integer', org_exists('parties')],
            'recipient_name' => ['required_without:party_id', 'nullable', 'string', 'max:160'],
            'recipient_company' => ['nullable', 'string', 'max:160'],
            'subject' => ['required', 'string', 'max:200'],
            'purpose' => ['required', Rule::in(config('modules.drawings.transmittal_purposes'))],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Only the current revision of a drawing can be issued.
            'versions' => ['required', 'array', 'min:1', 'max:200'],
            'versions.*' => ['bail', 'integer', 'distinct', Rule::exists('drawing_versions', 'id')->where('project_id', $project->id)->where('is_current', true)],
            'copies' => ['nullable', 'array'],
            'copies.*' => ['nullable', 'integer', 'min:1', 'max:999'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** Issue a numbered transmittal. A repeated client_uuid returns the original. */
    public function issue(Project $project, array $data, User $user): DrawingTransmittal
    {
        return DB::transaction(function () use ($project, $data, $user) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $uuid = $data['client_uuid'] ?? null;

            if ($uuid && ($existing = $this->findByClientUuid($project->organization_id, $uuid))) {
                return $existing;
            }

            $versionIds = array_values(array_map('intval', $data['versions']));

            if (DrawingVersion::whereIn('id', $versionIds)->whereHas('drawing')->count() !== count($versionIds)) {
                throw ValidationException::withMessages(['versions' => __('One of the drawings is no longer in the register.')]);
            }

            $party = ! empty($data['party_id']) ? Party::findOrFail($data['party_id']) : null;

            $transmittal = DrawingTransmittal::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'party_id' => $party?->id,
                'number' => $this->nextNumber($project),
                'recipient_name' => filled($data['recipient_name'] ?? null) ? $data['recipient_name'] : $party->name,
                'recipient_company' => $data['recipient_company'] ?? null,
                'subject' => $data['subject'],
                'purpose' => $data['purpose'],
                'notes' => $data['notes'] ?? null,
                'status' => 'issued',
                'issued_at' => now(),
                'issued_by' => $user->id,
                'client_uuid' => $uuid,
            ]);

            foreach ($versionIds as $versionId) {
                $transmittal->items()->create([
                    'organization_id' => $project->organization_id,
                    'drawing_version_id' => $versionId,
                    'copies' => (int) ($data['copies'][$versionId] ?? 1),
                ]);
            }

            AuditLog::record('items_issued', $transmittal, null, ['drawing_version_ids' => $versionIds]);

            return $transmittal;
        });
    }

    public function acknowledge(DrawingTransmittal $transmittal, array $data): DrawingTransmittal
    {
        if ($transmittal->status !== 'issued') {
            throw ValidationException::withMessages(['status' => __('Only an issued transmittal can be acknowledged.')]);
        }

        $transmittal->update([
            'status' => 'acknowledged',
            'acknowledged_on' => $data['acknowledged_on'],
            'acknowledged_by' => $data['acknowledged_by'],
        ]);

        return $transmittal;
    }

    public function cancel(DrawingTransmittal $transmittal, string $reason): DrawingTransmittal
    {
        if ($transmittal->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => __('This transmittal is already cancelled.')]);
        }

        $transmittal->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);

        return $transmittal;
    }

    /** Runs under the project lock. Transmittals are never deleted, so a count never reuses a number. */
    private function nextNumber(Project $project): string
    {
        $next = DrawingTransmittal::where('project_id', $project->id)->count() + 1;

        return 'TR-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function findByClientUuid(int $organizationId, string $uuid): ?DrawingTransmittal
    {
        return DrawingTransmittal::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }
}
