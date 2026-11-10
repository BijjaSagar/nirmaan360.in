<?php

namespace Tests\Feature\Notify;

use App\Models\PlatformSetting;
use App\Services\Notify\FcmGateway;
use App\Services\Notify\NotificationMessage;
use App\Services\Notify\PermanentDeliveryFailure;
use App\Services\Notify\PushCategory;
use App\Services\Notify\PushTarget;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** The Firebase gateway on its own: where the service account comes from, how it signs in, and the exact HTTP v1 request. */
class PushGatewayTest extends NotifyTestCase
{
    private function alertMessage(bool $critical = false): NotificationMessage
    {
        return new NotificationMessage(
            event: 'alert.schedule.behind',
            title: 'Behind schedule · Riverside Tower',
            body: '50% of the time is used but 35% of the work is done, 15% behind.',
            url: 'https://example.test/projects/7/site-dashboard',
            critical: $critical,
            push: new PushTarget(
                category: PushCategory::ALERTS,
                type: 'alert',
                id: 41,
                organizationId: 3,
                projectId: 7,
                permissions: ['alerts.view'],
                dedupeKey: 'alert:7:schedule.behind|project:warning',
                extra: ['alert_type' => 'schedule.behind', 'severity' => 'warning', 'skipped' => null],
            ),
        );
    }

    /** What a call throws, or null. A try with fail() inside would be caught as a RuntimeException itself. */
    private function thrownBy(callable $call): ?Throwable
    {
        try {
            $call();
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
    }

    public function test_text_that_is_not_a_service_account_is_refused(): void
    {
        $this->assertFalse(FcmGateway::isServiceAccount('not json'));
        $this->assertFalse(FcmGateway::isServiceAccount('{"project_id":"x","client_email":"y","private_key":"not a key"}'));
    }

    public function test_with_no_service_account_push_is_off_and_nothing_leaves(): void
    {
        $gateway = app(FcmGateway::class);

        $this->assertFalse($gateway->isLive());
        $this->assertNull($gateway->source());
        $this->assertNull($gateway->identity());
        $this->assertSame('missing', $gateway->environmentState());
        $this->assertFalse($gateway->keysWork());
        Http::assertNothingSent();
    }

    public function test_the_saved_account_is_encrypted_at_rest_and_only_its_identity_can_be_read(): void
    {
        $account = $this->connectFcm();
        $gateway = app(FcmGateway::class);

        $this->assertTrue($gateway->isLive());
        $this->assertSame(FcmGateway::SAVED, $gateway->source());
        $this->assertSame(['project_id' => 'demo-project', 'client_email' => $account['client_email']], $gateway->identity());

        // In the table, and in the copy of the settings the cache keeps, only ciphertext.
        $stored = PlatformSetting::find('fcm_service_account')->value;
        $cached = Cache::get('platform_settings.v1');
        $this->assertSame($stored, $cached['fcm_service_account']);

        foreach ([$stored, json_encode($cached)] as $atRest) {
            $this->assertStringNotContainsString('PRIVATE KEY', $atRest);
            $this->assertStringNotContainsString('demo-project', $atRest);
            $this->assertStringNotContainsString($account['client_email'], $atRest);
        }
    }

    public function test_the_environment_variable_is_used_only_while_nothing_is_saved(): void
    {
        $fromEnvironment = $this->connectFcmFromEnvironment();
        $gateway = app(FcmGateway::class);

        $this->assertTrue($gateway->isLive());
        $this->assertSame(FcmGateway::ENVIRONMENT, $gateway->source());
        $this->assertSame('env-project', $gateway->identity()['project_id']);
        $this->assertSame('usable', $gateway->environmentState());

        // A file saved in the admin screen wins, as every saved setting does.
        $saved = $this->connectFcm();
        $this->assertSame(FcmGateway::SAVED, $gateway->source());
        $this->assertSame($saved['client_email'], $gateway->identity()['client_email']);
        $this->assertNotSame($fromEnvironment['client_email'], $gateway->identity()['client_email']);

        $this->fakeFcm();
        $gateway->send('device-token', $this->alertMessage());
        Http::assertSent(fn (Request $request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/demo-project/messages:send');
    }

    public function test_an_environment_variable_that_is_not_a_service_account_leaves_push_off(): void
    {
        foreach (['not base64 at all!', base64_encode('{"project_id":"x"}'), base64_encode('plain text')] as $value) {
            config(['modules.notify.push.credentials_base64' => $value]);
            $gateway = app(FcmGateway::class);

            $this->assertFalse($gateway->isLive());
            $this->assertSame('unreadable', $gateway->environmentState());
        }
    }

    public function test_the_environment_value_may_be_wrapped_or_url_safe(): void
    {
        $account = collect($this->serviceAccount('wrapped'))->except('public_key')->all();
        $encoded = rtrim(strtr(base64_encode(json_encode($account)), '+/', '-_'), '=');
        config(['modules.notify.push.credentials_base64' => chunk_split($encoded, 76, "\n")]);

        $this->assertSame('wrapped', app(FcmGateway::class)->identity()['project_id']);
    }

    public function test_it_signs_in_with_a_verifiable_token_and_sends_the_http_v1_request(): void
    {
        $account = $this->connectFcm();
        $this->fakeFcm();
        $this->travelTo('2026-11-10 10:00:00');

        $this->assertTrue(app(FcmGateway::class)->send('device-token', $this->alertMessage()));

        Http::assertSent(function (Request $request) use ($account) {
            if ($request->url() !== 'https://oauth2.googleapis.com/token') {
                return false;
            }

            [$header, $claims, $signature] = explode('.', $request['assertion']);
            $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));
            $body = json_decode($decode($claims), true);

            return $request->isForm()
                && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && json_decode($decode($header), true) === ['alg' => 'RS256', 'typ' => 'JWT']
                && openssl_verify($header.'.'.$claims, $decode($signature), $account['public_key'], OPENSSL_ALGO_SHA256) === 1
                && $body['iss'] === $account['client_email']
                && $body['scope'] === 'https://www.googleapis.com/auth/firebase.messaging'
                && $body['aud'] === 'https://oauth2.googleapis.com/token'
                && $body['exp'] - $body['iat'] === 3600;
        });

        Http::assertSent(function (Request $request) {
            if ($request->url() !== 'https://fcm.googleapis.com/v1/projects/demo-project/messages:send') {
                return false;
            }

            $this->assertSame('POST', $request->method());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer google-token'));
            $this->assertTrue($request->isJson());
            $this->assertSame(['message'], array_keys($request->data()));

            $message = $request['message'];
            $this->assertSame('device-token', $message['token']);
            $this->assertSame(['title' => 'Behind schedule · Riverside Tower', 'body' => '50% of the time is used but 35% of the work is done, 15% behind.'], $message['notification']);
            $this->assertSame([
                'alert_type' => 'schedule.behind',
                'severity' => 'warning',
                'type' => 'alert',
                'id' => '41',
                'category' => 'alerts',
                'project_id' => '7',
                'event' => 'alert.schedule.behind',
                'critical' => '0',
                'url' => 'https://example.test/projects/7/site-dashboard',
            ], $message['data']);
            $this->assertSame(['priority' => 'HIGH', 'ttl' => '172800s', 'notification' => ['channel_id' => 'updates', 'tag' => 'alert-41']], $message['android']);
            $this->assertSame(['apns-priority' => '10', 'apns-expiration' => (string) (now()->timestamp + 172800), 'apns-collapse-id' => 'alert-41'], $message['apns']['headers']);
            $this->assertSame(['aps' => ['sound' => 'default']], $message['apns']['payload']);

            return true;
        });
    }

    public function test_every_data_value_is_a_string_and_nothing_secret_is_in_the_request(): void
    {
        $account = $this->connectFcm();
        $payload = app(FcmGateway::class)->payload('device-token', $this->alertMessage());

        foreach ($payload['message']['data'] as $key => $value) {
            $this->assertIsString($value, "data.{$key} must be a string");
        }

        $this->assertArrayNotHasKey('skipped', $payload['message']['data']);
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode($payload));
        $this->assertStringNotContainsString($account['client_email'], json_encode($payload));
    }

    public function test_a_critical_push_uses_the_loud_channel_and_breaks_through_on_ios(): void
    {
        $message = app(FcmGateway::class)->payload('device-token', $this->alertMessage(critical: true))['message'];

        $this->assertSame('1', $message['data']['critical']);
        $this->assertSame('critical', $message['android']['notification']['channel_id']);
        $this->assertSame('time-sensitive', $message['apns']['payload']['aps']['interruption-level']);
    }

    public function test_an_update_with_nothing_to_open_is_sent_as_a_link(): void
    {
        $message = app(FcmGateway::class)->payload('device-token', new NotificationMessage('company.export_ready', 'Export ready', '', 'https://example.test/company-data'))['message'];

        $this->assertSame(['title' => 'Export ready'], $message['notification']);
        $this->assertSame(['type' => 'link', 'id' => '', 'category' => '', 'event' => 'company.export_ready', 'critical' => '0', 'url' => 'https://example.test/company-data'], $message['data']);
        $this->assertArrayNotHasKey('tag', $message['android']['notification']);
        $this->assertArrayNotHasKey('apns-collapse-id', $message['apns']['headers']);
    }

    public function test_long_words_are_cut_so_the_request_stays_under_the_size_limit(): void
    {
        $long = str_repeat('मंज़ूरी चाहिए ', 400);
        $payload = app(FcmGateway::class)->payload(str_repeat('t', 180), new NotificationMessage('approval.returned', $long, $long, 'https://example.test/x'));

        $this->assertLessThan(4000, strlen(json_encode($payload['message'], JSON_UNESCAPED_UNICODE)));
    }

    public function test_the_access_token_is_cached_until_near_expiry(): void
    {
        $this->connectFcm();
        $this->fakeFcm();
        $gateway = app(FcmGateway::class);

        $gateway->send('a', $this->alertMessage());
        $gateway->send('b', $this->alertMessage());
        $this->assertCount(1, Http::recorded(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com')));

        // Google's token lasts an hour. Five minutes before that a new one is fetched.
        $this->travel(54)->minutes();
        $gateway->send('c', $this->alertMessage());
        $this->assertCount(1, Http::recorded(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com')));

        $this->travel(2)->minutes();
        $gateway->send('d', $this->alertMessage());
        $this->assertCount(2, Http::recorded(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com')));
    }

    public function test_the_cached_access_token_is_not_readable_in_the_cache(): void
    {
        $account = $this->connectFcm();
        $this->fakeFcm();

        app(FcmGateway::class)->send('a', $this->alertMessage());

        $cached = Cache::get('fcm.access_token.'.sha1($account['client_email'].$account['private_key']));
        $this->assertNotEmpty($cached);
        $this->assertStringNotContainsString('google-token', $cached);
    }

    public function test_it_says_when_a_phone_has_gone_or_its_token_is_not_valid(): void
    {
        $this->connectFcm();
        $gateway = app(FcmGateway::class);
        $this->fakeFcmAnswers(
            [404, ['error' => ['code' => 404, 'message' => 'Requested entity was not found.', 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]]],
            [400, $this->badTokenAnswer()],
            [400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Invalid JSON payload', 'details' => [['fieldViolations' => [['field' => 'message.token', 'description' => 'Invalid registration token']]]]]]],
        );

        $this->assertFalse($gateway->send('gone', $this->alertMessage()));
        $this->assertFalse($gateway->send('not-a-token', $this->alertMessage()));
        $this->assertFalse($gateway->send('also-not-a-token', $this->alertMessage()));
    }

    public function test_a_message_firebase_refuses_for_another_reason_keeps_the_phone_and_is_not_retried(): void
    {
        $this->connectFcm();
        $this->fakeFcm(400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Invalid value at message.android.ttl', 'details' => [['fieldViolations' => [['field' => 'message.android.ttl']]]]]]);

        $this->expectException(PermanentDeliveryFailure::class);
        $this->expectExceptionMessage('HTTP 400 INVALID_ARGUMENT');
        app(FcmGateway::class)->send('device-token', $this->alertMessage());
    }

    public function test_a_wrong_project_is_a_lasting_failure_and_never_costs_the_phone(): void
    {
        $this->connectFcm();
        $this->fakeFcm(403, ['error' => ['status' => 'PERMISSION_DENIED', 'details' => [['errorCode' => 'SENDER_ID_MISMATCH']]]]);

        $this->expectException(PermanentDeliveryFailure::class);
        $this->expectExceptionMessage('SENDER_ID_MISMATCH');
        app(FcmGateway::class)->send('device-token', $this->alertMessage());
    }

    public function test_a_busy_or_failing_firebase_is_retried(): void
    {
        $this->connectFcm();
        $gateway = app(FcmGateway::class);
        $this->fakeFcmAnswers([429, ['error' => ['status' => 'QUOTA_EXCEEDED']]], [500, ['error' => ['status' => 'INTERNAL']]], [503, ['error' => ['status' => 'UNAVAILABLE']]]);

        foreach ([429, 500, 503] as $status) {
            $thrown = $this->thrownBy(fn () => $gateway->send('device-token', $this->alertMessage()));

            $this->assertInstanceOf(RuntimeException::class, $thrown, "HTTP {$status} should throw so the job retries.");
            $this->assertNotInstanceOf(PermanentDeliveryFailure::class, $thrown);
            $this->assertStringContainsString('HTTP '.$status, $thrown->getMessage());
        }
    }

    public function test_a_rejected_access_token_is_dropped_so_the_retry_signs_in_again(): void
    {
        $this->connectFcm();
        $gateway = app(FcmGateway::class);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'google-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::sequence()->push(['error' => ['status' => 'UNAUTHENTICATED']], 401)->push(['name' => 'projects/demo-project/messages/2']),
        ]);

        $thrown = $this->thrownBy(fn () => $gateway->send('device-token', $this->alertMessage()));
        $this->assertInstanceOf(RuntimeException::class, $thrown, 'A 401 should throw so the job retries.');
        $this->assertNotInstanceOf(PermanentDeliveryFailure::class, $thrown);

        $this->assertTrue($gateway->send('device-token', $this->alertMessage()));
        $this->assertCount(2, Http::recorded(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com')));
    }

    public function test_google_refusing_the_account_never_puts_the_key_in_the_error(): void
    {
        $account = $this->connectFcm();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400)]);

        $thrown = $this->thrownBy(fn () => app(FcmGateway::class)->send('device-token', $this->alertMessage()));

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertSame('Google did not issue an access token: HTTP 400', $thrown->getMessage());
        $this->assertStringNotContainsString($account['client_email'], $thrown->getMessage());
    }

    public function test_the_dry_run_sends_the_real_message_with_validate_only_and_passes_when_only_the_made_up_token_is_refused(): void
    {
        $this->connectFcm();
        $this->fakeFcm(400, $this->badTokenAnswer());

        $this->assertTrue(app(FcmGateway::class)->keysWork());

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/projects/demo-project/messages:send')
            && $request['validate_only'] === true
            && $request['message']['token'] === 'connection-test'
            && isset($request['message']['notification']['title'], $request['message']['data']['type'], $request['message']['android'], $request['message']['apns']));
    }

    public function test_the_dry_run_also_passes_when_firebase_refuses_the_made_up_phone_without_naming_it(): void
    {
        $this->connectFcm();
        $this->fakeFcm(400, ['error' => ['code' => 400, 'message' => 'Request contains an invalid argument.', 'status' => 'INVALID_ARGUMENT', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'INVALID_ARGUMENT']]]]);

        $this->assertTrue(app(FcmGateway::class)->keysWork());
    }

    public function test_the_dry_run_fails_when_firebase_refuses_the_account_the_project_or_the_message(): void
    {
        $this->connectFcm();
        $gateway = app(FcmGateway::class);
        $this->fakeFcmAnswers(
            [403, ['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'Firebase Cloud Messaging API has not been used in project demo-project before or it is disabled.']]],
            [404, ['error' => ['status' => 'NOT_FOUND']]],
            [401, ['error' => ['status' => 'UNAUTHENTICATED']]],
            // A 400 that blames the message, not the made-up token, means the message itself is wrong.
            [400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Invalid JSON payload received. Unknown name "ttl"']]],
            [400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Request contains an invalid argument.', 'details' => [['fieldViolations' => [['field' => 'message.android.ttl', 'description' => 'Invalid TTL']]]]]]],
        );

        foreach ([403, 404, 401, 400, 400] as $status) {
            $this->assertFalse($gateway->keysWork(), "HTTP {$status} must fail the check.");
        }
    }

    public function test_an_unnamed_refusal_of_a_real_send_never_costs_a_phone_its_registration(): void
    {
        $this->connectFcm();
        $this->fakeFcm(400, ['error' => ['code' => 400, 'message' => 'Request contains an invalid argument.', 'status' => 'INVALID_ARGUMENT', 'details' => [['errorCode' => 'INVALID_ARGUMENT']]]]);

        // It might be the token and it might be the message. A phone is only forgotten when Firebase blames its token.
        $thrown = $this->thrownBy(fn () => app(FcmGateway::class)->send('device-token', $this->alertMessage()));

        $this->assertInstanceOf(PermanentDeliveryFailure::class, $thrown);
    }
}
