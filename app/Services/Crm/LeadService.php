<?php

namespace App\Services\Crm;

use App\Models\CrmLead;
use App\Models\Party;
use App\Models\User;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeadService
{
    public function __construct(
        private CustomFieldService $fields,
        private CompanyService $companies,
        private ActivityService $activities,
    ) {}

    public function rules(User $user): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:190'],
            'source' => ['required', Rule::in(CrmLead::SOURCES)],
            'expected_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'location' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['nullable', org_exists('users')],
            'tags' => ['nullable', 'string', 'max:500'],
            'client_uuid' => ['nullable', 'uuid'],
            ...$this->fields->rules($user->organization_id, 'lead'),
        ];
    }

    /** @return Builder<CrmLead> */
    public function query(int $organizationId, array $filters): Builder
    {
        $query = CrmLead::query()->with('owner')->where('organization_id', $organizationId);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }

        if (! empty($filters['owner'])) {
            $query->where('owner_id', (int) $filters['owner']);
        }

        Search::contains($query, ['crm_leads.name', 'crm_leads.company_name', 'crm_leads.phone', 'crm_leads.email'], $filters['q'] ?? null);
        Search::tagged($query, $filters['tag'] ?? null);

        return $query->latest('id');
    }

    /** Create a lead. A repeated client_uuid returns the original row. */
    public function create(array $data, User $user): CrmLead
    {
        $organizationId = $user->organization_id;
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findByClientUuid($organizationId, $uuid))) {
            return $existing;
        }

        $lead = Idempotent::create(
            fn () => CrmLead::create([
                ...$this->attributes($data, $organizationId),
                'organization_id' => $organizationId,
                'owner_id' => $data['owner_id'] ?? $user->id,
                'created_by' => $user->id,
                'status' => 'new',
                'client_uuid' => $uuid,
            ]),
            fn () => $this->findByClientUuid($organizationId, (string) $uuid),
        );

        return $lead->refresh();
    }

    public function update(CrmLead $lead, array $data): CrmLead
    {
        $lead->update($this->attributes($data, $lead->organization_id, $lead));

        return $lead;
    }

    /** Move a lead between the open statuses or mark it lost. Converting goes through convert(). */
    public function setStatus(CrmLead $lead, string $status, ?string $lostNote, User $user): CrmLead
    {
        if ($lead->status === 'converted') {
            throw ValidationException::withMessages(['status' => __('This lead has already been converted to a company.')]);
        }

        if (! in_array($status, [...CrmLead::OPEN_STATUSES, 'lost'], true)) {
            throw ValidationException::withMessages(['status' => __('Choose a valid status.')]);
        }

        if ($lead->status === $status) {
            return $lead;
        }

        $old = $lead->status;
        $lead->update(['status' => $status, 'lost_note' => $status === 'lost' ? $lostNote : null]);

        $labels = Vocab::leadStatuses();
        $this->activities->log($lead, 'stage', __('Status changed from :from to :to', ['from' => $labels[$old] ?? $old, 'to' => $labels[$status]]).($status === 'lost' && filled($lostNote) ? ': '.$lostNote : ''), $user);

        return $lead;
    }

    /**
     * Turn a lead into a company: a new Party, or an existing one chosen by the user.
     * Safe to repeat: a lead that already has a company returns it.
     */
    public function convert(CrmLead $lead, User $user, string $type = 'client', ?int $existingPartyId = null): Party
    {
        return DB::transaction(function () use ($lead, $user, $type, $existingPartyId) {
            $lead = CrmLead::query()->where('organization_id', $user->organization_id)->lockForUpdate()->findOrFail($lead->id);

            if ($lead->party_id && ($party = Party::query()->where('organization_id', $user->organization_id)->find($lead->party_id))) {
                return $party;
            }

            if ($existingPartyId) {
                $party = Party::query()->where('organization_id', $user->organization_id)->find($existingPartyId)
                    ?? throw ValidationException::withMessages(['party_id' => __('That company was not found.')]);
            } else {
                $party = Party::create([
                    'organization_id' => $user->organization_id,
                    'type' => $type,
                    'name' => $lead->company_name ?: $lead->name,
                    'contact_person' => $lead->company_name ? $lead->name : null,
                    'phone' => $lead->phone,
                    'email' => $lead->email,
                    'address' => $lead->location,
                ]);
            }

            $this->companies->addTags($party, $lead->tags ?? []);

            $lead->update(['party_id' => $party->id, 'status' => 'converted', 'converted_at' => now()]);
            $this->activities->log($lead, 'system', __('Converted to company :name', ['name' => $party->name]), $user);
            $this->activities->log($party, 'system', __('Created from lead :name', ['name' => $lead->name]), $user);

            return $party;
        });
    }

    public function delete(CrmLead $lead): void
    {
        $lead->delete();
    }

    private function findByClientUuid(int $organizationId, string $uuid): ?CrmLead
    {
        return CrmLead::withTrashed()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }

    /** The columns to store from a validated form. Fields the form did not send keep their value. */
    private function attributes(array $data, int $organizationId, ?CrmLead $lead = null): array
    {
        $values = collect($data)->only(['name', 'company_name', 'phone', 'email', 'source', 'location', 'notes'])->all();

        if (array_key_exists('expected_value', $data)) {
            $values['expected_value'] = Dec::money($data['expected_value']);
        }

        if ($lead && array_key_exists('owner_id', $data)) {
            $values['owner_id'] = $data['owner_id'] ?: null;
        }

        if (array_key_exists('tags', $data)) {
            $values['tags'] = TagService::parse($data['tags']);
        }

        if (array_key_exists('custom', $data)) {
            $values['custom_fields'] = $this->fields->clean($organizationId, 'lead', (array) $data['custom'], $lead?->custom_fields);
        }

        return $values;
    }
}
