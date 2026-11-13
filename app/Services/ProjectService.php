<?php

namespace App\Services;

use App\Exceptions\PlanLimitReachedException;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectValuePhase;
use App\Models\User;
use App\Services\ProjectSetup\StarterBoq;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Money;
use App\Support\Shares;
use Brick\Math\BigDecimal;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    /** More phases than this is a list of BOQ lines, not a split of the value. */
    public const MAX_PHASES = 50;

    /** The largest figure decimal(15,2) holds. */
    private const LARGEST_VALUE = '9999999999999.99';

    /** The web form's rules, where the commercial terms are all required. */
    public function rules(User $user, ?Project $project = null): array
    {
        return [
            ...$this->setupRules($user),
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
            'advance_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'advance_recovery_pct' => ['required', 'numeric', 'between:0,100'],
            'measurement_tolerance_pct' => ['required', 'numeric', 'between:0,100'],
            'deduction_basis' => ['required', Rule::in(['gross', 'running'])],
        ];
    }

    /**
     * Size, time and budget of the project. All optional. The budget and its margin are money, so
     * they are accepted only from someone who may see the budget; for anyone else they are left
     * out of the rules and the saved values stay as they are.
     */
    public function setupRules(User $user): array
    {
        return [
            'plot_size' => ['nullable', 'string', 'max:60'],
            'built_up_area' => ['nullable', 'string', 'max:60'],
            'planned_days' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'deadline_date' => ['nullable', 'date'],
            'boq_mode' => ['sometimes', Rule::in(StarterBoq::MODES)],
            ...($user->can('money.overview') ? [
                'budget_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
                'budget_margin_pct' => ['sometimes', 'required', 'numeric', 'between:0,100'],
            ] : []),
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
            ...$this->phaseRules(),
        ];
    }

    /**
     * The contract value split into phases, each with a name and an amount. When phases are given
     * the contract value is their sum. An empty list takes the phases away and leaves the value as it is.
     */
    public function phaseRules(): array
    {
        return [
            'phases' => ['sometimes', 'array', 'max:'.self::MAX_PHASES],
            'phases.*' => ['array'],
            'phases.*.name' => ['required', 'string', 'max:120'],
            'phases.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
        ];
    }

    /**
     * The app's edit: any field the website's form has, each only when sent. Money is accepted only
     * from someone who may see it, so nobody changes a figure they cannot read; for anyone else the
     * money fields are left out of the rules and keep their saved values. The end date is checked
     * against the saved start in update(), as a request may carry only one of the two.
     */
    public function updateRules(User $user, Project $project): array
    {
        $money = Money::visible($user);
        $rules = [
            ...Arr::except($this->rules($user, $project), $money ? [] : ['contract_value', 'advance_amount']),
            'end_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(config('buildos.project_statuses'))],
            ...($money ? $this->phaseRules() : []),
        ];

        // Only the fields themselves are optional: a phase that is sent still needs its name and amount.
        foreach ($rules as $field => $rule) {
            if (! str_contains($field, '.') && ! in_array('sometimes', $rule, true)) {
                $rules[$field] = ['sometimes', ...$rule];
            }
        }

        return $rules;
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
        $phases = $this->cleanPhases($data['phases'] ?? null);
        unset($data['phases']);

        if ($phases) {
            $data['contract_value'] = $this->valueOf($phases, $data['contract_value'] ?? null);
        }

        return DB::transaction(function () use ($data, $user, $phases) {
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

            if ($phases) {
                $this->replacePhases($project, $phases, $user);
            }

            // Those defaults are not on the model until it is read back.
            return $project->refresh();
        });
    }

    /**
     * Change a project, from the website's form or the app. Phases, when sent, replace the saved
     * ones and set the contract value to their sum. While a project has phases its value changes
     * only through them: a different contract_value on its own is refused, so the two never part.
     */
    public function update(Project $project, array $data, User $user): Project
    {
        $phases = array_key_exists('phases', $data) ? $this->cleanPhases($data['phases'] ?? []) : null;
        unset($data['phases']);

        return DB::transaction(function () use ($project, $data, $phases, $user) {
            $project = Project::lockForUpdate()->findOrFail($project->id);

            if ($phases) {
                $data['contract_value'] = $this->valueOf($phases, $data['contract_value'] ?? null);
            } elseif (array_key_exists('contract_value', $data)) {
                if ($phases === null && $project->valuePhases()->exists() && ! Dec::of($data['contract_value'])->isEqualTo(Dec::of($project->contract_value))) {
                    throw ValidationException::withMessages([
                        'contract_value' => __('This value is the total of the project\'s phases. Change the phases to change it.'),
                    ]);
                }

                $data['contract_value'] = Dec::money($data['contract_value']);
            }

            $start = array_key_exists('start_date', $data) ? $data['start_date'] : $project->start_date?->toDateString();
            $end = array_key_exists('end_date', $data) ? $data['end_date'] : $project->end_date?->toDateString();

            if (filled($start) && filled($end) && Carbon::parse($end)->lt(Carbon::parse($start))) {
                throw ValidationException::withMessages(['end_date' => __('The end date cannot be before the start date.')]);
            }

            $project->update($data);

            if ($phases !== null) {
                $this->replacePhases($project, $phases, $user);
            }

            return $project->refresh();
        });
    }

    /**
     * Each phase's name and amount, and its share of the total in percent with two decimals.
     *
     * @return list<array{id:int,name:string,amount:string,share_pct:?string}>
     */
    public function phasesOf(Project $project): array
    {
        $phases = $project->valuePhases()->get()->values();
        $shares = Shares::of($phases->pluck('amount')->all());

        return $phases->map(fn (ProjectValuePhase $phase, int $index) => [
            'id' => $phase->id,
            'name' => $phase->name,
            'amount' => Dec::money($phase->amount),
            'share_pct' => $shares[$index],
        ])->all();
    }

    /** @return list<array{name:string,amount:string}>|null Trimmed names, amounts at money precision. */
    private function cleanPhases(?array $phases): ?array
    {
        if ($phases === null) {
            return null;
        }

        return array_values(array_map(fn (array $phase) => ['name' => trim((string) $phase['name']), 'amount' => Dec::money($phase['amount'])], $phases));
    }

    /** The sum of the phases, which a contract value sent beside them must equal. */
    private function valueOf(array $phases, mixed $contractValue): string
    {
        $total = array_reduce($phases, fn (BigDecimal $sum, array $phase) => $sum->plus(Dec::of($phase['amount'])), BigDecimal::zero());

        if ($total->isGreaterThan(self::LARGEST_VALUE)) {
            throw ValidationException::withMessages(['phases' => __('The phases add up to more than a project can hold.')]);
        }

        if (filled($contractValue) && ! Dec::of($contractValue)->isEqualTo($total)) {
            throw ValidationException::withMessages(['contract_value' => __('The contract value must equal the total of the phases.')]);
        }

        return Dec::money($total);
    }

    /** Puts the phases in place of the saved ones and audits the change, when there is one. */
    private function replacePhases(Project $project, array $phases, User $user): void
    {
        $before = $project->valuePhases()->get()->map(fn (ProjectValuePhase $phase) => ['name' => $phase->name, 'amount' => Dec::money($phase->amount)])->all();

        if ($before === $phases) {
            return;
        }

        $project->valuePhases()->delete();

        foreach ($phases as $sort => $phase) {
            ProjectValuePhase::create([...$phase, 'organization_id' => $project->organization_id, 'project_id' => $project->id, 'sort' => $sort]);
        }

        AuditLog::record('project.phases_changed', $project, $before === [] ? null : ['phases' => $before], $phases === [] ? null : ['phases' => $phases], $user);
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
