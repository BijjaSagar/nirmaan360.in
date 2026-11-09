<?php

namespace App\Services\Crm;

use App\Models\CrmCompanyProfile;
use App\Models\Party;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Companies are Party rows, so contacts stay in one place. The CRM keeps tags, fields and an owner beside them. */
class CompanyService
{
    public function __construct(private CustomFieldService $fields) {}

    public function rules(User $user): array
    {
        return [
            'type' => ['required', Rule::in(config('buildos.party_types'))],
            'name' => ['required', 'string', 'max:160'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:190'],
            'gstin' => ['nullable', 'string', 'size:15'],
            'pan' => ['nullable', 'string', 'size:10'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['nullable', org_exists('users')],
            'tags' => ['nullable', 'string', 'max:500'],
            ...$this->fields->rules($user->organization_id, 'company'),
        ];
    }

    /** @return Builder<Party> */
    public function query(int $organizationId, array $filters): Builder
    {
        $query = Party::query()->where('organization_id', $organizationId);

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        Search::contains($query, ['parties.name', 'parties.contact_person', 'parties.phone', 'parties.email'], $filters['q'] ?? null);

        $tag = TagService::parse($filters['tag'] ?? null)[0] ?? null;

        if ($tag !== null) {
            $query->whereIn('parties.id', CrmCompanyProfile::withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->whereJsonContains('tags', $tag)
                ->select('party_id'));
        }

        return $query->orderBy('parties.name');
    }

    public function profile(Party $party): CrmCompanyProfile
    {
        return CrmCompanyProfile::query()->firstOrNew(
            ['organization_id' => $party->organization_id, 'party_id' => $party->id],
        );
    }

    public function create(array $data, User $user): Party
    {
        return DB::transaction(function () use ($data, $user) {
            $party = Party::create([
                ...collect($data)->only(['type', 'name', 'contact_person', 'phone', 'email', 'gstin', 'pan', 'address', 'notes'])->all(),
                'organization_id' => $user->organization_id,
            ]);

            $this->saveProfile($party, $data);

            return $party;
        });
    }

    public function update(Party $party, array $data): Party
    {
        return DB::transaction(function () use ($party, $data) {
            $party->update(collect($data)->only(['type', 'name', 'contact_person', 'phone', 'email', 'gstin', 'pan', 'address', 'notes'])->all());
            $this->saveProfile($party, $data);

            return $party;
        });
    }

    /** Save tags, custom fields and owner for a company. Fields not sent are left as they were. */
    public function saveProfile(Party $party, array $data): CrmCompanyProfile
    {
        $profile = $this->profile($party);

        if (array_key_exists('tags', $data)) {
            $profile->tags = TagService::parse($data['tags']);
        }

        if (array_key_exists('custom', $data)) {
            $profile->custom_fields = $this->fields->clean($party->organization_id, 'company', (array) $data['custom'], $profile->custom_fields);
        }

        if (array_key_exists('owner_id', $data)) {
            $profile->owner_id = $data['owner_id'] ?: null;
        }

        $profile->save();

        return $profile;
    }

    /** Add tags to a company without removing the ones it has. */
    public function addTags(Party $party, array $tags): void
    {
        $profile = $this->profile($party);
        $profile->tags = TagService::parse([...($profile->tags ?? []), ...$tags]);
        $profile->save();
    }
}
