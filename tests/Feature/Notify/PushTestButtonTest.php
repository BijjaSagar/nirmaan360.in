<?php

namespace Tests\Feature\Notify;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsTenant;
use Tests\TestCase;

class PushTestButtonTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private function admin(): User
    {
        $this->withoutVite();
        $user = $this->member($this->tenant(), 'owner');
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }

    public function test_reloading_a_test_address_goes_back_to_the_page_instead_of_an_error(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/integrations/fcm/send-to-me')->assertRedirect(route('admin.integrations'));
        $this->actingAs($admin)->get('/admin/integrations/fcm/test')->assertRedirect(route('admin.integrations'));
    }

    public function test_someone_who_is_not_a_platform_admin_still_cannot_reach_them(): void
    {
        $this->withoutVite();
        $owner = $this->member($this->tenant(), 'owner');

        $this->actingAs($owner)->get('/admin/integrations/fcm/send-to-me')->assertNotFound();
    }

    public function test_too_many_taps_come_back_to_the_page_with_a_message(): void
    {
        $admin = $this->admin();

        for ($tap = 1; $tap <= 6; $tap++) {
            $this->actingAs($admin)->from(route('admin.integrations'))->post(route('admin.integrations.push-test'))->assertRedirect(route('admin.integrations'));
        }

        $this->actingAs($admin)->from(route('admin.integrations'))->post(route('admin.integrations.push-test'))
            ->assertRedirect(route('admin.integrations'))
            ->assertSessionHasErrors('throttle');
    }

    public function test_the_buttons_turn_off_while_waiting(): void
    {
        $this->actingAs($this->admin())->get(route('admin.integrations'))->assertOk()
            ->assertSee('Sending…')->assertSee('x-bind:disabled="busy', false);
    }

    public function test_the_api_still_answers_429_as_json(): void
    {
        $this->actingAs($this->admin());

        $response = null;

        for ($try = 1; $try <= 8; $try++) {
            $response = $this->postJson(route('admin.integrations.push-test'));
        }

        $response->assertStatus(429);
    }

    public function test_opening_a_form_only_address_shows_a_plain_page(): void
    {
        $this->withoutVite();
        $owner = $this->member($this->tenant(), 'owner');

        $this->actingAs($owner)->get('/billing/checkout')->assertStatus(405)->assertSee('This page opens from a button');
    }
}
