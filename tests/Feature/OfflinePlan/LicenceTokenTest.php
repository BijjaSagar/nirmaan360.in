<?php

namespace Tests\Feature\OfflinePlan;

use App\Services\OfflinePlan\LicenceSigner;
use Tests\TestCase;

/** The token a phone keeps: checkable with the public key alone, and worthless once tampered with or run out. */
class LicenceTokenTest extends TestCase
{
    private function claims(array $overrides = []): array
    {
        return ['v' => 1, 'lic' => 7, 'org' => 3, 'dev' => 'phone-0001', 'plan' => 'offline', 'iat' => time(), 'exp' => time() + 3600, ...$overrides];
    }

    /** Splits a token so a test can rebuild it with one part changed. */
    private function parts(string $token): array
    {
        [$version, $payload, $signature] = explode('.', $token);

        return [$version, $payload, $signature];
    }

    private function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public function test_a_token_verifies_with_the_published_public_key_and_sodium_alone(): void
    {
        $signer = app(LicenceSigner::class);
        $token = $signer->sign($this->claims());

        // What the app does: fetch the key once, then verify every token on the phone with nothing but Ed25519.
        $published = $this->getJson('/api/v1/offline/public-key')->assertOk()->assertJsonPath('algorithm', 'Ed25519')->json();
        [, $payload, $signature] = $this->parts($token);
        $payloadBytes = base64_decode(strtr($payload, '-_', '+/'));
        $signatureBytes = base64_decode(strtr($signature, '-_', '+/'));

        $this->assertTrue(sodium_crypto_sign_verify_detached($signatureBytes, $payloadBytes, base64_decode($published['public_key'])));
        $this->assertSame($published['key_id'], $signer->keyId());
        $this->assertSame('phone-0001', json_decode($payloadBytes, true)['dev']);
        $this->assertSame('phone-0001', $signer->verify($token)['dev']);
    }

    public function test_a_tampered_token_fails(): void
    {
        $signer = app(LicenceSigner::class);
        $token = $signer->sign($this->claims());
        [$version, $payload, $signature] = $this->parts($token);

        // Same signature, different claims: another phone, or another company, written into the payload.
        $forged = $this->base64url(json_encode($this->claims(['dev' => 'phone-9999', 'org' => 99])));
        $this->assertNull($signer->verify("{$version}.{$forged}.{$signature}"));

        // Same claims, a signature with one byte flipped.
        $bytes = base64_decode(strtr($signature, '-_', '+/'));
        $bytes[0] = chr(ord($bytes[0]) ^ 1);
        $this->assertNull($signer->verify("{$version}.{$payload}.".$this->base64url($bytes)));

        // Wrong shape altogether.
        $this->assertNull($signer->verify('v1.not-a-token'));
        $this->assertNull($signer->verify("v0.{$payload}.{$signature}"));
        $this->assertNull($signer->verify(''));
    }

    public function test_an_expired_token_fails(): void
    {
        $signer = app(LicenceSigner::class);
        $token = $signer->sign($this->claims(['exp' => time() - 1]));

        $this->assertNull($signer->verify($token));
        // The same token was fine a minute before it ran out.
        $this->assertNotNull($signer->verify($token, time() - 60));
        // A token whose expiry is not a number is refused rather than trusted.
        $this->assertNull($signer->verify($signer->sign($this->claims(['exp' => 'never']))));
    }

    public function test_the_keypair_follows_app_key_and_the_secret_half_is_never_published(): void
    {
        $first = app(LicenceSigner::class);
        $again = new LicenceSigner;
        $this->assertSame($first->publicKeyBase64(), $again->publicKeyBase64(), 'Derived twice from the same APP_KEY, the key is the same.');

        // A token signed under one APP_KEY means nothing under another: rotating APP_KEY invalidates every licence.
        $token = $first->sign($this->claims());
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $rotated = new LicenceSigner;
        $this->assertNotSame($first->publicKeyBase64(), $rotated->publicKeyBase64());
        $this->assertNull($rotated->verify($token));
        $this->assertSame('phone-0001', $first->verify($token)['dev']);

        // The endpoint publishes the public key and nothing else. The secret key is 64 bytes; no 64-byte value appears.
        $published = $this->getJson('/api/v1/offline/public-key')->assertOk()->json();
        $this->assertSame(['algorithm', 'key_id', 'public_key', 'encoding', 'token_format'], array_keys($published));
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen(base64_decode($published['public_key'])));

        foreach ($published as $value) {
            $this->assertNotSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen((string) base64_decode((string) $value, true)));
        }

        // Dumping the signer shows the key id only.
        $this->assertSame(['key_id' => $rotated->keyId()], $rotated->__debugInfo());
    }
}
