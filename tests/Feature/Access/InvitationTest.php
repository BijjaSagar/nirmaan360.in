<?php

namespace Tests\Feature\Access;

use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\Organization;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\BuildsTenant;
use Tests\TestCase;

class InvitationTest extends TestCase
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

    private function signOut(): void
    {
        Auth::forgetUser();
        $this->app['auth']->forgetGuards();
    }

    /** Invite and come back signed out, holding the link. */
    private function invite(array $overrides = [], ?User $by = null): string
    {
        $this->actingAs($by ?? $this->owner);

        $response = $this->post('/team', ['email' => 'ravi@example.com', 'role' => 'engineer', 'project_access' => 'all', ...$overrides]);
        $response->assertRedirect()->assertSessionHasNoErrors();
        $link = session('invite_link');
        $this->flushSession();
        $this->signOut();

        return $link;
    }

    private function accept(string $link, array $overrides = [])
    {
        return $this->post($link, ['name' => 'Ravi Patil', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password', ...$overrides]);
    }

    public function test_the_invitee_sets_their_own_name_and_password(): void
    {
        $link = $this->invite();

        $this->get($link)->assertOk()->assertSee('ravi@example.com');
        $this->accept($link, ['name' => 'Ravi Patil'])->assertRedirect('/dashboard');

        $user = User::where('email', 'ravi@example.com')->firstOrFail();
        $this->assertSame('Ravi Patil', $user->name);
        $this->assertSame('engineer', $user->role);
        $this->assertSame($this->organization->id, $user->organization_id);
        $this->assertTrue(Hash::check('a-long-password', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_link_works_only_once(): void
    {
        $link = $this->invite();

        $this->accept($link)->assertRedirect('/dashboard');
        $this->signOut();
        $this->flushSession();

        $this->get($link)->assertStatus(410);
        $this->accept($link)->assertStatus(410);
        $this->assertSame(1, User::where('email', 'ravi@example.com')->count());
    }

    public function test_an_unsigned_or_tampered_link_is_refused(): void
    {
        $link = $this->invite();
        $path = parse_url($link, PHP_URL_PATH);

        $this->get($path)->assertForbidden();
        $this->post($path, ['name' => 'x', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])->assertForbidden();
        $this->get($link.'x')->assertForbidden();
        $this->assertSame(0, User::where('email', 'ravi@example.com')->count());
    }

    public function test_a_validly_signed_link_with_an_unknown_token_is_closed(): void
    {
        $this->get(URL::temporarySignedRoute('invitations.show', now()->addDay(), ['token' => 'made-up']))->assertStatus(410);
    }

    public function test_the_link_expires(): void
    {
        $link = $this->invite();

        $this->travel(8)->days();

        $this->get($link)->assertForbidden();
        $this->accept($link)->assertForbidden();
        $this->assertSame(0, User::where('email', 'ravi@example.com')->count());
    }

    public function test_only_a_hash_of_the_token_is_stored_and_it_stays_out_of_the_audit_log(): void
    {
        $link = $this->invite();
        $token = basename(parse_url($link, PHP_URL_PATH));
        $invitation = TeamInvitation::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertArrayNotHasKey('token_hash', $invitation->toArray());

        $this->assertFalse(AuditLog::withoutGlobalScopes()->get()->contains(
            fn (AuditLog $log) => str_contains((string) json_encode($log->new_values), $invitation->token_hash)
        ));
    }

    public function test_a_cancelled_invitation_stops_working(): void
    {
        $link = $this->invite();
        $invitation = TeamInvitation::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->owner);
        $this->delete("/team/invitations/{$invitation->id}")->assertRedirect();
        $this->signOut();

        $this->get($link)->assertStatus(410);
    }

    public function test_a_new_link_kills_the_old_one(): void
    {
        $old = $this->invite();
        $invitation = TeamInvitation::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->owner);
        $this->post("/team/invitations/{$invitation->id}/reissue")->assertRedirect();
        $new = session('invite_link');
        $this->flushSession();
        $this->signOut();

        $this->assertNotSame($old, $new);
        $this->get($old)->assertStatus(410);
        $this->accept($new)->assertRedirect('/dashboard');
    }

    public function test_inviting_the_same_person_again_replaces_the_first_invitation(): void
    {
        $first = $this->invite();
        $second = $this->invite();

        $this->get($first)->assertStatus(410);
        $this->get($second)->assertOk();
        $this->assertSame(1, TeamInvitation::withoutGlobalScopes()->pending()->count());
    }

    public function test_the_plan_user_limit_stops_new_invitations(): void
    {
        $organization = $this->tenant('Small Co', 'free');
        $owner = $this->member($organization, 'owner');
        $this->member($organization);

        // Free allows 3: two people plus this one invitation fills it.
        $this->invite(['email' => 'third@example.com'], $owner);

        $this->actingAs($owner);
        $this->post('/team', ['email' => 'fourth@example.com', 'role' => 'engineer', 'project_access' => 'all'])->assertForbidden();
        $this->get('/team')->assertOk()->assertSee('Your plan user limit has been reached.');
        $this->assertSame(0, TeamInvitation::withoutGlobalScopes()->where('email', 'fourth@example.com')->count());
    }

    public function test_the_plan_user_limit_is_checked_again_on_acceptance(): void
    {
        $organization = $this->tenant('Small Co', 'free');
        $owner = $this->member($organization, 'owner');
        $link = $this->invite(['email' => 'late@example.com'], $owner);

        $this->member($organization);
        $this->member($organization);

        $this->accept($link)->assertStatus(410);
        $this->assertSame(0, User::where('email', 'late@example.com')->count());
    }

    public function test_someone_without_team_permission_cannot_invite(): void
    {
        $this->actingAs($this->member($this->organization, 'pm'));

        $this->post('/team', ['email' => 'x@example.com', 'role' => 'engineer', 'project_access' => 'all'])->assertForbidden();
    }

    public function test_an_invitation_cannot_give_more_than_the_inviter_holds(): void
    {
        $manager = $this->manager(['user.manage', 'dpr.view']);
        $this->actingAs($manager);

        foreach (['owner', 'admin', 'pm'] as $role) {
            $this->post('/team', ['email' => 'x@example.com', 'role' => $role, 'project_access' => 'all'])->assertSessionHasErrors('role');
        }

        $this->assertSame(0, TeamInvitation::withoutGlobalScopes()->count());
    }

    public function test_the_inviters_right_is_checked_again_on_acceptance(): void
    {
        $manager = $this->manager(['user.manage', 'dpr.view']);
        $held = CustomRole::withoutGlobalScopes()->findOrFail($manager->custom_role_id);
        $viewer = CustomRole::withoutGlobalScopes()->create(['organization_id' => $this->organization->id, 'name' => 'Reader', 'permissions' => ['dpr.view']]);

        $link = $this->invite(['role' => $viewer->key()], $manager);

        // The inviter loses the right before the link is used.
        $held->update(['permissions' => ['dpr.view']]);

        $this->accept($link)->assertStatus(410);
        $this->assertSame(0, User::where('email', 'ravi@example.com')->count());
    }

    public function test_a_deactivated_inviters_link_stops_working(): void
    {
        $admin = $this->member($this->organization, 'admin');
        $link = $this->invite([], $admin);

        $admin->forceFill(['is_active' => false])->save();

        $this->accept($link)->assertStatus(410);
    }

    public function test_another_companys_custom_role_cannot_be_used(): void
    {
        $foreign = CustomRole::withoutGlobalScopes()->create([
            'organization_id' => $this->tenant('Other Co')->id, 'name' => 'Foreign', 'permissions' => ['dpr.view'],
        ]);

        $this->actingAs($this->owner);
        $this->post('/team', ['email' => 'x@example.com', 'role' => $foreign->key(), 'project_access' => 'all'])->assertSessionHasErrors('role');
    }

    public function test_a_custom_role_and_chosen_projects_carry_over(): void
    {
        $project = $this->makeProject($this->organization);
        $this->makeProject($this->organization);
        $role = CustomRole::withoutGlobalScopes()->create(['organization_id' => $this->organization->id, 'name' => 'Checker', 'permissions' => ['dpr.view', 'project.view']]);

        $link = $this->invite(['role' => $role->key(), 'project_access' => 'chosen', 'project_ids' => [$project->id]]);
        $this->accept($link)->assertRedirect('/dashboard');

        $user = User::where('email', 'ravi@example.com')->firstOrFail();
        $this->assertSame($role->id, $user->custom_role_id);
        $this->assertTrue($user->isProjectLimited());
        $this->assertSame([$project->id], $user->assignedProjectIds()->pluck('project_id')->all());
    }

    public function test_choosing_projects_needs_at_least_one(): void
    {
        $this->actingAs($this->owner);

        $this->post('/team', ['email' => 'x@example.com', 'role' => 'engineer', 'project_access' => 'chosen'])->assertSessionHasErrors('project_ids');
    }

    public function test_another_companys_project_cannot_be_chosen(): void
    {
        $foreign = $this->makeProject($this->tenant('Other Co'));
        $this->actingAs($this->owner);

        $this->post('/team', ['email' => 'x@example.com', 'role' => 'engineer', 'project_access' => 'chosen', 'project_ids' => [$foreign->id]])
            ->assertSessionHasErrors('project_ids.0');
    }

    public function test_an_owner_invitation_is_never_limited(): void
    {
        $project = $this->makeProject($this->organization);

        $link = $this->invite(['role' => 'owner', 'project_access' => 'chosen', 'project_ids' => [$project->id]]);
        $this->accept($link)->assertRedirect('/dashboard');

        $this->assertFalse(User::where('email', 'ravi@example.com')->firstOrFail()->isProjectLimited());
    }

    public function test_an_email_with_an_account_cannot_be_invited_whatever_its_case(): void
    {
        User::factory()->create(['organization_id' => $this->organization->id, 'email' => 'taken@example.com']);
        $this->actingAs($this->owner);

        $this->post('/team', ['email' => 'TAKEN@example.com', 'role' => 'engineer', 'project_access' => 'all'])->assertSessionHasErrors('email');
    }

    public function test_an_email_registered_after_the_invitation_closes_it(): void
    {
        $link = $this->invite();
        User::factory()->create(['organization_id' => $this->organization->id, 'email' => 'ravi@example.com']);

        $this->accept($link)->assertStatus(410);
    }

    public function test_a_signed_in_visitor_cannot_accept(): void
    {
        $link = $this->invite();

        $this->actingAs($this->member($this->organization, 'pm'));

        $this->get($link)->assertOk()->assertDontSee('Accept invitation');
        $this->accept($link)->assertSessionHasErrors('email');
        $this->assertSame(0, User::where('email', 'ravi@example.com')->count());
    }

    public function test_a_weak_password_is_refused_and_the_link_survives(): void
    {
        $link = $this->invite();

        $this->accept($link, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->assertSame(0, User::where('email', 'ravi@example.com')->count());
        $this->get($link)->assertOk();
    }

    public function test_a_link_cannot_set_fields_it_should_not(): void
    {
        $link = $this->invite();

        $this->accept($link, ['email' => 'evil@example.com', 'role' => 'owner', 'organization_id' => 999, 'is_platform_admin' => 1])->assertRedirect('/dashboard');

        $user = User::where('email', 'ravi@example.com')->firstOrFail();
        $this->assertSame('engineer', $user->role);
        $this->assertSame($this->organization->id, $user->organization_id);
        $this->assertFalse((bool) $user->is_platform_admin);
        $this->assertSame(0, User::where('email', 'evil@example.com')->count());
    }

    public function test_another_companys_invitation_is_out_of_reach(): void
    {
        $this->invite();
        $invitation = TeamInvitation::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->member($this->tenant('Other Co'), 'owner'));

        $this->post("/team/invitations/{$invitation->id}/reissue")->assertNotFound();
        $this->delete("/team/invitations/{$invitation->id}")->assertNotFound();
        $this->get('/team')->assertDontSee('ravi@example.com');
    }

    public function test_email_is_only_reported_as_sent_when_a_real_mailer_took_it(): void
    {
        Notification::fake();
        config(['mail.default' => 'log']);

        $this->actingAs($this->owner);
        $this->post('/team', ['email' => 'a@example.com', 'role' => 'engineer', 'project_access' => 'all']);
        $this->assertFalse(session('invite_emailed'));
        Notification::assertNothingSent();

        config(['mail.default' => 'smtp']);
        $this->post('/team', ['email' => 'b@example.com', 'role' => 'engineer', 'project_access' => 'all']);
        $this->assertTrue(session('invite_emailed'));
        Notification::assertSentOnDemand(TeamInvite::class);
    }

    public function test_a_failed_send_is_not_reported_as_sent(): void
    {
        Notification::shouldReceive('route')->andThrow(new \RuntimeException('mail server down'));
        config(['mail.default' => 'smtp']);

        $this->actingAs($this->owner);
        $this->post('/team', ['email' => 'a@example.com', 'role' => 'engineer', 'project_access' => 'all'])->assertRedirect();

        $this->assertFalse(session('invite_emailed'));
        $this->assertNotNull(session('invite_link'));
    }

    public function test_acceptance_is_audited(): void
    {
        $this->accept($this->invite())->assertRedirect('/dashboard');

        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'invitation.created')->count());
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'invitation.accepted')->count());
    }

    /** Someone who can manage the team but holds only these permissions. */
    private function manager(array $permissions): User
    {
        $role = CustomRole::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id, 'name' => 'Manager '.count($permissions), 'permissions' => $permissions,
        ]);
        $user = $this->member($this->organization, 'viewer');
        $user->forceFill(['custom_role_id' => $role->id])->save();

        return $user;
    }
}
