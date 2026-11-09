<?php

namespace Tests\Feature\ContractorPortal;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Party;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Access\PermissionCatalog;
use App\Services\Access\TeamAccessService;
use App\Services\ContractorPortal\ContractorAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\BuildsTenant;
use Tests\TestCase;

/** How a contractor login is given, taken away, and kept apart from the team. */
class ContractorPortalAccessTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private User $manager;

    private User $engineer;

    private Party $party;

    private Party $vendor;

    private Party $foreignParty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->organization = $this->tenant('Sentinel Infra', 'business');
        $this->owner = $this->member($this->organization, 'owner');
        $this->manager = $this->member($this->organization, 'pm');
        $this->engineer = $this->member($this->organization, 'engineer');

        $this->party = Party::create(['organization_id' => $this->organization->id, 'type' => 'subcontractor', 'name' => 'Shree Formwork', 'email' => 'shree@example.com']);
        $this->vendor = Party::create(['organization_id' => $this->organization->id, 'type' => 'vendor', 'name' => 'Birla Traders']);
        $this->foreignParty = Party::create(['organization_id' => $this->tenant('Other Co')->id, 'type' => 'subcontractor', 'name' => 'Foreign Formwork']);
    }

    private function signOut(): void
    {
        Auth::forgetUser();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    /** Invite from the contractor's page and come back signed out, holding the link. */
    private function invite(?User $by = null, array $overrides = []): string
    {
        $this->actingAs($by ?? $this->manager);
        $this->post("/contractor-portal/access/{$this->party->id}/invitations", ['email' => 'ramesh@example.com', ...$overrides])->assertRedirect()->assertSessionHasNoErrors();
        $link = session('invite_link');
        $this->signOut();

        return $link;
    }

    private function accept(string $link)
    {
        return $this->post($link, ['name' => 'Ramesh Pawar', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password']);
    }

    private function contractorLogin(Party $party): User
    {
        return User::factory()->create(['organization_id' => $party->organization_id, 'role' => User::CONTRACTOR_ROLE, 'contractor_party_id' => $party->id, 'is_active' => true]);
    }

    public function test_the_contractors_page_leads_to_the_login_screen(): void
    {
        $this->actingAs($this->manager);

        $this->get("/contractor-control/contractors/{$this->party->id}")->assertOk()->assertSee('Contractor login')->assertSee("/contractor-portal/access/{$this->party->id}");
        $this->get("/contractor-portal/access/{$this->party->id}")->assertOk()->assertSee('Shree Formwork')->assertSee('shree@example.com')->assertSee('No login yet');
    }

    public function test_a_manager_invites_a_contractor_who_lands_on_the_portal(): void
    {
        $link = $this->invite(overrides: ['phone' => '9000000002']);

        $invitation = TeamInvitation::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(User::CONTRACTOR_ROLE, $invitation->role_key);
        $this->assertSame($this->party->id, (int) $invitation->contractor_party_id);
        $this->assertSame($this->manager->id, $invitation->invited_by);

        $this->get($link)->assertOk()->assertSee('ramesh@example.com')->assertSee('Contractor');
        $this->accept($link)->assertRedirect('/dashboard');

        $user = User::where('email', 'ramesh@example.com')->firstOrFail();
        $this->assertTrue($user->isContractor());
        $this->assertSame($this->party->id, (int) $user->contractor_party_id);
        $this->assertSame('9000000002', $user->phone);
        $this->assertFalse($user->limited_to_projects);
        $this->assertAuthenticatedAs($user);

        // The next request reads the person back from the database, as a browser's would.
        $this->signOut();
        $this->actingAs($user);
        $this->get('/dashboard')->assertRedirect('/contractor-portal');
        $this->get('/contractor-portal')->assertOk()->assertSee('Shree Formwork')->assertSee('No work orders yet');
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'invitation.accepted')->count());
    }

    public function test_a_contractor_login_takes_no_seat_on_the_plan(): void
    {
        // Owner, manager and engineer already fill a three-seat plan.
        config(['buildos.plans.business.max_users' => 3]);
        $this->assertFalse($this->organization->canAddUser());

        $this->actingAs($this->owner)->post('/team', ['email' => 'staff@example.com', 'role' => 'engineer', 'project_access' => 'all'])->assertForbidden();
        $this->signOut();

        $link = $this->invite();
        $this->accept($link)->assertRedirect('/dashboard');

        $this->assertSame(4, User::where('organization_id', $this->organization->id)->count());
        $this->assertFalse($this->organization->refresh()->canAddUser());
        $this->actingAs($this->owner)->get('/team')->assertOk()->assertSee('Your plan user limit has been reached');
    }

    public function test_someone_without_the_permission_cannot_manage_contractor_logins(): void
    {
        $this->actingAs($this->engineer);

        $this->get("/contractor-portal/access/{$this->party->id}")->assertForbidden();
        $this->post("/contractor-portal/access/{$this->party->id}/invitations", ['email' => 'ramesh@example.com'])->assertForbidden();

        // The staff screens share the portal's route prefix, which the contractor gate lets through: the permission alone keeps a contractor out.
        $this->actingAs(User::factory()->create(['organization_id' => $this->organization->id, 'role' => User::CONTRACTOR_ROLE, 'contractor_party_id' => $this->party->id, 'is_active' => true]));
        $this->get("/contractor-portal/access/{$this->party->id}")->assertForbidden();
        $this->post("/contractor-portal/access/{$this->party->id}/invitations", ['email' => 'self@example.com'])->assertForbidden();
        $this->assertSame(0, TeamInvitation::withoutGlobalScopes()->count());
    }

    public function test_a_party_in_another_company_cannot_be_invited(): void
    {
        $this->actingAs($this->manager);

        $this->get("/contractor-portal/access/{$this->foreignParty->id}")->assertNotFound();
        $this->post("/contractor-portal/access/{$this->foreignParty->id}/invitations", ['email' => 'ramesh@example.com'])->assertNotFound();

        try {
            app(ContractorAccessService::class)->invite($this->manager, $this->foreignParty, ['email' => 'ramesh@example.com']);
            $this->fail('A party of another company was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('party', $exception->errors());
        }

        $this->assertSame(0, TeamInvitation::withoutGlobalScopes()->count());
    }

    public function test_only_a_subcontractor_can_have_a_login(): void
    {
        $this->actingAs($this->manager);

        $this->get("/contractor-portal/access/{$this->vendor->id}")->assertNotFound();
        $this->from("/contractor-portal/access/{$this->party->id}")->post("/contractor-portal/access/{$this->vendor->id}/invitations", ['email' => 'ramesh@example.com'])->assertSessionHasErrors('party');
        $this->assertSame(0, TeamInvitation::withoutGlobalScopes()->count());
    }

    public function test_the_contractor_role_is_kept_off_the_team_screens(): void
    {
        $login = $this->contractorLogin($this->party);
        $this->actingAs($this->owner);

        $this->assertArrayNotHasKey('contractor', app(TeamAccessService::class)->roleOptions($this->owner));
        $this->from('/team')->post('/team', ['email' => 'x@example.com', 'role' => 'contractor', 'project_access' => 'all'])->assertSessionHasErrors('role');
        $this->from('/team')->patch("/team/{$this->engineer->id}", ['role' => 'contractor', 'is_active' => 1])->assertSessionHasErrors('role');
        $this->assertSame('engineer', $this->engineer->refresh()->role);

        $this->get('/team')->assertOk()->assertDontSee($login->email);
        $this->get("/team/{$login->id}/access")->assertNotFound();
        $this->patch("/team/{$login->id}", ['role' => 'pm', 'is_active' => 1])->assertNotFound();
        $this->delete("/team/{$login->id}/devices")->assertNotFound();
        $this->assertTrue($login->refresh()->isContractor());
        $this->assertFalse($login->hasPermission('project.view'));

        $this->get('/team/roles')->assertOk()->assertDontSee('contractor_portal.view');
        $this->from('/team/roles/create')->post('/team/roles', ['name' => 'Peeker', 'permissions' => ['contractor_portal.view']])->assertSessionHasErrors('permissions.0');

        $catalog = app(PermissionCatalog::class)->all();
        $this->assertNotContains('contractor_portal.view', $catalog);
        $this->assertContains('contractor_portal.manage', $catalog);
    }

    public function test_the_role_cannot_be_given_through_the_service_either(): void
    {
        $this->expectException(ValidationException::class);

        app(TeamAccessService::class)->update($this->owner, $this->engineer, 'contractor', true);
    }

    public function test_cancelling_an_invitation_kills_the_link(): void
    {
        $link = $this->invite();
        $invitation = TeamInvitation::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->manager);
        $this->get("/contractor-portal/access/{$this->party->id}")->assertOk()->assertSee('ramesh@example.com')->assertSee('New link');
        $this->delete("/contractor-portal/access/{$this->party->id}/invitations/{$invitation->id}")->assertRedirect();
        $this->signOut();

        $this->get($link)->assertStatus(410);
        $this->accept($link)->assertStatus(410);
        $this->assertSame(0, User::where('email', 'ramesh@example.com')->count());
    }

    public function test_a_new_link_replaces_the_old_one(): void
    {
        $old = $this->invite();
        $invitation = TeamInvitation::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->manager);
        $this->post("/contractor-portal/access/{$this->party->id}/invitations/{$invitation->id}/reissue")->assertRedirect();
        $new = session('invite_link');
        $this->signOut();

        $this->get($old)->assertStatus(410);
        $this->accept($new)->assertRedirect('/dashboard');
        $this->assertTrue(User::where('email', 'ramesh@example.com')->firstOrFail()->isContractor());
    }

    public function test_an_invitation_for_another_partys_link_is_not_found_from_this_page(): void
    {
        $this->invite();
        $invitation = TeamInvitation::withoutGlobalScopes()->firstOrFail();
        $another = Party::create(['organization_id' => $this->organization->id, 'type' => 'subcontractor', 'name' => 'Second Formwork']);

        $this->actingAs($this->manager);
        $this->post("/contractor-portal/access/{$another->id}/invitations/{$invitation->id}/reissue")->assertNotFound();
        $this->delete("/contractor-portal/access/{$another->id}/invitations/{$invitation->id}")->assertNotFound();
        $this->assertTrue($invitation->refresh()->isPending());
    }

    public function test_an_invitation_dies_with_its_party(): void
    {
        $link = $this->invite();

        Party::withoutGlobalScopes()->whereKey($this->party->id)->update(['deleted_at' => now()]);

        $this->accept($link)->assertStatus(410);
        $this->assertSame(0, User::where('email', 'ramesh@example.com')->count());
    }

    public function test_an_invitation_outlives_neither_the_inviters_permission(): void
    {
        $link = $this->invite();

        $this->actingAs($this->owner);
        app(TeamAccessService::class)->update($this->owner, $this->manager, 'engineer', true);
        $this->signOut();

        $this->accept($link)->assertStatus(410);
    }

    public function test_access_can_be_taken_away_and_restored_with_an_audit_trail(): void
    {
        $login = $this->contractorLogin($this->party);
        $login->createToken('Phone');

        $this->actingAs($this->manager);
        $this->get("/contractor-portal/access/{$this->party->id}")->assertOk()->assertSee($login->email)->assertSee('Take access away');
        $this->post("/contractor-portal/access/{$this->party->id}/logins/{$login->id}/revoke")->assertRedirect();

        $this->assertFalse($login->refresh()->is_active);
        $this->assertSame(0, $login->tokens()->count());
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'contractor_portal.access_revoked')->where('auditable_id', $login->id)->count());
        $this->get("/contractor-portal/access/{$this->party->id}")->assertOk()->assertSee('Restore access');

        $this->post("/contractor-portal/access/{$this->party->id}/logins/{$login->id}/restore")->assertRedirect();
        $this->assertTrue($login->refresh()->is_active);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'contractor_portal.access_restored')->count());
    }

    public function test_a_login_of_another_party_or_a_staff_member_cannot_be_touched_from_this_page(): void
    {
        $another = Party::create(['organization_id' => $this->organization->id, 'type' => 'subcontractor', 'name' => 'Second Formwork']);
        $login = $this->contractorLogin($another);

        $this->actingAs($this->manager);
        $this->post("/contractor-portal/access/{$this->party->id}/logins/{$login->id}/revoke")->assertNotFound();
        $this->post("/contractor-portal/access/{$this->party->id}/logins/{$this->engineer->id}/revoke")->assertNotFound();

        $this->assertTrue($login->refresh()->is_active);
        $this->assertTrue($this->engineer->refresh()->is_active);
    }

    public function test_another_company_cannot_touch_a_login(): void
    {
        $login = $this->contractorLogin($this->party);
        $stranger = $this->member($this->foreignParty->organization, 'owner');

        $this->actingAs($stranger);
        $this->get("/contractor-portal/access/{$this->party->id}")->assertNotFound();
        $this->post("/contractor-portal/access/{$this->party->id}/logins/{$login->id}/revoke")->assertNotFound();
        $this->assertTrue($login->refresh()->is_active);
    }

    public function test_the_migration_can_be_rolled_back(): void
    {
        $path = 'database/migrations/2026_12_29_100001_add_contractor_portal_columns.php';

        $this->artisan('migrate:rollback', ['--path' => $path])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('users', 'contractor_party_id'));
        $this->assertFalse(Schema::hasColumn('team_invitations', 'contractor_party_id'));

        $this->artisan('migrate', ['--path' => $path])->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('users', 'contractor_party_id'));
        $this->assertTrue(Schema::hasColumn('team_invitations', 'contractor_party_id'));
    }
}
