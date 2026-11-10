<?php

namespace Tests\Feature\OfflinePlan;

use App\Models\AuditLog;
use App\Models\DeviceLicence;
use App\Services\OfflinePlan\DeviceLicenceService;
use App\Services\OfflinePlan\LicenceSigner;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\ApiTestCase;

/** The field app's licence calls: activate, refresh, release, and the company's mode. */
class DeviceLicenceApiTest extends ApiTestCase
{
    use BuildsOfflineCompany;

    public function test_a_phone_on_the_offline_plan_gets_a_signed_licence(): void
    {
        $organization = $this->offlineTenant();
        $owner = $this->signIn($this->member($organization, 'owner'));

        $response = $this->activatePhone()->assertCreated()
            ->assertJsonPath('licence.device_id', 'phone-0001')
            ->assertJsonPath('licence.device_name', 'Ravi Pixel')
            ->assertJsonPath('licence.status', 'active')
            ->assertJsonPath('max_devices', 1)
            ->assertJsonPath('active_devices', 1)
            ->assertJsonPath('signing_key.algorithm', 'Ed25519');

        $licence = DeviceLicence::first();
        $this->assertSame(1, $licence->slot);
        $this->assertSame($owner->id, $licence->user_id);
        $this->assertTrue($licence->expires_at->isFuture());

        // The token is what the phone keeps: it names this phone, this company and the plan's modules, and it verifies.
        $claims = app(LicenceSigner::class)->verify($response->json('token'));
        $this->assertSame($organization->id, $claims['org']);
        $this->assertSame('phone-0001', $claims['dev']);
        $this->assertSame($licence->id, $claims['lic']);
        $this->assertSame('offline', $claims['plan']);
        $this->assertContains('measurement', $claims['features']);
        $this->assertSame($licence->expires_at->getTimestamp(), $claims['exp']);
        $this->assertSame($response->json('signing_key.key_id'), $claims['kid']);

        // Issuing a licence is a state change, so it is audited.
        $this->assertSame(1, AuditLog::where('auditable_type', $licence->getMorphClass())->where('action', 'created')->count());
    }

    public function test_the_same_phone_activating_again_keeps_its_one_slot(): void
    {
        $this->signIn($this->member($this->offlineTenant(), 'owner'));

        $first = $this->activatePhone()->assertCreated()->json('licence.id');
        $second = $this->activatePhone()->assertOk()->assertJsonPath('active_devices', 1)->json('licence.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, DeviceLicence::count());
    }

    public function test_the_device_limit_holds_and_a_freed_phone_opens_the_slot(): void
    {
        $organization = $this->offlineTenant();
        $owner = $this->signIn($this->member($organization, 'owner'));

        $this->activatePhone('phone-0001', 'Ravi Pixel')->assertCreated();
        $this->activatePhone('phone-0002', 'Spare Samsung')->assertStatus(409)
            ->assertJsonPath('code', 'device_limit')
            ->assertJsonPath('max_devices', 1)
            ->assertJsonPath('active_devices', 1)
            ->assertJsonPath('manage_url', route('offline.devices.index'));
        $this->assertSame(1, DeviceLicence::count());

        app(DeviceLicenceService::class)->free(DeviceLicence::first(), $owner);

        $this->activatePhone('phone-0002', 'Spare Samsung')->assertCreated()->assertJsonPath('active_devices', 1);
        $this->assertSame(1, DeviceLicence::where('device_id', 'phone-0002')->value('slot'));

        // The freed phone is told the next time it comes online.
        $this->refreshPhone('phone-0001')->assertStatus(410)->assertJsonPath('code', 'licence_revoked')
            ->assertJsonPath('manage_url', route('offline.devices.index'));
    }

    public function test_the_limit_set_in_admin_is_the_limit_that_applies(): void
    {
        $this->signIn($this->member($this->offlineTenant(), 'owner'));

        config(['buildos.plans.offline.max_devices' => 2]);
        $this->activatePhone('phone-0001')->assertCreated();
        $this->activatePhone('phone-0002')->assertCreated()->assertJsonPath('max_devices', 2);
        $this->activatePhone('phone-0003')->assertStatus(409)->assertJsonPath('code', 'device_limit');

        config(['buildos.plans.offline.max_devices' => null]);
        $this->activatePhone('phone-0003')->assertCreated()->assertJsonPath('max_devices', null)->assertJsonPath('active_devices', 3);
    }

    public function test_two_phones_cannot_both_take_the_last_slot(): void
    {
        $organization = $this->offlineTenant();
        $this->signIn($this->member($organization, 'owner'));
        $squeezed = false;

        // Between this request's count of free slots and its insert, another phone's activation lands.
        // In production that is a second request on another connection; here it is written straight to the table.
        DeviceLicence::creating(function () use ($organization, &$squeezed) {
            if ($squeezed) {
                return;
            }

            $squeezed = true;
            DB::table('device_licences')->insert([
                'organization_id' => $organization->id, 'device_id' => 'rival-phone', 'device_name' => 'Rival', 'status' => 'active',
                'slot' => 1, 'activated_at' => now(), 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        // The unique slot key refuses the second insert, so this phone is turned away instead of a second licence being issued.
        $this->activatePhone('my-phone')->assertStatus(409)->assertJsonPath('code', 'device_limit');

        $this->assertTrue($squeezed, 'The rival activation ran between the count and the insert.');
        $this->assertSame(0, DeviceLicence::where('device_id', 'my-phone')->count());
        $this->assertLessThanOrEqual(1, DeviceLicence::active()->count());
    }

    public function test_refresh_moves_the_expiry_on_and_tells_an_upgraded_company_to_sync(): void
    {
        $organization = $this->offlineTenant();
        $owner = $this->signIn($this->member($organization, 'owner'));
        $first = $this->activatePhone()->assertCreated()->json();

        $this->travel(10)->days();
        $refreshed = $this->refreshPhone()->assertOk()->json();
        $licence = DeviceLicence::first();

        $this->assertNotSame($first['token'], $refreshed['token']);
        $this->assertTrue($licence->last_seen_at->isToday());
        $this->assertTrue($licence->expires_at->greaterThan($first['licence']['expires_at']));
        $this->assertSame(app(LicenceSigner::class)->verify($refreshed['token'])['exp'], $licence->expires_at->getTimestamp());
        // A refresh is not a state change, so it adds no audit row.
        $this->assertSame(1, AuditLog::where('auditable_type', $licence->getMorphClass())->count());

        // The company buys an online plan. The phone is told to sync instead of being handed another licence.
        $organization->update(['plan' => 'contractor', 'paid_until' => now()->addMonth()]);
        $owner->refresh();
        $this->refreshPhone()->assertForbidden()->assertJsonPath('code', 'not_offline_plan')->assertJsonPath('syncs', true)->assertJsonPath('plan', 'contractor');
        $this->activatePhone('phone-0002')->assertForbidden()->assertJsonPath('code', 'not_offline_plan');
    }

    public function test_refresh_needs_an_activation_first_and_input_is_checked(): void
    {
        $this->signIn($this->member($this->offlineTenant(), 'owner'));

        $this->refreshPhone('never-seen-phone')->assertNotFound()->assertJsonPath('code', 'licence_missing');
        $this->postJson(self::OFFLINE_API.'/devices/activate', ['device_name' => 'No id'])->assertJsonValidationErrors('device_id');
        $this->postJson(self::OFFLINE_API.'/devices/activate', ['device_id' => 'bad id!', 'device_name' => 'Spaces'])->assertJsonValidationErrors('device_id');
        $this->postJson(self::OFFLINE_API.'/devices/activate', ['device_id' => 'phone-0001'])->assertJsonValidationErrors('device_name');
        $this->assertSame(0, DeviceLicence::count());
    }

    public function test_a_phone_can_give_its_slot_back_and_take_it_again(): void
    {
        $this->signIn($this->member($this->offlineTenant(), 'owner'));
        $id = $this->activatePhone()->assertCreated()->json('licence.id');

        $this->postJson(self::OFFLINE_API.'/devices/release', ['device_id' => 'phone-0001'])->assertOk()->assertJsonPath('status', 'released')->assertJsonPath('licence.status', 'revoked');
        $this->assertSame(0, DeviceLicence::active()->count());
        $this->assertNull(DeviceLicence::first()->slot);

        // Same row, back in service, with a fresh licence.
        $this->activatePhone()->assertCreated()->assertJsonPath('licence.id', $id)->assertJsonPath('licence.status', 'active');
        $this->postJson(self::OFFLINE_API.'/devices/release', ['device_id' => 'phone-0404'])->assertNotFound()->assertJsonPath('code', 'licence_missing');
    }

    public function test_licences_belong_to_one_company(): void
    {
        $first = $this->offlineTenant('First Co');
        $this->signIn($this->member($first, 'owner'));
        $this->activatePhone('shared-device-id')->assertCreated();

        // Another company's phone with the very same device id takes that company's slot, not First Co's.
        $this->signIn($this->member($this->offlineTenant('Second Co'), 'owner'));
        $this->activatePhone('shared-device-id', 'Second phone')->assertCreated()->assertJsonPath('active_devices', 1);
        $this->refreshPhone('shared-device-id')->assertOk()->assertJsonPath('licence.device_name', 'Second phone');

        $this->assertSame(1, DeviceLicence::withoutGlobalScopes()->where('organization_id', $first->id)->active()->count());
        $this->assertSame(2, DeviceLicence::withoutGlobalScopes()->count());
    }

    public function test_status_tells_the_app_whether_the_company_syncs(): void
    {
        $this->signIn($this->member($this->offlineTenant(), 'owner'));
        $this->getJson(self::OFFLINE_API.'/status')->assertOk()
            ->assertJsonPath('plan', 'offline')->assertJsonPath('offline', true)->assertJsonPath('syncs', false)
            ->assertJsonPath('max_devices', 1)->assertJsonPath('active_devices', 0)->assertJsonPath('licence_days', 90)
            ->assertJsonPath('signing_key.algorithm', 'Ed25519');

        $this->signIn($this->member($this->tenant('Online Co', 'contractor'), 'engineer'));
        $this->getJson(self::OFFLINE_API.'/status')->assertOk()->assertJsonPath('plan', 'contractor')->assertJsonPath('offline', false)->assertJsonPath('syncs', true);

        // The signing key needs no sign-in at all.
        $this->app['auth']->forgetGuards();
        $this->getJson(self::OFFLINE_API.'/public-key')->assertOk()->assertJsonPath('algorithm', 'Ed25519');
    }

    public function test_the_licence_ends_with_the_trial_or_the_paid_period_and_never_runs_past_the_cap(): void
    {
        $service = app(DeviceLicenceService::class);
        // Whole seconds, because dates come back from the database without microseconds.
        $this->freezeSecond();

        $trial = $this->offlineTenant('Trial Co');
        $trial->update(['plan_status' => 'trial', 'trial_ends_at' => now()->addDays(12)]);
        $this->assertTrue($service->expiryFor($trial)->equalTo(now()->addDays(12)));

        $monthly = $this->offlineTenant('Monthly Co');
        $monthly->update(['paid_until' => now()->addDays(20)]);
        $this->assertTrue($service->expiryFor($monthly)->equalTo(now()->addDays(20 + config('buildos.payment_grace_days'))));

        $yearly = $this->offlineTenant('Yearly Co');
        $yearly->update(['paid_until' => now()->addYear()]);
        $this->assertTrue($service->expiryFor($yearly)->equalTo(now()->addDays(90)));

        // Set active by the platform admin with no dates: the cap alone applies.
        $this->assertTrue($service->expiryFor($this->offlineTenant('Granted Co'))->equalTo(now()->addDays(90)));

        // A trial that has run out is the free plan, which syncs, so no licence is issued.
        $trial->update(['trial_ends_at' => now()->subDay()]);
        $this->signIn($this->member($trial, 'owner'));
        $this->activatePhone()->assertForbidden()->assertJsonPath('code', 'not_offline_plan')->assertJsonPath('plan', 'free');
    }
}
