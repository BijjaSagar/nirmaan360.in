<?php

namespace Tests\Feature\Access;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access\SignupService;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use Tests\BuildsTenant;
use Tests\TestCase;

/** The web sign-up form, now built on SignupService. */
class SignupWebTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function register(array $overrides = []): TestResponse
    {
        return $this->post('/register', [
            'company' => 'Deccan Builders',
            'name' => 'Priya Rane',
            'email' => 'priya@example.com',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
            ...$overrides,
        ]);
    }

    public function test_the_email_is_stored_trimmed_and_in_lower_case(): void
    {
        $this->register(['email' => ' Priya.Rane@Example.COM '])->assertRedirect('/dashboard')
            ->assertSessionHas('success', 'Welcome! Your 30-day trial has started.');

        $this->assertSame(['priya.rane@example.com'], User::pluck('email')->all());
        $this->assertAuthenticatedAs(User::sole());
    }

    public function test_an_email_taken_in_another_letter_case_is_refused(): void
    {
        $this->member($this->tenant())->forceFill(['email' => 'Priya@Example.com'])->save();

        $this->register(['email' => 'priya@example.com'])->assertSessionHasErrors(['email' => 'This email already has an account.']);
        $this->register(['email' => 'PRIYA@EXAMPLE.COM'])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(1, User::count());
        $this->assertFalse(Organization::where('name', 'Deccan Builders')->exists());
    }

    public function test_a_mobile_number_already_in_use_gets_its_own_message(): void
    {
        $this->member($this->tenant())->forceFill(['phone' => '9876543210'])->save();

        $this->register(['phone' => '9876543210'])->assertSessionHasErrors(['phone' => 'This mobile number already has an account.']);
        $this->assertGuest();
    }

    /** The owner's report: a stranger typed a platform admin's email in capitals and got the admin screens. */
    public function test_a_platform_admin_email_is_refused_in_any_letter_case(): void
    {
        config(['buildos.platform_admins' => ['ops@nirmaan360.test', 'boss@nirmaan360.test']]);
        $admin = $this->member($this->tenant('Platform Co'), 'owner');
        $admin->forceFill(['email' => 'ops@nirmaan360.test'])->save();

        $this->register(['email' => 'OPS@Nirmaan360.test', 'company' => 'Stranger Co'])
            ->assertSessionHasErrors(['email' => 'This email already has an account.']);
        $this->register(['email' => 'Boss@NIRMAAN360.test', 'company' => 'Stranger Co'])
            ->assertSessionHasErrors(['email' => 'This email already has an account.']);

        $this->assertGuest();
        $this->assertFalse(Organization::where('name', 'Stranger Co')->exists());
        $this->assertSame([$admin->id], User::all()->filter->isPlatformAdmin()->pluck('id')->all());

        // The stranger's password does not open the admin's own account in another letter case either.
        $this->post('/login', ['email' => 'OPS@Nirmaan360.test', 'password' => 'a-long-password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->get('/admin/admins')->assertRedirect('/login');
    }

    public function test_the_sign_up_is_audited_as_web(): void
    {
        $this->register(['plan' => 'business'])->assertRedirect('/dashboard');
        $user = User::sole();

        $log = AuditLog::withoutGlobalScopes()->where('action', 'account.signed_up')->sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($user->organization_id, $log->organization_id);
        $this->assertSame(['method' => 'web', 'plan' => 'business'], $log->new_values);
    }

    public function test_the_analytics_sign_up_still_says_email(): void
    {
        app(PlatformSettings::class)->set(['ga4_measurement_id' => 'G-AB12CD34EF']);

        $this->register(['email' => 'Priya@Example.com'])->assertRedirect('/dashboard');

        $this->assertSame([['name' => 'sign_up', 'params' => ['method' => 'email', 'plan' => 'contractor']]], session('analytics.events'));
    }

    public function test_the_visitors_language_is_kept_on_the_account(): void
    {
        $this->get('/locale/mr')->assertRedirect();

        $this->register()->assertRedirect('/dashboard');

        $this->assertSame('mr', User::sole()->locale);
    }

    /** Only the field app starts on the free plan. The website still starts the default plan's trial. */
    public function test_the_website_still_starts_a_trial_of_the_default_plan(): void
    {
        $this->freezeSecond();

        $this->get('/register')->assertOk()->assertSee('Plan: Contractor. No card needed. After the trial you move to the free plan.');
        $this->register()->assertRedirect('/dashboard')->assertSessionHas('success', 'Welcome! Your 30-day trial has started.');

        $organization = User::sole()->organization;
        $this->assertSame(SignupService::DEFAULT_PLAN, $organization->plan);
        $this->assertSame('trial', $organization->plan_status);
        $this->assertTrue($organization->trial_ends_at->equalTo(now()->addDays(config('buildos.trial_days'))));
    }

    public function test_the_website_honours_the_plan_in_the_address(): void
    {
        $this->freezeSecond();

        $this->get('/register?plan=business')->assertOk()
            ->assertSee('Plan: Business. No card needed. After the trial you move to the free plan.')
            ->assertSee('name="plan" value="business"', false);
        $this->register(['plan' => 'business'])->assertRedirect('/dashboard');

        $organization = User::sole()->organization;
        $this->assertSame('business', $organization->plan);
        $this->assertSame('trial', $organization->plan_status);
        $this->assertTrue($organization->trial_ends_at->equalTo(now()->addDays(config('buildos.trial_days'))));

        // The free plan, chosen on the website, has no trial.
        auth()->logout();
        $this->get('/register?plan=free')->assertOk()->assertSee('Free plan: 1 project and 3 users.');
        $this->register(['plan' => 'free', 'email' => 'second@example.com', 'company' => 'Second Co'])->assertRedirect('/dashboard');
        $free = User::where('email', 'second@example.com')->sole()->organization;
        $this->assertSame(['free', 'active', null], [$free->plan, $free->plan_status, $free->trial_ends_at]);
    }

    public function test_the_form_and_the_sign_up_fall_back_to_free_when_the_contractor_plan_is_off_sale(): void
    {
        config(['buildos.plans' => Arr::except(config('buildos.plans'), SignupService::DEFAULT_PLAN)]);

        $this->get('/register')->assertOk()->assertSee('Free plan: 1 project and 3 users.');
        $this->get('/register?plan=contractor')->assertOk()->assertSee('Free plan: 1 project and 3 users.');

        $this->register()->assertRedirect('/dashboard')->assertSessionHas('success', 'Welcome! Your free plan is ready.');

        $organization = User::sole()->organization;
        $this->assertSame('free', $organization->plan);
        $this->assertSame('active', $organization->plan_status);
    }
}
