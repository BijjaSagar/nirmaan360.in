<?php

namespace App\Services\ContractTerms;

use App\Models\ContractTerm;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\ClientUuidRepeat;
use App\Support\Idempotent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The dated terms of a contract, as people typed them from the agreement.
 *
 * The app knows no percentage, amount, period or clause of its own. Nothing is seeded, no field is
 * filled in for the user, and a term counts only after a person has ticked that they read it in the
 * agreement. Changing a confirmed term takes the confirmation away again, so what counts is always
 * exactly what somebody confirmed.
 */
class ContractTerms
{
    public function __construct(private TermText $text) {}

    /**
     * The confirmed terms of one contract that are in force on a day: the client contract when
     * $workOrder is null, otherwise that subcontract. Optionally one kind only.
     *
     * Filters by company and project itself, so it gives the same answer from a job or the scheduler,
     * where nobody is signed in. Signed in, the usual company and project limits apply as well.
     *
     * @return Collection<int, ContractTerm>
     */
    public function effective(Project $project, ?WorkOrder $workOrder, CarbonInterface $on, ?string $kind = null): Collection
    {
        if ($workOrder !== null && (int) $workOrder->project_id !== (int) $project->id) {
            throw new InvalidArgumentException('The work order belongs to another project.');
        }

        $day = $on->toDateString();

        return $this->ofContract($project->organization_id, $project->id, $workOrder?->id)
            ->whereNotNull('confirmed_at')
            ->whereDate('effective_from', '<=', $day)
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->when($kind !== null, fn (Builder $query) => $query->where('kind', $kind))
            ->orderBy('kind')->orderBy('code')->orderBy('effective_from')->orderBy('id')
            ->get();
    }

    /**
     * Every confirmed term of one contract, whatever its dates, in the order effective() gives.
     * For a caller that needs the terms in force on many days: one query, then
     * inForce() for each day gives what effective() would give for it.
     *
     * @param  list<string>|null  $kinds  Only these kinds, or every kind.
     * @return Collection<int, ContractTerm>
     */
    public function confirmed(Project $project, ?WorkOrder $workOrder, ?array $kinds = null): Collection
    {
        if ($workOrder !== null && (int) $workOrder->project_id !== (int) $project->id) {
            throw new InvalidArgumentException('The work order belongs to another project.');
        }

        return $this->ofContract($project->organization_id, $project->id, $workOrder?->id)
            ->whereNotNull('confirmed_at')
            ->when($kinds !== null, fn (Builder $query) => $query->whereIn('kind', $kinds))
            ->orderBy('kind')->orderBy('code')->orderBy('effective_from')->orderBy('id')
            ->get();
    }

    /**
     * The terms of a list read with confirmed() that are in force on a day, in the same order.
     *
     * @param  Collection<int, ContractTerm>  $terms
     * @return Collection<int, ContractTerm>
     */
    public static function inForce(Collection $terms, CarbonInterface $on): Collection
    {
        return $terms->filter(fn (ContractTerm $term) => $term->covers($on))->values();
    }

    /**
     * What a term must hold, which depends on its kind, its code and how a deduction is worked out.
     * A field that does not belong to the kind is left out of the validated data and saved empty.
     *
     * @param  array<string, mixed>  $input  The request, read only to pick the rules.
     * @param  ContractTerm|null  $term  The term being changed, or null when one is being added.
     */
    public function rules(Project $project, array $input, ?ContractTerm $term = null): array
    {
        // Only a known kind is ever used to look anything up.
        $kind = in_array($input['kind'] ?? null, ContractTerm::KINDS, true) ? $input['kind'] : '';
        $code = is_string($input['code'] ?? null) ? $input['code'] : '';
        $method = is_string($input['method'] ?? null) ? $input['method'] : '';
        $codes = $kind === '' ? [] : array_keys(config("modules.contract_terms.codes.{$kind}"));
        $starts = array_keys(config('modules.contract_terms.counted_from')[$kind] ?? []);

        $deduction = $kind === 'deduction';
        $percent = $deduction && $method === 'percent';
        $counted = $kind === 'notice' || $kind === 'payment_period';
        $dated = $kind === 'reference' && $code !== 'agreement_no';

        // A term stays where it is even after its work order was removed; any other work order must be a live one of this project.
        $staysPut = $term?->work_order_id !== null && (string) ($input['work_order_id'] ?? '') === (string) $term->work_order_id;

        $money = ['numeric', 'gt:0', 'max:9999999999999', 'decimal:0,2'];
        $date = ['date_format:Y-m-d'];
        $leftOut = ['exclude'];

        return [
            // Sent by the add form, so pressing save twice adds one term.
            'client_uuid' => $term === null ? ['required', 'uuid'] : $leftOut,
            'work_order_id' => $staysPut ? ['required', 'integer'] : ['nullable', 'integer', Rule::exists('work_orders', 'id')
                ->where('organization_id', $project->organization_id)->where('project_id', $project->id)->whereNull('deleted_at')],
            'kind' => ['required', 'string', Rule::in(ContractTerm::KINDS)],
            'code' => ['required', 'string', Rule::in($codes)],
            'label' => [$code === 'other' ? 'required' : 'nullable', 'string', 'max:160'],
            'effective_from' => ['required', ...$date],
            'effective_to' => ['nullable', ...$date, 'after_or_equal:effective_from'],
            'source_ref' => ['required', 'string', 'max:255'],

            // A deduction the bill already works out is a percentage there, so its term is one too.
            'method' => $deduction ? ['required', 'string', Rule::in(isset(ContractTerm::BILL_PERCENTAGES[$code]) ? ['percent'] : ContractTerm::METHODS)] : $leftOut,
            'pct' => match (true) {
                $percent => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
                $kind === 'excess_quantity' => ['required', 'numeric', 'min:0', 'max:999', 'decimal:0,4'],
                default => $leftOut,
            },
            'base' => $percent ? ['required', 'string', Rule::in(ContractTerm::BASES)] : $leftOut,
            'cap_amount' => $percent ? ['nullable', ...$money] : $leftOut,
            'amount' => $deduction && $method === 'fixed_per_bill' ? ['required', ...$money] : $leftOut,
            'nature' => $deduction ? ['nullable', 'string', Rule::in(ContractTerm::NATURES)] : $leftOut,

            'days' => $counted ? ['required', 'integer', 'min:1', 'max:3650'] : $leftOut,
            'counted_from' => $counted ? ['required', 'string', Rule::in($starts)] : $leftOut,
            // Two notice periods that go together carry the same group name and the same choice.
            'combine' => $kind === 'notice' ? ['nullable', 'required_with:combine_group', 'string', Rule::in(ContractTerm::COMBINE)] : $leftOut,
            'combine_group' => $kind === 'notice' ? ['nullable', 'required_with:combine', 'string', 'max:20'] : $leftOut,

            'value_text' => $kind === 'reference' && ! $dated ? ['required', 'string', 'max:255'] : $leftOut,
            'value_date' => $dated ? ['required', ...$date] : $leftOut,
        ];
    }

    /**
     * Add a term, not yet confirmed. A repeated client_uuid returns the original.
     *
     * @param  array<string, mixed>  $data  Validated with rules().
     */
    public function create(Project $project, array $data, User $by): ContractTerm
    {
        $uuid = $data['client_uuid'] ?? null;
        $find = fn () => $uuid ? ClientUuidRepeat::reachable(ContractTerm::withoutGlobalScopes()->where('organization_id', $project->organization_id)->where('client_uuid', $uuid)->first()) : null;

        if ($existing = $find()) {
            return $this->sameProject($existing, $project);
        }

        return $this->sameProject(Idempotent::create(fn () => ContractTerm::create([
            ...$this->columns($data),
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'entered_by' => $by->id,
            'client_uuid' => $uuid,
        ]), $find), $project);
    }

    /**
     * Change a term. Any change takes its confirmation away, so it has to be read against the
     * agreement and confirmed again before it counts. The form sends the whole term, so a field
     * that is left out is saved empty.
     *
     * @param  array<string, mixed>  $data  Validated with rules().
     */
    public function update(ContractTerm $term, array $data): ContractTerm
    {
        return $this->oneAtATime($term, function (ContractTerm $term) use ($data) {
            $term->fill($this->columns($data));

            if ($term->isDirty()) {
                $term->fill(['confirmed_by' => null, 'confirmed_at' => null])->save();
            }

            return $term;
        });
    }

    /** Remove a term. The row is kept, marked deleted, and the audit log says who removed it. */
    public function delete(ContractTerm $term): void
    {
        $term->delete();
    }

    /**
     * Record that this person has read the term in the agreement. From then on it counts.
     *
     * $seen is the fingerprint of the term as the person had it in front of them. When the term has
     * been changed since, the confirmation is refused, because they would be confirming words they
     * never read. It is also refused when another confirmed term of the same contract already says
     * the same thing for some of the same days, because the app could not tell which of the two applies.
     *
     * @throws ValidationException
     */
    public function confirm(ContractTerm $term, User $by, ?string $seen = null): ContractTerm
    {
        return $this->oneAtATime($term, function (ContractTerm $term) use ($by, $seen) {
            if ($seen !== null && ! hash_equals($term->fingerprint(), $seen)) {
                throw ValidationException::withMessages(['read_in_agreement' => __('This term was changed after you opened the page. Read it again, then confirm it.')]);
            }

            if ($term->isConfirmed()) {
                return $term;
            }

            $this->refuseClash($term);
            $term->update(['confirmed_by' => $by->id, 'confirmed_at' => now()]);

            return $term;
        });
    }

    /**
     * Runs one change at a time on a project, on the term as it is saved now and not as it was when
     * the request began. Without this, a change saved while someone else was confirming could end up
     * under their confirmation.
     *
     * @param  callable(ContractTerm): ContractTerm  $change
     */
    private function oneAtATime(ContractTerm $term, callable $change): ContractTerm
    {
        return DB::transaction(function () use ($term, $change) {
            Project::withoutGlobalScopes()->whereKey($term->project_id)->lockForUpdate()->first();
            $term->refresh();

            if ($term->trashed()) {
                throw (new ModelNotFoundException)->setModel(ContractTerm::class, [$term->id]);
            }

            return $change($term);
        });
    }

    /** @return Builder<ContractTerm> */
    private function ofContract(int $organizationId, int $projectId, ?int $workOrderId): Builder
    {
        return ContractTerm::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->when($workOrderId === null, fn (Builder $query) => $query->whereNull('work_order_id'), fn (Builder $query) => $query->where('work_order_id', $workOrderId));
    }

    /**
     * The columns of a term from validated data. Everything that does not belong to its kind is
     * saved empty, and a blank name takes the code's own.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        $label = trim((string) ($data['label'] ?? ''));

        return [
            ...array_fill_keys(ContractTerm::VALUE_COLUMNS, null),
            ...Arr::only($data, [...ContractTerm::VALUE_COLUMNS, 'kind', 'code', 'effective_from', 'effective_to', 'source_ref']),
            'work_order_id' => $data['work_order_id'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
            'label' => $label !== '' ? $label : (string) config("modules.contract_terms.codes.{$data['kind']}.{$data['code']}"),
        ];
    }

    private function sameProject(ContractTerm $term, Project $project): ContractTerm
    {
        if ($term->project_id !== $project->id) {
            throw ValidationException::withMessages(['client_uuid' => __('This request id was already used on another project.')]);
        }

        return $term;
    }

    /** @throws ValidationException */
    private function refuseClash(ContractTerm $term): void
    {
        $others = $this->ofContract($term->organization_id, $term->project_id, $term->work_order_id)
            ->where('code', $term->code)
            ->whereKeyNot($term->id)
            ->whereNotNull('confirmed_at')
            ->orderBy('effective_from')->orderBy('id')
            ->get()
            ->filter(fn (ContractTerm $other) => $this->overlap($term, $other));

        if ($others->isEmpty()) {
            return;
        }

        $period = ['period' => $this->text->period($others->first())];

        if (in_array($term->code, ContractTerm::ONE_AT_A_TIME, true)) {
            throw ValidationException::withMessages(['read_in_agreement' => __('Another confirmed term of the same name is already entered for this contract: :period. Give that one an end date first, or correct the dates of this one.', $period)]);
        }

        // Two notice periods for the same notice only make sense as a pair the agreement ties together.
        $paired = $term->combine_group !== null && $others->every(
            fn (ContractTerm $other) => $other->combine_group === $term->combine_group && $other->combine === $term->combine
        );

        if ($term->kind === 'notice' && ! $paired) {
            throw ValidationException::withMessages(['read_in_agreement' => __('Another confirmed notice period of the same name is already entered for this contract: :period. If the agreement gives two periods together, give both the same group name and the same choice of first or later.', $period)]);
        }
    }

    private function overlap(ContractTerm $one, ContractTerm $other): bool
    {
        $end = fn (ContractTerm $term) => $term->effective_to?->toDateString() ?? '9999-12-31';

        return $one->effective_from->toDateString() <= $end($other) && $other->effective_from->toDateString() <= $end($one);
    }
}
