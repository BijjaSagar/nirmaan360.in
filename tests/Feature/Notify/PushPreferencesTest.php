<?php

namespace Tests\Feature\Notify;

use App\Models\NotificationPreference;
use App\Models\Party;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/** Each person's own switches for push: which kinds they get and their quiet hours, on the web and through the API. */
class PushPreferencesTest extends NotifyTestCase
{
    private function contractorLogin(): User
    {
        $organization = $this->tenant('Portal Co');
        $party = Party::create(['organization_id' => $organization->id, 'type' => 'subcontractor', 'name' => 'Shree Formwork']);

        return User::factory()->create(['organization_id' => $organization->id, 'role' => User::CONTRACTOR_ROLE, 'contractor_party_id' => $party->id, 'is_active' => true]);
    }

    private function saved(User $user): NotificationPreference
    {
        return NotificationPreference::withoutGlobalScopes()->where('user_id', $user->id)->sole();
    }

    public function test_the_app_is_told_every_kind_of_push_this_person_can_get_and_the_quiet_hours(): void
    {
        Sanctum::actingAs($this->member($this->tenant(), 'pm'));

        $response = $this->getJson(self::API.'/notification-preferences')->assertOk()
            ->assertJsonPath('data.push.enabled', true)
            ->assertJsonPath('data.push.connected', false)
            ->assertJsonPath('data.push.quiet_hours', ['enabled' => true, 'from' => '22:00', 'until' => '07:00', 'timezone' => 'Asia/Kolkata']);

        $this->assertSame(['alerts', 'approvals', 'payments', 'material', 'issues', 'announcements'], array_column($response->json('data.push.categories'), 'key'));
        $this->assertSame(['Site alerts', 'Approvals', 'Contractor payments', 'Material checks', 'Issues', 'Announcements and reminders'], array_column($response->json('data.push.categories'), 'label'));
        $this->assertSame([true, true, true, true, true, true], array_column($response->json('data.push.categories'), 'enabled'));
        $this->assertNotEmpty($response->json('data.push.categories.0.hint'));
    }

    public function test_a_person_is_only_offered_the_kinds_their_role_can_receive(): void
    {
        $organization = $this->tenant();

        foreach ([
            'owner' => ['alerts', 'approvals', 'payments', 'material', 'issues', 'announcements'],
            'accounts' => ['alerts', 'approvals', 'payments', 'announcements'],
            'engineer' => ['alerts', 'approvals', 'payments', 'material', 'issues', 'announcements'],
            'store' => ['alerts', 'approvals', 'material', 'issues', 'announcements'],
            'viewer' => ['alerts', 'approvals', 'issues', 'announcements'],
        ] as $role => $expected) {
            Sanctum::actingAs($this->member($organization, $role));

            $this->assertSame($expected, array_column($this->getJson(self::API.'/notification-preferences')->json('data.push.categories'), 'key'), $role);
        }
    }

    public function test_the_kinds_are_named_in_the_persons_language(): void
    {
        $user = $this->member($this->tenant(), 'pm');
        $user->update(['locale' => 'hi']);
        Sanctum::actingAs($user);

        $this->getJson(self::API.'/notification-preferences')->assertOk()
            ->assertJsonPath('data.push.categories.0.label', 'साइट अलर्ट')
            ->assertJsonPath('data.push.categories.2.label', 'ठेकेदार भुगतान');
    }

    public function test_the_app_can_switch_kinds_off_and_change_quiet_hours(): void
    {
        $user = $this->member($this->tenant(), 'pm');
        Sanctum::actingAs($user);

        $response = $this->putJson(self::API.'/notification-preferences', [
            'categories' => ['alerts' => false, 'material' => false],
            'quiet_hours' => ['from' => '21:30', 'until' => '06:15'],
        ])->assertOk()->assertJsonPath('data.push.quiet_hours.from', '21:30')->assertJsonPath('data.push.quiet_hours.until', '06:15')->assertJsonPath('data.push.quiet_hours.enabled', true);

        $this->assertSame(['alerts' => false, 'approvals' => true, 'payments' => true, 'material' => false, 'issues' => true, 'announcements' => true], array_column($response->json('data.push.categories'), 'enabled', 'key'));

        // A later change touches only what it names.
        $this->putJson(self::API.'/notification-preferences', ['categories' => ['alerts' => true], 'quiet_hours' => ['enabled' => false]])->assertOk();

        $saved = $this->saved($user);
        $this->assertTrue($saved->allowsCategory('alerts'));
        $this->assertFalse($saved->allowsCategory('material'));
        $this->assertFalse($saved->quiet_hours);
        $this->assertSame(['21:30', '06:15'], $saved->quietWindow());
        $this->assertTrue($saved->email);
    }

    public function test_what_the_app_sends_is_checked(): void
    {
        $user = $this->member($this->tenant(), 'viewer');
        Sanctum::actingAs($user);

        $this->putJson(self::API.'/notification-preferences', ['quiet_hours' => ['from' => '25:00']])->assertUnprocessable()->assertJsonValidationErrors('quiet_hours.from');
        $this->putJson(self::API.'/notification-preferences', ['quiet_hours' => ['until' => '7am']])->assertUnprocessable()->assertJsonValidationErrors('quiet_hours.until');
        $this->putJson(self::API.'/notification-preferences', ['categories' => ['alerts' => 'sometimes']])->assertUnprocessable()->assertJsonValidationErrors('categories.alerts');

        // A kind that does not exist, or one this role is not offered, is not stored.
        $this->putJson(self::API.'/notification-preferences', ['categories' => ['gossip' => false, 'payments' => false, 'contractor' => false, 'issues' => false]])->assertOk();
        $this->assertSame(['issues' => false], $this->saved($user)->push_categories);
    }

    public function test_one_persons_switches_do_not_touch_anyone_elses(): void
    {
        $organization = $this->tenant();
        $user = $this->member($organization, 'pm');
        $other = $this->member($organization, 'pm');
        Sanctum::actingAs($user);

        $this->putJson(self::API.'/notification-preferences', ['categories' => ['alerts' => false], 'quiet_hours' => ['enabled' => false]])->assertOk();

        $this->assertTrue(NotificationPreference::for($other)->allowsCategory('alerts'));
        $this->assertTrue(NotificationPreference::for($other)->quiet_hours);
    }

    public function test_the_web_page_shows_the_kinds_and_the_quiet_hours(): void
    {
        $this->connectFcm();
        $this->actingAs($this->member($this->tenant(), 'pm'));

        $this->get('/notification-settings')->assertOk()
            ->assertSee('What is pushed to your phone')
            ->assertSee('Site alerts')
            ->assertSee('Contractor payments')
            ->assertSee('Material checks')
            ->assertSee('Quiet hours')
            ->assertSee('name="categories[alerts]"', false)
            ->assertSee('name="quiet_hours[from]" type="time" value="22:00"', false)
            ->assertSee('name="quiet_hours[until]" type="time" value="07:00"', false)
            ->assertDontSee('My payments and bills');
    }

    public function test_saving_on_the_web_stores_the_kinds_and_the_quiet_hours(): void
    {
        $this->connectFcm();
        $user = $this->member($this->tenant(), 'pm');
        $this->actingAs($user);

        // Unticked boxes are simply not posted: alerts and issues are off, the rest on.
        $this->patch('/notification-settings', [
            'email' => '1', 'push' => '1',
            'categories' => ['approvals' => '1', 'payments' => '1', 'material' => '1'],
            'quiet_hours' => ['enabled' => '1', 'from' => '23:00', 'until' => '06:00'],
        ])->assertRedirect()->assertSessionHas('success');

        $saved = $this->saved($user);
        // Unticked boxes are sent as off, the announcements one among them.
        $this->assertSame(['alerts' => false, 'approvals' => true, 'payments' => true, 'material' => true, 'issues' => false, 'announcements' => false], $saved->push_categories);
        $this->assertTrue($saved->quiet_hours);
        $this->assertSame(['23:00', '06:00'], $saved->quietWindow());

        $this->get('/notification-settings')->assertSee('value="23:00"', false);

        $this->from('/notification-settings')->patch('/notification-settings', ['quiet_hours' => ['from' => 'midnight']])->assertSessionHasErrors('quiet_hours.from');
    }

    public function test_while_push_is_not_connected_the_web_form_leaves_those_choices_alone(): void
    {
        $user = $this->member($this->tenant(), 'pm');
        $this->prefer($user, ['push_categories' => ['alerts' => false], 'quiet_hours' => false, 'quiet_from' => '20:00']);
        $this->actingAs($user);

        $this->get('/notification-settings')->assertOk()->assertSee('Not connected yet');
        // The boxes are drawn disabled, so nothing about push is posted.
        $this->patch('/notification-settings', ['email' => '1'])->assertRedirect();

        $saved = $this->saved($user);
        $this->assertSame(['alerts' => false], $saved->push_categories);
        $this->assertFalse($saved->quiet_hours);
        $this->assertSame('20:00', $saved->quiet_from);
    }

    public function test_a_contractor_login_has_its_own_page_inside_the_portal(): void
    {
        $this->connectFcm();
        $contractor = $this->contractorLogin();
        $this->phone($contractor);
        $this->actingAs($contractor);

        $this->get('/notification-settings')->assertRedirect(route('contractor-portal.home'));
        $this->get('/contractor-portal')->assertOk()->assertSee(route('contractor-portal.notifications'));

        $this->get('/contractor-portal/notification-settings')->assertOk()
            ->assertSee('My payments and bills')
            ->assertSee('Quiet hours')
            ->assertSee('Phones signed in: 1')
            ->assertSee('Shree Formwork')
            ->assertDontSee('Site alerts')
            ->assertDontSee('Approvals')
            ->assertDontSee('WhatsApp');

        $this->patch('/contractor-portal/notification-settings', ['email' => '1', 'whatsapp' => '1', 'categories' => ['alerts' => '1'], 'quiet_hours' => ['from' => '21:00', 'until' => '08:00']])
            ->assertRedirect()->assertSessionHas('success');

        $saved = $this->saved($contractor);
        $this->assertSame(['contractor' => false, 'announcements' => false], $saved->push_categories);
        $this->assertFalse($saved->quiet_hours);
        $this->assertSame(['21:00', '08:00'], $saved->quietWindow());
        // A contractor login is told by push only, so no other channel can be switched on for it here.
        $this->assertFalse($saved->whatsapp);
        $this->assertNull($saved->whatsapp_opted_in_at);
    }

    public function test_a_contractor_login_uses_the_same_switches_through_the_portal_api(): void
    {
        $contractor = $this->contractorLogin();
        Sanctum::actingAs($contractor);

        $response = $this->getJson(self::API.'/contractor-portal/notification-preferences')->assertOk();
        $this->assertSame(['push'], array_keys($response->json('data')));
        $this->assertSame(['contractor', 'announcements'], array_column($response->json('data.push.categories'), 'key'));
        $this->assertSame('My payments and bills', $response->json('data.push.categories.0.label'));

        $this->putJson(self::API.'/contractor-portal/notification-preferences', ['categories' => ['contractor' => false, 'alerts' => false], 'email' => false, 'quiet_hours' => ['enabled' => false]])
            ->assertOk()->assertJsonPath('data.push.categories.0.enabled', false)->assertJsonPath('data.push.quiet_hours.enabled', false);

        $saved = $this->saved($contractor);
        $this->assertSame(['contractor' => false], $saved->push_categories);
        $this->assertTrue($saved->email);
    }

    public function test_the_contractor_page_is_closed_to_staff_without_every_permission_and_to_guests(): void
    {
        $this->get('/contractor-portal/notification-settings')->assertRedirect(route('login'));

        $this->actingAs($this->member($this->tenant(), 'pm'));
        $this->get('/contractor-portal/notification-settings')->assertForbidden();
        $this->patch('/contractor-portal/notification-settings', [])->assertForbidden();
    }
}
