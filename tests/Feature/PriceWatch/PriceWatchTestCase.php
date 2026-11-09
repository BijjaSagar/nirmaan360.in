<?php

namespace Tests\Feature\PriceWatch;

use App\Models\CustomRole;
use App\Models\MaterialPrice;
use App\Models\Organization;
use App\Models\PriceWatchMaterial;
use App\Models\PriceWatchProjectRate;
use App\Models\Project;
use App\Models\User;
use App\Services\Access\TeamAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\BuildsTenant;
use Tests\TestCase;

/** A company on the Business plan with one of every role and a project with a ₹10,00,000 budget. */
abstract class PriceWatchTestCase extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected const API = '/api/v1';

    protected Organization $org;

    protected User $owner;

    protected User $pm;

    protected User $qs;

    protected User $accounts;

    protected User $engineer;

    protected User $store;

    protected User $viewer;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();

        $this->org = $this->tenant('Acme Infra', 'business');

        foreach (['owner', 'pm', 'qs', 'accounts', 'engineer', 'store', 'viewer'] as $role) {
            $this->{$role} = $this->member($this->org, $role);
        }

        $this->project = $this->makeProject($this->org, ['code' => 'SH1', 'name' => 'Sargam Hotel', 'status' => 'active', 'budget_amount' => '1000000.00', 'budget_margin_pct' => '5.00']);
    }

    /** A material on the board, with no price yet. */
    protected function watch(string $name, string $unit, ?string $key = null, ?int $materialId = null, ?Organization $organization = null): PriceWatchMaterial
    {
        return PriceWatchMaterial::withoutGlobalScopes()->create([
            'organization_id' => ($organization ?? $this->org)->id,
            'key' => $key,
            'name' => $name,
            'unit' => $unit,
            'material_id' => $materialId,
            'is_active' => true,
            'sort' => (int) PriceWatchMaterial::withoutGlobalScopes()->where('organization_id', ($organization ?? $this->org)->id)->max('sort') + 10,
        ]);
    }

    /** One dated price on a material's list. */
    protected function price(PriceWatchMaterial $material, string $rate, string $pricedOn, ?string $source = 'Dealer quote', string $kind = MaterialPrice::MANUAL): MaterialPrice
    {
        return MaterialPrice::withoutGlobalScopes()->create([
            'organization_id' => $material->organization_id,
            'price_watch_material_id' => $material->id,
            'rate' => $rate,
            'unit' => $material->unit,
            'priced_on' => $pricedOn,
            'source' => $source,
            'source_kind' => $kind,
        ]);
    }

    /** What a project's BOQ assumed for a material: its rate, and its quantity when no norm gives one. */
    protected function boqRate(PriceWatchMaterial $material, ?string $rate, ?string $qty = null, ?Project $project = null): PriceWatchProjectRate
    {
        $project ??= $this->project;

        return PriceWatchProjectRate::withoutGlobalScopes()->updateOrCreate(
            ['project_id' => $project->id, 'price_watch_material_id' => $material->id],
            ['organization_id' => $project->organization_id, 'boq_rate' => $rate, 'boq_qty' => $qty],
        );
    }

    /** Someone holding exactly these permissions. */
    protected function holding(array $permissions, ?Organization $organization = null): User
    {
        $organization ??= $this->org;
        $role = CustomRole::withoutGlobalScopes()->create(['organization_id' => $organization->id, 'name' => 'Role '.uniqid(), 'permissions' => $permissions]);
        $user = $this->member($organization, $role->baseRole());
        $user->forceFill(['custom_role_id' => $role->id])->save();

        return $user;
    }

    /** A project manager who may only see the given projects. */
    protected function limitedPm(Project ...$projects): User
    {
        $user = $this->member($this->org, 'pm');
        app(TeamAccessService::class)->applyProjects($user, true, array_map(fn (Project $project) => $project->id, $projects));

        return $user->refresh();
    }

    /** A second company with a project of its own, for tenant isolation checks. @return array{0: Organization, 1: User, 2: Project} */
    protected function rival(): array
    {
        $org = $this->tenant('Rival Builders', 'business');
        $owner = $this->member($org, 'owner');
        $project = $this->makeProject($org, ['code' => 'RV1', 'name' => 'Rival Plaza']);

        return [$org, $owner, $project];
    }

    protected function platformAdmin(): User
    {
        return User::factory()->create(['organization_id' => $this->org->id, 'role' => 'owner', 'is_active' => true, 'is_platform_admin' => true]);
    }
}
