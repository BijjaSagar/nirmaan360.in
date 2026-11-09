<?php

namespace App\Services\Sso;

use App\Support\PlatformSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Crypt;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Throwable;

/**
 * The only place that talks to Google and Microsoft. The platform owner saves one set of keys for the whole
 * product; until they do, a provider is "not connected" and no sign-in button is shown for it.
 * Tests replace this class with a fake.
 */
class SsoGateway
{
    public const PROVIDERS = ['google' => 'Google', 'microsoft' => 'Microsoft'];

    /** Personal Microsoft accounts all live in this tenant. */
    private const MICROSOFT_CONSUMER_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';

    public function __construct(private PlatformSettings $settings) {}

    public function isConnected(string $provider): bool
    {
        return $this->clientId($provider) !== null && $this->clientSecret($provider) !== null;
    }

    /** @return list<string> */
    public function connected(): array
    {
        return array_values(array_filter(array_keys(self::PROVIDERS), fn ($provider) => $this->isConnected($provider)));
    }

    public function clientId(string $provider): ?string
    {
        return $this->settings->get("sso_{$provider}_client_id") ?: null;
    }

    public function tenant(): string
    {
        return (string) $this->settings->get('sso_microsoft_tenant', 'common');
    }

    public function redirectUri(string $provider): string
    {
        return route('sso.callback', ['provider' => $provider]);
    }

    public function redirect(string $provider): RedirectResponse
    {
        return $this->driver($provider)->redirect();
    }

    /**
     * Who signed in, once the provider has sent the person back.
     *
     * @return array{provider:string, subject:string, email:string, verified:bool, name:?string}
     *
     * @throws SsoException
     */
    public function identity(string $provider): array
    {
        try {
            $user = $this->driver($provider)->user();
        } catch (Throwable $exception) {
            report($exception);

            throw new SsoException(__('The sign-in with :provider did not complete. Please try again.', ['provider' => self::PROVIDERS[$provider]]));
        }

        $raw = $user->getRaw();

        return [
            'provider' => $provider,
            'subject' => (string) $user->getId(),
            'email' => strtolower((string) $user->getEmail()),
            'verified' => $provider === 'google' ? $this->truthy($raw['email_verified'] ?? false) : $this->microsoftEmailIsVerified($raw),
            'name' => $user->getName(),
        ];
    }

    /** A blank secret keeps the one already saved. */
    public function saveKeys(string $provider, string $clientId, ?string $secret, ?string $tenant = null): void
    {
        $values = ["sso_{$provider}_client_id" => $clientId];

        if (filled($secret)) {
            // Stored encrypted. PlatformSettings only encrypts its own fixed list of secrets, so this one is sealed here.
            $values["sso_{$provider}_client_secret"] = Crypt::encryptString($secret);
        }

        if ($provider === 'microsoft') {
            $values['sso_microsoft_tenant'] = filled($tenant) ? $tenant : null;
        }

        $this->settings->set($values);
    }

    public function disconnect(string $provider): void
    {
        $this->settings->set(["sso_{$provider}_client_id" => null, "sso_{$provider}_client_secret" => null]);
    }

    private function clientSecret(string $provider): ?string
    {
        $sealed = $this->settings->get("sso_{$provider}_client_secret");

        if (! $sealed) {
            return null;
        }

        try {
            return Crypt::decryptString($sealed);
        } catch (DecryptException) {
            return null;
        }
    }

    private function driver(string $provider)
    {
        abort_unless(isset(self::PROVIDERS[$provider]) && $this->isConnected($provider), 404);

        $config = [
            'client_id' => $this->clientId($provider),
            'client_secret' => $this->clientSecret($provider),
            'redirect' => $this->redirectUri($provider),
        ];

        if ($provider === 'google') {
            return Socialite::buildProvider(GoogleProvider::class, $config);
        }

        return Socialite::buildProvider(MicrosoftProvider::class, $config)->tenant($this->tenant());
    }

    /**
     * Microsoft does not promise that the email claim was checked: anyone can set the mail address of
     * a user in their own Entra tenant. It is trusted only when Microsoft says the domain owner confirmed
     * it (the optional claim xms_edov), or for a personal Microsoft account, whose address is confirmed at sign-up.
     */
    private function microsoftEmailIsVerified(array $claims): bool
    {
        return filled($claims['email'] ?? null)
            && ($this->truthy($claims['xms_edov'] ?? false) || ($claims['tid'] ?? null) === self::MICROSOFT_CONSUMER_TENANT);
    }

    private function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
