<?php

namespace Tests\Feature\Sso;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Sso\MicrosoftProvider;
use App\Services\Sso\SsoGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use ReflectionMethod;
use RuntimeException;
use Tests\BuildsTenant;
use Tests\TestCase;

/** The real gateway: keys saved by the platform owner, and how a Microsoft ID token is judged. */
class SsoKeysTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['buildos.platform_admins' => ['platform@nirmaan360.test']]);
    }

    private function platformAdmin()
    {
        $organization = $this->tenant('Platform Co', 'business');

        return User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner', 'email' => 'platform@nirmaan360.test', 'is_active' => true]);
    }

    public function test_nothing_is_connected_until_keys_are_saved(): void
    {
        $gateway = app(SsoGateway::class);

        $this->assertFalse($gateway->isConnected('google'));
        $this->assertFalse($gateway->isConnected('microsoft'));
        $this->assertSame([], $gateway->connected());
        $this->get('/login')->assertOk()->assertDontSee('Or continue with');
        $this->get('/auth/google/redirect')->assertNotFound();
    }

    public function test_saved_keys_connect_a_provider_and_the_secret_is_stored_encrypted(): void
    {
        $this->actingAs($this->platformAdmin())->patch('/admin/sso', [
            'provider' => 'google', 'action' => 'save', 'client_id' => 'google-client-id', 'client_secret' => 'super-secret-value',
        ])->assertSessionHasNoErrors();

        $gateway = app(SsoGateway::class);
        $this->assertTrue($gateway->isConnected('google'));
        $this->assertFalse($gateway->isConnected('microsoft'));
        $this->assertSame('google-client-id', $gateway->clientId('google'));

        $stored = PlatformSetting::findOrFail('sso_google_client_secret')->value;
        $this->assertStringNotContainsString('super-secret-value', $stored);
        $this->assertSame('super-secret-value', Crypt::decryptString($stored));

        auth()->logout();
        $this->get('/login')->assertOk()->assertSee('Or continue with')->assertSee('/auth/google/redirect', false);
    }

    public function test_the_redirect_goes_to_the_provider_with_our_client_id_and_return_address(): void
    {
        $this->actingAs($this->platformAdmin())->patch('/admin/sso', ['provider' => 'google', 'action' => 'save', 'client_id' => 'google-client-id', 'client_secret' => 's3cret']);
        auth()->logout();

        $response = $this->get('/auth/google/redirect')->assertRedirect();
        $location = $response->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.com/', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('google-client-id', $query['client_id']);
        $this->assertSame(route('sso.callback', 'google'), $query['redirect_uri']);
        $this->assertArrayHasKey('state', $query);
        $this->assertStringNotContainsString('s3cret', $location);
    }

    public function test_a_blank_secret_keeps_the_saved_one_and_disconnect_removes_the_keys(): void
    {
        $admin = $this->platformAdmin();
        $this->actingAs($admin)->patch('/admin/sso', ['provider' => 'microsoft', 'action' => 'save', 'client_id' => 'ms-id', 'client_secret' => 'ms-secret', 'tenant' => 'contoso.onmicrosoft.com']);
        $this->actingAs($admin)->patch('/admin/sso', ['provider' => 'microsoft', 'action' => 'save', 'client_id' => 'ms-id-2', 'client_secret' => '', 'tenant' => 'contoso.onmicrosoft.com'])->assertSessionHasNoErrors();

        $gateway = app(SsoGateway::class);
        $this->assertTrue($gateway->isConnected('microsoft'));
        $this->assertSame('ms-id-2', $gateway->clientId('microsoft'));
        $this->assertSame('contoso.onmicrosoft.com', $gateway->tenant());

        $this->actingAs($admin)->patch('/admin/sso', ['provider' => 'microsoft', 'action' => 'disconnect'])->assertSessionHasNoErrors();
        $this->assertFalse(app(SsoGateway::class)->isConnected('microsoft'));
        $this->assertNull(PlatformSetting::find('sso_microsoft_client_secret'));
    }

    public function test_a_first_save_needs_the_secret(): void
    {
        $this->actingAs($this->platformAdmin())->patch('/admin/sso', ['provider' => 'google', 'action' => 'save', 'client_id' => 'only-an-id'])->assertSessionHasErrors('client_secret');
        $this->assertFalse(app(SsoGateway::class)->isConnected('google'));
    }

    public function test_company_owners_cannot_save_platform_keys(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->tenant('Some Co', 'business')->id, 'role' => 'owner', 'is_active' => true]);

        $this->actingAs($owner)->patch('/admin/sso', ['provider' => 'google', 'action' => 'save', 'client_id' => 'x', 'client_secret' => 'y'])->assertNotFound();
        $this->assertFalse(app(SsoGateway::class)->isConnected('google'));
    }

    public function test_the_key_form_is_only_shown_to_platform_staff_and_never_shows_the_secret(): void
    {
        $admin = $this->platformAdmin();
        $this->actingAs($admin)->patch('/admin/sso', ['provider' => 'google', 'action' => 'save', 'client_id' => 'google-client-id', 'client_secret' => 'super-secret-value']);

        $this->actingAs($admin)->get('/settings/sso')->assertOk()->assertSee('google-client-id')->assertSee(route('sso.callback', 'google'), false)->assertDontSee('super-secret-value');

        $owner = User::factory()->create(['organization_id' => $this->tenant('Some Co', 'business')->id, 'role' => 'owner', 'is_active' => true]);
        $this->actingAs($owner)->get('/settings/sso')->assertOk()->assertSee('Connected')->assertDontSee('google-client-id')->assertDontSee('Platform keys');
    }

    public function test_microsoft_email_is_trusted_only_when_microsoft_vouches_for_it(): void
    {
        $verified = new ReflectionMethod(SsoGateway::class, 'microsoftEmailIsVerified');
        $gateway = app(SsoGateway::class);
        $consumer = '9188040d-6c67-4c5b-b112-36a304b66dad';

        $this->assertTrue($verified->invoke($gateway, ['email' => 'a@b.test', 'xms_edov' => true, 'tid' => 'some-company-tenant']));
        $this->assertTrue($verified->invoke($gateway, ['email' => 'a@b.test', 'xms_edov' => '1', 'tid' => 'some-company-tenant']));
        $this->assertTrue($verified->invoke($gateway, ['email' => 'a@b.test', 'tid' => $consumer]));
        // Anyone can type any email into their own work directory, so an unvouched work email is not enough.
        $this->assertFalse($verified->invoke($gateway, ['email' => 'ceo@victim.test', 'tid' => 'attacker-tenant']));
        $this->assertFalse($verified->invoke($gateway, ['email' => 'ceo@victim.test', 'xms_edov' => false, 'tid' => 'attacker-tenant']));
        $this->assertFalse($verified->invoke($gateway, ['xms_edov' => true, 'tid' => 'some-company-tenant']));
    }

    private function microsoftClaims(array $claims, string $tenant = 'common'): array
    {
        $token = fn (array $payload) => 'header.'.rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=').'.signature';
        $provider = (new MicrosoftProvider(Request::create('/'), 'our-client-id', 'secret', 'https://app.test/cb'))->tenant($tenant);
        $method = new ReflectionMethod($provider, 'claims');

        return $method->invoke($provider, $token($claims));
    }

    public function test_a_valid_microsoft_id_token_is_read(): void
    {
        $claims = $this->microsoftClaims([
            'aud' => 'our-client-id', 'tid' => 'tenant-1', 'iss' => 'https://login.microsoftonline.com/tenant-1/v2.0',
            'exp' => time() + 600, 'oid' => 'user-9', 'email' => 'meera@buildco.test',
        ]);

        $this->assertSame('meera@buildco.test', $claims['email']);
    }

    public function test_a_microsoft_id_token_for_another_app_issuer_tenant_or_time_is_refused(): void
    {
        $good = ['aud' => 'our-client-id', 'tid' => 'tenant-1', 'iss' => 'https://login.microsoftonline.com/tenant-1/v2.0', 'exp' => time() + 600];

        foreach ([
            ['aud' => 'someone-elses-app'],
            ['iss' => 'https://evil.test/tenant-1/v2.0'],
            ['exp' => time() - 10],
            ['tid' => null],
        ] as $change) {
            try {
                $this->microsoftClaims([...$good, ...$change]);
                $this->fail('Expected the token to be refused: '.json_encode($change));
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        // A deployment tied to one directory refuses users from any other.
        $this->expectException(RuntimeException::class);
        $this->microsoftClaims($good, tenant: 'tenant-2');
    }

    public function test_a_microsoft_response_without_an_id_token_fails_cleanly(): void
    {
        $this->expectException(RuntimeException::class);
        $provider = new MicrosoftProvider(Request::create('/'), 'our-client-id', 'secret', 'https://app.test/cb');
        (new ReflectionMethod($provider, 'claims'))->invoke($provider, '');
    }
}
