<?php

namespace App\Services\Crm;

use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Collection;

/** The lists behind the select boxes on CRM forms. */
class Lookups
{
    /** @return Collection<int,User> */
    public function users(int $organizationId): Collection
    {
        return User::query()->where('organization_id', $organizationId)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /** @return Collection<int,Party> */
    public function parties(int $organizationId): Collection
    {
        return Party::query()->where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name', 'type']);
    }
}
