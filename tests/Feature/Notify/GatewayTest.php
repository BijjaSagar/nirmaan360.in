<?php

namespace Tests\Feature\Notify;

use App\Models\OtpCode;
use App\Services\Notify\FcmGateway;
use App\Services\Notify\NotificationMessage;
use App\Services\Notify\PermanentDeliveryFailure;
use App\Services\Notify\Phone;
use App\Services\Notify\WhatsAppGateway;
use App\Services\SmsGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GatewayTest extends NotifyTestCase
{
    public function test_phone_numbers_are_normalised_for_providers(): void
    {
        $this->assertSame('919876543210', Phone::digits('98765 43210'));
        $this->assertSame('919876543210', Phone::digits('+91 98765-43210'));
        $this->assertSame('919876543210', Phone::digits('09876543210'));
        $this->assertSame('14155552671', Phone::digits('+1 415 555 2671'));
        $this->assertNull(Phone::digits(null));
        $this->assertNull(Phone::digits('12345'));
        $this->assertNull(Phone::digits('+91 12345 67890'));
    }

    public function test_sms_reports_not_connected_and_sends_nothing_without_keys(): void
    {
        $gateway = app(SmsGateway::class);

        $this->assertFalse($gateway->isLive());
        $this->assertFalse($gateway->sendOtp('9876543210', '123456'));
        Http::assertNothingSent();
    }

    public function test_choosing_msg91_without_keys_is_still_not_connected(): void
    {
        config(['buildos.sms_driver' => 'msg91']);

        $this->assertFalse(app(SmsGateway::class)->isLive());
    }

    public function test_msg91_receives_the_code_in_the_body_and_the_key_in_a_header(): void
    {
        $this->connectMsg91();
        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success', 'message' => 'abc'])]);

        $this->assertTrue(app(SmsGateway::class)->isLive());
        $this->assertTrue(app(SmsGateway::class)->sendOtp('98765 43210', '654321'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://control.msg91.com/api/v5/flow/'
            && $request->hasHeader('authkey', 'test-msg91-key')
            && $request['template_id'] === 'tmpl-1'
            && $request['recipients'] === [['mobiles' => '919876543210', 'otp' => '654321']]);
    }

    public function test_msg91_error_inside_a_200_counts_as_failure_and_never_throws(): void
    {
        $this->connectMsg91();
        Http::fake(['control.msg91.com/*' => Http::response(['type' => 'error', 'message' => 'Invalid authkey'])]);

        $this->assertFalse(app(SmsGateway::class)->sendOtp('9876543210', '654321'));
    }

    public function test_otp_screen_answers_the_same_when_the_provider_is_down(): void
    {
        $this->connectMsg91();
        Http::fake(['control.msg91.com/*' => Http::response([], 500)]);
        $this->member($this->tenant(), 'owner')->update(['phone' => '9876543210']);

        $this->post('/login/otp/send', ['phone' => '9876543210'])->assertRedirect('/login/otp')->assertSessionHas('success');
        $this->assertSame(1, OtpCode::count());
    }

    public function test_whatsapp_is_not_connected_until_every_detail_is_saved(): void
    {
        $this->assertFalse(app(WhatsAppGateway::class)->isLive());
        $this->assertFalse(app(WhatsAppGateway::class)->keysWork());
        Http::assertNothingSent();
    }

    public function test_whatsapp_sends_an_approved_template_with_clean_values(): void
    {
        $this->connectWhatsApp();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        app(WhatsAppGateway::class)->send('919876543210', new NotificationMessage('issue.assigned', "Line one\nLine two", ''));

        Http::assertSent(function (Request $request) {
            $parameters = $request['template']['components'][0]['parameters'];

            return str_ends_with($request->url(), '/1234567890/messages')
                && $request->hasHeader('Authorization', 'Bearer test-whatsapp-token')
                && $request['to'] === '919876543210'
                && $request['template']['name'] === 'issue_update'
                && $request['template']['language']['code'] === 'en'
                && $parameters[0]['text'] === 'Line one Line two'
                && $parameters[1]['text'] === '-';
        });
    }

    public function test_whatsapp_only_retries_what_a_retry_could_fix(): void
    {
        $this->connectWhatsApp();
        $message = new NotificationMessage('approval.waiting', 'Title');

        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['error' => ['code' => 132001, 'message' => 'Template not found']], 400)
            ->push(['error' => ['code' => 130429, 'message' => 'Rate limit']], 400)]);

        try {
            app(WhatsAppGateway::class)->send('919876543210', $message);
            $this->fail('A missing template should throw.');
        } catch (PermanentDeliveryFailure) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('130429');
        app(WhatsAppGateway::class)->send('919876543210', $message);
    }

    public function test_only_approval_and_issue_events_have_whatsapp_templates(): void
    {
        $this->connectWhatsApp();
        $gateway = app(WhatsAppGateway::class);

        $this->assertSame('approval_update', $gateway->templateFor('approval.waiting'));
        $this->assertSame('issue_update', $gateway->templateFor('issue.raised'));
        $this->assertNull($gateway->templateFor('company.export_ready'));
    }

    public function test_fcm_rejects_text_that_is_not_a_service_account(): void
    {
        $this->assertFalse(FcmGateway::isServiceAccount('not json'));
        $this->assertFalse(FcmGateway::isServiceAccount('{"project_id":"x","client_email":"y","private_key":"not a key"}'));
        $this->assertFalse(app(FcmGateway::class)->isLive());
    }

    public function test_fcm_signs_in_with_a_verifiable_token_and_sends(): void
    {
        $account = $this->connectFcm();
        $this->fakeFcm();

        $this->assertTrue(app(FcmGateway::class)->send('device-token', new NotificationMessage('issue.assigned', 'Title', 'Body', 'https://example.test/x')));

        Http::assertSent(function (Request $request) use ($account) {
            if (! str_contains($request->url(), 'oauth2.googleapis.com')) {
                return false;
            }

            [$header, $claims, $signature] = explode('.', $request['assertion']);
            $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));
            $body = json_decode($decode($claims), true);

            return openssl_verify($header.'.'.$claims, $decode($signature), $account['public_key'], OPENSSL_ALGO_SHA256) === 1
                && $body['iss'] === $account['client_email']
                && $body['scope'] === 'https://www.googleapis.com/auth/firebase.messaging';
        });

        Http::assertSent(fn (Request $request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/demo-project/messages:send'
            && $request->hasHeader('Authorization', 'Bearer google-token')
            && $request['message']['token'] === 'device-token'
            && $request['message']['notification'] === ['title' => 'Title', 'body' => 'Body']
            && $request['message']['data'] === ['event' => 'issue.assigned', 'url' => 'https://example.test/x']);
    }

    public function test_fcm_reuses_its_access_token(): void
    {
        $this->connectFcm();
        $this->fakeFcm();
        $gateway = app(FcmGateway::class);

        $gateway->send('a', new NotificationMessage('issue.assigned', 'One'));
        $gateway->send('b', new NotificationMessage('issue.assigned', 'Two'));

        $this->assertCount(1, Http::recorded(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com')));
    }

    public function test_fcm_says_when_a_device_has_gone(): void
    {
        $this->connectFcm();
        $this->fakeFcm(404, ['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]]);

        $this->assertFalse(app(FcmGateway::class)->send('gone', new NotificationMessage('issue.assigned', 'Title')));
    }

    public function test_fcm_check_sends_a_dry_run_and_treats_bad_sample_token_as_success(): void
    {
        $this->connectFcm();
        $this->fakeFcm(400, ['error' => ['status' => 'INVALID_ARGUMENT']]);

        $this->assertTrue(app(FcmGateway::class)->keysWork());

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send') && $request['validate_only'] === true);
    }

    public function test_fcm_check_fails_when_firebase_refuses_the_account(): void
    {
        $this->connectFcm();
        $this->fakeFcm(403, ['error' => ['status' => 'PERMISSION_DENIED']]);

        $this->assertFalse(app(FcmGateway::class)->keysWork());
    }
}
