<?php

namespace Tests\Feature\Sso;

use App\Models\Organization;
use App\Models\SsoIdentity;
use App\Models\User;
use App\Services\Sso\SsoGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsTenant;
use Tests\TestCase;

class SsoTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private FakeSsoGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->gateway = new FakeSsoGateway;
        $this->app->instance(SsoGateway::class, $this->gateway);
    }

    private function person(string $plan = 'business', array $attributes = []): User
    {
        return User::factory()->create([
            'organization_id' => $this->tenant('Sso Co '.uniqid(), $plan)->id,
            'role' => 'engineer',
            'is_active' => true,
            'email' => 'meera@buildco.test',
            ...$attributes,
        ]);
    }

    public function test_a_known_user_with_a_verified_email_signs_in(): void
    {
        $user = $this->person();
        $this->gateway->willReturn('google', 'Meera@BuildCo.test');

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $identity = SsoIdentity::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($user->id, $identity->user_id);
        $this->assertSame($user->organization_id, $identity->organization_id);
        $this->assertSame('google', $identity->provider);
        $this->assertNotNull($user->refresh()->last_login_at);
    }

    public function test_it_never_creates_a_user_or_a_company(): void
    {
        $this->person();
        $users = User::count();
        $organizations = Organization::count();
        $this->gateway->willReturn('google', 'stranger@elsewhere.test');

        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame($users, User::count());
        $this->assertSame($organizations, Organization::count());
        $this->assertSame(0, SsoIdentity::withoutGlobalScopes()->count());
    }

    public function test_an_email_the_provider_did_not_verify_is_never_matched(): void
    {
        $this->person();
        $this->gateway->willReturn('microsoft', 'meera@buildco.test', 'ms-1', verified: false);

        $this->get('/auth/microsoft/callback')->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(0, SsoIdentity::withoutGlobalScopes()->count());
    }

    public function test_plans_without_single_sign_on_are_turned_away(): void
    {
        $this->person('contractor');
        $this->gateway->willReturn('google', 'meera@buildco.test');

        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString('plan', session('errors')->first('email'));
    }

    public function test_an_expired_trial_loses_single_sign_on_with_the_plan(): void
    {
        $user = $this->person();
        Organization::whereKey($user->organization_id)->update(['plan_status' => 'trial', 'trial_ends_at' => now()->subDay()]);
        $this->gateway->willReturn('google', 'meera@buildco.test');

        $this->get('/auth/google/callback')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_deactivated_user_cannot_sign_in(): void
    {
        $this->person('business', ['is_active' => false]);
        $this->gateway->willReturn('google', 'meera@buildco.test');

        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_once_linked_the_provider_account_id_decides_not_the_email(): void
    {
        $user = $this->person();
        $this->gateway->willReturn('google', 'meera@buildco.test', 'google-sub-A');
        $this->get('/auth/google/callback')->assertRedirect('/dashboard');
        $this->post('/logout');

        // Same person, new email at Google: the account id still matches.
        $this->gateway->willReturn('google', 'meera.new@buildco.test', 'google-sub-A');
        $this->get('/auth/google/callback')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');

        // Someone else who now owns the old email address at Google cannot take the account over.
        $this->gateway->willReturn('google', 'meera@buildco.test', 'google-sub-B');
        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(1, SsoIdentity::withoutGlobalScopes()->count());
    }

    public function test_the_same_person_can_link_both_providers(): void
    {
        $this->person();
        $this->gateway->willReturn('google', 'meera@buildco.test', 'g-1');
        $this->get('/auth/google/callback')->assertRedirect('/dashboard');
        $this->post('/logout');
        $this->gateway->willReturn('microsoft', 'meera@buildco.test', 'm-1');
        $this->get('/auth/microsoft/callback')->assertRedirect('/dashboard');

        $this->assertSame(2, SsoIdentity::withoutGlobalScopes()->count());
    }

    public function test_a_provider_that_is_not_connected_has_no_button_and_no_route(): void
    {
        $this->gateway->live = [];

        $this->get('/login')->assertOk()->assertDontSee('Or continue with');
        $this->get('/auth/google/redirect')->assertNotFound();
        $this->get('/auth/microsoft/callback')->assertNotFound();
        $this->get('/auth/yahoo/redirect')->assertNotFound();
    }

    public function test_a_connected_provider_shows_a_button_that_sends_the_person_to_it(): void
    {
        $this->gateway->live = ['google'];

        $this->get('/login')->assertOk()->assertSee('Or continue with')->assertSee('/auth/google/redirect', false)->assertDontSee('/auth/microsoft/redirect', false);
        $this->get('/auth/google/redirect')->assertRedirect('https://provider.test/google');
    }

    public function test_cancelling_at_the_provider_returns_to_sign_in(): void
    {
        $this->get('/auth/google/callback?error=access_denied')->assertRedirect('/login')->assertSessionHasErrors('email');
    }

    public function test_someone_already_signed_in_is_sent_to_the_dashboard(): void
    {
        $this->actingAs($this->person());

        $this->get('/auth/google/redirect')->assertRedirect('/dashboard');
    }

    public function test_the_settings_page_says_not_connected_until_keys_are_saved(): void
    {
        $this->gateway->live = [];
        $owner = $this->person('business', ['role' => 'owner']);

        $this->actingAs($owner)->get('/settings/sso')->assertOk()->assertSee('Not connected')->assertDontSee('Connected<', false);

        $this->gateway->live = ['google'];
        $this->actingAs($owner)->get('/settings/sso')->assertOk()->assertSee('Connected');
    }

    public function test_the_settings_page_needs_company_settings_permission_and_shows_the_plan_note(): void
    {
        $this->actingAs($this->person('business'))->get('/settings/sso')->assertForbidden();

        $contractor = $this->person('contractor', ['role' => 'owner', 'email' => 'owner@small.test']);
        $this->actingAs($contractor)->get('/settings/sso')->assertOk()->assertSee('Business and Enterprise');
    }

    public function test_menu_shows_the_page_only_on_plans_with_single_sign_on(): void
    {
        $this->actingAs($this->person('business', ['role' => 'owner']))->get('/dashboard')->assertSee('Single sign-on');
        $this->actingAs($this->person('contractor', ['role' => 'owner', 'email' => 'o@small.test']))->get('/dashboard')->assertDontSee('Single sign-on');
    }
}
