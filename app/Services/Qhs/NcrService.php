<?php

namespace App\Services\Qhs;

use App\Exceptions\ImmutableRecordException;
use App\Models\Ncr;
use App\Models\Project;
use App\Models\User;
use App\Services\FieldOps\CreatesOnce;
use App\Support\Dec;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Non-conformance reports. Resolving one needs the root cause and the corrective action,
 * and closing it is the QA sign-off after which it is locked.
 */
class NcrService
{
    use CreatesOnce;

    private const FIELDS = [
        'title', 'description', 'location', 'site_id', 'category', 'severity', 'raised_on', 'assigned_to', 'due_date',
        'root_cause_category', 'root_cause', 'corrective_action', 'preventive_action', 'rework_cost',
    ];

    /** Where an NCR may go from each status. */
    private const MOVES = [
        'open' => ['in_progress', 'resolved'],
        'in_progress' => ['resolved'],
        'resolved' => ['in_progress', 'closed'],
        'closed' => [],
    ];

    public function rules(Project $project, User $user): array
    {
        return [
            ...$this->fields($project, $user),
            'title' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(array_keys(config('modules.qhs.ncr_categories')))],
            'severity' => ['required', Rule::in(array_keys(config('modules.qhs.ncr_severities')))],
            'due_date' => ['nullable', 'date', 'after_or_equal:raised_on'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** Every field is optional, so a button can send only a new status. */
    public function updateRules(Project $project, User $user): array
    {
        $rules = collect($this->fields($project, $user))
            ->map(fn (array $rule) => ['sometimes', ...$rule])
            ->all();

        return [
            ...$rules,
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'category' => ['sometimes', Rule::in(array_keys(config('modules.qhs.ncr_categories')))],
            'severity' => ['sometimes', Rule::in(array_keys(config('modules.qhs.ncr_severities')))],
            'status' => ['sometimes', Rule::in(array_keys(config('modules.qhs.ncr_statuses')))],
        ];
    }

    /** Raise an NCR. A repeated client_uuid returns the original row. */
    public function create(Project $project, array $data, User $user): Ncr
    {
        return $this->createOnce(Ncr::class, $project->organization_id, $data['client_uuid'] ?? null, function () use ($project, $data, $user) {
            $this->lockProject($project);
            $seq = $this->nextSequence(Ncr::class, 'project_id', $project->id);

            return Ncr::create([
                ...$this->attributes($data),
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'seq' => $seq,
                'number' => sprintf('NCR-%s-%03d', $project->code, $seq),
                'raised_on' => $data['raised_on'] ?? today()->toDateString(),
                'rework_cost' => Dec::money($data['rework_cost'] ?? 0),
                'status' => 'open',
                'raised_by' => $user->id,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);
        });
    }

    public function update(Ncr $ncr, array $data, User $user): Ncr
    {
        if ($ncr->isClosed()) {
            throw new ImmutableRecordException(__('A closed NCR cannot be changed. Raise a new NCR instead.'));
        }

        $changes = $this->attributes($data);

        if (array_key_exists('rework_cost', $data)) {
            $changes['rework_cost'] = Dec::money($data['rework_cost'] ?? 0);
        }

        $status = $data['status'] ?? $ncr->status;

        if ($status !== $ncr->status) {
            if (! in_array($status, self::MOVES[$ncr->status], true)) {
                throw ValidationException::withMessages(['status' => __('An NCR cannot move from :from to :to.', [
                    'from' => __(config('modules.qhs.ncr_statuses.'.$ncr->status)),
                    'to' => __(config('modules.qhs.ncr_statuses.'.$status)),
                ])]);
            }

            if (in_array($status, ['resolved', 'closed'], true)) {
                $this->assertInvestigated($changes['root_cause'] ?? $ncr->root_cause, $changes['corrective_action'] ?? $ncr->corrective_action);
            }

            $changes['status'] = $status;

            if ($status === 'resolved') {
                $changes['resolved_at'] = $ncr->resolved_at ?? now();
            } elseif ($status === 'in_progress') {
                $changes['resolved_at'] = null;
            } elseif ($status === 'closed') {
                $changes['closed_at'] = now();
                $changes['closed_by'] = $user->id;
            }
        }

        $ncr->update($changes);

        return $ncr;
    }

    /** Rework cost across a project's NCRs, as an exact total. */
    public function reworkTotal(Project $project): string
    {
        return Dec::money(Ncr::where('project_id', $project->id)->sum('rework_cost'));
    }

    private function fields(Project $project, User $user): array
    {
        return [
            'title' => ['string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:160'],
            'site_id' => ['bail', 'nullable', 'integer', $this->siteOfProjectRule($project->id)],
            'category' => [Rule::in(array_keys(config('modules.qhs.ncr_categories')))],
            'severity' => [Rule::in(array_keys(config('modules.qhs.ncr_severities')))],
            'raised_on' => ['nullable', 'date', 'before_or_equal:today'],
            'assigned_to' => ['bail', 'nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $user->organization_id)],
            'due_date' => ['nullable', 'date'],
            'root_cause_category' => ['nullable', Rule::in(array_keys(config('modules.qhs.root_causes')))],
            'root_cause' => ['nullable', 'string', 'max:3000'],
            'corrective_action' => ['nullable', 'string', 'max:3000'],
            'preventive_action' => ['nullable', 'string', 'max:3000'],
            'rework_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
        ];
    }

    private function attributes(array $data): array
    {
        return Arr::except(Arr::only($data, self::FIELDS), ['rework_cost']);
    }

    private function assertInvestigated(?string $rootCause, ?string $correctiveAction): void
    {
        $errors = [];

        if (blank($rootCause)) {
            $errors['root_cause'] = __('Record the root cause before resolving or closing.');
        }

        if (blank($correctiveAction)) {
            $errors['corrective_action'] = __('Record the corrective action before resolving or closing.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
