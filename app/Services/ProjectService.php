<?php

namespace App\Services;

use App\Exceptions\PlanLimitReachedException;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectAdvance;
use App\Models\ProjectValuePhase;
use App\Models\User;
use App\Services\ProjectSetup\StarterBoq;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Money;
use App\Support\Shares;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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

    /** The most mobilisation advance lines one project takes. */
    public const MAX_ADVANCES = 20;

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
            // One figure, or the sum of the advance lines when they are sent, in which case this is ignored.
            'advance_amount' => ['required_without:advances', 'numeric', 'min:0', 'max:9999999999999'],
            // Ignored while the percentage is worked out from the advance and the contract value.
            'advance_recovery_pct' => ['required', 'numeric', 'between:0,100'],
            'advance_recovery_auto' => ['boolean'],
            ...$this->advanceRules(),
            'measurement_tolerance_pct' => ['required', 'numeric', 'between:0,100'],
            'deduction_basis' => ['required', Rule::in(['gross', 'running'])],
        ];
    }

    /**
     * The mobilisation advance as lines, each with an amount, the date it came in and a note. A line
     * with no amount is left out, with whatever else it holds. When lines are sent the advance is their sum.
     */
    public function advanceRules(): array
    {
        return [
            'advances' => ['sometimes', 'array', 'max:'.self::MAX_ADVANCES],
            'advances.*' => ['array'],
            'advances.*.amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999999'],
            'advances.*.received_on' => ['exclude_without:advances.*.amount', 'nullable', 'date'],
            'advances.*.note' => ['exclude_without:advances.*.amount', 'nullable', 'string', 'max:255'],
        ];
    }

    /** Messages for a line of the advance, which say what to fix on that line. */
    public function advanceMessages(): array
    {
        return [
            'advances.max' => __('A project can have at most :max advances.'),
            'advances.*.amount.numeric' => __('Enter the amount in rupees.'),
            'advances.*.amount.gt' => __('An advance must be more than zero.'),
            'advances.*.amount.max' => __('This amount is more than a project can hold.'),
            'advances.*.received_on.date' => __('Enter the date the advance came in.'),
            'advances.*.note.max' => __('Keep the note to :max characters.'),
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
        $moneyFields = ['contract_value', 'advance_amount', 'advance_recovery_auto', ...array_keys($this->advanceRules())];
        $rules = [
            ...Arr::except($this->rules($user, $project), $money ? [] : $moneyFields),
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
        $advances = $this->cleanAdvances($data['advances'] ?? null);
        unset($data['phases'], $data['advances']);

        if ($phases) {
            $data['contract_value'] = $this->valueOf($phases, $data['contract_value'] ?? null);
        }

        $data = $this->withAdvanceTerms(null, $data, $advances);

        return DB::transaction(function () use ($data, $user, $phases, $advances) {
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

            if ($advances) {
                $this->replaceAdvances($project, $advances, $user);
            }

            // Those defaults are not on the model until it is read back.
            return $project->refresh();
        });
    }

    /**
     * Change a project, from the website's form or the app. Phases, when sent, replace the saved
     * ones and set the contract value to their sum. While a project has phases its value changes
     * only through them: a different contract_value on its own is refused, so the two never part.
     * Advance lines work the same way for advance_amount, and a worked-out recovery percentage
     * follows both.
     */
    public function update(Project $project, array $data, User $user): Project
    {
        $phases = array_key_exists('phases', $data) ? $this->cleanPhases($data['phases'] ?? []) : null;
        $advances = array_key_exists('advances', $data) ? $this->cleanAdvances($data['advances'] ?? []) : null;
        unset($data['phases'], $data['advances']);

        return DB::transaction(function () use ($project, $data, $phases, $advances, $user) {
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

            $data = $this->withAdvanceTerms($project, $data, $advances);

            $start = array_key_exists('start_date', $data) ? $data['start_date'] : $project->start_date?->toDateString();
            $end = array_key_exists('end_date', $data) ? $data['end_date'] : $project->end_date?->toDateString();

            if (filled($start) && filled($end) && Carbon::parse($end)->lt(Carbon::parse($start))) {
                throw ValidationException::withMessages(['end_date' => __('The end date cannot be before the start date.')]);
            }

            $project->update($data);

            if ($phases !== null) {
                $this->replacePhases($project, $phases, $user);
            }

            if ($advances !== null) {
                $this->replaceAdvances($project, $advances, $user);
            }

            return $project->refresh();
        });
    }

    /**
     * The share of the contract value the advance is, in percent, which is how much of each bill is
     * held back until the advance is recovered. Rounded half up to two places, never above 100, and
     * 0 when there is no advance or no contract value. The project form works it out the same way.
     */
    public static function recoveryPct(mixed $advance, mixed $contractValue): string
    {
        $advance = Dec::of($advance);
        $value = Dec::of($contractValue);

        if (! $advance->isPositive() || ! $value->isPositive()) {
            return '0.00';
        }

        $pct = $advance->multipliedBy(100)->dividedBy($value, 2, RoundingMode::HalfUp);

        return Dec::money($pct->isGreaterThan(100) ? BigDecimal::of(100) : $pct);
    }

    /**
     * The saved advance lines, in order.
     *
     * @return list<array{id:int,amount:string,received_on:?string,note:?string}>
     */
    public function advancesOf(Project $project): array
    {
        return $project->advances()->get()->map(fn (ProjectAdvance $advance) => [
            'id' => $advance->id,
            'amount' => Dec::money($advance->amount),
            'received_on' => $advance->received_on?->toDateString(),
            'note' => $advance->note,
        ])->values()->all();
    }

    /**
     * The advance as lines for the form and the project page. A project saved before advances came
     * in lines has one figure: that figure is its first line, with no date, so a save keeps it.
     *
     * @return list<array{id:?int,amount:string,received_on:?string,note:?string}>
     */
    public function advanceLines(Project $project): array
    {
        $lines = $project->exists ? $this->advancesOf($project) : [];

        if ($lines === [] && $project->exists && Dec::of($project->advance_amount)->isPositive()) {
            return [['id' => null, 'amount' => Dec::money($project->advance_amount), 'received_on' => null, 'note' => null]];
        }

        return $lines;
    }

    /**
     * Sets the advance and its recovery percentage in what is saved. Lines that are sent make the
     * advance their sum, whatever advance_amount came with them. A different advance_amount on its
     * own is refused while the project has lines, so the two never part. While the percentage is
     * worked out, it is worked out again here from the advance and contract value being saved, and
     * a percentage that was sent is ignored.
     */
    private function withAdvanceTerms(?Project $project, array $data, ?array $advances): array
    {
        if ($advances !== null) {
            $data['advance_amount'] = $this->advanceTotal($advances);
        } elseif (array_key_exists('advance_amount', $data)) {
            if ($project?->advances()->exists() && ! Dec::of($data['advance_amount'])->isEqualTo(Dec::of($project->advance_amount))) {
                throw ValidationException::withMessages([
                    'advance_amount' => __('This is the total of the project\'s advances. Change the advances to change it.'),
                ]);
            }

            $data['advance_amount'] = Dec::money($data['advance_amount']);
        }

        if (array_key_exists('advance_recovery_auto', $data)) {
            $data['advance_recovery_auto'] = (bool) $data['advance_recovery_auto'];
        }

        if ($data['advance_recovery_auto'] ?? (bool) $project?->advance_recovery_auto) {
            $data['advance_recovery_pct'] = self::recoveryPct(
                $data['advance_amount'] ?? $project?->advance_amount,
                $data['contract_value'] ?? $project?->contract_value,
            );
        }

        return $data;
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

    /**
     * The advance lines that have an amount, in the order sent, with amounts at money precision, the
     * date as Y-m-d and the note trimmed. An amount that rounds to nothing is an error on its line.
     *
     * @return list<array{amount:string,received_on:?string,note:?string}>|null
     */
    private function cleanAdvances(?array $lines): ?array
    {
        if ($lines === null) {
            return null;
        }

        $clean = [];

        foreach ($lines as $index => $line) {
            if (! is_array($line) || blank($line['amount'] ?? null)) {
                continue;
            }

            $amount = Dec::money($line['amount']);

            if (! Dec::of($amount)->isPositive()) {
                throw ValidationException::withMessages(["advances.{$index}.amount" => __('An advance must be more than zero.')]);
            }

            $note = trim((string) ($line['note'] ?? ''));

            $clean[] = [
                'amount' => $amount,
                'received_on' => filled($line['received_on'] ?? null) ? Carbon::parse($line['received_on'])->toDateString() : null,
                'note' => $note === '' ? null : $note,
            ];
        }

        return $clean;
    }

    /** The sum of the advance lines, which becomes the project's advance_amount. */
    private function advanceTotal(array $advances): string
    {
        $total = array_reduce($advances, fn (BigDecimal $sum, array $line) => $sum->plus(Dec::of($line['amount'])), BigDecimal::zero());

        if ($total->isGreaterThan(self::LARGEST_VALUE)) {
            throw ValidationException::withMessages(['advances' => __('The advances add up to more than a project can hold.')]);
        }

        return Dec::money($total);
    }

    /**
     * Puts the lines in place of the saved ones and audits the change, when there is one. The audit
     * keeps each line's amount and date and whether it has a note, never the note itself, which is
     * free text and may name a person.
     */
    private function replaceAdvances(Project $project, array $lines, User $user): void
    {
        $before = array_map(fn (array $line) => Arr::except($line, 'id'), $this->advancesOf($project));

        if ($before === $lines) {
            return;
        }

        $project->advances()->delete();

        foreach ($lines as $sort => $line) {
            ProjectAdvance::create([...$line, 'organization_id' => $project->organization_id, 'project_id' => $project->id, 'sort' => $sort]);
        }

        $audit = fn (array $lines) => $lines === [] ? null : ['advances' => array_map(fn (array $line) => [
            'amount' => $line['amount'],
            'received_on' => $line['received_on'],
            'has_note' => $line['note'] !== null,
        ], $lines)];

        AuditLog::record('project.advances_changed', $project, $audit($before), $audit($lines), $user);
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
