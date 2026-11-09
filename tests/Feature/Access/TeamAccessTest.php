<?php

namespace Tests\Feature\Access;

use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access\TeamAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\BuildsTenant;
use Tests\TestCase;

class TeamAccessTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->organization = $this->tenant();
        $this->owner = $this->member($this->organization, 'owner');
    }

    public function test_the_team_screens_load(): void
    {
        $member = $this->member($this->organization, 'engineer');
        $this->actingAs($this->owner);

        $this->get('/team')->assertOk()->assertSee($member->email)->assertSee('Invite member');
        $this->get("/team/{$member->id}/access")->assertOk()->assertSee($member->name);
        $this->get("/team/{$this->owner->id}/access")->assertOk()->assertSee('You cannot change your own role');
    }

    public function test_a_member_can_be_limited_to_chosen_projects_and_released(): void
    {
        $first = $this->makeProject($this->organization);
        $this->makeProject($this->organization);
        $member = $this->member($this->organization, 'pm');
        $this->actingAs($this->owner);

        $this->patch("/team/{$member->id}", ['role' => 'pm', 'is_active' => 1, 'project_access' => 'chosen', 'project_ids' => [$first->id]])->assertRedirect();
        $this->assertTrue($member->refresh()->isProjectLimited());
        $this->assertSame([$first->id], $member->assignedProjectIds()->pluck('project_id')->all());

        $this->patch("/team/{$member->id}", ['role' => 'pm', 'is_active' => 1, 'project_access' => 'all'])->assertRedirect();
        $this->assertFalse($member->refresh()->isProjectLimited());
        $this->assertSame(0, $member->assignedProjectIds()->count());
    }

    public function test_saving_a_role_without_project_fields_leaves_projects_alone(): void
    {
        $project = $this->makeProject($this->organization);
        $member = $this->member($this->organization, 'pm');
        app(TeamAccessService::class)->applyProjects($member, true, [$project->id]);
        $this->actingAs($this->owner);

        $this->patch("/team/{$member->id}", ['role' => 'qs', 'is_active' => 1])->assertRedirect();

        $this->assertTrue($member->refresh()->isProjectLimited());
        $this->assertSame([$project->id], $member->assignedProjectIds()->pluck('project_id')->all());
    }

    public function test_promoting_to_admin_clears_any_limit(): void
    {
        $project = $this->makeProject($this->organization);
        $member = $this->member($this->organization, 'pm');
        app(TeamAccessService::class)->applyProjects($member, true, [$project->id]);
        $this->actingAs($this->owner);

        $this->patch("/team/{$member->id}", ['role' => 'admin', 'is_active' => 1])->assertRedirect();

        $this->assertFalse($member->refresh()->limited_to_projects);
        $this->assertSame(0, $member->assignedProjectIds()->count());
    }

    public function test_you_cannot_change_your_own_role_or_deactivate_yourself(): void
    {
        $this->actingAs($this->owner);

        $this->patch("/team/{$this->owner->id}", ['role' => 'viewer', 'is_active' => 1])->assertSessionHasErrors('role');
        $this->patch("/team/{$this->owner->id}", ['role' => 'owner'])->assertSessionHasErrors('role');

        $this->assertSame('owner', $this->owner->refresh()->role);
        $this->assertTrue($this->owner->is_active);
    }

    public function test_you_cannot_limit_your_own_projects(): void
    {
        $project = $this->makeProject($this->organization);
        $admin = $this->member($this->organization, 'admin');
        $this->actingAs($admin);

        $this->patch("/team/{$admin->id}", ['role' => 'admin', 'is_active' => 1, 'project_access' => 'chosen', 'project_ids' => [$project->id]])->assertRedirect();

        $this->assertFalse($admin->refresh()->limited_to_projects);
    }

    public function test_the_last_active_owner_cannot_be_removed(): void
    {
        $admin = $this->member($this->organization, 'admin');
        $this->actingAs($admin);

        $this->patch("/team/{$this->owner->id}", ['role' => 'viewer', 'is_active' => 1])->assertSessionHasErrors('role');
        $this->patch("/team/{$this->owner->id}", ['role' => 'owner', 'is_active' => 0])->assertSessionHasErrors('role');

        $this->assertSame('owner', $this->owner->refresh()->role);
        $this->assertTrue($this->owner->is_active);
    }

    public function test_an_owner_can_be_removed_when_another_remains(): void
    {
        $this->member($this->organization, 'owner');
        $admin = $this->member($this->organization, 'admin');
        $this->actingAs($admin);

        $this->patch("/team/{$this->owner->id}", ['role' => 'viewer', 'is_active' => 1])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('viewer', $this->owner->refresh()->role);
    }

    public function test_an_inactive_owner_does_not_count_as_the_one_remaining(): void
    {
        $this->member($this->organization, 'owner')->forceFill(['is_active' => false])->save();
        $this->actingAs($this->member($this->organization, 'admin'));

        $this->patch("/team/{$this->owner->id}", ['role' => 'viewer', 'is_active' => 1])->assertSessionHasErrors('role');
    }

    public function test_someone_in_another_company_cannot_be_opened_or_changed(): void
    {
        $outsider = $this->member($this->tenant('Other Co'), 'engineer');
        $this->actingAs($this->owner);

        $this->get("/team/{$outsider->id}/access")->assertNotFound();
        $this->patch("/team/{$outsider->id}", ['role' => 'owner', 'is_active' => 1])->assertNotFound();
    }

    public function test_the_team_screens_need_team_permission(): void
    {
        $member = $this->member($this->organization, 'engineer');
        $this->actingAs($this->member($this->organization, 'pm'));

        $this->get('/team')->assertForbidden();
        $this->get("/team/{$member->id}/access")->assertForbidden();
        $this->patch("/team/{$member->id}", ['role' => 'owner', 'is_active' => 1])->assertForbidden();
    }

    public function test_a_change_is_audited_without_private_fields(): void
    {
        $member = $this->member($this->organization, 'engineer');
        $this->actingAs($this->owner);

        $this->patch("/team/{$member->id}", ['role' => 'pm', 'is_active' => 1])->assertRedirect();

        $log = AuditLog::where('action', 'team.access_changed')->firstOrFail();
        $this->assertSame('engineer', $log->old_values['role']);
        $this->assertSame('pm', $log->new_values['role']);
        $this->assertArrayNotHasKey('password', $log->new_values);
    }

    public function test_saving_with_no_change_is_not_audited(): void
    {
        $member = $this->member($this->organization, 'engineer');
        $this->actingAs($this->owner);

        $this->patch("/team/{$member->id}", ['role' => 'engineer', 'is_active' => 1])->assertRedirect();

        $this->assertSame(0, AuditLog::where('action', 'team.access_changed')->count());
        $this->assertNull($member->refresh()->access_changed_at);
    }

    public function test_a_person_on_a_custom_role_is_labelled_with_it(): void
    {
        $role = CustomRole::withoutGlobalScopes()->create(['organization_id' => $this->organization->id, 'name' => 'Checker', 'permissions' => ['dpr.view']]);
        $member = $this->member($this->organization, 'viewer');
        $member->forceFill(['custom_role_id' => $role->id])->save();

        $this->assertSame('Checker', $member->refresh()->roleLabel());
        $this->assertSame($role->key(), $member->roleKey());
    }

    public function test_project_limited_label_shows_on_the_team_list(): void
    {
        $project = $this->makeProject($this->organization);
        $member = $this->member($this->organization, 'pm');
        app(TeamAccessService::class)->applyProjects($member, true, [$project->id]);
        $this->actingAs($this->owner);

        $this->get('/team')->assertOk()->assertSee('1 project');
    }

    public function test_the_migration_can_be_rolled_back(): void
    {
        $this->artisan('migrate:rollback', ['--path' => 'database/migrations/2026_12_03_100001_create_access_tables.php'])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('custom_roles'));
        $this->assertFalse(Schema::hasColumn('users', 'custom_role_id'));

        $this->artisan('migrate', ['--path' => 'database/migrations/2026_12_03_100001_create_access_tables.php'])->assertSuccessful();

        $this->assertTrue(Schema::hasColumn('users', 'limited_to_projects'));
    }
}
