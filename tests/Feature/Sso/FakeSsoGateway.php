<?php

namespace Tests\Feature\Sso;

use App\Services\Sso\SsoGateway;
use App\Support\PlatformSettings;
use Illuminate\Http\RedirectResponse;

/** Stands in for Google and Microsoft: no network, and the "provider" answers with whatever the test sets. */
class FakeSsoGateway extends SsoGateway
{
    /** @var list<string> */
    public array $live = ['google', 'microsoft'];

    /** @var array{provider:string, subject:string, email:string, verified:bool, name:?string}|null */
    public ?array $answer = null;

    public function __construct()
    {
        parent::__construct(app(PlatformSettings::class));
    }

    public function isConnected(string $provider): bool
    {
        return in_array($provider, $this->live, true);
    }

    public function redirect(string $provider): RedirectResponse
    {
        return redirect('https://provider.test/'.$provider);
    }

    public function identity(string $provider): array
    {
        return $this->answer ?? throw new \RuntimeException('No identity set.');
    }

    public function willReturn(string $provider, string $email, string $subject = 'subject-1', bool $verified = true): self
    {
        $this->answer = ['provider' => $provider, 'subject' => $subject, 'email' => strtolower($email), 'verified' => $verified, 'name' => 'Someone'];

        return $this;
    }
}
