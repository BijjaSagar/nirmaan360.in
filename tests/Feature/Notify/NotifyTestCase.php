<?php

namespace Tests\Feature\Notify;

use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\BuildsTenant;
use Tests\TestCase;

abstract class NotifyTestCase extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected const API = '/api/v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Any outside call a test forgets to fake is an error, so no real message can ever leave.
        Http::preventStrayRequests();
    }

    protected function connectWhatsApp(): void
    {
        app(PlatformSettings::class)->set([
            'whatsapp_phone_number_id' => '1234567890',
            'whatsapp_access_token' => 'test-whatsapp-token',
            'whatsapp_template_approval' => 'approval_update',
            'whatsapp_template_issue' => 'issue_update',
        ]);
    }

    protected function connectMsg91(): void
    {
        app(PlatformSettings::class)->set(['sms_driver' => 'msg91', 'msg91_auth_key' => 'test-msg91-key', 'msg91_otp_template_id' => 'tmpl-1']);
    }

    /** A throwaway key made for this run, so the repository never holds one. */
    protected function connectFcm(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        $account = ['type' => 'service_account', 'project_id' => 'demo-project', 'client_email' => 'push@demo-project.iam.example', 'private_key' => $pem];
        app(PlatformSettings::class)->set(['fcm_service_account' => json_encode($account)]);

        return [...$account, 'public_key' => openssl_pkey_get_details($key)['key']];
    }

    protected function fakeFcm(int $status = 200, array $body = ['name' => 'projects/demo-project/messages/1']): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'google-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response($body, $status),
        ]);
    }
}
