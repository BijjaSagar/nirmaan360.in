<?php

namespace Tests\Feature\Notify;

use App\Models\AuditLog;
use App\Models\DeviceToken;
use App\Models\NotificationFailure;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Notify\FcmGateway;
use App\Services\SmsGateway;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Http;

class IntegrationsAdminTest extends NotifyTestCase
{
    private function admin(): User
    {
        $user = $this->member($this->tenant(), 'owner');
        $user->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($user);

        return $user;
    }

    private function serviceAccountJson(string $project = 'demo-project'): string
    {
        return json_encode(collect($this->serviceAccount($project))->except('public_key')->all());
    }

    public function test_company_owners_cannot_reach_it(): void
    {
        $this->actingAs($this->member($this->tenant(), 'owner'));

        $this->get('/admin/integrations')->assertNotFound();
        $this->patch('/admin/integrations/sms', ['msg91_auth_key' => 'x'])->assertNotFound();
        $this->post('/admin/integrations/sms/test', ['phone' => '9876543210'])->assertNotFound();
        $this->assertSame(0, PlatformSetting::count());
    }

    public function test_unknown_integrations_do_not_exist(): void
    {
        $this->admin();

        $this->patch('/admin/integrations/telegram', [])->assertNotFound();
    }

    public function test_it_shows_not_connected_and_blocks_the_test_button_with_no_keys(): void
    {
        $this->admin();

        $response = $this->get('/admin/integrations')->assertOk()->assertSee('Not connected');
        $this->assertSame(3, substr_count($response->getContent(), 'Save all the details above to test.'));
        $response->assertSee('No service account yet, so nothing is pushed. Everything else keeps working.');
    }

    public function test_the_tab_appears_on_other_admin_screens(): void
    {
        $this->admin();

        $this->get('/admin/email')->assertOk()->assertSee(route('admin.integrations'));
    }

    public function test_secrets_are_encrypted_and_never_shown_back(): void
    {
        $this->admin();

        $this->patch('/admin/integrations/sms', ['sms_driver' => 'msg91', 'msg91_auth_key' => 'super-secret-key', 'msg91_otp_template_id' => 'tmpl-9'])->assertRedirect();

        $stored = PlatformSetting::find('msg91_auth_key')->value;
        $this->assertNotSame('super-secret-key', $stored);
        $this->assertStringNotContainsString('super-secret-key', $stored);
        $this->assertSame('super-secret-key', app(PlatformSettings::class)->get('msg91_auth_key'));

        $this->get('/admin/integrations')->assertOk()
            ->assertDontSee('super-secret-key')
            ->assertSee('tmpl-9')
            ->assertSee('Saved. Leave blank to keep it.')
            ->assertSee('Keys saved, not tested');
    }

    public function test_every_new_secret_is_on_the_encrypted_list(): void
    {
        foreach (['msg91_auth_key', 'whatsapp_access_token', 'fcm_service_account'] as $key) {
            $this->assertContains($key, PlatformSettings::SECRETS);
        }
    }

    public function test_a_blank_secret_keeps_what_is_saved(): void
    {
        $this->admin();
        $this->patch('/admin/integrations/sms', ['sms_driver' => 'msg91', 'msg91_auth_key' => 'keep-me', 'msg91_otp_template_id' => 'one']);

        $this->patch('/admin/integrations/sms', ['sms_driver' => 'msg91', 'msg91_auth_key' => '', 'msg91_otp_template_id' => 'two']);

        $this->assertSame('keep-me', app(PlatformSettings::class)->get('msg91_auth_key'));
        $this->assertSame('two', app(PlatformSettings::class)->get('msg91_otp_template_id'));
    }

    public function test_remove_clears_every_detail(): void
    {
        $this->admin();
        $this->patch('/admin/integrations/sms', ['sms_driver' => 'msg91', 'msg91_auth_key' => 'gone', 'msg91_otp_template_id' => 'one']);

        $this->patch('/admin/integrations/sms', ['remove' => '1']);

        $this->assertFalse(app(SmsGateway::class)->isLive());
        $this->assertSame(0, PlatformSetting::whereIn('key', ['sms_driver', 'msg91_auth_key', 'msg91_otp_template_id'])->count());
    }

    public function test_the_audit_log_names_settings_but_never_holds_their_values(): void
    {
        $this->admin();

        $this->patch('/admin/integrations/sms', ['sms_driver' => 'msg91', 'msg91_auth_key' => 'super-secret-key', 'msg91_otp_template_id' => 'tmpl']);
        $this->patch('/admin/integrations/fcm', ['fcm_service_account' => $this->serviceAccountJson()]);

        $logs = AuditLog::withoutGlobalScopes()->where('action', 'platform.settings_changed')->orderBy('id')->get();
        $this->assertContains('msg91_auth_key', $logs[0]->new_values['settings']);
        $this->assertSame(['fcm_service_account'], $logs[1]->new_values['settings']);
        $this->assertStringNotContainsString('super-secret-key', $logs->toJson());
        $this->assertStringNotContainsString('PRIVATE KEY', $logs->toJson());
        $this->assertStringNotContainsString('demo-project', $logs->toJson());
    }

    public function test_a_bad_service_account_is_refused(): void
    {
        $this->admin();

        $this->from('/admin/integrations')->patch('/admin/integrations/fcm', ['fcm_service_account' => '{"not":"valid"}'])
            ->assertSessionHasErrors('fcm_service_account');
        $this->assertSame(0, PlatformSetting::where('key', 'fcm_service_account')->count());
    }

    public function test_a_pasted_secret_that_fails_the_check_is_not_left_in_the_session(): void
    {
        $this->admin();
        // A real file with its end cut off: not valid, but the key is in it.
        $almost = substr($this->serviceAccountJson(), 0, -40);
        $this->assertStringContainsString('PRIVATE KEY', $almost);

        $this->from('/admin/integrations')->patch('/admin/integrations/fcm', ['fcm_service_account' => $almost])->assertSessionHasErrors('fcm_service_account');
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode(session()->all()));
        $this->assertSame(0, PlatformSetting::where('key', 'fcm_service_account')->count());

        $this->from('/admin/integrations')->patch('/admin/integrations/sms', ['sms_driver' => 'pigeon', 'msg91_auth_key' => 'super-secret-key', 'msg91_otp_template_id' => 'tmpl-7'])->assertSessionHasErrors('sms_driver');
        $this->assertStringNotContainsString('super-secret-key', json_encode(session()->all()));
        // What is not secret is still handed back to the form.
        $this->assertSame('tmpl-7', session()->getOldInput('msg91_otp_template_id'));
    }

    public function test_whatsapp_details_are_validated(): void
    {
        $this->admin();

        $this->patch('/admin/integrations/whatsapp', ['whatsapp_phone_number_id' => 'abc', 'whatsapp_template_approval' => 'Bad Name'])
            ->assertSessionHasErrors(['whatsapp_phone_number_id', 'whatsapp_template_approval']);
    }

    public function test_sms_test_sends_to_the_number_given_and_records_success(): void
    {
        $this->admin();
        $this->connectMsg91();
        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success', 'message' => 'x'])]);

        $this->post('/admin/integrations/sms/test', ['phone' => '9876543210'])->assertRedirect()->assertSessionHas('success');

        Http::assertSent(fn ($request) => $request['recipients'][0]['mobiles'] === '919876543210');
        $this->get('/admin/integrations')->assertSee('Last test passed on');
    }

    public function test_sms_test_needs_a_number(): void
    {
        $this->admin();
        $this->connectMsg91();

        $this->post('/admin/integrations/sms/test', [])->assertSessionHasErrors('phone');
    }

    public function test_a_failed_test_says_so_and_does_not_mark_it_connected(): void
    {
        $this->admin();
        $this->connectMsg91();
        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'error', 'message' => 'Invalid authkey'])]);

        $this->post('/admin/integrations/sms/test', ['phone' => '9876543210'])->assertSessionHasErrors('sms');

        $this->get('/admin/integrations')->assertDontSee('Last test passed on');
    }

    public function test_changing_keys_clears_an_earlier_pass(): void
    {
        $this->admin();
        $this->connectMsg91();
        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success', 'message' => 'x'])]);
        $this->post('/admin/integrations/sms/test', ['phone' => '9876543210']);

        $this->patch('/admin/integrations/sms', ['sms_driver' => 'msg91', 'msg91_otp_template_id' => 'new-template']);

        $this->get('/admin/integrations')->assertDontSee('Last test passed on')->assertSee('Keys saved, not tested');
    }

    public function test_whatsapp_test_checks_the_number_without_sending(): void
    {
        $this->admin();
        $this->connectWhatsApp();
        Http::fake(['graph.facebook.com/*' => Http::response(['display_phone_number' => '+91 90000 00000', 'id' => '1234567890'])]);

        $this->post('/admin/integrations/whatsapp/test')->assertSessionHas('success');

        Http::assertSent(fn ($request) => $request->method() === 'GET');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/messages'));
    }

    public function test_whatsapp_test_reports_a_rejected_token(): void
    {
        $this->admin();
        $this->connectWhatsApp();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190]], 401)]);

        $this->post('/admin/integrations/whatsapp/test')->assertSessionHasErrors('whatsapp');
    }

    public function test_a_valid_service_account_can_be_saved_and_tested_and_is_never_shown_back(): void
    {
        $this->admin();
        $json = $this->serviceAccountJson();
        $account = json_decode($json, true);
        $this->fakeFcm(400, $this->badTokenAnswer());

        $this->patch('/admin/integrations/fcm', ['fcm_service_account' => $json])->assertSessionHasNoErrors();
        $this->get('/admin/integrations')->assertSee('Keys saved, not tested');
        $this->post('/admin/integrations/fcm/test')->assertSessionHas('success');

        // Encrypted in the table like every other integration secret.
        $stored = PlatformSetting::find('fcm_service_account')->value;
        $this->assertStringNotContainsString('PRIVATE KEY', $stored);
        $this->assertStringNotContainsString('demo-project', $stored);

        // The page says one is saved and whose it is. The key itself never comes back.
        $page = $this->get('/admin/integrations')->assertOk()
            ->assertSee('Connected')
            ->assertSee('Last test passed on')
            ->assertSee('demo-project')
            ->assertSee($account['client_email'])
            ->assertSee('A service account file is saved here, encrypted. Its key is never shown again.')
            ->assertSee('Saved. Leave blank to keep it.')
            ->assertDontSee('PRIVATE KEY')
            ->assertDontSee($account['private_key_id']);

        foreach (array_filter(explode("\n", $account['private_key'])) as $line) {
            $this->assertStringNotContainsString($line, $page->getContent());
        }

        // The test was Firebase's own dry run of the real message, addressed to nobody.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'fcm.googleapis.com/v1/projects/demo-project/messages:send') && $request['validate_only'] === true);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'messages:send') && ! isset($request['validate_only']));
    }

    public function test_a_failed_dry_run_does_not_mark_push_connected(): void
    {
        $this->admin();
        $this->connectFcm();
        $this->fakeFcm(403, ['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'Firebase Cloud Messaging API has not been used in project demo-project before or it is disabled.']]);

        $this->post('/admin/integrations/fcm/test')->assertSessionHasErrors('fcm');

        $this->get('/admin/integrations')->assertSee('Keys saved, not tested')->assertDontSee('Last test passed on');
    }

    public function test_removing_the_saved_account_turns_push_off(): void
    {
        $this->admin();
        $this->connectFcm();

        $this->patch('/admin/integrations/fcm', ['remove' => '1'])->assertSessionHas('success');

        $this->assertSame(0, PlatformSetting::where('key', 'fcm_service_account')->count());
        $this->assertFalse(app(FcmGateway::class)->isLive());
        $this->get('/admin/integrations')->assertSee('No service account yet, so nothing is pushed. Everything else keeps working.')->assertDontSee('demo-project');
    }

    public function test_the_page_says_which_source_is_in_use_when_the_environment_variable_is_set(): void
    {
        $this->admin();
        $fromEnvironment = $this->connectFcmFromEnvironment();

        // Nothing saved: the variable is used, and the page says so.
        $this->get('/admin/integrations')->assertOk()
            ->assertSee('env-project')
            ->assertSee($fromEnvironment['client_email'])
            ->assertSee('Read from the FIREBASE_CREDENTIALS_BASE64 environment variable, because no file is saved here.')
            ->assertDontSee('PRIVATE KEY')
            ->assertDontSee(config('modules.notify.push.credentials_base64'));
        $this->assertSame(0, PlatformSetting::where('key', 'fcm_service_account')->count());

        // A file saved here wins.
        $this->patch('/admin/integrations/fcm', ['fcm_service_account' => $this->serviceAccountJson('saved-project')])->assertSessionHasNoErrors();
        $this->get('/admin/integrations')
            ->assertSee('saved-project')
            ->assertDontSee('env-project')
            ->assertSee('The FIREBASE_CREDENTIALS_BASE64 environment variable is also set. The file saved here wins.');

        // Removing it falls back to the variable.
        $this->patch('/admin/integrations/fcm', ['remove' => '1']);
        $this->get('/admin/integrations')->assertSee('env-project')->assertDontSee('saved-project');
    }

    public function test_an_unreadable_environment_variable_is_called_out_without_being_shown(): void
    {
        $this->admin();
        config(['modules.notify.push.credentials_base64' => 'bm90LWEtc2VydmljZS1hY2NvdW50']);

        $this->get('/admin/integrations')->assertOk()
            ->assertSee('Not connected')
            ->assertSee('The FIREBASE_CREDENTIALS_BASE64 environment variable is set, but it is not a service account file in base64.')
            ->assertDontSee('bm90LWEtc2VydmljZS1hY2NvdW50');
    }

    public function test_a_passed_test_belongs_to_the_account_that_passed_it(): void
    {
        $this->admin();
        $this->connectFcmFromEnvironment('first-project');
        $this->fakeFcm(400, $this->badTokenAnswer());
        $this->post('/admin/integrations/fcm/test')->assertSessionHas('success');
        $this->get('/admin/integrations')->assertSee('Last test passed on');

        // A deploy swaps the variable for another account, which nobody has tested yet.
        $this->connectFcmFromEnvironment('second-project');

        $this->get('/admin/integrations')->assertSee('second-project')->assertSee('Keys saved, not tested')->assertDontSee('Last test passed on');
    }

    public function test_send_a_test_notification_to_me_pushes_to_the_admins_own_phones(): void
    {
        $admin = $this->admin();
        $colleague = $this->member($this->tenant('Other Infra'), 'owner');
        $this->connectFcm();
        $this->fakeFcm();
        $this->phone($admin, 'admin-phone', ['locale' => 'en']);
        $this->phone($admin, 'admin-tablet');
        $this->phone($colleague, 'colleague-phone');
        // The test goes out at once, even in the middle of the night.
        $this->at('2026-11-10 02:00');

        $this->get('/admin/integrations')->assertSee('Send a test notification to me')->assertSee('Yours now: 2.');

        $admin->update(['locale' => 'hi']);
        $this->post('/admin/integrations/fcm/send-to-me')->assertRedirect()->assertSessionHas('success', 'टेस्ट सूचना भेज दी गई। जिन फ़ोन तक पहुँची: 2।');

        $requests = Http::recorded(fn ($request) => str_contains($request->url(), 'messages:send'))->map(fn (array $pair) => $pair[0])->values();
        $sent = $requests->map(fn ($request) => $request['message']);
        $this->assertEqualsCanonicalizing(['admin-phone', 'admin-tablet'], $sent->pluck('token')->all());
        // Each phone in its own language: the one set to English, and the one that falls back to the admin's Hindi.
        $this->assertSame(['title' => 'Test notification', 'body' => 'Push notifications from Nirmaan360 are working on this phone.'], $sent->firstWhere('token', 'admin-phone')['notification']);
        $this->assertSame('टेस्ट सूचना', $sent->firstWhere('token', 'admin-tablet')['notification']['title']);
        $this->assertSame(['test', 'push.test'], [$sent->first()['data']['type'], $sent->first()['data']['event']]);
        $this->assertArrayNotHasKey('validate_only', $requests->first()->data());
    }

    public function test_send_a_test_notification_explains_what_is_missing(): void
    {
        $admin = $this->admin();

        // No service account at all.
        $this->post('/admin/integrations/fcm/send-to-me')->assertSessionHasErrors(['push_test' => 'Push is not connected yet. Save a service account first.']);
        Http::assertNothingSent();

        // Connected, but the admin has not signed in to the app on any phone.
        $this->connectFcm();
        $this->post('/admin/integrations/fcm/send-to-me')->assertSessionHasErrors(['push_test' => 'No phone is registered for your login. Sign in to the mobile app, allow notifications, then try again.']);
        Http::assertNothingSent();

        // Firebase refuses, and its reason is shown without anything secret.
        $this->phone($admin, 'admin-phone');
        $this->fakeFcmAnswers([403, ['error' => ['status' => 'PERMISSION_DENIED', 'details' => [['errorCode' => 'SENDER_ID_MISMATCH']]]]], [404, ['error' => ['details' => [['errorCode' => 'UNREGISTERED']]]]]);
        $this->post('/admin/integrations/fcm/send-to-me')->assertSessionHasErrors(['push_test' => 'Firebase did not accept the test notification: FCM refused the message: HTTP 403 SENDER_ID_MISMATCH']);
        $this->assertSame(1, DeviceToken::withoutGlobalScopes()->count());

        // A phone Firebase no longer knows is removed and the admin is told to open the app again.
        $this->post('/admin/integrations/fcm/send-to-me')->assertSessionHasErrors(['push_test' => 'Firebase no longer knows that phone, so it was removed. Open the mobile app once and try again.']);
        $this->assertSame(0, DeviceToken::withoutGlobalScopes()->count());
    }

    public function test_company_owners_cannot_send_the_test_notification(): void
    {
        $owner = $this->member($this->tenant(), 'owner');
        $this->connectFcm();
        $this->phone($owner);
        $this->actingAs($owner);

        $this->post('/admin/integrations/fcm/send-to-me')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_it_lists_recent_failures_across_companies(): void
    {
        $this->admin();
        $elsewhere = $this->tenant('Other Infra');
        NotificationFailure::withoutGlobalScopes()->create(['organization_id' => $elsewhere->id, 'channel' => 'whatsapp', 'event' => 'issue.assigned', 'error' => 'Template missing', 'created_at' => now()]);

        $this->get('/admin/integrations')->assertSee('Template missing')->assertSee('Other Infra');
    }
}
