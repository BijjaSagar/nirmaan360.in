<?php

namespace Tests\Feature\Notify;

use App\Models\AuditLog;
use App\Models\NotificationFailure;
use App\Models\PlatformSetting;
use App\Models\User;
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

    private function serviceAccountJson(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return json_encode(['project_id' => 'demo-project', 'client_email' => 'push@demo.iam.example', 'private_key' => $pem]);
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

        $log = AuditLog::withoutGlobalScopes()->where('action', 'platform.settings_changed')->sole();
        $this->assertContains('msg91_auth_key', $log->new_values['settings']);
        $this->assertStringNotContainsString('super-secret-key', json_encode($log->new_values));
    }

    public function test_a_bad_service_account_is_refused(): void
    {
        $this->admin();

        $this->from('/admin/integrations')->patch('/admin/integrations/fcm', ['fcm_service_account' => '{"not":"valid"}'])
            ->assertSessionHasErrors('fcm_service_account');
        $this->assertSame(0, PlatformSetting::where('key', 'fcm_service_account')->count());
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

    public function test_a_valid_service_account_can_be_saved_and_tested(): void
    {
        $this->admin();
        $json = $this->serviceAccountJson();
        $this->fakeFcm(400, ['error' => ['status' => 'INVALID_ARGUMENT']]);

        $this->patch('/admin/integrations/fcm', ['fcm_service_account' => $json])->assertSessionHasNoErrors();
        $this->post('/admin/integrations/fcm/test')->assertSessionHas('success');

        $this->assertStringNotContainsString('PRIVATE KEY', PlatformSetting::find('fcm_service_account')->value);
        $this->get('/admin/integrations')->assertDontSee('PRIVATE KEY')->assertDontSee('push@demo.iam.example');
    }

    public function test_it_lists_recent_failures_across_companies(): void
    {
        $this->admin();
        $elsewhere = $this->tenant('Other Infra');
        NotificationFailure::withoutGlobalScopes()->create(['organization_id' => $elsewhere->id, 'channel' => 'whatsapp', 'event' => 'issue.assigned', 'error' => 'Template missing', 'created_at' => now()]);

        $this->get('/admin/integrations')->assertSee('Template missing')->assertSee('Other Infra');
    }
}
