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
        ], array_keys($can));
        $this->assertTrue($can['dpr.create']);
        $this->assertFalse($can['dpr.approve']);
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
