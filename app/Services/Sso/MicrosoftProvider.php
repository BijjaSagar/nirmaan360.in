<?php

namespace App\Services\Sso;

use GuzzleHttp\RequestOptions;
use Illuminate\Support\Arr;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;
use RuntimeException;

/**
 * Microsoft sign-in (Entra ID and personal accounts) over OpenID Connect.
 * Socialite has no Microsoft driver of its own, and adding the community package is not allowed here.
 *
 * The identity comes from the ID token. It arrives straight from Microsoft's token endpoint over TLS,
 * in answer to a request that carries our client secret, which OpenID Connect accepts in place of
 * checking the signature. The audience, issuer and expiry are still checked.
 */
class MicrosoftProvider extends AbstractProvider implements ProviderInterface
{
    protected $scopes = ['openid', 'profile', 'email'];

    protected $scopeSeparator = ' ';

    private string $tenant = 'common';

    public function tenant(string $tenant): static
    {
        $this->tenant = $tenant !== '' ? $tenant : 'common';

        return $this;
    }

    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase("https://login.microsoftonline.com/{$this->tenant}/oauth2/v2.0/authorize", $state);
    }

    protected function getTokenUrl()
    {
        return "https://login.microsoftonline.com/{$this->tenant}/oauth2/v2.0/token";
    }

    public function user()
    {
        if ($this->user) {
            return $this->user;
        }

        if ($this->hasInvalidState()) {
            throw new InvalidStateException;
        }

        $response = $this->getAccessTokenResponse($this->getCode());

        return $this->userInstance($response, $this->claims((string) Arr::get($response, 'id_token')));
    }

    protected function getUserByToken($token)
    {
        $response = $this->getHttpClient()->get('https://graph.microsoft.com/oidc/userinfo', [
            RequestOptions::HEADERS => ['Authorization' => 'Bearer '.$token],
        ]);

        return json_decode((string) $response->getBody(), true) ?: [];
    }

    protected function mapUserToObject(array $user)
    {
        return (new User)->setRaw($user)->map([
            'id' => isset($user['tid'], $user['oid']) ? $user['tid'].':'.$user['oid'] : ($user['sub'] ?? null),
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
        ]);
    }

    /** @return array<string, mixed> */
    private function claims(string $idToken): array
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            throw new RuntimeException('Microsoft did not return an ID token.');
        }

        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);

        if (! is_array($claims)) {
            throw new RuntimeException('The ID token could not be read.');
        }

        $audience = (array) ($claims['aud'] ?? []);
        $issuer = (string) ($claims['iss'] ?? '');

        if (! in_array($this->clientId, $audience, true)
            || ! isset($claims['tid'])
            || $issuer !== "https://login.microsoftonline.com/{$claims['tid']}/v2.0"
            || ($claims['exp'] ?? 0) < time()
            || (! in_array($this->tenant, ['common', 'organizations', 'consumers'], true) && $claims['tid'] !== $this->tenant)) {
            throw new RuntimeException('The ID token is not valid for this app.');
        }

        return $claims;
    }
}
