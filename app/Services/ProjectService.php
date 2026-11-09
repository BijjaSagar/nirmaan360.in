<?php

namespace App\Services;

use App\Exceptions\PlanLimitReachedException;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProjectService
{
    /** The web form's rules, where the commercial terms are all required. */
    public function rules(User $user, ?Project $project = null): array
    {
        return [
            'deduction_order' => ['required', Rule::in(array_keys(config('buildos.deduction_orders')))],
            'code' => [
                'required', 'string', 'max:32', 'alpha_dash',
                Rule::unique('projects', 'code')->where('organization_id', $user->organization_id)->ignore($project?->id),
            ],
            'name' => ['required', 'string', 'max:160'],
            'client_id' => ['nullable', org_exists('parties')],
            'location' => ['nullable', 'string', 'max:160'],
            'state' => ['nullable', 'string', 'max:60'],
            'contract_value' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'retention_pct' => ['required', 'numeric', 'between:0,100'],
            'tds_pct' => ['required', 'numeric', 'between:0,100'],
            'gst_pct' => ['required', 'numeric', 'between:0,100'],
            'advance_amount' => ['required', 'numeric', 'min:0'],
            'advance_recovery_pct' => ['required', 'numeric', 'between:0,100'],
            'measurement_tolerance_pct' => ['required', 'numeric', 'between:0,100'],
            'deduction_basis' => ['required', Rule::in(['gross', 'running'])],
        ];
    }

    /** The app's setup form: a name is enough and everything else has a default. */
    public function quickRules(User $user): array
    {
        $rules = $this->rules($user);

        return [
            ...Arr::only($rules, ['name', 'location', 'start_date', 'end_date']),
            'contract_value' => $this->optional($rules['contract_value']),
            'code' => $this->optional($rules['code']),
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function assertWithinPlan(Organization $organization): void
    {
        if (! $organization->canAddProject()) {
            throw new PlanLimitReachedException;
        }
    }

    /**
     * Create a project. A repeated client_uuid returns the original row, even once
     * the plan is full. The organisation row lock serialises creates, so neither
     * the plan limit nor a generated code can be raced.
     */
    public function create(array $data, User $user): Project
    {
        return DB::transaction(function () use ($data, $user) {
            $organization = Organization::lockForUpdate()->findOrFail($user->organization_id);
            $uuid = $data['client_uuid'] ?? null;

            if ($uuid && ($existing = $this->findByClientUuid($organization->id, $uuid))) {
                return $existing;
            }

            $this->assertWithinPlan($organization);

            // Blank optional fields are left out so the schema defaults apply.
            $project = Project::create([
                ...array_filter($data, fn ($value) => $value !== null),
                ...(isset($data['contract_value']) ? ['contract_value' => Dec::money($data['contract_value'])] : []),
                'organization_id' => $organization->id,
                'code' => filled($data['code'] ?? null) ? $data['code'] : $this->generateCode($organization->id, $data['name']),
                'status' => 'active',
                'created_by' => $user->id,
            ]);

            // Someone limited to chosen projects keeps access to the one they just made.
            if ($user->isProjectLimited()) {
                $user->assignedProjects()->attach($project->id, ['organization_id' => $organization->id]);
            }

            // Those defaults are not on the model until it is read back.
            return $project->refresh();
        });
    }

    /** Trashed rows count: the unique key still holds their client_uuid. */
    public function findByClientUuid(int $organizationId, string $uuid): ?Project
    {
        return ClientUuidRepeat::reachable(Project::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }

    /** Up to four initials of the name, numbered when already taken. Trashed projects still hold their codes. */
    private function generateCode(int $organizationId, string $name): string
    {
        preg_match_all("/(?<![\p{L}\p{M}\p{N}'’])[\p{L}\p{N}]/u", $name, $matches);

        $initials = Str::upper(implode('', array_slice($matches[0], 0, 4))) ?: 'P';
        $taken = Project::withoutGlobalScopes()->where('organization_id', $organizationId)->where('code', 'like', $initials.'%')->pluck('code')->all();

        $code = $initials;

        for ($number = 2; in_array($code, $taken, true); $number++) {
            $code = $initials.$number;
        }

        return $code;
    }

    private function optional(array $rules): array
    {
        return ['nullable', ...array_filter($rules, fn ($rule) => $rule !== 'required')];
    }
}
