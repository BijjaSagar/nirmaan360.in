<?php

namespace App\Services\OfflinePlan;

use RuntimeException;
use SodiumException;

/**
 * Signs device licences with Ed25519 so the field app can check them with the public key, with no internet.
 *
 * The keypair is derived from APP_KEY through HKDF with its own context string, so no new secret has to be
 * set up by hand and the same APP_KEY used for anything else gives an unrelated key here. The other side of
 * that coin: changing APP_KEY changes the keypair, and every licence signed before then stops verifying
 * until the phone refreshes it. The secret half never leaves this class and is never logged or returned.
 *
 * Token: "v1.<base64url payload>.<base64url signature>". The signature covers the decoded payload bytes
 * exactly as sent, so the app verifies first and parses the JSON after.
 */
final class LicenceSigner
{
    public const ALGORITHM = 'Ed25519';

    public const VERSION = 'v1';

    private const CONTEXT = 'nirmaan360:offline_plan:device-licence:ed25519:v1';

    private ?string $publicKey = null;

    private ?string $secretKey = null;

    /** Raw 32-byte public key. */
    public function publicKey(): string
    {
        $this->derive();

        return $this->publicKey;
    }

    public function publicKeyBase64(): string
    {
        return base64_encode($this->publicKey());
    }

    /** Short name for the key in use, so a token can say which key signed it. */
    public function keyId(): string
    {
        return substr(hash('sha256', $this->publicKey()), 0, 12);
    }

    /** What the app needs to verify tokens offline. Only public material. */
    public function describe(): array
    {
        return [
            'algorithm' => self::ALGORITHM,
            'key_id' => $this->keyId(),
            'public_key' => $this->publicKeyBase64(),
            'encoding' => 'base64 of the raw 32-byte Ed25519 public key',
            'token_format' => self::VERSION.'.<base64url payload>.<base64url signature over the decoded payload bytes>',
        ];
    }

    /** @param  array<string, mixed>  $claims */
    public function sign(array $claims): string
    {
        $payload = json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = sodium_crypto_sign_detached($payload, $this->secretKey());

        return self::VERSION.'.'.self::encode($payload).'.'.self::encode($signature);
    }

    /**
     * The claims of a token that is genuine and has not run out, else null.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $token, ?int $now = null): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            return null;
        }

        $payload = self::decode($parts[1]);
        $signature = self::decode($parts[2]);

        if ($payload === null || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }

        try {
            if (! sodium_crypto_sign_verify_detached($signature, $payload, $this->publicKey())) {
                return null;
            }
        } catch (SodiumException) {
            return null;
        }

        $claims = json_decode($payload, true);

        if (! is_array($claims) || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= ($now ?? time())) {
            return null;
        }

        return $claims;
    }

    /** Keeps the secret out of dumps and logs. */
    public function __debugInfo(): array
    {
        return ['key_id' => $this->keyId()];
    }

    private function secretKey(): string
    {
        $this->derive();

        return $this->secretKey;
    }

    private function derive(): void
    {
        if ($this->publicKey !== null) {
            return;
        }

        $appKey = (string) config('app.key');
        $raw = str_starts_with($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7), true) : $appKey;

        if ($raw === '') {
            throw new RuntimeException('APP_KEY is not set, so device licences cannot be signed.');
        }

        $seed = hash_hkdf('sha256', $raw, SODIUM_CRYPTO_SIGN_SEEDBYTES, self::CONTEXT);
        $pair = sodium_crypto_sign_seed_keypair($seed);

        $this->publicKey = sodium_crypto_sign_publickey($pair);
        $this->secretKey = sodium_crypto_sign_secretkey($pair);

        sodium_memzero($seed);
        sodium_memzero($pair);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $text): ?string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
