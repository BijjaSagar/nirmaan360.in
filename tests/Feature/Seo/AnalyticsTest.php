<?php

namespace Tests\Feature\Seo;

use App\Models\ContactRequest;
use App\Models\RaBill;
use App\Models\User;
use App\Services\Seo\Analytics;
use App\Support\PlatformSettings;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\BuildsTenant;
use Tests\TestCase;

/**
 * What a page hands to the analytics script. Google's code is never in the page itself: the script fetches
 * it after consent (AnalyticsScriptTest), and may only send what is checked here.
 */
class AnalyticsTest extends TestCase
{
    use BuildsTenant, ReadsPageHead, RefreshDatabase;

    private const ID = 'G-AB12CD34EF';

    private const PUBLIC_PAGES = ['/', '/pricing', '/calculators', '/ai', '/contact', '/privacy', '/terms', '/login', '/register', '/hi', '/mr/pricing'];

    /** Any trace of analytics in a page's HTML. */
    private const TRACES = ['googletagmanager', 'google-analytics', 'gtag', 'dataLayer', 'analytics-config', 'analytics-consent', 'data-consent'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function switchOn(bool $includeApp = false): void
    {
        app(PlatformSettings::class)->set(['ga4_measurement_id' => self::ID, 'ga4_include_app' => $includeApp ? '1' : null]);
    }

    private function assertNoTrace(TestResponse $response, string $where): void
    {
        $html = (string) $response->getContent();

        foreach (self::TRACES as $trace) {
            $this->assertStringNotContainsString($trace, $html, "{$where} must carry no analytics, but has \"{$trace}\".");
        }
    }

    /** The page hands Google's address to the script as text; it must never load it by itself. */
    private function assertGoogleIsNotLoadedByThePage(TestResponse $response, string $where): void
    {
        foreach ($this->document($response)->querySelectorAll('script[src], link[href], img[src], iframe[src]') as $tag) {
            $address = (string) ($tag->getAttribute('src') ?: $tag->getAttribute('href'));

            $this->assertDoesNotMatchRegularExpression('/google|doubleclick|gstatic/i', $address, "{$where} loads {$address} without asking.");
        }
    }

    public function test_with_no_measurement_id_nothing_is_drawn_anywhere(): void
    {
        foreach (self::PUBLIC_PAGES as $path) {
            $this->flushSession();
            $this->assertNoTrace($this->get($path)->assertOk(), $path);
        }

        $this->get('/')->assertDontSee('Cookie settings');
        $this->get('/privacy')->assertDontSee('Analytics cookies');

        $this->actingAs($this->member($this->tenant(), 'owner'));
        $this->assertNoTrace($this->get('/dashboard')->assertOk(), '/dashboard');
    }

    public function test_a_bad_id_in_the_database_is_treated_as_no_id(): void
    {
        foreach (['UA-12345-6', 'G-AB12"><script>', 'GTM-ABCDEF', ' '] as $bad) {
            app(PlatformSettings::class)->set(['ga4_measurement_id' => $bad]);

            $this->assertNull(app(Analytics::class)->measurementId());
            $this->assertNoTrace($this->get('/')->assertOk(), "/ with {$bad}");
        }
    }

    public function test_public_pages_get_the_consent_script_but_never_googles_code(): void
    {
        $this->switchOn();

        foreach (self::PUBLIC_PAGES as $path) {
            $this->flushSession();
            app()->setLocale('en');
            $response = $this->get($path)->assertOk();
            $config = $this->analyticsConfig($response);

            $this->assertNotNull($config, "{$path} is counted");
            $this->assertSame(self::ID, $config['id']);
            $this->assertSame('pageview', $config['mode']);
            $this->assertSame([], $config['events']);
            $this->assertGoogleIsNotLoadedByThePage($response, $path);

            $document = $this->document($response);
            $this->assertNotNull($document->querySelector('head template#analytics-consent'), "{$path} carries the consent question");
            $this->assertCount(1, $document->querySelectorAll('script#analytics-config'));
        }
    }

    public function test_consent_starts_as_denied_before_anything_else_happens(): void
    {
        $this->switchOn();
        $html = (string) $this->get('/')->getContent();

        $default = strpos($html, "gtag('consent', 'default'");
        $this->assertNotFalse($default);

        foreach (['ad_storage', 'ad_user_data', 'ad_personalization', 'analytics_storage'] as $signal) {
            $this->assertMatchesRegularExpression("/gtag\\('consent', 'default', \\{[^}]*{$signal}: 'denied'/", $html, "{$signal} starts denied");
        }

        // The defaults are set before the code that can load Google's library or send anything.
        $this->assertLessThan(strpos($html, "gtag('config'"), $default);
        $this->assertLessThan(strpos($html, 'googletagmanager.com/gtag/js'), $default);
        $this->assertSame(1, substr_count($html, 'googletagmanager.com'), 'Google is named once, inside the script that waits for consent.');
    }

    public function test_the_consent_question_offers_accept_and_decline_in_the_visitors_language(): void
    {
        $this->switchOn();

        $this->get('/')->assertSee('May we use Google Analytics cookies to count visits to this website?')
            ->assertSee('data-consent="accept"', false)->assertSee('data-consent="decline"', false)
            ->assertSee('Accept')->assertSee('Decline')->assertSee('Cookie settings');

        $this->flushSession();
        $this->get('/hi')->assertSee('स्वीकार करें')->assertSee('मना करें')->assertSee('कुकी सेटिंग्स');

        $this->flushSession();
        $this->get('/mr')->assertSee('स्वीकारा')->assertSee('नकार द्या')->assertSee('कुकी सेटिंग्ज');
    }

    public function test_the_privacy_page_explains_analytics_only_while_it_is_on(): void
    {
        $this->get('/privacy')->assertDontSee('Google Analytics');

        $this->switchOn();
        $this->get('/privacy')->assertSee('Analytics cookies')->assertSee('It sets cookies only after you accept.')->assertDontSee('screens inside the app');

        $this->switchOn(includeApp: true);
        $this->get('/privacy')->assertSee('screens inside the app');
    }

    public function test_app_screens_are_left_alone_unless_the_admin_includes_them(): void
    {
        $this->seed(DemoSeeder::class);
        Auth::forgetUser();
        $this->switchOn();
        $this->actingAs(User::where('email', 'owner@demo.test')->firstOrFail());
        $bill = RaBill::firstOrFail();
        $screens = ['/dashboard', '/projects', "/projects/{$bill->project_id}", "/projects/{$bill->project_id}/boq", "/ra-bills/{$bill->id}", '/billing', '/settings'];

        foreach ($screens as $path) {
            $this->assertNoTrace($this->get($path)->assertOk(), $path);
        }

        $this->switchOn(includeApp: true);

        foreach ($screens as $path) {
            $response = $this->get($path)->assertOk();
            $config = $this->analyticsConfig($response);

            $this->assertNotNull($config, "{$path} is counted once the admin includes app screens");
            $this->assertSame('pageview', $config['mode']);
            $this->assertGoogleIsNotLoadedByThePage($response, $path);
        }
    }

    public function test_an_app_screen_is_reported_by_its_pattern_never_by_its_address_or_title(): void
    {
        $this->seed(DemoSeeder::class);
        Auth::forgetUser();
        $this->switchOn(includeApp: true);
        $owner = User::where('email', 'owner@demo.test')->firstOrFail();
        $this->actingAs($owner);
        $bill = RaBill::firstOrFail();
        $project = $bill->project;

        $response = $this->get("/projects/{$project->id}/boq?search=steel")->assertOk()->assertSee($project->name);
        $config = $this->analyticsConfig($response);

        $this->assertSame(url('/projects/:project/boq'), $config['location']);
        $this->assertSame('boq.index', $config['title']);

        $this->assertSame(url('/ra-bills/:bill'), $this->analyticsConfig($this->get("/ra-bills/{$bill->id}"))['location']);
        $this->assertSame(url('/dashboard'), $this->analyticsConfig($this->get('/dashboard'))['location']);

        // Nothing that names a person, a company, a project or an amount is handed over.
        foreach (["/projects/{$project->id}", "/projects/{$project->id}/boq", "/ra-bills/{$bill->id}", '/dashboard', '/team', '/billing'] as $path) {
            $handed = json_encode($this->analyticsConfig($this->get($path)->assertOk()), JSON_UNESCAPED_UNICODE);

            foreach ([$project->name, $project->code, $owner->name, $owner->email, $owner->organization->name, $bill->number, (string) (int) $bill->net_payable, (string) (int) $project->contract_value] as $private) {
                $this->assertStringNotContainsString((string) $private, $handed, "{$path} hands over \"{$private}\".");
            }

            $this->assertStringNotContainsString('₹', $handed);
            $this->assertDoesNotMatchRegularExpression('#/\d#', parse_url(json_decode($handed, true)['location'], PHP_URL_PATH) ?? '', "{$path} sends a record id in its address");
        }
    }

    public function test_private_links_sign_in_helpers_and_error_pages_are_never_counted(): void
    {
        $this->seed(DemoSeeder::class);
        Auth::forgetUser();
        $this->switchOn(includeApp: true);

        foreach (['/login/otp' => 200, '/forgot-password' => 200, URL::signedRoute('client.bill', RaBill::firstOrFail()) => 200, '/no-such-page' => 404] as $path => $status) {
            $response = $this->get($path);
            $response->assertStatus($status);
            $this->assertNoTrace($response, $path);
        }

        config(['app.debug' => false, 'buildos.plans.contractor' => null]);
        $this->assertNoTrace($this->get('/pricing')->assertStatus(500), 'the error page shown at /pricing');
    }

    public function test_an_outside_contractor_is_never_counted(): void
    {
        $this->switchOn(includeApp: true);
        $organization = $this->tenant();

        $request = function (User $user): Request {
            $request = Request::create('/dashboard');
            $request->setRouteResolver(fn () => Route::getRoutes()->getByName('dashboard'));
            $request->setUserResolver(fn () => $user);

            return $request;
        };

        $this->assertNotNull(app(Analytics::class)->forPage($request($this->member($organization, 'engineer'))), 'A team member is counted when app screens are included.');
        $this->assertNull(app(Analytics::class)->forPage($request($this->member($organization, 'contractor'))), 'A contractor login is not.');
    }

    public function test_the_query_string_is_dropped_except_for_plain_campaign_labels(): void
    {
        $this->switchOn();

        $config = $this->analyticsConfig($this->get('/contact?type=sales&name=Ramesh+Patil&email=ramesh%40example.com&phone=1234567890&company=Patil+Constructions&amount=450000&utm_source=whatsapp&utm_medium=social&utm_campaign=diwali-2026&utm_term=ramesh&utm_content=1234567890&gclid=abc'));

        $this->assertSame(url('/contact').'?utm_source=whatsapp&utm_medium=social&utm_campaign=diwali-2026', $config['location']);

        $handed = json_encode($config);

        foreach (['Ramesh', 'ramesh', 'Patil', 'example.com', '1234567890', '450000', 'type=sales', 'gclid', 'utm_term', 'utm_content'] as $private) {
            $this->assertStringNotContainsString($private, $handed);
        }

        $this->assertSame(url('/hi/pricing').'?utm_source=newsletter', $this->analyticsConfig($this->get('/hi/pricing?utm_source=newsletter&plan=business'))['location']);
        $this->assertSame(url('/register'), $this->analyticsConfig($this->get('/register?plan=business'))['location']);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function campaignLabels(): array
    {
        return [
            'a word' => ['whatsapp', true],
            'words with a dash' => ['diwali-offer', true],
            'a dated campaign' => ['site_visit_2026-11-10', true],
            'two words' => ['Summer Sale', true],
            'an email' => ['ramesh@example.com', false],
            'a mobile number' => ['1234567890', false],
            'a mobile number with spaces' => ['12345 67890', false],
            'a number with country code' => ['+91-1234567890', false],
            'a sentence with punctuation' => ['Hello, Ramesh!', false],
            'a web address' => ['https://example.com/x', false],
            'something very long' => [str_repeat('a', 65), false],
            'nothing' => ['', false],
            'markup' => ['<b>x</b>', false],
        ];
    }

    #[DataProvider('campaignLabels')]
    public function test_only_plain_labels_count_as_a_campaign(string $value, bool $kept): void
    {
        $this->assertSame($kept, Analytics::isPlainLabel($value));

        $this->switchOn();
        $location = $this->analyticsConfig($this->get('/?'.http_build_query(['utm_campaign' => $value])))['location'];

        $this->assertSame($kept ? url('/').'?'.http_build_query(['utm_campaign' => $value]) : url('/'), $location);
    }

    public function test_a_sent_contact_form_is_counted_once_without_what_was_typed(): void
    {
        $this->switchOn();

        $this->post('/contact', [
            'type' => 'ai_early_access',
            'name' => 'Ramesh Patil',
            'company' => 'Patil Constructions',
            'email' => 'ramesh@example.com',
            'phone' => '1234567890',
            'message' => 'We run four sites in Nashik worth 12 crore.',
        ])->assertRedirect();

        $this->assertSame(1, ContactRequest::count());

        $config = $this->analyticsConfig($this->get('/contact')->assertOk());
        $this->assertSame([['name' => 'generate_lead', 'params' => ['topic' => 'ai_early_access']]], $config['events']);

        $handed = json_encode($config);

        foreach (['Ramesh', 'Patil', 'example.com', '1234567890', 'Nashik', 'crore'] as $typed) {
            $this->assertStringNotContainsString($typed, $handed);
        }

        $this->assertSame([], $this->analyticsConfig($this->get('/contact'))['events'], 'The event is handed over once.');
    }

    public function test_every_kind_of_enquiry_is_counted_and_a_bot_is_not(): void
    {
        $this->switchOn();

        foreach (['sales', 'ai_early_access', 'support'] as $type) {
            $this->post('/contact', ['type' => $type, 'name' => 'A', 'email' => 'a@example.com'])->assertRedirect();
            $this->assertSame([['name' => 'generate_lead', 'params' => ['topic' => $type]]], $this->analyticsConfig($this->get('/contact'))['events']);
        }

        // The hidden field only a bot fills in: nothing is saved and nothing is counted.
        $this->post('/contact', ['type' => 'sales', 'name' => 'Bot', 'email' => 'b@example.com', 'website' => 'spam.test'])->assertRedirect();
        $this->assertSame([], $this->analyticsConfig($this->get('/contact'))['events']);

        // Neither is a form that failed validation.
        $this->post('/contact', ['type' => 'sales', 'name' => '', 'email' => 'not-an-email'])->assertSessionHasErrors();
        $this->assertSame([], $this->analyticsConfig($this->get('/contact'))['events']);
    }

    public function test_nothing_is_kept_for_later_while_analytics_is_off(): void
    {
        $this->post('/contact', ['type' => 'sales', 'name' => 'A', 'email' => 'a@example.com'])->assertRedirect();
        $this->assertNull(session('analytics.events'));

        $this->switchOn();
        $this->assertSame([], $this->analyticsConfig($this->get('/contact'))['events']);
    }

    public function test_a_finished_sign_up_is_counted_on_the_first_app_screen_and_nothing_else_is(): void
    {
        $this->switchOn();

        $this->post('/register', [
            'plan' => 'business',
            'company' => 'Deccan Builders',
            'name' => 'Priya Rane',
            'email' => 'priya@example.com',
            'phone' => '1234567891',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ])->assertRedirect('/dashboard');

        // As on a real next request, the person is read back from the session rather than kept in memory.
        Auth::forgetUser();

        $response = $this->get('/dashboard')->assertOk();
        $config = $this->analyticsConfig($response);

        $this->assertSame('events', $config['mode'], 'The dashboard is not counted as a visit: it only passes the sign-up on.');
        $this->assertSame([['name' => 'sign_up', 'params' => ['method' => 'email', 'plan' => 'business']]], $config['events']);
        $this->assertSame(url('/dashboard'), $config['location']);
        $this->assertSame('dashboard', $config['title']);
        $this->assertNull($this->document($response)->querySelector('template#analytics-consent'), 'Nobody is asked about cookies inside the app.');
        $this->assertGoogleIsNotLoadedByThePage($response, '/dashboard');

        $handed = json_encode($config);

        foreach (['Deccan', 'Priya', 'Rane', 'example.com', '1234567891', 'a-long-password'] as $typed) {
            $this->assertStringNotContainsString($typed, $handed);
        }

        // With the sign-up handed over, the app goes back to carrying no analytics at all.
        $this->assertNoTrace($this->get('/dashboard')->assertOk(), '/dashboard afterwards');
        $this->assertNoTrace($this->get('/projects')->assertOk(), '/projects');
    }

    public function test_a_sign_up_that_fails_is_not_counted(): void
    {
        $this->switchOn();

        $this->post('/register', ['company' => 'X', 'name' => 'Y', 'email' => 'not-an-email', 'password' => 'short'])->assertSessionHasErrors();

        $this->assertSame([], $this->analyticsConfig($this->get('/register'))['events']);
    }

    public function test_starting_a_plan_checkout_is_counted_without_any_amount(): void
    {
        $this->switchOn();
        config(['services.razorpay.key_id' => 'rzp_test_key', 'services.razorpay.key_secret' => 'test_secret']);
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'order_abc'])]);
        $owner = $this->member($this->tenant('Checkout Co', 'free'), 'owner');
        $this->actingAs($owner);

        $response = $this->post('/billing/checkout', ['plan' => 'contractor', 'cycle' => 'yearly'])->assertOk()->assertSee('order_abc');
        $config = $this->analyticsConfig($response);

        $this->assertSame('events', $config['mode']);
        $this->assertSame([['name' => 'begin_checkout', 'params' => ['plan' => 'contractor', 'cycle' => 'yearly']]], $config['events']);
        $this->assertSame(url('/billing/checkout'), $config['location']);

        $handed = json_encode($config);

        // The payment page itself shows the amount. What is handed to analytics must not.
        foreach (['29990', '29,990', '35388', '35,388', '5398', 'order_abc', 'rzp_test_key', 'Checkout Co', $owner->email, $owner->name] as $private) {
            $this->assertStringNotContainsString($private, $handed);
        }

        foreach (['value', 'currency', 'price', 'items', 'transaction_id'] as $moneyField) {
            $this->assertArrayNotHasKey($moneyField, $config['events'][0]['params']);
        }
    }

    public function test_a_checkout_that_cannot_start_is_not_counted(): void
    {
        $this->switchOn();
        $this->actingAs($this->member($this->tenant(), 'owner'));

        // No payment keys are set, so the checkout is refused.
        $this->post('/billing/checkout', ['plan' => 'contractor', 'cycle' => 'monthly'])->assertSessionHasErrors('plan');

        $this->assertNull(session('analytics.events'));
    }

    public function test_an_event_can_only_carry_values_from_its_own_list(): void
    {
        $this->switchOn();
        $analytics = app(Analytics::class);
        $request = Request::create('/contact');
        $request->setLaravelSession(app('session.store'));

        $analytics->record($request, 'generate_lead', ['topic' => 'ramesh@example.com', 'email' => 'ramesh@example.com', 'name' => 'Ramesh']);
        $analytics->record($request, 'sign_up', ['method' => 'email', 'plan' => 'Patil Constructions', 'value' => 2999]);
        $analytics->record($request, 'begin_checkout', ['plan' => 'enterprise', 'cycle' => 'weekly', 'amount' => '29990.00']);
        $analytics->record($request, 'purchase', ['value' => 29990]);
        $analytics->record($request, 'page_view', ['page_location' => 'https://example.com/?email=ramesh@example.com']);

        $this->assertSame([
            ['name' => 'generate_lead', 'params' => []],
            ['name' => 'sign_up', 'params' => ['method' => 'email']],
            ['name' => 'begin_checkout', 'params' => []],
        ], session('analytics.events'), 'Unknown events are dropped whole, and unknown values are dropped from known ones.');

        $this->assertStringNotContainsString('ramesh', json_encode(session('analytics.events')));
    }

    public function test_the_values_an_event_may_carry_are_short_fixed_words(): void
    {
        $events = app(Analytics::class)->events();

        $this->assertSame(['sign_up_start', 'sign_up', 'begin_checkout', 'generate_lead', 'calculator_use'], array_keys($events));

        foreach ($events as $event => $details) {
            $this->assertMatchesRegularExpression('/^[a-z_]{3,40}$/', $event);

            foreach ($details as $detail => $values) {
                foreach ([...$values, $detail] as $word) {
                    $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{1,30}$/', $word, "{$event}.{$detail} allows \"{$word}\"");
                }
            }
        }

        // Plans are sent by their key, which the admin cannot rename, not by the label on the website.
        $this->assertSame(['free', 'contractor', 'business', 'offline'], $events['sign_up']['plan']);
        $this->assertNotContains('enterprise', $events['begin_checkout']['plan']);
        $this->assertNotContains('free', $events['begin_checkout']['plan']);
    }

    public function test_the_calculator_names_match_the_calculators_on_the_page(): void
    {
        preg_match('/tabs: \{(.+?)\}/', (string) file_get_contents(resource_path('views/public/calculators.blade.php')), $tabs);
        preg_match_all('/(\w+): @js/', $tabs[1], $names);

        $this->assertSame($names[1], app(Analytics::class)->events()['calculator_use']['calculator']);
    }

    public function test_the_pages_carry_the_hooks_the_script_listens_for(): void
    {
        $this->switchOn();

        $calculators = $this->document($this->get('/calculators'))->querySelector('[data-track="calculator_use"]');
        $this->assertNotNull($calculators);
        $this->assertSame('concrete', $calculators->getAttribute('data-track-calculator'));
        $this->assertSame('tab', $calculators->getAttribute(':data-track-calculator'), 'Alpine keeps the name in step with the open calculator.');
        $this->assertGreaterThan(5, $calculators->querySelectorAll('input, select')->length);

        foreach (['free', 'contractor', 'business', 'offline'] as $plan) {
            $form = $this->document($this->get("/register?plan={$plan}"))->querySelector('form[data-track="sign_up_start"]');

            $this->assertSame($plan, $form?->getAttribute('data-track-plan'));
            $this->assertNotNull($form->querySelector('input[name="email"]'));
        }
    }
}
