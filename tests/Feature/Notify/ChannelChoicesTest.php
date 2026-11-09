<?php

namespace Tests\Feature\Notify;

use App\Models\DeviceToken;
use App\Models\NotificationPreference;
use Laravel\Sanctum\Sanctum;

class ChannelChoicesTest extends NotifyTestCase
{
    public function test_devices_need_a_signed_in_user(): void
    {
        $this->postJson(self::API.'/devices', ['token' => 'abc', 'platform' => 'android'])->assertUnauthorized();
    }

    public function test_a_device_is_stored_encrypted_and_registering_again_keeps_one_row(): void
    {
        Sanctum::actingAs($this->member($this->tenant()));

        $this->postJson(self::API.'/devices', ['token' => 'fcm-abc', 'platform' => 'android', 'device_name' => 'Redmi'])
            ->assertCreated()->assertJsonPath('data.platform', 'android')->assertJsonPath('push_connected', false);
        $this->postJson(self::API.'/devices', ['token' => 'fcm-abc', 'platform' => 'android'])->assertOk();

        $this->assertSame(1, DeviceToken::count());
        $this->assertNotSame('fcm-abc', DeviceToken::query()->toBase()->value('token'));
        $this->assertSame('fcm-abc', DeviceToken::first()->token);
    }

    public function test_a_device_is_never_returned_in_a_response(): void
    {
        Sanctum::actingAs($this->member($this->tenant()));

        $this->postJson(self::API.'/devices', ['token' => 'fcm-abc', 'platform' => 'ios'])->assertJsonMissingPath('data.token');
    }

    public function test_a_phone_that_changes_hands_moves_to_the_new_user_and_company(): void
    {
        $first = $this->member($this->tenant('First Co'));
        $second = $this->member($this->tenant('Second Co'));

        Sanctum::actingAs($first);
        $this->postJson(self::API.'/devices', ['token' => 'shared', 'platform' => 'android'])->assertCreated();
        Sanctum::actingAs($second);
        $this->postJson(self::API.'/devices', ['token' => 'shared', 'platform' => 'android'])->assertOk();

        $device = DeviceToken::withoutGlobalScopes()->sole();
        $this->assertSame($second->id, $device->user_id);
        $this->assertSame($second->organization_id, $device->organization_id);
    }

    public function test_only_your_own_device_can_be_removed(): void
    {
        $organization = $this->tenant();
        $owner = $this->member($organization);

        Sanctum::actingAs($owner);
        $this->postJson(self::API.'/devices', ['token' => 'mine', 'platform' => 'android']);

        Sanctum::actingAs($this->member($organization));
        $this->deleteJson(self::API.'/devices', ['token' => 'mine'])->assertOk();
        $this->assertSame(1, DeviceToken::count());

        Sanctum::actingAs($owner);
        $this->deleteJson(self::API.'/devices', ['token' => 'mine'])->assertOk();
        $this->assertSame(0, DeviceToken::count());
    }

    public function test_preferences_start_with_whatsapp_off(): void
    {
        Sanctum::actingAs($this->member($this->tenant()));

        $this->getJson(self::API.'/notification-preferences')->assertOk()
            ->assertJsonPath('data.in_app.enabled', true)
            ->assertJsonPath('data.email.enabled', true)
            ->assertJsonPath('data.push.enabled', true)
            ->assertJsonPath('data.push.connected', false)
            ->assertJsonPath('data.whatsapp.enabled', false)
            ->assertJsonPath('data.whatsapp.connected', false);
    }

    public function test_the_app_can_save_choices(): void
    {
        Sanctum::actingAs($this->member($this->tenant()));

        $this->putJson(self::API.'/notification-preferences', ['email' => false])->assertOk()
            ->assertJsonPath('data.email.enabled', false)
            ->assertJsonPath('data.push.enabled', true);
    }

    public function test_whatsapp_needs_a_mobile_number_and_records_when_it_was_agreed(): void
    {
        $user = $this->member($this->tenant());
        Sanctum::actingAs($user);

        $this->putJson(self::API.'/notification-preferences', ['whatsapp' => true])->assertUnprocessable()->assertJsonValidationErrors('whatsapp');

        $user->update(['phone' => '9876543210']);
        $this->putJson(self::API.'/notification-preferences', ['whatsapp' => true])->assertOk();
        $this->assertNotNull(NotificationPreference::where('user_id', $user->id)->first()->whatsapp_opted_in_at);

        $this->putJson(self::API.'/notification-preferences', ['whatsapp' => false])->assertOk();
        $this->assertNull(NotificationPreference::where('user_id', $user->id)->first()->whatsapp_opted_in_at);
    }

    public function test_the_web_page_marks_channels_that_are_not_connected(): void
    {
        $this->actingAs($this->member($this->tenant()));
        config(['mail.default' => 'log']);

        $this->get('/notification-settings')->assertOk()->assertSee('Not connected yet');
    }

    public function test_saving_on_the_web_leaves_a_disconnected_channels_choice_alone(): void
    {
        $user = $this->member($this->tenant());
        $this->actingAs($user);

        // Email is connected in tests and push is not, so push is drawn disabled and not posted.
        $this->patch('/notification-settings', [])->assertRedirect();

        $preference = NotificationPreference::where('user_id', $user->id)->sole();
        $this->assertFalse($preference->email);
        $this->assertTrue($preference->push);
    }

    public function test_one_persons_choices_do_not_touch_anyone_elses(): void
    {
        $organization = $this->tenant();
        $user = $this->member($organization);
        $other = $this->member($organization);
        $this->actingAs($user);

        $this->patch('/notification-settings', []);

        $this->assertTrue(NotificationPreference::for($other)->email);
    }

    public function test_the_menu_links_to_notification_settings(): void
    {
        $this->actingAs($this->member($this->tenant()));

        $this->get('/dashboard')->assertOk()->assertSee('Notification settings');
    }
}
