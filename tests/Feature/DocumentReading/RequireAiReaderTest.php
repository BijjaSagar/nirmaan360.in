<?php

namespace Tests\Feature\DocumentReading;

use App\Http\Middleware\RequireAiReader;
use App\Models\AiDocumentSettings;
use App\Models\User;
use App\Services\Agents\ModelGatewayException;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\ProviderBlock;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use LogicException;

/**
 * The guard on every route that makes the AI model read or write something: what it refuses, in
 * which order, and how a browser and the phone are told. The routes here stand in for the real
 * ones, which arrive with each feature.
 */
class RequireAiReaderTest extends PageReadingTestCase
{
    private const WEB = '/_reader-test';

    private const API = '/api/v1/_reader-test';

    /** How often a guarded route was let through. */
    private int $reached = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();

        $sent = function () {
            $this->reached++;

            return response('sent');
        };

        foreach (['consent', 'text'] as $needs) {
            Route::middleware(['web', 'auth', 'active', RequireAiReader::class.':'.$needs])->post(self::WEB.'/'.$needs, $sent);
            Route::middleware(['api', 'auth:sanctum', RequireAiReader::class.':'.$needs])->post(self::API.'/'.$needs, $sent);
        }

        Route::middleware(['web', 'auth', RequireAiReader::class])->post(self::WEB.'/bare', $sent);
        Route::middleware(['web', 'auth', RequireAiReader::class.':pictures'])->post(self::WEB.'/misspelt', $sent);
    }

    private function platform(array $values): void
    {
        app(PlatformSettings::class)->set($values);
    }

    /** From a browser: a form posted from the page at /dashboard. */
    private function web(User $user, string $needs = 'consent'): TestResponse
    {
        return $this->actingAs($user)->from('/dashboard')->post(self::WEB.'/'.$needs, ['title' => 'Tower A estimate']);
    }

    /** From the phone, with its token. */
    private function api(User $user, string $needs = 'consent'): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson(self::API.'/'.$needs);
    }

    private function assertSentBack(TestResponse $response, string $message): void
    {
        $response->assertRedirect('/dashboard')->assertSessionHasErrors(['ai_reader' => $message]);
    }

    private function assertRefused(TestResponse $response, array $body): void
    {
        $response->assertStatus(409)->assertExactJson($body);
    }

    public function test_a_company_that_may_use_the_readers_gets_through(): void
    {
        $this->web($this->owner)->assertOk()->assertSee('sent');
        $this->web($this->owner, 'text')->assertOk();
        $this->web($this->member($this->organization, 'engineer'))->assertOk();
        $this->api($this->owner)->assertOk();
        $this->api($this->owner, 'text')->assertOk();

        $this->assertSame(5, $this->reached);
        // Deciding whether a call may be made never makes one.
        Http::assertNothingSent();
    }

    public function test_nothing_gets_through_until_the_readers_are_released(): void
    {
        $this->platform(['ai_readers_released' => '0']);

        $this->assertSentBack($this->web($this->owner), 'The readers are not switched on yet.');
        $this->assertSentBack($this->web($this->owner, 'text'), 'The readers are not switched on yet.');
        $this->assertRefused($this->api($this->owner), ['code' => 'not_released', 'message' => 'The readers are not switched on yet.']);
        $this->assertRefused($this->api($this->owner, 'text'), ['code' => 'not_released', 'message' => 'The readers are not switched on yet.']);

        // The switch alone is not a release: the provider's monthly cap must be on record too.
        $this->platform(['ai_readers_released' => '1', 'ai_tier_monthly_cap_usd' => null]);
        $this->assertRefused($this->api($this->owner), ['code' => 'not_released', 'message' => 'The readers are not switched on yet.']);

        $this->assertSame(0, $this->reached);
    }

    public function test_nothing_gets_through_without_a_key_or_while_the_provider_is_refusing_calls(): void
    {
        $words = 'The AI service is not available right now. Try again later.';

        $this->platform(['anthropic_api_key' => null]);
        $this->assertSentBack($this->web($this->owner), $words);
        $this->assertRefused($this->api($this->owner, 'text'), ['code' => 'not_connected', 'message' => $words]);

        $this->connect();
        $this->api($this->owner)->assertOk();

        // Each reason the provider gives stops new work the same way, and none of them is told to a company.
        foreach ([ModelGatewayException::BILLING, ModelGatewayException::REJECTED_KEY, ModelGatewayException::SPEND_LIMIT] as $reason) {
            ProviderBlock::clear();
            ProviderBlock::set($reason);

            $this->assertRefused($this->api($this->owner), ['code' => 'not_connected', 'message' => $words]);
            $this->assertSentBack($this->web($this->owner, 'text'), $words);
        }

        ProviderBlock::clear();
        $this->api($this->owner)->assertOk();
        $this->assertSame(2, $this->reached);
    }

    public function test_a_stop_that_lifts_by_itself_lets_work_through_again_afterwards(): void
    {
        ProviderBlock::set(ModelGatewayException::BILLING);
        $this->api($this->owner)->assertStatus(409);

        $this->travel(ProviderBlock::MINUTES + 1)->minutes();
        $this->api($this->owner)->assertOk();
    }

    public function test_a_plan_without_ai_is_refused_outright(): void
    {
        $small = $this->tenant('Small Works', 'contractor');
        $owner = $this->member($small, 'owner');
        $this->consent($small);

        $this->web($owner)->assertForbidden();
        $this->web($owner, 'text')->assertForbidden();
        $this->api($owner)->assertForbidden()->assertJsonPath('message', 'Your plan does not include this module. Upgrade to continue.');
        $this->assertSame(0, $this->reached);
    }

    public function test_a_company_the_platform_admin_has_blocked_is_refused(): void
    {
        AiDocumentSettings::for($this->organization)->update(['platform_block' => true]);
        $words = 'The readers are switched off for your company. Please contact support.';

        $this->assertSentBack($this->web($this->owner), $words);
        $this->assertSentBack($this->web($this->owner, 'text'), $words);
        $this->assertRefused($this->api($this->owner), ['code' => 'company_limit', 'limit' => 'blocked', 'message' => $words]);
        $this->assertRefused($this->api($this->owner, 'text'), ['code' => 'company_limit', 'limit' => 'blocked', 'message' => $words]);
        $this->assertSame(0, $this->reached);

        // Another company is not touched by it.
        $other = $this->tenant('Godavari Infra');
        $this->consent($other);
        $this->api($this->member($other, 'owner'))->assertOk();
    }

    public function test_a_route_that_sends_documents_needs_the_companys_consent(): void
    {
        $words = 'Your company has not agreed to the current notice for sending documents to the AI service. An owner or admin can do that in Settings.';
        $engineer = $this->member($this->organization, 'engineer');

        foreach ([
            'switched off' => fn () => $this->consent($this->organization, false),
            'an older notice' => fn () => $this->consent($this->organization, true, 'ai-docs-v0'),
            'never decided' => fn () => AiDocumentSettings::withoutGlobalScopes()->where('organization_id', $this->organization->id)->delete(),
        ] as $state => $arrange) {
            $arrange();
            $this->assertFalse(AiDocumentConsent::current($this->organization), $state);

            // The phone is told with a code, whoever asks.
            $this->assertRefused($this->api($this->owner), ['code' => 'consent_required', 'message' => $words]);
            $this->assertRefused($this->api($engineer), ['code' => 'consent_required', 'message' => $words]);

            // In a browser, the people who can agree are taken to the notice; everyone else is told who can.
            $this->web($this->owner)->assertRedirect(route('ai-documents.edit'))
                ->assertSessionHasErrors(['ai_reader' => 'Read the notice and switch the readers on for your company first.']);
            $this->web($this->member($this->organization, 'admin'))->assertRedirect(route('ai-documents.edit'));
            $this->web($engineer)->assertForbidden()->assertSee('An owner or admin can do that in Settings.');
            $this->web($this->member($this->organization, 'pm'))->assertForbidden();

            // A browser request that asks for JSON, as the page uploader does, gets the code as well.
            $this->actingAs($engineer)->postJson(self::WEB.'/consent')->assertStatus(409)->assertJsonPath('code', 'consent_required');
        }

        $this->assertSame(0, $this->reached);

        // A call that sends only labels the code prepared is not under the switch.
        $this->web($engineer, 'text')->assertOk();
        $this->api($engineer, 'text')->assertOk();
        $this->assertSame(2, $this->reached);
    }

    public function test_one_companys_consent_never_opens_the_gate_for_another(): void
    {
        $other = $this->tenant('Godavari Infra');
        $theirOwner = $this->member($other, 'owner');

        $this->api($this->owner)->assertOk();
        $this->assertRefused($this->api($theirOwner), [
            'code' => 'consent_required',
            'message' => 'Your company has not agreed to the current notice for sending documents to the AI service. An owner or admin can do that in Settings.',
        ]);

        $this->consent($other);
        $this->consent($this->organization, false);

        $this->api($theirOwner)->assertOk();
        $this->api($this->owner)->assertStatus(409);
    }

    public function test_a_platform_spending_limit_stops_new_work_for_the_day_or_the_month(): void
    {
        $other = $this->tenant('Godavari Infra');
        $this->consent($other);
        $theirOwner = $this->member($other, 'owner');
        $read = $this->startedRead();

        // Another company used up the day: the limit is the platform's, so it stops everyone.
        $this->platform(['ai_reader_daily_usd' => '5']);
        $theirs = $this->startedRead($theirOwner);
        $this->paidPage($theirs, 1, '5.000000');

        $today = 'The reader is paused for today. Try again tomorrow.';
        $this->assertSentBack($this->web($this->owner), $today);
        $this->assertSentBack($this->web($this->owner, 'text'), $today);
        $this->assertRefused($this->api($this->owner), ['code' => 'reader_paused', 'period' => 'daily', 'message' => $today]);
        $this->assertRefused($this->api($theirOwner, 'text'), ['code' => 'reader_paused', 'period' => 'daily', 'message' => $today]);
        $this->assertSame(0, $this->reached);

        // The next day in India it starts again.
        $this->travelTo(now('Asia/Kolkata')->addDay()->startOfDay()->addHour());
        $this->api($this->owner)->assertOk();

        // The month's limit is a share of the cap the platform admin typed.
        $this->platform(['ai_reader_daily_usd' => '500', 'ai_tier_monthly_cap_usd' => '10']);
        $this->paidPage($read, 2, '6.000000');

        $month = 'The reader is paused for this month.';
        $this->assertRefused($this->api($this->owner), ['code' => 'reader_paused', 'period' => 'monthly', 'message' => $month]);
        $this->assertSentBack($this->web($this->owner), $month);
    }

    public function test_the_first_reason_that_applies_is_the_one_given(): void
    {
        // Everything is wrong at once.
        $this->platform(['ai_readers_released' => '0', 'anthropic_api_key' => null, 'ai_reader_daily_usd' => '1']);
        $this->organization->update(['plan' => 'contractor']);
        AiDocumentSettings::for($this->organization)->update(['platform_block' => true, 'enabled' => false]);
        $this->paidPage($this->startedRead(), 1, '2.000000');

        $code = fn () => $this->api($this->owner->fresh())->json('code');

        $this->assertSame('not_released', $code());

        $this->platform(['ai_readers_released' => '1']);
        $this->assertSame('not_connected', $code());

        $this->connect();
        $this->api($this->owner->fresh())->assertForbidden();

        $this->organization->update(['plan' => 'business']);
        $this->assertSame('company_limit', $code());

        AiDocumentSettings::for($this->organization)->update(['platform_block' => false]);
        $this->assertSame('consent_required', $code());

        $this->consent($this->organization);
        $this->assertSame('reader_paused', $code());

        $this->platform(['ai_reader_daily_usd' => '50']);
        $this->api($this->owner->fresh())->assertOk();
        $this->assertSame(1, $this->reached);
    }

    public function test_a_browser_keeps_what_was_typed_and_never_sees_an_error_page(): void
    {
        $this->platform(['ai_readers_released' => '0']);

        $this->web($this->owner)->assertRedirect('/dashboard')->assertSessionHasInput('title', 'Tower A estimate');

        // Followed as a browser does: the page the person came from, with the reason on it.
        $this->actingAs($this->owner)->from('/dashboard')->followingRedirects()->post(self::WEB.'/consent', ['title' => 'Tower A estimate'])
            ->assertOk()->assertSee('The readers are not switched on yet.');
    }

    public function test_a_refusal_is_written_in_the_persons_language(): void
    {
        $this->platform(['ai_readers_released' => '0']);
        app()->setLocale('mr');

        $this->api($this->owner)->assertStatus(409)->assertJsonPath('message', 'रीडर अजून सुरू केलेले नाहीत.');

        $this->platform(['ai_readers_released' => '1', 'anthropic_api_key' => null]);
        app()->setLocale('hi');
        $this->api($this->owner)->assertJsonPath('message', 'AI सेवा अभी उपलब्ध नहीं है। बाद में फिर कोशिश करें।');
    }

    public function test_no_refusal_is_a_server_error(): void
    {
        $states = [
            fn () => $this->platform(['ai_readers_released' => '0']),
            fn () => $this->platform(['ai_readers_released' => '1', 'anthropic_api_key' => null]),
            function () {
                $this->connect();
                ProviderBlock::set(ModelGatewayException::SPEND_LIMIT);
            },
            function () {
                ProviderBlock::clear();
                AiDocumentSettings::for($this->organization)->update(['platform_block' => true]);
            },
            fn () => AiDocumentSettings::for($this->organization)->update(['platform_block' => false, 'enabled' => false]),
            function () {
                $this->consent($this->organization);
                $this->platform(['ai_reader_daily_usd' => '1']);
                $this->paidPage($this->startedRead(), 1, '2.000000');
            },
        ];

        foreach ($states as $index => $arrange) {
            $arrange();

            foreach ([$this->web($this->owner), $this->api($this->owner)] as $response) {
                $this->assertContains($response->getStatusCode(), [302, 403, 409], "State {$index}");
            }
        }

        $this->assertSame(0, $this->reached);
    }

    public function test_without_a_word_it_asks_for_consent_and_a_misspelt_word_is_a_mistake_in_the_code(): void
    {
        $this->consent($this->organization, false);

        // Forgetting the word must never open a route that sends documents.
        $this->actingAs($this->owner)->post(self::WEB.'/bare')->assertRedirect(route('ai-documents.edit'));

        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);
        $this->actingAs($this->owner)->post(self::WEB.'/misspelt');
    }

    public function test_someone_who_is_not_signed_in_never_reaches_it(): void
    {
        $this->post(self::WEB.'/consent')->assertRedirect(route('login'));
        $this->postJson(self::API.'/consent')->assertUnauthorized();
        $this->assertSame(0, $this->reached);
    }

    public function test_the_guard_sits_on_no_page_that_only_shows_something(): void
    {
        // Looking at a read, its status and its pictures must work when the provider, the limits or the consent do not.
        $guarded = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => collect($route->gatherMiddleware())->contains(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, RequireAiReader::class)));

        $this->assertNotEmpty($guarded);

        foreach ($guarded as $route) {
            $this->assertNotContains('GET', $route->methods(), $route->uri().' only shows something, so it carries no reader guard.');
        }
    }

    protected function tearDown(): void
    {
        Cache::forget(ProviderBlock::KEY);

        parent::tearDown();
    }
}
