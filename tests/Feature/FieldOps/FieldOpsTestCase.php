<?php

namespace Tests\Feature\FieldOps;

use App\Models\Asset;
use App\Models\Organization;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use App\Services\Assets\AssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\BuildsTenant;
use Tests\TestCase;

/** Shared set-up for the assets and QHS tests. */
abstract class FieldOpsTestCase extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** A company on the Business plan, an owner, and a project. */
    protected function world(string $role = 'owner', string $plan = 'business', string $name = 'Acme Infra'): object
    {
        $organization = $this->tenant($name, $plan);
        $owner = $this->member($organization, 'owner');
        $user = $role === 'owner' ? $owner : $this->member($organization, $role);

        return (object) ['organization' => $organization, 'owner' => $owner, 'user' => $user, 'project' => $this->makeProject($organization)];
    }

    protected function vendor(Organization $organization, string $name = 'Shree Equipment Hire'): Party
    {
        return Party::create(['organization_id' => $organization->id, 'type' => 'vendor', 'name' => $name]);
    }

    protected function ownedAsset(User $user, array $overrides = []): Asset
    {
        return app(AssetService::class)->create([
            'name' => 'JCB 3DX',
            'category' => 'backhoe',
            'ownership' => 'owned',
            'meter_unit' => 'hours',
            'client_uuid' => (string) Str::uuid(),
            ...$overrides,
        ], $user);
    }

    /** A hired machine. Pass hire_basis, hire_rate and the rest to change the terms. */
    protected function hiredAsset(User $user, array $overrides = []): Asset
    {
        return app(AssetService::class)->create([
            'name' => 'Hired Excavator',
            'category' => 'excavator',
            'ownership' => 'hired',
            'hire_basis' => 'hour',
            'hire_rate' => '1200.00',
            'hire_type' => 'dry',
            'meter_unit' => 'hours',
            'client_uuid' => (string) Str::uuid(),
            ...$overrides,
        ], $user);
    }

    protected function projectOf(Organization $organization, string $name = 'Other Project'): Project
    {
        return $this->makeProject($organization, ['name' => $name]);
    }
}
