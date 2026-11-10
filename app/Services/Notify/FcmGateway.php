<?php

namespace App\Services\Notify;

use App\Support\PlatformSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Firebase Cloud Messaging HTTP v1, signed in with a Google service account.
 *
 * The service account is the file saved under Platform admin > Integrations, encrypted at rest
 * like every other integration secret. With nothing saved there, the FIREBASE_CREDENTIALS_BASE64
 * environment variable is used instead. With neither, push is off and nothing is sent. The key
 * is never shown, logged or put in an error message: identity() is all any screen may read.
 */
class FcmGateway
{
    public const SAVED = 'saved';

    public const ENVIRONMENT = 'environment';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** Android notification channels the app creates. A critical push uses the loud one. */
    private const CHANNEL_CRITICAL = 'critical';

    private const CHANNEL_UPDATES = 'updates';

    /** @var array<string, array<string, mixed>|null> Parsed accounts by a hash of their text, so the key is not read again for every message. */
    private array $parsed = [];

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

    /** Where the account in use comes from: the admin screen, the environment, or null when there is none. */
    public function source(): ?string
    {
        if ($this->saved() !== null) {
            return self::SAVED;
        }

        return $this->fromEnvironment() !== null ? self::ENVIRONMENT : null;
    }

    /**
     * The parts of the account in use that may be shown. Never the key.
     *
     * @return array{project_id: string, client_email: string}|null
     */
    public function identity(): ?array
    {
        $account = $this->account();

        return $account === null ? null : ['project_id' => (string) $account['project_id'], 'client_email' => (string) $account['client_email']];
    }

    /** Names the account in use without holding anything secret, so a passed test can be tied to the account that passed it. */
    public function fingerprint(): ?string
    {
        $account = $this->account();

        return $account === null ? null : sha1($account['project_id'].'|'.$account['client_email'].'|'.($account['private_key_id'] ?? ''));
    }

    /** "missing", "usable" or "unreadable": the state of the environment variable, whether or not it is the one in use. */
    public function environmentState(): string
    {
        if (blank(config('modules.notify.push.credentials_base64'))) {
            return 'missing';
        }

        return $this->fromEnvironment() !== null ? 'usable' : 'unreadable';
    }

    /**
     * Checks the account can sign in and reach its Firebase project, without sending anything.
     * Firebase runs the real message as a dry run (validate_only) addressed to a made-up phone.
     * So an answer that refuses only that phone means the account, the project, the Cloud
     * Messaging API and the shape of the message all passed. Any other refusal fails the check.
     */
    public function keysWork(): bool
    {
        $account = $this->account();

        if ($account === null) {
            return false;
        }

        $response = Http::withToken($this->accessToken($account))
            ->acceptJson()
            ->timeout(10)
            ->post($this->sendUrl($account), ['validate_only' => true, ...$this->payload('connection-test', new NotificationMessage('push.test', 'Connection test', 'Nothing is sent.'))]);

        if ($response->successful() || $this->refusesOnlyTheMadeUpPhone($response)) {
            return true;
        }

        // Google's own words say what is wrong (API switched off, no permission, wrong project). They never repeat the key.
        Log::warning('FCM dry run refused', ['status' => $response->status(), 'code' => $this->errorCode($response), 'message' => Str::limit((string) $response->json('error.message'), 200)]);

        return false;
    }

    /** @return bool False when the phone has uninstalled the app or its token is not valid, so the token should be forgotten. */
    public function send(string $deviceToken, NotificationMessage $message): bool
    {
        $account = $this->account() ?? throw new RuntimeException('Push is not connected.');

        $response = Http::withToken($this->accessToken($account))
            ->acceptJson()
            ->timeout(15)
            ->post($this->sendUrl($account), $this->payload($deviceToken, $message));

        if ($response->successful()) {
            return true;
        }

        $code = $this->errorCode($response);

        if ($code === 'UNREGISTERED' || $this->refusesToken($response, $code)) {
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

    /**
     * The HTTP v1 request body for one phone. The app reads "data": type and id say what to open,
     * the rest are the ids that screen needs. Every data value is a string, as FCM requires.
     *
     * @return array{message: array<string, mixed>}
     */
    public function payload(string $deviceToken, NotificationMessage $message): array
    {
        $target = $message->push ?? null;
        $ttl = max(1, (int) config('modules.notify.push.ttl_hours', 48)) * 3600;
        // A later push about the same record replaces the earlier one on the phone instead of stacking up.
        $tag = $target === null ? null : Str::limit($target->type.'-'.$target->id, 60, '');

        $data = [
            ...($target?->extra ?? []),
            'type' => $target?->type ?? 'link',
            'id' => $target?->id ?? '',
            'category' => $target?->category ?? '',
            'project_id' => $target?->projectId,
            'event' => $message->event,
            'critical' => $message->critical ? '1' : '0',
            'url' => $message->url,
        ];

        return ['message' => [
            'token' => $deviceToken,
            'notification' => array_filter(['title' => Str::limit($message->title, 120), 'body' => Str::limit($message->body, 300)]),
            'data' => array_map(strval(...), array_filter($data, fn ($value) => $value !== null)),
            'android' => [
                'priority' => 'HIGH',
                'ttl' => $ttl.'s',
                'notification' => array_filter(['channel_id' => $message->critical ? self::CHANNEL_CRITICAL : self::CHANNEL_UPDATES, 'tag' => $tag]),
            ],
            'apns' => [
                'headers' => array_filter(['apns-priority' => '10', 'apns-expiration' => (string) (now()->timestamp + $ttl), 'apns-collapse-id' => $tag]),
                // Time-sensitive lets a critical push through a Focus mode when the app holds that capability; without it iOS shows it as usual.
                'payload' => ['aps' => array_filter(['sound' => 'default', 'interruption-level' => $message->critical ? 'time-sensitive' : null])],
            ],
        ]];
    }

    /** The saved file wins over the environment, as every setting saved in /admin does. */
    private function account(): ?array
    {
        return $this->saved() ?? $this->fromEnvironment();
    }

    private function saved(): ?array
    {
        return $this->parse((string) $this->settings->get('fcm_service_account', ''));
    }

    /** The variable holds the JSON file in base64, so its line breaks and quotes survive any host's settings screen. */
    private function fromEnvironment(): ?array
    {
        $value = preg_replace('/\s+/', '', (string) config('modules.notify.push.credentials_base64', ''));
        $json = $value === '' ? false : base64_decode(strtr($value, '-_', '+/'), true);

        return $json === false ? null : $this->parse($json);
    }

    private function parse(string $json): ?array
    {
        if ($json === '') {
            return null;
        }

        return $this->parsed[sha1($json)] ??= self::isServiceAccount($json) ? json_decode($json, true) : null;
    }

    private function errorCode(Response $response): ?string
    {
        return collect($response->json('error.details', []))->pluck('errorCode')->filter()->first() ?? $response->json('error.status');
    }

    /** A 400 can also mean the message itself is wrong. Only one that blames the token costs the phone its registration. */
    private function refusesToken(Response $response, ?string $code): bool
    {
        if ($response->status() !== 400 || $code !== 'INVALID_ARGUMENT') {
            return false;
        }

        return $this->fieldsRefused($response)->contains('message.token') || str_contains(strtolower((string) $response->json('error.message')), 'registration token');
    }

    /**
     * For the dry run, whose made-up phone is meant to be refused. Firebase does not always name the
     * token when it refuses one, but it does name any part of the message it cannot accept, so a
     * 400 that names nothing in the message is the answer a working account gives.
     */
    private function refusesOnlyTheMadeUpPhone(Response $response): bool
    {
        if ($response->status() !== 400 || $this->errorCode($response) !== 'INVALID_ARGUMENT') {
            return false;
        }

        if ($this->refusesToken($response, 'INVALID_ARGUMENT')) {
            return true;
        }

        return $this->fieldsRefused($response)->isEmpty()
            && ! preg_match('/payload|invalid value|unknown name|too big/i', (string) $response->json('error.message'));
    }

    /** @return Collection<int, string> The request fields Firebase says are wrong, such as message.token. */
    private function fieldsRefused(Response $response): Collection
    {
        return collect($response->json('error.details', []))->flatMap(fn ($detail) => $detail['fieldViolations'] ?? [])->pluck('field')->filter()->values();
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
