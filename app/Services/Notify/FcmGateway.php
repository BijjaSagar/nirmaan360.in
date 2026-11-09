<?php

namespace App\Services\Notify;

use App\Support\PlatformSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Firebase Cloud Messaging HTTP v1, signed in with a Google service account. */
class FcmGateway
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function __construct(private PlatformSettings $settings) {}

    public function isLive(): bool
    {
        return $this->account() !== null;
    }

    /** Whether text pasted into the admin screen is a usable service account file. */
    public static function isServiceAccount(string $json): bool
    {
        $account = json_decode($json, true);

        return is_array($account)
            && filled($account['project_id'] ?? null)
            && filled($account['client_email'] ?? null)
            && is_string($account['private_key'] ?? null)
            && openssl_pkey_get_private($account['private_key']) !== false;
    }

    /**
     * Checks the account can sign in and reach its Firebase project, without sending anything.
     * The dry run uses a made-up device, so Firebase answering "bad token" (400) means everything else worked.
     */
    public function keysWork(): bool
    {
        $account = $this->account();

        if ($account === null) {
            return false;
        }

        $response = Http::withToken($this->accessToken($account))
            ->timeout(10)
            ->post($this->sendUrl($account), ['validate_only' => true, 'message' => ['token' => 'connection-test']]);

        return $response->successful() || $response->status() === 400;
    }

    /** @return bool False when the device has uninstalled the app, so its token should be forgotten. */
    public function send(string $deviceToken, NotificationMessage $message): bool
    {
        $account = $this->account() ?? throw new RuntimeException('Push is not connected.');

        $response = Http::withToken($this->accessToken($account))
            ->acceptJson()
            ->timeout(15)
            ->post($this->sendUrl($account), ['message' => [
                'token' => $deviceToken,
                'notification' => array_filter(['title' => $message->title, 'body' => Str::limit($message->body, 1000)]),
                'data' => ['event' => $message->event, 'url' => (string) $message->url],
                'android' => ['priority' => 'HIGH'],
            ]]);

        if ($response->successful()) {
            return true;
        }

        $code = collect($response->json('error.details', []))->pluck('errorCode')->filter()->first() ?? $response->json('error.status');

        if ($code === 'UNREGISTERED') {
            return false;
        }

        if ($response->status() === 401) {
            Cache::forget($this->cacheKey($account));
        }

        $error = 'FCM refused the message: HTTP '.$response->status().' '.$code;

        throw $response->serverError() || in_array($response->status(), [401, 429], true)
            ? new RuntimeException($error)
            : new PermanentDeliveryFailure($error);
    }

    /** @return array{project_id:string, client_email:string, private_key:string}|null */
    private function account(): ?array
    {
        $json = (string) $this->settings->get('fcm_service_account', '');

        return self::isServiceAccount($json) ? json_decode($json, true) : null;
    }

    private function sendUrl(array $account): string
    {
        return 'https://fcm.googleapis.com/v1/projects/'.$account['project_id'].'/messages:send';
    }

    private function cacheKey(array $account): string
    {
        return 'fcm.access_token.'.sha1($account['client_email'].$account['private_key']);
    }

    /** Google tokens last an hour. Asking for a new one per message would be slow and rate limited. */
    private function accessToken(array $account): string
    {
        $key = $this->cacheKey($account);

        if ($cached = Cache::get($key)) {
            try {
                return Crypt::decryptString($cached);
            } catch (DecryptException) {
                Cache::forget($key);
            }
        }

        $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->signedRequest($account),
        ]);

        $token = $response->json('access_token');

        if (! $response->successful() || blank($token)) {
            throw new RuntimeException('Google did not issue an access token: HTTP '.$response->status());
        }

        Cache::put($key, Crypt::encryptString($token), now()->addSeconds(max(60, (int) $response->json('expires_in', 3600) - 300)));

        return $token;
    }

    private function signedRequest(array $account): string
    {
        $now = time();
        $encode = fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        $unsigned = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode(json_encode([
            'iss' => $account['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        if (! openssl_sign($unsigned, $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new PermanentDeliveryFailure('The FCM service account key could not sign the request.');
        }

        return $unsigned.'.'.$encode($signature);
    }
}
