<?php

namespace Tests\Feature\Notify;

use App\Models\DeviceToken;
use App\Models\NotificationFailure;
use App\Models\Party;
use App\Models\PushReceipt;
use App\Models\User;
use App\Services\Notify\NotificationMessage;
use App\Services\Notify\Notifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/** The phone's push token: registering, refreshing, unregistering, and every way a dead one is forgotten. */
class PushDeviceTest extends NotifyTestCase
{
    private function message(string $title = 'Title'): NotificationMessage
    {
        return new NotificationMessage('issue.assigned', $title);
    }

    private function contractorLogin(): User
    {
        $organization = $this->tenant('Portal Co');
        $party = Party::create(['organization_id' => $organization->id, 'type' => 'subcontractor', 'name' => 'Shree Formwork']);

        return User::factory()->create(['organization_id' => $organization->id, 'role' => User::CONTRACTOR_ROLE, 'contractor_party_id' => $party->id, 'is_active' => true]);
    }

    public function test_a_phone_registers_with_its_platform_app_version_and_language(): void
    {
        $user = $this->member($this->tenant());
        Sanctum::actingAs($user);

        $response = $this->postJson(self::API.'/devices', ['token' => 'fcm-abc', 'platform' => 'android', 'app_version' => '1.4.0+12', 'locale' => 'hi_IN', 'device_name' => 'Redmi Note'])
            ->assertCreated()
            ->assertJsonPath('data.platform', 'android')
            ->assertJsonPath('data.app_version', '1.4.0+12')
            ->assertJsonPath('data.locale', 'hi')
            ->assertJsonPath('push_connected', false);

        $this->assertStringNotContainsString('fcm-abc', $response->getContent());

        $device = DeviceToken::sole();
        $this->assertSame($user->id, $device->user_id);
        $this->assertSame($user->organization_id, $device->organization_id);
        $this->assertSame('fcm-abc', $device->token);
        $this->assertNotSame('fcm-abc', DeviceToken::query()->toBase()->value('token'));
        $this->assertSame('Redmi Note', $device->device_name);
        $this->assertNotNull($device->last_seen_at);
    }

    public function test_registering_again_refreshes_the_same_row(): void
    {
        Sanctum::actingAs($this->member($this->tenant()));
        $this->at('2026-11-10 09:00');
        $this->postJson(self::API.'/devices', ['token' => 'fcm-abc', 'platform' => 'android', 'app_version' => '1.4.0', 'locale' => 'en'])->assertCreated();

        $this->at('2026-11-12 09:00');
        $this->postJson(self::API.'/devices', ['token' => 'fcm-abc', 'platform' => 'android', 'app_version' => '1.5.0', 'locale' => 'mr'])
            ->assertOk()->assertJsonPath('data.app_version', '1.5.0')->assertJsonPath('data.locale', 'mr');

        $device = DeviceToken::sole();
        $this->assertSame('1.5.0', $device->app_version);
        $this->assertTrue($device->last_seen_at->isSameDay('2026-11-12'));
    }

    public function test_a_new_token_from_firebase_replaces_the_old_one(): void
    {
        $organization = $this->tenant();
        $user = $this->member($organization);
        $colleague = $this->member($organization);
        $this->phone($colleague, 'colleague-token');
        Sanctum::actingAs($user);
        $this->postJson(self::API.'/devices', ['token' => 'old-token', 'platform' => 'ios'])->assertCreated();

        $this->postJson(self::API.'/devices', ['token' => 'new-token', 'previous_token' => 'old-token', 'platform' => 'ios'])->assertCreated();

        $this->assertSame(['new-token'], DeviceToken::where('user_id', $user->id)->get()->pluck('token')->all());

        // Naming someone else's token as the old one retires nothing of theirs.
        $this->postJson(self::API.'/devices', ['token' => 'newer-token', 'previous_token' => 'colleague-token', 'platform' => 'ios'])->assertCreated();
        $this->assertSame(1, DeviceToken::withoutGlobalScopes()->where('user_id', $colleague->id)->count());
    }

    public function test_what_the_app_sends_is_checked(): void
    {
        Sanctum::actingAs($this->member($this->tenant()));

        $this->postJson(self::API.'/devices', ['platform' => 'android'])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->postJson(self::API.'/devices', ['token' => 'abc', 'platform' => 'windows'])->assertUnprocessable()->assertJsonValidationErrors('platform');
        $this->postJson(self::API.'/devices', ['token' => 'abc', 'platform' => 'android', 'app_version' => str_repeat('9', 40)])->assertUnprocessable()->assertJsonValidationErrors('app_version');
        $this->assertSame(0, DeviceToken::count());

        // A language the server does not write in is kept as it came and the push falls back to the person's own.
        $this->postJson(self::API.'/devices', ['token' => 'abc', 'platform' => 'android', 'locale' => 'ta-IN'])->assertCreated()->assertJsonPath('data.locale', 'ta');
    }

    public function test_signing_out_unregisters_only_your_own_phone(): void
    {
        $organization = $this->tenant();
        $user = $this->member($organization);
        $colleague = $this->member($organization);
        $this->phone($user, 'mine');
        $this->phone($colleague, 'theirs');

        Sanctum::actingAs($user);
        $this->deleteJson(self::API.'/devices', ['token' => 'theirs'])->assertOk();
        $this->assertSame(2, DeviceToken::withoutGlobalScopes()->count());

        $this->deleteJson(self::API.'/devices', ['token' => 'mine'])->assertOk();
        $this->assertSame(['theirs'], DeviceToken::withoutGlobalScopes()->get()->pluck('token')->all());
        $this->deleteJson(self::API.'/devices', [])->assertUnprocessable();
    }

    public function test_a_person_keeps_only_their_newest_phones(): void
    {
        config(['modules.notify.push.max_devices' => 3]);
        Sanctum::actingAs($this->member($this->tenant()));

        foreach (['a', 'b', 'c', 'd', 'e'] as $index => $token) {
            $this->at('2026-11-1'.$index.' 09:00');
            $this->postJson(self::API.'/devices', ['token' => $token, 'platform' => 'android'])->assertCreated();
        }

        $this->assertEqualsCanonicalizing(['c', 'd', 'e'], DeviceToken::all()->pluck('token')->all());
    }

    public function test_a_phone_stops_being_pushed_the_moment_its_sign_in_ends(): void
    {
        $this->connectFcm();
        $this->fakeFcm();
        $user = $this->member($this->tenant());
        $this->prefer($user, ['email' => false]);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('Phone')->plainTextToken];

        $this->postJson(self::API.'/devices', ['token' => 'signed-in-phone', 'platform' => 'android'], $headers)->assertCreated()->assertJsonPath('push_connected', true);
        $this->assertNotNull(DeviceToken::withoutGlobalScopes()->sole()->access_token_id);

        $this->at('2026-11-10 11:00');
        app(Notifier::class)->send($user, $this->message('While signed in'));
        Http::assertSent(fn (Request $request) => ($request['message']['token'] ?? null) === 'signed-in-phone' && $request['message']['notification']['title'] === 'While signed in');

        // The app could not say goodbye (no network), but the server-side sign-out is enough.
        $this->postJson(self::API.'/auth/logout', [], $headers)->assertOk();
        app(Notifier::class)->send($user, $this->message('After sign-out'));

        Http::assertNotSent(fn (Request $request) => ($request['message']['notification']['title'] ?? null) === 'After sign-out');
        $this->assertSame(0, DeviceToken::withoutGlobalScopes()->count());
    }

    public function test_a_phone_firebase_reports_as_unregistered_is_forgotten(): void
    {
        $this->connectFcm();
        $this->fakeFcm(404, ['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]]);
        $user = $this->member($this->tenant());
        $this->phone($user);
        $this->prefer($user, ['email' => false]);
        $this->at('2026-11-10 11:00');

        app(Notifier::class)->send($user, $this->message());

        $this->assertSame(0, DeviceToken::withoutGlobalScopes()->count());
        $this->assertSame(0, NotificationFailure::withoutGlobalScopes()->count());
    }

    public function test_a_token_firebase_calls_invalid_is_forgotten_but_other_refusals_keep_the_phone(): void
    {
        $this->connectFcm();
        $this->fakeFcmAnswers([400, $this->badTokenAnswer()], [400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Message too big']]]);
        $user = $this->member($this->tenant());
        $this->phone($user, 'not-a-real-token');
        $this->phone($user, 'good-phone');
        $this->prefer($user, ['email' => false]);
        $this->at('2026-11-10 11:00');

        app(Notifier::class)->send($user, $this->message());

        $this->assertSame(['good-phone'], DeviceToken::withoutGlobalScopes()->get()->pluck('token')->all());
        $failure = NotificationFailure::withoutGlobalScopes()->sole();
        $this->assertSame('push', $failure->channel);
        $this->assertStringContainsString('HTTP 400 INVALID_ARGUMENT', $failure->error);
    }

    public function test_a_phone_that_changed_hands_while_a_push_waited_gets_nothing_of_the_last_persons(): void
    {
        $this->connectFcm();
        $this->fakeFcm();
        $first = $this->member($this->tenant('First Co'));
        $second = $this->member($this->tenant('Second Co'));
        $device = $this->phone($first, 'shared-phone');

        // The phone is handed over and the app registers it for the new person.
        Sanctum::actingAs($second);
        $this->postJson(self::API.'/devices', ['token' => 'shared-phone', 'platform' => 'android'])->assertOk();
        $this->assertSame($second->id, $device->refresh()->user_id);

        app(Notifier::class)->deliver($first, 'push', $this->message('For the first person only'), $device->id);

        Http::assertNothingSent();
        $this->assertSame(1, DeviceToken::withoutGlobalScopes()->count());
    }

    public function test_housekeeping_forgets_phones_that_can_no_longer_be_pushed_to(): void
    {
        $user = $this->member($this->tenant());
        $live = $user->createToken('Live phone')->accessToken;
        $gone = $user->createToken('Signed-out phone')->accessToken;
        $expired = $user->createToken('Expired phone', ['*'], now()->subDay())->accessToken;

        $this->phone($user, 'keep-signed-in', ['access_token_id' => $live->id]);
        $this->phone($user, 'keep-no-sign-in-recorded');
        $this->phone($user, 'drop-signed-out', ['access_token_id' => $gone->id]);
        $this->phone($user, 'drop-expired', ['access_token_id' => $expired->id]);
        $this->phone($user, 'drop-stale', ['last_seen_at' => now()->subDays(271)]);
        $this->phone($user, 'keep-recent', ['last_seen_at' => now()->subDays(269)]);
        $gone->delete();

        PushReceipt::create(['organization_id' => $user->organization_id, 'user_id' => $user->id, 'dedupe_key' => 'old', 'event' => 'alert.x', 'claimed_at' => now()->subHours(49)]);
        PushReceipt::create(['organization_id' => $user->organization_id, 'user_id' => $user->id, 'dedupe_key' => 'recent', 'event' => 'alert.x', 'claimed_at' => now()->subHours(47)]);

        $this->artisan('company-data:housekeeping')->expectsOutputToContain('Phones forgotten: 3. Old push receipts removed: 1.')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['keep-signed-in', 'keep-no-sign-in-recorded', 'keep-recent'], DeviceToken::withoutGlobalScopes()->get()->pluck('token')->all());
        $this->assertSame(['recent'], PushReceipt::withoutGlobalScopes()->pluck('dedupe_key')->all());
    }

    public function test_a_contractor_login_registers_through_the_portal_and_nowhere_else(): void
    {
        $contractor = $this->contractorLogin();
        Sanctum::actingAs($contractor);

        $this->postJson(self::API.'/devices', ['token' => 'contractor-phone', 'platform' => 'android'])->assertForbidden();
        $this->getJson(self::API.'/notification-preferences')->assertForbidden();
        $this->assertSame(0, DeviceToken::withoutGlobalScopes()->count());

        $this->postJson(self::API.'/contractor-portal/devices', ['token' => 'contractor-phone', 'platform' => 'android', 'app_version' => '1.4.0', 'locale' => 'mr'])
            ->assertCreated()->assertJsonPath('data.locale', 'mr');
        $this->assertSame($contractor->id, DeviceToken::withoutGlobalScopes()->sole()->user_id);

        $this->deleteJson(self::API.'/contractor-portal/devices', ['token' => 'contractor-phone'])->assertOk();
        $this->assertSame(0, DeviceToken::withoutGlobalScopes()->count());
    }

    public function test_staff_cannot_use_the_contractor_endpoints(): void
    {
        $organization = $this->tenant();

        foreach (['pm', 'accounts', 'engineer', 'store', 'viewer'] as $role) {
            Sanctum::actingAs($this->member($organization, $role));

            $this->postJson(self::API.'/contractor-portal/devices', ['token' => 'abc', 'platform' => 'android'])->assertForbidden();
            $this->deleteJson(self::API.'/contractor-portal/devices', ['token' => 'abc'])->assertForbidden();
            $this->getJson(self::API.'/contractor-portal/notification-preferences')->assertForbidden();
            $this->putJson(self::API.'/contractor-portal/notification-preferences', ['push' => false])->assertForbidden();
        }

        $this->assertSame(0, DeviceToken::count());

        // An owner holds every permission, so the same four answer. They only ever act on the owner's own phone and switches.
        $owner = $this->member($organization, 'owner');
        $colleague = $this->member($organization);
        $this->phone($colleague, 'colleague-phone');
        Sanctum::actingAs($owner);

        $this->postJson(self::API.'/contractor-portal/devices', ['token' => 'owner-phone', 'platform' => 'android'])->assertCreated();
        $this->deleteJson(self::API.'/contractor-portal/devices', ['token' => 'colleague-phone'])->assertOk();
        $this->assertEqualsCanonicalizing([[$owner->id, 'owner-phone'], [$colleague->id, 'colleague-phone']], DeviceToken::all()->map(fn (DeviceToken $device) => [$device->user_id, $device->token])->all());
    }

    public function test_every_device_endpoint_needs_a_signed_in_user(): void
    {
        $this->postJson(self::API.'/devices', ['token' => 'abc', 'platform' => 'android'])->assertUnauthorized();
        $this->deleteJson(self::API.'/devices', ['token' => 'abc'])->assertUnauthorized();
        $this->postJson(self::API.'/contractor-portal/devices', ['token' => 'abc', 'platform' => 'android'])->assertUnauthorized();
        $this->getJson(self::API.'/contractor-portal/notification-preferences')->assertUnauthorized();
    }
}
