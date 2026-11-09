<?php

namespace Tests\Feature\Access;

use App\Models\CustomRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access\PermissionCatalog;
use App\Services\ApprovalService;
use App\Services\MeasurementService;
use App\Support\Modules;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\BuildsTenant;
use Tests\TestCase;

class CustomRoleTest extends TestCase
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

    private function role(array $permissions, array $attributes = [], ?Organization $organization = null): CustomRole
    {
        return CustomRole::withoutGlobalScopes()->create([
            'organization_id' => ($organization ?? $this->organization)->id,
            'name' => 'Role '.uniqid(),
            'permissions' => $permissions,
            ...$attributes,
        ]);
    }

    private function holding(CustomRole $role): User
    {
        $user = $this->member($this->organization, $role->baseRole());
        $user->forceFill(['custom_role_id' => $role->id])->save();

        return $user;
    }

    public function test_a_custom_role_grants_exactly_its_permissions(): void
    {
        $user = $this->holding($this->role(['dpr.view', 'project.view']));

        $this->assertTrue($user->can('dpr.view'));
        $this->assertTrue($user->can('project.view'));
        $this->assertFalse($user->can('dpr.create'));
        $this->assertFalse($user->can('boq.view'));
    }

    public function test_a_custom_role_replaces_the_base_role_rather_than_adding_to_it(): void
    {
        $user = $this->holding($this->role(['dpr.view'], ['approval_role' => 'pm']));

        $this->assertSame('pm', $user->role);
        $this->assertFalse($user->can('boq.manage'), 'The base role must not add its own permissions.');
    }

    public function test_a_custom_role_opens_the_routes_it_grants(): void
    {
        $project = $this->makeProject($this->organization);
        $this->actingAs($this->holding($this->role(['project.view', 'boq.view'])));

        $this->get("/projects/{$project->id}/boq")->assertOk();
        $this->get("/projects/{$project->id}/dprs")->assertForbidden();
    }

    public function test_built_in_roles_are_unchanged(): void
    {
        $engineer = $this->member($this->organization, 'engineer');
        $pm = $this->member($this->organization, 'pm');

        $this->assertTrue($engineer->can('dpr.create'));
        $this->assertFalse($engineer->can('ra_bill.view'));
        $this->assertTrue($pm->can('project.manage'));
        $this->assertTrue($this->owner->can('anything.at_all'));
        $this->assertSame('Site Engineer', $engineer->roleLabel());
    }

    public function test_a_custom_role_that_has_gone_grants_nothing(): void
    {
        $user = $this->holding($this->role(['dpr.view'], ['approval_role' => 'pm']));

        // The foreign key stops this happening, so the missing row is simulated.
        $user->setRelation('customRole', null);

        $this->assertSame([], $user->grantedPermissions());
        $this->assertFalse($user->hasPermission('dpr.view'));
        $this->assertFalse($user->hasPermission('boq.manage'), 'It must not fall back to the base role.');
    }

    public function test_the_database_will_not_delete_a_role_in_use(): void
    {
        $user = $this->holding($this->role(['dpr.view']));

        $this->expectException(QueryException::class);

        DB::table('custom_roles')->where('id', $user->custom_role_id)->delete();
    }

    public function test_a_role_from_another_company_grants_nothing_even_if_set_directly(): void
    {
        $foreign = $this->role(['dpr.view'], [], $this->tenant('Other Co'));
        $user = $this->member($this->organization, 'viewer');
        $user->forceFill(['custom_role_id' => $foreign->id])->save();

        $this->assertSame([], $user->refresh()->grantedPermissions());
        $this->assertFalse($user->can('dpr.view'));
    }

    public function test_the_list_includes_module_permissions(): void
    {
        config(['modules.crm_demo' => ['permissions' => ['pm' => ['crm_demo.view', 'crm_demo.export']]]]);
        Modules::apply();

        $all = app(PermissionCatalog::class)->all();

        $this->assertContains('crm_demo.view', $all);
        $this->assertContains('crm_demo.export', $all);
        $this->assertContains('measurement.approve', $all, 'Approve permissions are checked in a service, not on a route.');
        $this->assertContains('boq.manage', $all, 'Found from the routes.');
        $this->assertNotContains('*', $all);
    }

    public function test_the_form_lists_module_permissions(): void
    {
        config(['modules.crm_demo' => ['permissions' => ['pm' => ['crm_demo.view']]]]);
        Modules::apply();

        $this->actingAs($this->owner);

        $this->get('/team/roles/create')->assertOk()->assertSee('crm_demo.view');
    }

    public function test_an_owner_can_create_edit_and_delete_a_role(): void
    {
        $this->actingAs($this->owner);

        $this->post('/team/roles', ['name' => 'Checker', 'permissions' => ['dpr.view']])->assertRedirect('/team/roles')->assertSessionHasNoErrors();
        $role = CustomRole::where('name', 'Checker')->firstOrFail();
        $this->assertSame($this->organization->id, $role->organization_id);

        $this->patch("/team/roles/{$role->id}", ['name' => 'Senior checker', 'permissions' => ['dpr.view', 'boq.view']])->assertRedirect('/team/roles');
        $this->assertSame(['dpr.view', 'boq.view'], $role->refresh()->permissions);

        $this->delete("/team/roles/{$role->id}")->assertRedirect('/team/roles');
        $this->assertNull(CustomRole::find($role->id));
    }

    public function test_a_star_or_unknown_permission_is_refused(): void
    {
        $this->actingAs($this->owner);

        foreach (['*', 'made.up', 'not-a-permission'] as $bad) {
            $this->post('/team/roles', ['name' => 'Bad', 'permissions' => [$bad]])->assertSessionHasErrors('permissions.0');
        }

        $this->assertSame(0, CustomRole::count());
    }

    public function test_a_role_needs_at_least_one_permission(): void
    {
        $this->actingAs($this->owner);

        $this->post('/team/roles', ['name' => 'Empty', 'permissions' => []])->assertSessionHasErrors('permissions');
    }

    public function test_a_built_in_name_cannot_be_reused(): void
    {
        $this->actingAs($this->owner);

        $this->post('/team/roles', ['name' => ' owner ', 'permissions' => ['dpr.view']])->assertSessionHasErrors('name');
    }

    public function test_a_role_cannot_exceed_what_its_creator_holds(): void
    {
        $this->actingAs($this->holding($this->role(['user.manage', 'dpr.view'])));

        $this->post('/team/roles', ['name' => 'Too big', 'permissions' => ['dpr.view', 'ra_bill.approve']])->assertSessionHasErrors('permissions.1');
        $this->post('/team/roles', ['name' => 'Wider', 'permissions' => ['dpr.*']])->assertSessionHasErrors('permissions.0');

        $this->assertNull(CustomRole::where('name', 'Too big')->first());
        $this->assertNull(CustomRole::where('name', 'Wider')->first());
    }

    public function test_a_person_cannot_raise_the_role_they_hold(): void
    {
        $held = $this->role(['user.manage', 'dpr.view']);
        $this->actingAs($this->holding($held));

        $this->patch("/team/roles/{$held->id}", ['name' => $held->name, 'permissions' => ['user.manage', 'dpr.view', 'ra_bill.approve']])
            ->assertSessionHasErrors('permissions.2');

        $this->assertSame(['user.manage', 'dpr.view'], $held->refresh()->permissions);
    }

    public function test_a_role_above_the_editor_cannot_be_edited_or_deleted(): void
    {
        $above = $this->role(['ra_bill.approve', 'dpr.view']);
        $this->actingAs($this->holding($this->role(['user.manage', 'dpr.view'])));

        $this->get("/team/roles/{$above->id}/edit")->assertForbidden();
        $this->patch("/team/roles/{$above->id}", ['name' => 'Lowered', 'permissions' => ['dpr.view']])->assertForbidden();
        $this->delete("/team/roles/{$above->id}")->assertForbidden();

        $this->assertSame(['ra_bill.approve', 'dpr.view'], $above->refresh()->permissions);
    }

    public function test_a_role_in_use_cannot_be_deleted(): void
    {
        $role = $this->role(['dpr.view']);
        $this->holding($role);
        $this->actingAs($this->owner);

        $this->delete("/team/roles/{$role->id}")->assertSessionHasErrors('role');
        $this->assertNotNull(CustomRole::find($role->id));
    }

    public function test_assigning_cannot_exceed_what_the_assigner_holds(): void
    {
        $manager = $this->holding($this->role(['user.manage', 'dpr.view']));
        $above = $this->role(['ra_bill.approve']);
        $target = $this->member($this->organization, 'viewer');
        $target->forceFill(['custom_role_id' => $this->role(['dpr.view'])->id])->save();
        $this->actingAs($manager);

        foreach (['owner', 'admin', 'pm', $above->key()] as $key) {
            $this->patch("/team/{$target->id}", ['role' => $key, 'is_active' => 1])->assertSessionHasErrors('role');
        }

        $this->assertSame('viewer', $target->refresh()->role);
    }

    public function test_a_manager_cannot_touch_someone_above_them(): void
    {
        $this->actingAs($this->holding($this->role(['user.manage', 'dpr.view'])));

        $this->patch("/team/{$this->owner->id}", ['role' => 'viewer', 'is_active' => 0])->assertForbidden();
        $this->get("/team/{$this->owner->id}/access")->assertForbidden();

        $this->assertSame('owner', $this->owner->refresh()->role);
        $this->assertTrue($this->owner->is_active);
    }

    public function test_the_role_list_offers_only_what_the_assigner_may_give(): void
    {
        $within = $this->role(['dpr.view'], ['name' => 'Within']);
        $this->role(['ra_bill.approve'], ['name' => 'Beyond']);
        $this->actingAs($this->holding($this->role(['user.manage', 'dpr.view'])));

        $this->get('/team')->assertOk()->assertSee($within->key())->assertSee('Within')->assertDontSee('Beyond');
    }

    public function test_assigning_a_custom_role_sets_its_base_and_can_be_undone(): void
    {
        $role = $this->role(['measurement.approve', 'dpr.view'], ['approval_role' => 'qs']);
        $target = $this->member($this->organization, 'engineer');
        $this->actingAs($this->owner);

        $this->patch("/team/{$target->id}", ['role' => $role->key(), 'is_active' => 1])->assertRedirect();
        $target->refresh();
        $this->assertSame($role->id, $target->custom_role_id);
        $this->assertSame('qs', $target->role);

        $this->patch("/team/{$target->id}", ['role' => 'engineer', 'is_active' => 1])->assertRedirect();
        $target->refresh();
        $this->assertNull($target->custom_role_id);
        $this->assertTrue($target->can('dpr.create'));
    }

    public function test_a_custom_role_can_approve_at_its_level(): void
    {
        $project = $this->makeProject($this->organization);
        $entry = $this->draftEntry($project, $this->makeBoqItem($project), $this->owner);
        $approval = app(MeasurementService::class)->submit($entry, $this->owner);

        $checker = $this->holding($this->role(['measurement.approve'], ['approval_role' => 'qs']));
        $noRight = $this->holding($this->role(['measurement.view'], ['approval_role' => 'qs']));

        $this->assertTrue(app(ApprovalService::class)->canAct($approval, $checker));
        $this->assertFalse(app(ApprovalService::class)->canAct($approval, $noRight));
        $this->assertFalse($checker->isApprovalOverride());
    }

    public function test_editing_the_approval_level_moves_the_people_on_the_role(): void
    {
        $role = $this->role(['dpr.view'], ['approval_role' => 'qs']);
        $holder = $this->holding($role);
        $this->actingAs($this->owner);

        $this->patch("/team/roles/{$role->id}", ['name' => $role->name, 'approval_role' => 'pm', 'permissions' => ['dpr.view']])->assertRedirect();

        $this->assertSame('pm', $holder->refresh()->role);
    }

    public function test_owner_cannot_be_chosen_as_an_approval_level(): void
    {
        $this->actingAs($this->owner);

        foreach (['owner', 'admin', 'client'] as $level) {
            $this->post('/team/roles', ['name' => 'Bad', 'approval_role' => $level, 'permissions' => ['dpr.view']])->assertSessionHasErrors('approval_role');
        }
    }

    public function test_no_company_can_see_or_use_another_companys_roles(): void
    {
        $foreign = $this->role(['dpr.view'], ['name' => 'Foreign role'], $this->tenant('Other Co'));
        $target = $this->member($this->organization, 'viewer');
        $this->actingAs($this->owner);

        $this->get('/team/roles')->assertOk()->assertDontSee('Foreign role');
        $this->get('/team')->assertDontSee('Foreign role');
        $this->get("/team/roles/{$foreign->id}/edit")->assertNotFound();
        $this->patch("/team/roles/{$foreign->id}", ['name' => 'Taken', 'permissions' => ['dpr.view']])->assertNotFound();
        $this->delete("/team/roles/{$foreign->id}")->assertNotFound();

        $this->patch("/team/{$target->id}", ['role' => $foreign->key(), 'is_active' => 1])->assertSessionHasErrors('role');
        $this->assertNull($target->refresh()->custom_role_id);
        $this->assertSame('Foreign role', $foreign->refresh()->name);
    }

    public function test_the_same_name_is_allowed_in_different_companies_only(): void
    {
        $this->role(['dpr.view'], ['name' => 'Checker'], $this->tenant('Other Co'));
        $this->actingAs($this->owner);

        $this->post('/team/roles', ['name' => 'Checker', 'permissions' => ['dpr.view']])->assertSessionHasNoErrors();
        $this->post('/team/roles', ['name' => 'Checker', 'permissions' => ['dpr.view']])->assertSessionHasErrors('name');
    }

    public function test_roles_need_team_permission(): void
    {
        $role = $this->role(['dpr.view']);
        $this->actingAs($this->member($this->organization, 'pm'));

        $this->get('/team/roles')->assertForbidden();
        $this->post('/team/roles', ['name' => 'X', 'permissions' => ['dpr.view']])->assertForbidden();
        $this->get("/team/roles/{$role->id}/edit")->assertForbidden();
        $this->delete("/team/roles/{$role->id}")->assertForbidden();
    }
}
