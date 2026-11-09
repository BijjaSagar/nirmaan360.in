<?php

namespace App\Services\Crm;

use App\Models\CrmLead;
use App\Models\CrmTender;
use App\Models\User;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TenderService
{
    /** How far back the Won, Lost and No bid columns reach on the board unless asked for everything. */
    public const CLOSED_WINDOW_DAYS = 90;

    public function __construct(
        private CustomFieldService $fields,
        private ReasonService $reasons,
        private ActivityService $activities,
    ) {}

    public function rules(User $user): array
    {
        return [
            'reference' => ['nullable', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:200'],
            'authority' => ['required', 'string', 'max:160'],
            'party_id' => ['nullable', org_exists('parties')],
            'lead_id' => ['nullable', org_exists('crm_leads')],
            'location' => ['nullable', 'string', 'max:160'],
            'state' => ['nullable', 'string', 'max:60'],
            'portal_url' => ['nullable', 'url:http,https', 'max:500'],
            'stage' => ['nullable', Rule::in(CrmTender::OPEN_STAGES)],
            'estimated_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'bid_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'published_on' => ['nullable', 'date'],
            'pre_bid_at' => ['nullable', 'date'],
            'pre_bid_note' => ['nullable', 'string', 'max:255'],
            'due_at' => ['nullable', 'date'],
            'opens_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['nullable', org_exists('users')],
            'tags' => ['nullable', 'string', 'max:500'],
            'client_uuid' => ['nullable', 'uuid'],
            ...$this->fields->rules($user->organization_id, 'tender'),
        ];
    }

    /** Rules for closing a tender as won, lost or not bid. */
    public function closeRules(): array
    {
        return [
            'outcome' => ['required', Rule::in(CrmTender::CLOSED_STAGES)],
            'outcome_reason_id' => ['nullable', org_exists('crm_outcome_reasons')],
            'outcome_note' => ['nullable', 'string', 'max:2000'],
            'closed_on' => ['nullable', 'date', 'before_or_equal:today'],
            'awarded_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'winner_name' => ['nullable', 'string', 'max:160'],
            'winning_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
        ];
    }

    /** @return Builder<CrmTender> */
    public function query(int $organizationId, array $filters = []): Builder
    {
        $query = CrmTender::query()->with(['owner', 'party'])->where('organization_id', $organizationId);

        if (! empty($filters['stage'])) {
            $query->where('stage', $filters['stage']);
        }

        if (! empty($filters['owner'])) {
            $query->where('owner_id', (int) $filters['owner']);
        }

        if (! empty($filters['party'])) {
            $query->where('party_id', (int) $filters['party']);
        }

        Search::contains($query, ['crm_tenders.title', 'crm_tenders.authority', 'crm_tenders.reference'], $filters['q'] ?? null);
        Search::tagged($query, $filters['tag'] ?? null);

        return $query;
    }

    /**
     * The Kanban columns: every stage with its tenders, count and total value.
     * Closed columns show recent closings only unless $allClosed is set.
     *
     * @return array<string,array{tenders:Collection<int,CrmTender>,count:int,value:string}>
     */
    public function board(int $organizationId, array $filters = [], bool $allClosed = false): array
    {
        $query = $this->query($organizationId, $filters);

        if (! $allClosed) {
            $since = today()->subDays(self::CLOSED_WINDOW_DAYS);
            $query->where(fn (Builder $inner) => $inner
                ->whereIn('stage', CrmTender::OPEN_STAGES)
                ->orWhere('closed_on', '>=', $since)
                ->orWhereNull('closed_on'));
        }

        $tenders = $query->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderByDesc('id')->get()->groupBy('stage');
        $columns = [];

        foreach (CrmTender::STAGES as $stage) {
            $group = $tenders->get($stage, collect());
            $columns[$stage] = [
                'tenders' => $group,
                'count' => $group->count(),
                'value' => Dec::money($group->reduce(fn ($sum, CrmTender $tender) => $sum->plus(Dec::of($tender->headlineValue())), Dec::of(0))),
            ];
        }

        return $columns;
    }

    /** Create a tender. A repeated client_uuid returns the original row. */
    public function create(array $data, User $user): CrmTender
    {
        $organizationId = $user->organization_id;
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findByClientUuid($organizationId, $uuid))) {
            return $existing;
        }

        $lead = ! empty($data['lead_id']) ? CrmLead::query()->where('organization_id', $organizationId)->find($data['lead_id']) : null;
        $stage = $data['stage'] ?? 'enquiry';

        $tender = Idempotent::create(
            fn () => CrmTender::create([
                ...$this->attributes($data, $organizationId),
                'organization_id' => $organizationId,
                'party_id' => $data['party_id'] ?? $lead?->party_id,
                'owner_id' => $data['owner_id'] ?? $user->id,
                'created_by' => $user->id,
                'stage' => $stage,
                'stage_changed_at' => now(),
                'client_uuid' => $uuid,
            ]),
            fn () => $this->findByClientUuid($organizationId, (string) $uuid),
        );

        if ($tender->wasRecentlyCreated) {
            $this->activities->log($tender, 'system', __('Tender added in stage :stage', ['stage' => Vocab::stages()[$stage]]), $user);
        }

        return $tender->refresh();
    }

    public function update(CrmTender $tender, array $data): CrmTender
    {
        $values = $this->attributes($data, $tender->organization_id, $tender);

        if (array_key_exists('party_id', $data)) {
            $values['party_id'] = $data['party_id'] ?: null;
        }

        if (array_key_exists('owner_id', $data)) {
            $values['owner_id'] = $data['owner_id'] ?: null;
        }

        $tender->fill($values);

        // A new deadline deserves a new warning.
        if ($tender->isDirty('due_at')) {
            $tender->due_alerted_at = null;
        }

        $tender->save();

        return $tender;
    }

    /** Move a tender between the open stages. Won, lost and not bid go through close(). */
    public function move(CrmTender $tender, string $stage, User $user): CrmTender
    {
        if (! in_array($stage, CrmTender::OPEN_STAGES, true)) {
            throw ValidationException::withMessages(['stage' => __('Use the close form to mark a tender won, lost or not bid.')]);
        }

        if (! $tender->isOpen()) {
            throw ValidationException::withMessages(['stage' => __('Reopen this tender before moving it.')]);
        }

        if ($tender->stage === $stage) {
            return $tender;
        }

        $labels = Vocab::stages();
        $from = $tender->stage;
        $tender->update(['stage' => $stage, 'stage_changed_at' => now()]);
        $this->activities->log($tender, 'stage', __('Moved from :from to :to', ['from' => $labels[$from], 'to' => $labels[$stage]]), $user);

        return $tender;
    }

    /**
     * Close an open tender as won, lost or not bid, with the reason.
     * A lost or not-bid tender must have a reason, so the win/loss report is never blank.
     */
    public function close(CrmTender $tender, string $outcome, array $data, User $user): CrmTender
    {
        if (! in_array($outcome, CrmTender::CLOSED_STAGES, true)) {
            throw ValidationException::withMessages(['outcome' => __('Choose won, lost or not bid.')]);
        }

        if (! $tender->isOpen()) {
            throw ValidationException::withMessages(['outcome' => __('This tender is already closed.')]);
        }

        $reason = $this->reasons->resolve($tender->organization_id, $outcome, $data['outcome_reason_id'] ?? null);

        if ($reason === null && $outcome !== 'won') {
            throw ValidationException::withMessages(['outcome_reason_id' => __('Choose a reason.')]);
        }

        $values = [
            'stage' => $outcome,
            'stage_changed_at' => now(),
            'closed_on' => ! empty($data['closed_on']) ? $data['closed_on'] : today()->toDateString(),
            'outcome_reason_id' => $reason?->id,
            'outcome_note' => filled($data['outcome_note'] ?? null) ? $data['outcome_note'] : null,
        ];

        if ($outcome === 'won') {
            $awarded = filled($data['awarded_value'] ?? null) ? $data['awarded_value'] : ($tender->bid_value ?? $tender->estimated_value);
            $values['awarded_value'] = Dec::money($awarded);
        }

        if ($outcome === 'lost') {
            $values['winner_name'] = filled($data['winner_name'] ?? null) ? $data['winner_name'] : null;
            $values['winning_value'] = filled($data['winning_value'] ?? null) ? Dec::money($data['winning_value']) : null;
        }

        $tender->update($values);

        $this->activities->log($tender, 'stage', __('Closed as :outcome', ['outcome' => Vocab::stages()[$outcome]]).($reason ? ': '.$reason->label : ''), $user);

        return $tender;
    }

    /** Put a closed tender back into an open stage. Not possible once it has become a project. */
    public function reopen(CrmTender $tender, string $stage, User $user): CrmTender
    {
        if ($tender->isOpen()) {
            throw ValidationException::withMessages(['stage' => __('This tender is already open.')]);
        }

        if ($tender->isConverted()) {
            throw ValidationException::withMessages(['stage' => __('This tender was converted to a project and cannot be reopened.')]);
        }

        if (! in_array($stage, CrmTender::OPEN_STAGES, true)) {
            throw ValidationException::withMessages(['stage' => __('Choose an open stage.')]);
        }

        $was = Vocab::stages()[$tender->stage].($tender->reason ? ': '.$tender->reason->label : '');

        $tender->update([
            'stage' => $stage,
            'stage_changed_at' => now(),
            'closed_on' => null,
            'outcome_reason_id' => null,
            'outcome_note' => null,
            'awarded_value' => null,
            'winner_name' => null,
            'winning_value' => null,
        ]);

        $this->activities->log($tender, 'stage', __('Reopened as :stage (was :was)', ['stage' => Vocab::stages()[$stage], 'was' => $was]), $user);

        return $tender;
    }

    public function delete(CrmTender $tender): void
    {
        if ($tender->isConverted()) {
            throw ValidationException::withMessages(['tender' => __('This tender was converted to a project and cannot be deleted.')]);
        }

        if ($tender->payments()->whereIn('status', ['planned', 'paid'])->exists()) {
            throw ValidationException::withMessages(['tender' => __('Settle or remove the EMD and fees on this tender before deleting it.')]);
        }

        $tender->delete();
    }

    private function findByClientUuid(int $organizationId, string $uuid): ?CrmTender
    {
        return CrmTender::withTrashed()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }

    /** The columns to store from a validated form. Fields the form did not send keep their value. */
    private function attributes(array $data, int $organizationId, ?CrmTender $tender = null): array
    {
        $values = collect($data)->only([
            'reference', 'title', 'authority', 'lead_id', 'location', 'state', 'portal_url', 'published_on',
            'pre_bid_at', 'pre_bid_note', 'due_at', 'opens_at', 'notes',
        ])->map(fn ($value) => $value === '' ? null : $value)->all();

        foreach (['estimated_value', 'bid_value'] as $column) {
            if (array_key_exists($column, $data)) {
                $values[$column] = filled($data[$column]) ? Dec::money($data[$column]) : ($column === 'estimated_value' ? '0.00' : null);
            }
        }

        if (array_key_exists('tags', $data)) {
            $values['tags'] = TagService::parse($data['tags']);
        }

        if (array_key_exists('custom', $data)) {
            $values['custom_fields'] = $this->fields->clean($organizationId, 'tender', (array) $data['custom'], $tender?->custom_fields);
        }

        return $values;
    }
}
