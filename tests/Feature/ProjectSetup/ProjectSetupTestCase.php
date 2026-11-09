<?php

namespace Tests\Feature\ProjectSetup;

use App\Models\BoqItem;
use App\Models\CustomRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsTenant;
use Tests\TestCase;

abstract class ProjectSetupTestCase extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected Organization $org;

    protected User $owner;

    protected User $pm;

    protected User $qs;

    protected User $engineer;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->org = $this->tenant('Acme Infra', 'business');
        $this->owner = $this->member($this->org, 'owner');
        $this->pm = $this->member($this->org, 'pm');
        $this->qs = $this->member($this->org, 'qs');
        $this->engineer = $this->member($this->org, 'engineer');
        $this->project = $this->makeProject($this->org, ['code' => 'SH1', 'name' => 'Sargam Hotel']);
    }

    /** Someone holding exactly these permissions. */
    protected function holding(array $permissions, ?Organization $organization = null): User
    {
        $organization ??= $this->org;
        $role = CustomRole::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'name' => 'Role '.uniqid(),
            'permissions' => $permissions,
        ]);
        $user = $this->member($organization, $role->baseRole());
        $user->forceFill(['custom_role_id' => $role->id])->save();

        return $user;
    }

    protected function line(string $qty = '100.000', string $rate = '5000.00', array $attributes = [], ?Project $project = null): BoqItem
    {
        $project ??= $this->project;

        return BoqItem::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'code' => 'L'.uniqid(),
            'description' => 'RCC M25 in slab',
            'unit' => 'cum',
            'qty' => $qty,
            'rate' => $rate,
            'sort' => (int) BoqItem::withoutGlobalScopes()->where('project_id', $project->id)->max('sort') + 1,
            ...$attributes,
        ]);
    }

    /** A second company with a project of its own, for tenant isolation checks. */
    protected function rival(): array
    {
        $org = $this->tenant('Rival Builders', 'business');
        $owner = $this->member($org, 'owner');
        $project = $this->makeProject($org, ['code' => 'RV1']);

        return [$org, $owner, $project];
    }
}
