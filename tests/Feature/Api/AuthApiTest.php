<?php

namespace Tests\Feature\Api;

class AuthApiTest extends ApiTestCase
{
    public function test_login_returns_a_token_and_the_full_profile(): void
    {
        $organization = $this->tenant();
        $user = $this->member($organization);

        $response = $this->postJson(self::API.'/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'phone'])->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $response->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'engineer')
            ->assertJsonPath('user.role_label', 'Site Engineer')
            ->assertJsonPath('user.locale', 'en')
            ->assertJsonPath('user.organization.id', $organization->id)
            ->assertJsonPath('user.organization.features', config('buildos.plans.business.features'));

        $can = $response->json('user')['can'];
        $this->assertSame([
            'project.view', 'project.manage', 'boq.view', 'boq.manage', 'dpr.view', 'dpr.create', 'dpr.approve', 'issue.view', 'issue.manage',
            'attendance.view', 'attendance.manage', 'worker.view', 'worker.manage', 'petty_cash.view', 'petty_cash.manage',
            'measurement.view', 'measurement.create', 'measurement.approve', 'ra_bill.view', 'ra_bill.approve',
            'money.view', 'money.overview',
            'progress.view', 'progress.create', 'progress.manage',
            'material_control.view', 'material_control.challan', 'material_control.consume', 'material_control.count',
            'material_control.reason', 'material_control.extra', 'material_control.bills', 'material_control.suppliers', 'material_control.adjust',
            'contractor.view', 'contractor.request', 'contractor.approve', 'contractor.manage', 'contractor.tender', 'contractor.award',
            'asset.log', 'checklist.fill', 'procurement.stock',
            'contractor_portal.view',
            'alerts.view', 'alerts.manage',
        ], array_keys($can));
        $this->assertFalse($can['money.view']);
        $this->assertTrue($can['progress.create']);
        $this->assertTrue($can['material_control.challan']);
        $this->assertFalse($can['contractor.approve']);
        $this->assertTrue($can['dpr.create']);
        $this->assertFalse($can['dpr.approve']);
    }

    public function test_login_finds_the_email_in_any_letter_case(): void
    {
        $user = $this->member($this->tenant());
        $user->forceFill(['email' => 'priya@example.com'])->save();
        $old = $this->member($this->tenant('Old Co'));
        $old->forceFill(['email' => 'Old.Row@Example.com'])->save();

        $this->postJson(self::API.'/auth/login', ['email' => ' PRIYA@Example.COM ', 'password' => 'password', 'device_name' => 'phone'])->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', 'priya@example.com');
        $this->postJson(self::API.'/auth/login', ['email' => 'old.row@example.com', 'password' => 'password', 'device_name' => 'phone'])->assertOk()
            ->assertJsonPath('user.id', $old->id);

        // Still refused: a wrong password, and a deactivated account.
        $this->postJson(self::API.'/auth/login', ['email' => 'PRIYA@example.com', 'password' => 'wrong-password', 'device_name' => 'phone'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email' => 'These details do not match our records.']);
        $user->update(['is_active' => false]);
        $this->postJson(self::API.'/auth/login', ['email' => 'Priya@example.com', 'password' => 'password', 'device_name' => 'phone'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_two_old_accounts_that_differ_only_by_case_sign_in_by_the_exact_spelling_only(): void
    {
        $upper = $this->member($this->tenant('First Co'));
        $upper->forceFill(['email' => 'Twin@example.com'])->save();
        $lower = $this->member($this->tenant('Second Co'));
        $lower->forceFill(['email' => 'twin@example.com'])->save();

        $this->postJson(self::API.'/auth/login', ['email' => 'Twin@example.com', 'password' => 'password', 'device_name' => 'phone'])->assertOk()
            ->assertJsonPath('user.id', $upper->id);
        $this->postJson(self::API.'/auth/login', ['email' => 'twin@example.com', 'password' => 'password', 'device_name' => 'phone'])->assertOk()
            ->assertJsonPath('user.id', $lower->id);

        foreach (['TWIN@example.com', 'twin@Example.com'] as $email) {
            $this->postJson(self::API.'/auth/login', ['email' => $email, 'password' => 'password', 'device_name' => 'phone'])
                ->assertUnprocessable()->assertJsonValidationErrors('email');
        }
    }

    public function test_me_needs_a_token(): void
    {
        $this->getJson(self::API.'/me')->assertUnauthorized();
    }

    public function test_me_returns_the_same_profile_as_login(): void
    {
        $site = $this->site();

        $this->getJson(self::API.'/me')->assertOk()
            ->assertJsonPath('user.email', $site->user->email)
            ->assertJsonPath('user.organization.name', $site->organization->name);
    }

    public function test_a_deactivated_user_is_refused_with_json_even_without_an_accept_header(): void
    {
        $user = $this->member($this->tenant());
        $token = $user->createToken('phone')->plainTextToken;
        $user->update(['is_active' => false]);

        $this->withHeader('Authorization', 'Bearer '.$token)->get(self::API.'/me')->assertForbidden();
    }
}
