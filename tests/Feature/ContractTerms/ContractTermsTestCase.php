<?php

namespace Tests\Feature\ContractTerms;

use App\Models\ContractTerm;
use App\Models\CustomRole;
use App\Models\Organization;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\BuildsTenant;
use Tests\TestCase;

/**
 * An invented company with one project and one subcontract. Every percentage, amount, number of days,
 * date and clause in these tests is made up for the test and comes from no agreement and no law.
 */
abstract class ContractTermsTestCase extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected Organization $org;

    protected User $owner;

    protected User $pm;

    protected User $qs;

    protected User $accounts;

    protected User $engineer;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->org = $this->tenant('Meghdoot Buildcon', 'business');
        $this->owner = $this->member($this->org, 'owner');
        $this->pm = $this->member($this->org, 'pm');
        $this->qs = $this->member($this->org, 'qs');
        $this->accounts = $this->member($this->org, 'accounts');
        $this->engineer = $this->member($this->org, 'engineer');
        $this->project = $this->makeProject($this->org, ['code' => 'MB1', 'name' => 'Canal Road Housing']);
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

    protected function workOrder(?Project $project = null, string $number = 'WO-11'): WorkOrder
    {
        $project ??= $this->project;
        $party = Party::withoutGlobalScopes()->create(['organization_id' => $project->organization_id, 'type' => 'subcontractor', 'name' => 'Trimurti Shuttering']);

        return WorkOrder::withoutGlobalScopes()->create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'party_id' => $party->id,
            'number' => $number, 'title' => 'Slab shuttering', 'status' => 'ACTIVE', 'rate_type' => 'item_rate',
        ]);
    }

    /** A saved term, written straight to the table. Unconfirmed unless $attributes says otherwise. */
    protected function term(array $attributes = [], ?Project $project = null): ContractTerm
    {
        $project ??= $this->project;

        return ContractTerm::withoutGlobalScopes()->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'kind' => 'deduction',
            'code' => 'retention',
            'label' => 'Retention',
            'method' => 'percent',
            'pct' => '7.25',
            'base' => 'gross',
            'effective_from' => '2026-04-01',
            'source_ref' => 'Sample agreement, part C',
            ...$attributes,
        ]);
    }

    /** The same, already confirmed. */
    protected function confirmed(array $attributes = [], ?Project $project = null): ContractTerm
    {
        return $this->term(['confirmed_by' => $this->owner->id, 'confirmed_at' => now(), ...$attributes], $project);
    }

    /** What the add form sends for a percentage deduction. */
    protected function form(array $overrides = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'kind' => 'deduction',
            'code' => 'retention',
            'method' => 'percent',
            'pct' => '7.25',
            'base' => 'gross',
            'effective_from' => '2026-04-01',
            'source_ref' => 'Sample agreement, part C',
            ...$overrides,
        ];
    }

    /** What the confirm form sends: the tick, and the fingerprint of the term as the page showed it. */
    protected function tick(ContractTerm $term): array
    {
        return ['read_in_agreement' => '1', 'seen' => $term->fingerprint()];
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
