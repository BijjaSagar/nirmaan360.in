<?php

namespace Tests\Feature\Api;

use App\Models\ApprovalRule;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access\SignupService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

/** The field app's sign-up: a new company with its first owner, signed in on the phone. */
class SignupApiTest extends ApiTestCase
{
    private function signUp(array $overrides = []): TestResponse
    {
        return $this->postJson(self::API.'/auth/register', [
            'company' => 'Deccan Builders',
            'name' => 'Priya Rane',
            'email' => 'priya@example.com',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
            'device_name' => 'Pixel 8',
            ...$overrides,
        ]);
    }

    /** A fresh request, as the phone's next call would be: nothing signed in is kept in memory. */
    private function forgetSignIn(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_a_sign_up_returns_a_token_and_the_same_profile_as_login(): void
    {
        $response = $this->signUp(['phone' => '9876543210'])->assertCreated();

        $this->assertSame(['token', 'user'], array_keys($response->json()));
        $token = $response->json('token');
        $this->assertNotEmpty($token);

        $this->forgetSignIn();
        $login = $this->postJson(self::API.'/auth/login', ['email' => 'priya@example.com', 'password' => 'a-long-password', 'device_name' => 'Pixel 8'])->assertOk();
        $this->assertSame($login->json('user'), $response->json('user'));
        $response->assertJsonPath('user.role', 'owner')
            ->assertJsonPath('user.kind', 'staff')
            ->assertJsonPath('user.organization.name', 'Deccan Builders')
            ->assertJsonPath('user.organization.features', config('buildos.plans.free.features'));

        $this->forgetSignIn();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson(self::API.'/me')->assertOk()
            ->assertJsonPath('user', $response->json('user'));
    }

    public function test_the_new_owner_sees_only_their_own_company(): void
    {
        $other = $this->tenant('Other Co');
        $project = $this->makeProject($other);
        $this->makeBoqItem($project);

        $token = $this->signUp()->assertCreated()->json('token');
        $this->forgetSignIn();
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->getJson(self::API.'/projects', $auth)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::API."/projects/{$project->id}/boq", $auth)->assertNotFound();
    }

    /** Apple guideline 3.1.1: a company made in the app must never need paying for outside the app, so there is no trial. */
    public function test_the_company_starts_on_the_free_plan_with_no_trial_with_its_owner_and_approval_rules(): void
    {
        $id = $this->signUp()->assertCreated()->json('user.id');

        $user = User::findOrFail($id);
        $organization = $user->organization;
        $this->assertSame('owner', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertSame('Deccan Builders', $organization->name);
        $this->assertSame('free', $organization->plan);
        $this->assertSame('active', $organization->plan_status);
        $this->assertNull($organization->trial_ends_at);
        $this->assertFalse($organization->isOnTrial());
        $this->assertSame('free', $organization->effectivePlan());
        $this->assertSame(3, ApprovalRule::withoutGlobalScopes()->where('organization_id', $organization->id)->count());

        // Still free a year on: nothing ends and nothing falls away.
        $this->travel(400)->days();
        $this->assertSame('free', $organization->refresh()->effectivePlan());
        $this->assertSame(config('buildos.plans.free.features'), $organization->planConfig()['features']);
    }

    public function test_a_plan_in_the_body_is_ignored(): void
    {
        // Five, the sign-up limit for a minute.
        foreach (['contractor' => 'a@example.com', 'business' => 'b@example.com', 'enterprise' => 'c@example.com', 'offline' => 'd@example.com', 'no-such-plan' => 'e@example.com'] as $plan => $email) {
            $id = $this->signUp(['plan' => $plan, 'email' => $email])->assertCreated()->json('user.id');

            $organization = User::findOrFail($id)->organization;
            $this->assertSame('free', $organization->plan, "A sign-up asking for {$plan}");
            $this->assertSame('active', $organization->plan_status, "A sign-up asking for {$plan}");
            $this->assertNull($organization->trial_ends_at, "A sign-up asking for {$plan}");
            $this->forgetSignIn();
        }
    }

    public function test_the_app_starts_free_whether_or_not_the_contractor_plan_is_on_sale(): void
    {
        config(['buildos.plans' => Arr::except(config('buildos.plans'), SignupService::DEFAULT_PLAN)]);

        $id = $this->signUp()->assertCreated()->json('user.id');

        $organization = User::findOrFail($id)->organization;
        $this->assertSame('free', $organization->plan);
        $this->assertSame('active', $organization->plan_status);
        $this->assertNull($organization->trial_ends_at);
    }

    /** If the free plan has gone, the app's sign-up is refused rather than started on a paid plan or a trial. */
    public function test_with_no_free_plan_the_app_sign_up_is_refused_and_no_trial_starts(): void
    {
        $plans = config('buildos.plans');
        $broken = [
            'taken off sale' => [...$plans, 'free' => [...$plans['free'], 'monthly' => null, 'yearly' => null]],
            'given a price' => [...$plans, 'free' => [...$plans['free'], 'monthly' => 99, 'yearly' => 990]],
            'renamed' => [...Arr::except($plans, 'free'), 'site' => $plans['free']],
        ];

        foreach ($broken as $case => $config) {
            config(['buildos.plans' => $config]);

            $this->signUp()->assertUnprocessable()
                ->assertJsonValidationErrors(['company' => 'A new company cannot be started from the app right now. Try again later.']);

            $this->assertSame(0, Organization::count(), "Free plan {$case}");
            $this->assertSame(0, User::count(), "Free plan {$case}");
        }

        $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'account.signed_up')->count());
    }

    /** The website and the console are not changed by this: they still start the default plan's trial. */
    public function test_the_console_still_starts_the_default_plans_trial(): void
    {
        $this->freezeSecond();

        $user = app(SignupService::class)->create(['company' => 'Deccan Builders', 'name' => 'Priya Rane', 'email' => 'priya@example.com', 'password' => 'a-long-password'], 'console');

        $organization = $user->organization;
        $this->assertSame('contractor', $organization->plan);
        $this->assertSame('trial', $organization->plan_status);
        $this->assertTrue($organization->trial_ends_at->equalTo(now()->addDays(config('buildos.trial_days'))));
    }

    /** A company made in the app hears no upgrade, buy or billing prompt when the free plan stops it. */
    public function test_the_free_plans_limits_answer_the_app_without_an_upgrade_prompt(): void
    {
        $token = $this->signUp()->assertCreated()->json('token');
        $this->forgetSignIn();
        $auth = ['Authorization' => 'Bearer '.$token];

        // A module the free plan does not include.
        $refused = $this->getJson(self::API.'/assets', $auth)->assertForbidden()
            ->assertJsonPath('message', "Your company's plan does not include this module. Your company's owner can check the plan.");
        $this->assertNoPaymentPrompt($refused);

        // The free plan's single project, then its limit.
        $this->postJson(self::API.'/projects', ['name' => 'Kalyani Tower'], $auth)->assertCreated();
        $limit = $this->postJson(self::API.'/projects', ['name' => 'Second Site'], $auth)->assertUnprocessable()
            ->assertJsonPath('message', "Your company's plan has reached its project limit. Your company's owner can check the plan.")
            ->assertJsonPath('errors.plan.0', "Your company's plan has reached its project limit. Your company's owner can check the plan.");
        $this->assertNoPaymentPrompt($limit);
    }

    /** What the app can show: the message and the field errors. A debug trace, if any, is left out. */
    private function assertNoPaymentPrompt(TestResponse $response): void
    {
        $shown = json_encode(Arr::only($response->json(), ['message', 'errors']));

        foreach (['upgrade', 'buy', 'renew', 'billing', 'pricing', 'purchase'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $shown);
        }
    }

    public function test_the_email_is_stored_trimmed_and_in_lower_case(): void
    {
        $this->signUp(['email' => '  Priya.Rane@Example.COM '])->assertCreated()
            ->assertJsonPath('user.email', 'priya.rane@example.com');

        $this->assertSame(['priya.rane@example.com'], User::pluck('email')->all());
    }

    public function test_an_email_taken_in_any_letter_case_is_refused(): void
    {
        $this->signUp()->assertCreated();
        $this->forgetSignIn();

        $this->signUp(['email' => 'PRIYA@Example.com', 'company' => 'Copy Co'])->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'This email already has an account.']);

        // An account kept from before emails were stored in lower case.
        $old = $this->member($this->tenant(), 'owner');
        $old->forceFill(['email' => 'Old.Row@Example.com'])->save();

        foreach (['old.row@example.com', 'OLD.ROW@EXAMPLE.COM', 'Old.Row@Example.com'] as $email) {
            $this->signUp(['email' => $email, 'company' => 'Copy Co'])->assertUnprocessable()->assertJsonValidationErrors('email');
        }

        $this->assertSame(2, User::count());
        $this->assertFalse(Organization::where('name', 'Copy Co')->exists());
    }

    public function test_a_mobile_number_already_in_use_is_refused(): void
    {
        $member = $this->member($this->tenant());
        $member->forceFill(['phone' => '9876543210'])->save();

        $this->signUp(['phone' => '9876543210'])->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'This mobile number already has an account.'])
            ->assertJsonMissingValidationErrors('email');

        $this->assertFalse(User::where('email', 'priya@example.com')->exists());
    }

    /** The owner's report: a stranger typed a platform admin's email in capitals and got the admin screens. */
    public function test_a_platform_admin_email_cannot_start_a_company_in_any_letter_case(): void
    {
        config(['buildos.platform_admins' => ['ops@nirmaan360.test', 'boss@nirmaan360.test']]);
        $admin = $this->member($this->tenant('Platform Co'), 'owner');
        $admin->forceFill(['email' => 'ops@nirmaan360.test'])->save();

        foreach (['OPS@nirmaan360.test', 'Ops@Nirmaan360.Test', ' ops@NIRMAAN360.TEST '] as $email) {
            $this->signUp(['email' => $email, 'company' => 'Stranger Co'])->assertUnprocessable()
                ->assertJsonValidationErrors(['email' => 'This email already has an account.']);
        }

        // A listed address that nobody has signed up with yet is not up for grabs either.
        foreach (['boss@nirmaan360.test', 'BOSS@Nirmaan360.test'] as $email) {
            $this->signUp(['email' => $email, 'company' => 'Stranger Co'])->assertUnprocessable()
                ->assertJsonValidationErrors(['email' => 'This email already has an account.']);
        }

        $this->assertFalse(Organization::where('name', 'Stranger Co')->exists());
        $this->assertSame([$admin->id], User::all()->filter->isPlatformAdmin()->pluck('id')->all());
    }

    public function test_the_language_is_kept_and_an_unknown_one_refused(): void
    {
        $this->signUp(['locale' => 'mr'])->assertCreated()->assertJsonPath('user.locale', 'mr');
        $this->assertSame('mr', User::where('email', 'priya@example.com')->value('locale'));
        $this->forgetSignIn();

        $this->signUp(['email' => 'b@example.com'])->assertCreated()->assertJsonPath('user.locale', 'en');
        $this->forgetSignIn();

        $this->signUp(['email' => 'c@example.com', 'locale' => 'fr'])->assertUnprocessable()->assertJsonValidationErrors('locale');
        $this->assertFalse(User::where('email', 'c@example.com')->exists());
    }

    public function test_a_device_name_and_matching_passwords_are_required(): void
    {
        $this->signUp(['device_name' => null])->assertUnprocessable()->assertJsonValidationErrors('device_name');
        $this->signUp(['password_confirmation' => 'something-else'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->signUp(['password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertSame(0, User::count());
        $this->assertSame(0, Organization::count());
    }

    public function test_the_sign_up_is_audited_without_who_signed_up(): void
    {
        $id = $this->signUp()->assertCreated()->json('user.id');
        $user = User::findOrFail($id);

        $log = AuditLog::withoutGlobalScopes()->where('action', 'account.signed_up')->sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($user->organization_id, $log->organization_id);
        $this->assertSame($user->getMorphClass(), $log->auditable_type);
        $this->assertSame($user->id, (int) $log->auditable_id);
        $this->assertNull($log->old_values);
        $this->assertSame(['method' => 'app', 'plan' => 'free'], $log->new_values);

        foreach (['priya', 'Priya', 'Rane', 'Deccan', 'example.com'] as $personal) {
            $this->assertStringNotContainsString($personal, json_encode($log->new_values));
        }
    }

    public function test_the_sixth_sign_up_in_a_minute_is_refused_while_sign_in_still_works(): void
    {
        $member = $this->member($this->tenant());

        foreach (range(1, 5) as $n) {
            $this->signUp(['email' => "owner{$n}@example.com"])->assertCreated();
            $this->forgetSignIn();
        }

        $this->signUp(['email' => 'owner6@example.com'])->assertTooManyRequests();
        $this->assertFalse(User::where('email', 'owner6@example.com')->exists());

        $this->postJson(self::API.'/auth/login', ['email' => $member->email, 'password' => 'password', 'device_name' => 'phone'])->assertOk();
    }

    public function test_sign_up_keeps_its_own_counter_when_sign_in_has_used_up_its_own(): void
    {
        $member = $this->member($this->tenant());

        foreach (range(1, 10) as $ignored) {
            $this->postJson(self::API.'/auth/login', ['email' => $member->email, 'password' => 'wrong-password', 'device_name' => 'phone'])->assertUnprocessable();
        }
        $this->postJson(self::API.'/auth/login', ['email' => $member->email, 'password' => 'password', 'device_name' => 'phone'])->assertTooManyRequests();

        $this->signUp()->assertCreated();
    }

    public function test_a_sign_up_that_loses_a_race_for_the_email_gets_422(): void
    {
        // Another sign-up with this email lands between the checks and the insert.
        User::creating(function (User $user) {
            DB::table('users')->insert(['name' => 'Racer', 'email' => $user->email, 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        });

        $this->signUp()->assertUnprocessable()->assertJsonValidationErrors(['email' => 'This email already has an account.']);

        $this->assertFalse(Organization::where('name', 'Deccan Builders')->exists());
        $this->assertSame(0, AuditLog::withoutGlobalScopes()->where('action', 'account.signed_up')->count());
    }

    public function test_a_sign_up_that_loses_a_race_for_the_mobile_number_gets_422(): void
    {
        User::creating(function (User $user) {
            DB::table('users')->insert(['name' => 'Racer', 'email' => 'racer@example.com', 'phone' => $user->phone, 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        });

        $this->signUp(['phone' => '9876543210'])->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'This mobile number already has an account.'])
            ->assertJsonMissingValidationErrors('email');

        $this->assertFalse(Organization::where('name', 'Deccan Builders')->exists());
    }

    public function test_the_service_checks_the_email_again_when_it_creates(): void
    {
        $this->member($this->tenant())->forceFill(['email' => 'priya@example.com'])->save();
        config(['buildos.platform_admins' => ['ops@nirmaan360.test']]);
        $data = ['company' => 'Deccan Builders', 'name' => 'Priya Rane', 'password' => 'a-long-password'];

        foreach (['PRIYA@example.com' => 'This email already has an account.', 'OPS@nirmaan360.test' => 'This email already has an account.'] as $email => $message) {
            try {
                app(SignupService::class)->create([...$data, 'email' => $email], 'console');
                $this->fail("{$email} was accepted without validation.");
            } catch (ValidationException $exception) {
                $this->assertSame(['email' => [$message]], $exception->errors());
            }
        }

        $this->assertFalse(Organization::where('name', 'Deccan Builders')->exists());
    }
}
