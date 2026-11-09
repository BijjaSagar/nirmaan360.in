<?php

namespace Tests\Feature\Access;

use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\BuildsTenant;
use Tests\TestCase;

class DeviceTest extends TestCase
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

    public function test_a_person_sees_their_own_devices_and_never_a_token(): void
    {
        $user = $this->member($this->organization, 'engineer');
        $issued = $user->createToken('Ravi Pixel');
        $this->member($this->organization, 'engineer')->createToken('Someone Else Phone');

        $this->actingAs($user);

        $response = $this->get('/devices')->assertOk()->assertSee('Ravi Pixel')->assertDontSee('Someone Else Phone');
        $response->assertDontSee($issued->plainTextToken);
        $response->assertDontSee($issued->accessToken->token);
    }

    public function test_an_empty_list_loads(): void
    {
        $this->actingAs($this->owner);

        $this->get('/devices')->assertOk()->assertSee('No signed-in devices');
    }

    public function test_the_menu_links_to_my_devices(): void
    {
        $this->actingAs($this->member($this->organization, 'engineer'));

        $this->get('/dashboard')->assertOk()->assertSee('My devices')->assertSee(route('devices.index'));
    }

    public function test_a_person_can_revoke_their_own_device_and_it_stops_working(): void
    {
        $user = $this->member($this->organization, 'engineer');
        $plain = $user->createToken('Phone')->plainTextToken;
        $id = PersonalAccessToken::where('tokenable_id', $user->id)->value('id');

        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])->assertOk();
        Auth::forgetUser();
        $this->app['auth']->forgetGuards();

        $this->actingAs($user);
        $this->delete("/devices/{$id}")->assertRedirect();
        $this->assertNull(PersonalAccessToken::find($id));

        Auth::forgetUser();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])->assertUnauthorized();
    }

    public function test_a_person_cannot_revoke_someone_elses_device_through_their_own_route(): void
    {
        $victim = $this->member($this->organization, 'pm');
        $victim->createToken('Victim Phone');
        $id = PersonalAccessToken::where('tokenable_id', $victim->id)->value('id');

        $this->actingAs($this->member($this->organization, 'engineer'));

        $this->delete("/devices/{$id}")->assertNotFound();
        $this->assertNotNull(PersonalAccessToken::find($id));
    }

    public function test_revoke_all_signs_out_only_that_person(): void
    {
        $user = $this->member($this->organization, 'engineer');
        $user->createToken('One');
        $user->createToken('Two');
        $other = $this->member($this->organization, 'engineer');
        $other->createToken('Other');

        $this->actingAs($user);
        $this->delete('/devices')->assertRedirect();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_a_manager_sees_and_revokes_a_members_devices(): void
    {
        $member = $this->member($this->organization, 'engineer');
        $member->createToken('Member Phone');
        $id = $member->tokens()->value('id');
        $this->actingAs($this->owner);

        $this->get("/team/{$member->id}/access")->assertOk()->assertSee('Member Phone');
        $this->delete("/team/{$member->id}/devices/{$id}")->assertRedirect();

        $this->assertSame(0, $member->tokens()->count());
        $this->assertSame(1, AuditLog::where('action', 'device.revoked')->count());
    }

    public function test_a_token_can_only_be_revoked_under_its_owner(): void
    {
        $member = $this->member($this->organization, 'engineer');
        $other = $this->member($this->organization, 'engineer');
        $other->createToken('Other Phone');
        $id = $other->tokens()->value('id');
        $this->actingAs($this->owner);

        $this->delete("/team/{$member->id}/devices/{$id}")->assertNotFound();

        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_a_manager_can_revoke_all_of_a_members_devices(): void
    {
        $member = $this->member($this->organization, 'engineer');
        $member->createToken('One');
        $member->createToken('Two');
        $this->actingAs($this->owner);

        $this->delete("/team/{$member->id}/devices")->assertRedirect();

        $this->assertSame(0, $member->tokens()->count());
        $this->assertSame(1, AuditLog::where('action', 'device.revoked_all')->count());
    }

    public function test_another_companys_devices_are_out_of_reach(): void
    {
        $outsider = $this->member($this->tenant('Other Co'), 'engineer');
        $outsider->createToken('Outside Phone');
        $id = $outsider->tokens()->value('id');
        $this->actingAs($this->owner);

        $this->get("/team/{$outsider->id}/access")->assertNotFound();
        $this->delete("/team/{$outsider->id}/devices/{$id}")->assertNotFound();
        $this->delete("/team/{$outsider->id}/devices")->assertNotFound();

        $this->assertSame(1, $outsider->tokens()->count());
    }

    public function test_managing_devices_needs_team_permission(): void
    {
        $member = $this->member($this->organization, 'engineer');
        $member->createToken('Phone');
        $id = $member->tokens()->value('id');
        $this->actingAs($this->member($this->organization, 'pm'));

        $this->delete("/team/{$member->id}/devices/{$id}")->assertForbidden();
        $this->delete("/team/{$member->id}/devices")->assertForbidden();

        $this->assertSame(1, $member->tokens()->count());
    }

    public function test_a_manager_cannot_sign_out_someone_above_them(): void
    {
        $this->owner->createToken('Owner Phone');
        $role = CustomRole::withoutGlobalScopes()->create(['organization_id' => $this->organization->id, 'name' => 'Clerk', 'permissions' => ['user.manage']]);
        $manager = $this->member($this->organization, 'viewer');
        $manager->forceFill(['custom_role_id' => $role->id])->save();
        $this->actingAs($manager);

        $this->delete("/team/{$this->owner->id}/devices")->assertForbidden();

        $this->assertSame(1, $this->owner->tokens()->count());
    }

    public function test_deactivating_a_member_revokes_every_device(): void
    {
        $member = $this->member($this->organization, 'engineer');
        $plain = $member->createToken('Phone')->plainTextToken;
        $member->createToken('Tablet');
        $this->actingAs($this->owner);

        $this->patch("/team/{$member->id}", ['role' => 'engineer'])->assertRedirect();

        $this->assertFalse($member->refresh()->is_active);
        $this->assertSame(0, $member->tokens()->count());

        Auth::forgetUser();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])->assertUnauthorized();
    }

    public function test_saving_an_active_member_leaves_devices_signed_in(): void
    {
        $member = $this->member($this->organization, 'engineer');
        $member->createToken('Phone');
        $this->actingAs($this->owner);

        $this->patch("/team/{$member->id}", ['role' => 'pm', 'is_active' => 1])->assertRedirect();

        $this->assertSame(1, $member->tokens()->count());
    }
}
