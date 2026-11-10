<?php

namespace Tests\Feature\Notify;

use App\Jobs\Notify\DeliverNotification;
use App\Models\DeviceToken;
use App\Models\NotificationPreference;
use App\Models\Project;
use App\Models\User;
use App\Services\Notify\FcmGateway;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
    protected function serviceAccount(string $project = 'demo-project'): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return [
            'type' => 'service_account',
            'project_id' => $project,
            'private_key_id' => 'key-'.$project,
            'client_email' => 'push@'.$project.'.iam.example',
            'private_key' => $pem,
            'public_key' => openssl_pkey_get_details($key)['key'],
        ];
    }

    /** Saves a service account the way the admin screen does. */
    protected function connectFcm(string $project = 'demo-project'): array
    {
        $account = $this->serviceAccount($project);
        app(PlatformSettings::class)->set(['fcm_service_account' => json_encode(collect($account)->except('public_key')->all())]);

        return $account;
    }

    /** Supplies a service account through the environment variable instead, as base64 of the file. */
    protected function connectFcmFromEnvironment(string $project = 'env-project'): array
    {
        $account = $this->serviceAccount($project);
        config(['modules.notify.push.credentials_base64' => base64_encode(json_encode(collect($account)->except('public_key')->all()))]);

        return $account;
    }

    protected function fakeFcm(int $status = 200, array $body = ['name' => 'projects/demo-project/messages/1']): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'google-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response($body, $status),
        ]);
    }

    /**
     * Firebase answering one send after another. A second Http::fake() for the same address would
     * never be reached, so tests that need several answers queue them here.
     *
     * @param  array{0: int, 1: array}  ...$answers  Status and body, in the order they are given out.
     */
    protected function fakeFcmAnswers(array ...$answers): void
    {
        $sequence = Http::sequence();

        foreach ($answers as [$status, $body]) {
            $sequence->push($body, $status);
        }

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'google-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => $sequence,
        ]);
    }

    /** What Firebase answers when the token is not one of its own, as it does for the dry run's made-up phone. */
    protected function badTokenAnswer(): array
    {
        return ['error' => [
            'code' => 400,
            'message' => 'The registration token is not a valid FCM registration token',
            'status' => 'INVALID_ARGUMENT',
            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'INVALID_ARGUMENT']],
        ]];
    }

    /** A phone registered for push, as the app would leave it. */
    protected function phone(User $user, string $token = 'device-1', array $overrides = []): DeviceToken
    {
        return DeviceToken::withoutGlobalScopes()->create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'token_hash' => DeviceToken::hashOf($token),
            'token' => $token,
            'platform' => 'android',
            'last_seen_at' => now(),
            ...$overrides,
        ]);
    }

    protected function prefer(User $user, array $choices): void
    {
        NotificationPreference::withoutGlobalScopes()->updateOrCreate(['user_id' => $user->id], ['organization_id' => $user->organization_id, ...$choices]);
    }

    /** Sets the clock by India time, which is the clock quiet hours are kept on. */
    protected function at(string $indiaTime): void
    {
        $this->travelTo(Carbon::parse($indiaTime, 'Asia/Kolkata'));
    }

    /**
     * Push jobs queued for one person, oldest first. Needs Queue::fake().
     *
     * @return Collection<int, DeliverNotification>
     */
    protected function pushesFor(User $user): Collection
    {
        return Queue::pushed(DeliverNotification::class, fn (DeliverNotification $job) => $job->channel === 'push' && $job->userId === $user->id)->values();
    }

    /** The "data" block the app would receive for a queued push. */
    protected function dataOf(DeliverNotification $job): array
    {
        return app(FcmGateway::class)->payload('token', $job->message)['message']['data'];
    }

    /** The words a queued push shows on the phone. */
    protected function wordsOf(DeliverNotification $job): string
    {
        return $job->message->title.' | '.$job->message->body;
    }

    /** Limits a person to the given projects; none means they see no project at all. */
    protected function limitTo(User $user, Project ...$projects): void
    {
        $user->forceFill(['limited_to_projects' => true])->save();

        foreach ($projects as $project) {
            DB::table('user_project_access')->insert([
                'organization_id' => $user->organization_id,
                'user_id' => $user->id,
                'project_id' => $project->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
