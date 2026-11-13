<?php

namespace Tests\Feature\Website;

use App\Models\ContactRequest;
use App\Services\Agents\Autonomy;
use App\Services\DocumentReading\AiSpend;
use App\Services\Website\AiOffer;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\BuildsTenant;
use Tests\Feature\DocumentReading\Support\FakeKind;
use Tests\Feature\DocumentReading\Support\FakeSlipKind;
use Tests\Feature\DocumentReading\Support\FakeTextKind;
use Tests\TestCase;

/**
 * What the website says about AI on the AI page, the home page and the pricing page.
 * It may describe only what is built, name it as live only while it can run, and show a helper only once
 * its module is deployed and a reader only while the readers are on (AiSpend::readersOn). With no key
 * saved the pages say early access; with one, nothing on them says early access or not live.
 */
class AiPagesTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    /** Promises the pages used to make about things that are not built. */
    private const NOT_BUILT = [
        'WhatsApp', 'voice note', 'Labour and Payroll', 'Safety and Quality', 'Takeoff', 'DSR', 'Tender Scout', 'GeM',
        'Bills and Challan', 'matches PO', 'Collections', 'promises to pay', 'Act within limits', 'This is the default',
        'stock-outs', 'issue slips', 'AI roadmap', 'planned feature', 'We are building these', 'are being built',
    ];

    /** Cards that work from the company's own records, with no AI model. Each of these modules is built. */
    private const CODE_ONLY = ['RA bill pre-check', 'Rate helper', 'Hindrance register and letters', 'Home-owner pack'];

    /** Cards that send a page, a photo or words to the AI model. None of these readers is built yet. */
    private const NEEDS_MODEL = ['Scanned BOQ reader', 'Challan and bill photo', 'Tender reader'];

    /** The routes the built helpers' cards wait for: the names their modules really register. */
    private const BUILT_ROUTES = ['bill-checks.run', 'rate-suggestions.store', 'site-diary.hindrances.index', 'owner-updates.index'];

    /** The routes the readers not built yet wait for, by the names the design gives them. The tests stand in for them. */
    private const NOT_BUILT_ROUTES = ['boq-scans.create', 'slip-reads.store', 'tender-reads.show'];

    /** Card => the route it waits for. */
    private const CARD_ROUTES = [
        'Scanned BOQ reader' => 'boq-scans.create',
        'Challan and bill photo' => 'slip-reads.store',
        'Tender reader' => 'tender-reads.show',
        'RA bill pre-check' => 'bill-checks.run',
        'Rate helper' => 'rate-suggestions.store',
        'Hindrance register and letters' => 'site-diary.hindrances.index',
        'Home-owner pack' => 'owner-updates.index',
        'Voice DPR' => AiOffer::VOICE_ROUTE,
    ];

    private const OWNER_PACK_LANGUAGES = 'A quotation from your BOQ with a stage-payment schedule, and a weekly update of the work in your approved daily reports and measurements, in Marathi, Hindi or English.';

    private const OWNER_PACK_NO_LANGUAGES = 'For the home owner: a quotation from your BOQ with a stage-payment schedule, and a weekly update of the work in your approved daily reports and measurements.';

    private const LIVE_INTRO = 'The agents work on the records already in your account. What an agent prepares is saved as a draft and marked as one, and nothing it prepares is approved, posted to stock, billed or sent outside your company until a person confirms it.';

    private const NOT_LIVE_INTRO = 'These agents work on the records already in your account. None of them are live yet. Tell us which one would save you the most time.';

    private const LIVE_TAG = 'Part of the Business and Enterprise plans';

    private const HELPERS = 'Helpers that work from your own records';

    private const PRICING_NOT_LIVE = 'AI agents are in early access and are not part of these plans yet. Prices are in Indian rupees.';

    private const PRICING_AGENTS = 'AI agents are part of the Business and Enterprise plans and use AI credits. Prices are in Indian rupees.';

    private const PRICING_READERS = 'AI agents and readers are part of the Business and Enterprise plans and use AI credits. Prices are in Indian rupees.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Showing a page must never reach the AI provider, key or no key.
        Http::fake();
    }

    private function settings(array $values): void
    {
        app(PlatformSettings::class)->set($values);
    }

    private function connect(): void
    {
        $this->settings(['anthropic_api_key' => 'test-key-value']);
    }

    /** What the platform admin does: the release switch, with the provider's monthly cap on record. */
    private function release(): void
    {
        $this->settings([AiOffer::RELEASED => '1', 'ai_tier_monthly_cap_usd' => '1000']);
    }

    /** Stands in for the reader modules: the kinds they register. Without one, no reader is deployed. */
    private function deployReaderKinds(): void
    {
        config(['modules.reader_stand_ins.read_kinds' => [FakeKind::class, FakeTextKind::class, FakeSlipKind::class]]);
    }

    /** Stands in for a later module going live: a route with the name its card waits for. */
    private function deploy(string ...$names): void
    {
        foreach ($names as $name) {
            Route::get('/_card-test/'.str_replace('.', '/', $name), fn () => '')->name($name);
        }

        Route::getRoutes()->refreshNameLookups();
    }

    /**
     * The cards among these whose module is really in the app, before any deploy() in the test. A module
     * that has landed registers its route, so its card is rightly shown; only the others can be absent.
     *
     * @param  list<string>  $cards
     * @return list<string>
     */
    private function landed(array $cards): array
    {
        return array_values(array_filter($cards, fn (string $card) => Route::has(self::CARD_ROUTES[$card])));
    }

    /**
     * @param  list<string>  $cards
     * @return list<string>
     */
    private function notLanded(array $cards): array
    {
        return array_values(array_diff($cards, $this->landed($cards)));
    }

    /** The page shows the cards of the modules that have landed among $offered, under the helpers' heading, and no other card. */
    private function assertOnlyLandedCards(TestResponse $page, array $offered): void
    {
        $landed = $this->landed($offered);
        $landed === [] ? $page->assertDontSee(self::HELPERS) : $page->assertSee(self::HELPERS);
        // No reader is among them, so the page never says "Readers".
        $page->assertDontSee('<h2 class="text-[30px]">Readers</h2>', false);

        foreach ($landed as $card) {
            $page->assertSee($card);
        }

        foreach (array_diff(array_keys(self::CARD_ROUTES), $landed) as $card) {
            $page->assertDontSee($card);
        }
    }

    /** A new visitor with no cookies, so an earlier request's language does not carry over. */
    private function asNewVisitor(): void
    {
        $this->flushSession();
        app()->setLocale('en');
    }

    /** @return list<string> Text passed through __() while $work runs. */
    private function recorded(callable $work): array
    {
        $translator = app('translator');
        $translator->startRecording();

        try {
            $work();
        } finally {
            return $translator->stopRecording();
        }
    }

    /** Short capital-letter terms such as MT, GST or BOQ read the same in every language. */
    private function mayStayAsIs(string $text): bool
    {
        return (bool) preg_match('/^[A-Z0-9][A-Z0-9 \/.#&%-]*s?$/', $text) && strlen($text) <= 8;
    }

    public function test_the_ai_page_describes_the_agents_that_are_built_and_no_others(): void
    {
        $offer = app(AiOffer::class);
        $built = array_map('basename', glob(app_path('Services/Agents/Definitions/*'), GLOB_ONLYDIR));

        $this->assertNotEmpty($built);
        $this->assertEqualsCanonicalizing($built, $offer->agentKeys(), 'The website lists exactly the agents that have a definition in the code.');

        $page = $this->get('/ai')->assertOk()->assertSee('Agents');

        foreach ($offer->agentKeys() as $index => $key) {
            [, $name, $text] = $offer->agents()[$index];

            $this->assertSame((require app_path("Services/Agents/Definitions/{$key}/agent.php"))['name'], $name, 'The website uses the name the app uses.');
            $page->assertSee($name)->assertSee($text);
        }

        $page->assertSee('Type what happened on site in Marathi, Hindi or English.')
            ->assertSee('Drafts client and subcontractor RA bills from approved measurements.')
            ->assertSee('get the answer with links to the records')
            ->assertSee('A morning brief built from your own records.');
    }

    public function test_what_is_not_built_is_no_longer_promised_on_any_page(): void
    {
        $visit = function (string $state) {
            foreach (['/ai', '/', '/pricing'] as $path) {
                $html = $this->get($path)->assertOk()->getContent();

                foreach (self::NOT_BUILT as $phrase) {
                    $this->assertStringNotContainsString($phrase, $html, "{$path} {$state} must not say \"{$phrase}\".");
                }
            }
        };

        $visit('with no key');

        $this->connect();
        $this->release();
        $this->settings([AiOffer::VOICE_ACCEPTED => '1']);
        $this->deploy(AiOffer::VOICE_ROUTE, ...self::NOT_BUILT_ROUTES);

        $visit('with everything deployed and released');
    }

    public function test_only_the_levels_an_agent_can_have_are_listed(): void
    {
        $levels = app(AiOffer::class)->levels();

        $this->assertCount(count(Autonomy::LEVELS), $levels, 'One line per level in App\Services\Agents\Autonomy, and no level it does not have.');

        $page = $this->get('/ai')->assertOk()
            ->assertSee('It prepares a record for a person to submit.')
            ->assertSee('After one tap from an approver it sends the draft into your normal approval chain.')
            ->assertSee('Payments, bill approvals and approved measurements always need a person.');

        foreach ($levels as [$name, $text]) {
            $page->assertSee($name)->assertSee($text);
        }

        $this->assertSame(count(Autonomy::LEVELS), substr_count($page->getContent(), 'rounded-full bg-white/70'), 'The page numbers that many levels.');
    }

    public function test_with_no_key_saved_nothing_is_presented_as_live(): void
    {
        // Even with every later module in place and the release switch set, no key means nothing can run.
        $this->release();
        $this->settings([AiOffer::VOICE_ACCEPTED => '1']);
        $this->deploy(AiOffer::VOICE_ROUTE, ...self::NOT_BUILT_ROUTES);
        $this->deployReaderKinds();

        $offer = app(AiOffer::class);
        $this->assertFalse($offer->agentsLive());
        $this->assertFalse($offer->readersOn());
        $this->assertTrue(app(AiSpend::class)->readerDeployed());

        $page = $this->get('/ai')->assertOk()
            ->assertSee('None of them are live yet')
            ->assertSee('Early access')
            ->assertSee('Request early access')
            ->assertDontSee(self::LIVE_INTRO)
            ->assertDontSee(self::LIVE_TAG)
            ->assertDontSee('<h2 class="text-[30px]">Readers</h2>', false);

        foreach ([...self::NEEDS_MODEL, 'Voice DPR'] as $card) {
            $page->assertDontSee($card);
        }

        $this->get('/pricing')->assertOk()
            ->assertSee(self::PRICING_NOT_LIVE)
            ->assertDontSee('part of the Business and Enterprise plans');

        Http::assertNothingSent();
    }

    public function test_once_connected_the_pages_say_what_the_agents_do_and_which_plans_have_them(): void
    {
        $this->connect();

        // Working: the tag, the intro and the button say so, and nothing says early access or not live.
        $page = $this->get('/ai')->assertOk()
            ->assertSee(self::LIVE_TAG)
            ->assertSee(self::LIVE_INTRO)
            ->assertSee('href="'.url('/pricing').'"', false)
            ->assertSee('See pricing')
            ->assertDontSee('Early access')
            ->assertDontSee('early access')
            ->assertDontSee('Request early access')
            ->assertDontSee('None of them are live yet');

        // Not released: only a helper that works from the company's own records, and only once its module is in.
        $this->assertOnlyLandedCards($page, self::CODE_ONLY);

        $this->get('/')->assertOk()->assertSee(self::LIVE_TAG)->assertDontSee('Early access');

        $this->get('/pricing')->assertOk()
            ->assertSee(self::PRICING_AGENTS)
            ->assertDontSee('readers')
            ->assertDontSee('not part of these plans yet');

        Http::assertNothingSent();
    }

    public function test_the_plans_named_on_the_pricing_page_are_the_plans_that_include_agents(): void
    {
        $this->assertSame(['business', 'enterprise'], config('modules.agents.plans'), 'The pricing line names Business and Enterprise. Change both together.');
        $this->assertSame(['business', 'enterprise'], array_keys(config('modules.agents.plan_credits')), 'Both plans come with AI credits.');

        foreach (config('buildos.plans') as $key => $plan) {
            $this->assertSame(in_array($key, ['business', 'enterprise'], true), in_array('ai_agents', $plan['features'], true), "{$key} plan");
        }

        $this->assertStringStartsWith('Business', config('buildos.plans.business.label'));
        $this->assertStringStartsWith('Enterprise', config('buildos.plans.enterprise.label'));
    }

    public function test_a_reader_or_helper_is_shown_only_once_its_route_is_deployed(): void
    {
        $this->connect();
        $this->release();
        $this->deployReaderKinds();

        $this->assertEqualsCanonicalizing([...self::BUILT_ROUTES, ...self::NOT_BUILT_ROUTES, AiOffer::VOICE_ROUTE], array_values(self::CARD_ROUTES), 'Every card is listed with the route it waits for.');
        $this->assertEqualsCanonicalizing([...self::CODE_ONLY, ...self::NEEDS_MODEL, 'Voice DPR'], array_keys(self::CARD_ROUTES));

        // Before anything is stood in for, the page shows the modules that have really landed, and no other.
        $waiting = $this->notLanded(['RA bill pre-check', 'Rate helper', 'Home-owner pack', 'Challan and bill photo', 'Tender reader', 'Voice DPR']);
        $this->assertOnlyLandedCards($this->get('/ai')->assertOk(), [...self::CODE_ONLY, ...self::NEEDS_MODEL]);

        $this->deploy('boq-scans.create');

        $page = $this->get('/ai')->assertOk()
            ->assertSee('<h2 class="text-[30px]">Readers</h2>', false)
            ->assertSee(self::HELPERS)
            ->assertSee('Hindrance register and letters')
            ->assertSee('Keeps a dated register from your daily reports')
            ->assertSee('Scanned BOQ reader')
            ->assertSee('you confirm every line before anything is saved');

        foreach ($waiting as $card) {
            $page->assertDontSee($card);
        }

        foreach ($this->landed(['RA bill pre-check', 'Rate helper', 'Home-owner pack', 'Challan and bill photo', 'Tender reader']) as $card) {
            $page->assertSee($card);
        }
    }

    public function test_a_card_that_needs_the_model_waits_for_the_release_switch(): void
    {
        $this->deploy(...self::NOT_BUILT_ROUTES);
        $this->deployReaderKinds();
        $this->connect();

        $sees = function (array $shown, array $hidden) {
            $page = $this->get('/ai')->assertOk();

            foreach ($shown as $card) {
                $page->assertSee($card);
            }

            foreach ($hidden as $card) {
                $page->assertDontSee($card);
            }
        };

        // Connected but not released: only what works from the company's own records.
        $sees(self::CODE_ONLY, self::NEEDS_MODEL);

        // Only the exact value the admin screen saves counts as released.
        foreach (['0', 'no', 'true'] as $value) {
            $this->settings([AiOffer::RELEASED => $value]);
            $sees(self::CODE_ONLY, self::NEEDS_MODEL);
        }

        $this->release();
        $this->assertTrue(app(AiOffer::class)->readersOn());
        $sees([...self::CODE_ONLY, ...self::NEEDS_MODEL], ['Voice DPR']);

        // Taking the release back hides them again, and so does taking the monthly cap or the key away.
        $this->settings([AiOffer::RELEASED => null]);
        $sees(self::CODE_ONLY, self::NEEDS_MODEL);

        $this->release();
        $this->settings(['ai_tier_monthly_cap_usd' => null]);
        $sees(self::CODE_ONLY, self::NEEDS_MODEL);

        $this->release();
        $this->settings(['anthropic_api_key' => null]);
        $sees(self::CODE_ONLY, self::NEEDS_MODEL);

        // Each reader's card waits for its own kind: with only the BOQ reader registered, only its card shows.
        $this->connect();
        config(['modules.reader_stand_ins.read_kinds' => [FakeKind::class]]);
        $sees(['Scanned BOQ reader'], ['Challan and bill photo', 'Tender reader']);

        Http::assertNothingSent();
    }

    /**
     * Each helper that is built shows its card by the real name of the route its module registers, with no
     * stand-in, key or release needed: it works from the company's own records. The readers and voice are
     * not built, so no route of theirs exists and their cards stay hidden whatever is switched on.
     */
    public function test_every_built_helper_shows_its_card_by_the_real_name_of_its_route_and_nothing_unbuilt_does(): void
    {
        foreach (self::BUILT_ROUTES as $route) {
            $this->assertTrue(Route::has($route), "{$route} is registered by its module.");
        }

        foreach ([...self::NOT_BUILT_ROUTES, AiOffer::VOICE_ROUTE] as $route) {
            $this->assertFalse(Route::has($route), "{$route} belongs to a reader that is not built yet.");
        }

        // The names the design first gave two of them are not the ones their modules use, and nothing waits for those.
        $this->assertFalse(Route::has('bill-checks.store'));
        $this->assertFalse(Route::has('hindrances.index'));

        $assertCards = function (string $state) {
            $page = $this->get('/ai')->assertOk()->assertSee(self::HELPERS)->assertDontSee('<h2 class="text-[30px]">Readers</h2>', false);

            foreach (self::CODE_ONLY as $card) {
                $page->assertSee($card);
            }

            foreach ([...self::NEEDS_MODEL, 'Voice DPR'] as $card) {
                $page->assertDontSee($card);
            }

            $this->assertSame(self::CODE_ONLY, array_column(app(AiOffer::class)->helpers(), 1), $state);
            $this->assertSame([], app(AiOffer::class)->readers(), $state);
        };

        $assertCards('with no key');

        $this->connect();
        $assertCards('connected');

        $this->release();
        $this->settings([AiOffer::VOICE_ACCEPTED => '1']);
        $assertCards('connected, released and voice accepted');

        $this->get('/ai')->assertOk()
            ->assertSee('Re-adds the bill, compares it with earlier bills, the BOQ and approved measurements')
            ->assertSee('Suggests rates from your own rate library and past work')
            ->assertSee('Keeps a dated register from your daily reports')
            ->assertSee(self::OWNER_PACK_LANGUAGES);

        Http::assertNothingSent();
    }

    /**
     * The home-owner pack's card names Marathi, Hindi and English only while a quotation and an update can be
     * prepared in each. On the live site Hindi and Marathi wait for a native reader's review, so until both are
     * listed as reviewed the card names no language.
     */
    public function test_the_home_owner_pack_card_names_a_language_only_once_its_documents_can_be_written_in_it(): void
    {
        // Anywhere but the live site every language is offered, so that the wording can be read.
        $this->get('/ai')->assertOk()->assertSee(self::OWNER_PACK_LANGUAGES)->assertDontSee(self::OWNER_PACK_NO_LANGUAGES);

        $this->app->detectEnvironment(fn () => 'production');
        $this->assertSame([], config('wording.reviewed_languages.owner_pack'), 'No language is marked reviewed by default.');
        $this->get('/ai')->assertOk()->assertSee(self::OWNER_PACK_NO_LANGUAGES)->assertDontSee(self::OWNER_PACK_LANGUAGES)->assertDontSee('Marathi, Hindi or English, with the figures');

        config(['wording.reviewed_languages.owner_pack' => ['mr']]);
        $this->get('/ai')->assertOk()->assertSee(self::OWNER_PACK_NO_LANGUAGES);

        // Letters being reviewed says nothing about the owner pack's documents.
        config(['wording.reviewed_languages.site_letters' => ['hi', 'mr']]);
        $this->get('/ai')->assertOk()->assertSee(self::OWNER_PACK_NO_LANGUAGES);

        config(['wording.reviewed_languages.owner_pack' => ['mr', 'hi']]);
        $this->get('/ai')->assertOk()->assertSee(self::OWNER_PACK_LANGUAGES);

        // In Marathi too.
        config(['wording.reviewed_languages.owner_pack' => []]);
        $this->asNewVisitor();
        $this->get('/mr/ai')->assertOk()->assertSee(__(self::OWNER_PACK_NO_LANGUAGES, [], 'mr'))->assertDontSee(__(self::OWNER_PACK_LANGUAGES, [], 'mr'));
    }

    public function test_the_voice_card_also_waits_for_the_acceptance_test_on_real_phones(): void
    {
        $this->connect();
        $this->release();
        $this->deploy(AiOffer::VOICE_ROUTE);

        // Voice counts as a reader by its route, so the readers are on; its card waits for the acceptance test.
        $this->assertTrue(app(AiOffer::class)->readersOn());
        $this->get('/ai')->assertOk()->assertDontSee('Voice DPR')->assertDontSee('<h2 class="text-[30px]">Readers</h2>', false)->assertSee(self::HELPERS);

        $this->settings([AiOffer::VOICE_ACCEPTED => '1']);

        $this->get('/ai')->assertOk()
            ->assertSee('<h2 class="text-[30px]">Readers</h2>', false)
            ->assertSee('Voice DPR')
            ->assertSee('on phones that can turn speech into text by themselves')
            ->assertSee('It becomes a draft for you to check and send.');

        // Accepted, but the readers were taken back: the card goes too.
        $this->settings([AiOffer::RELEASED => null]);
        $this->get('/ai')->assertOk()->assertDontSee('Voice DPR');
    }

    public function test_pricing_names_readers_only_when_one_is_deployed_and_released(): void
    {
        $this->connect();

        // Released with nothing deployed: there is no reader to name.
        $this->release();
        $this->get('/pricing')->assertOk()->assertSee(self::PRICING_AGENTS)->assertDontSee('readers');

        // A reader kind registered by a module, but not released.
        $this->settings([AiOffer::RELEASED => null]);
        config(['modules.boq_scan.read_kinds' => [FakeKind::class]]);
        $this->get('/pricing')->assertOk()->assertSee(self::PRICING_AGENTS)->assertDontSee('readers');

        $this->release();
        $this->get('/pricing')->assertOk()->assertSee(self::PRICING_READERS);

        // Released and deployed, but without the provider's monthly cap on record the readers are not on.
        $this->settings(['ai_tier_monthly_cap_usd' => null]);
        $this->get('/pricing')->assertOk()->assertSee(self::PRICING_AGENTS)->assertDontSee('readers');
        $this->release();

        // The voice endpoint counts as a reader too, as it does for the company's consent switch.
        config(['modules.boq_scan.read_kinds' => []]);
        $this->get('/pricing')->assertOk()->assertSee(self::PRICING_AGENTS)->assertDontSee('readers');
        $this->deploy(AiOffer::VOICE_ROUTE);
        $this->get('/pricing')->assertOk()->assertSee(self::PRICING_READERS);
    }

    public function test_the_home_page_uses_the_same_agent_lines_and_an_answer_the_agent_can_give(): void
    {
        $page = $this->get('/')->assertOk()
            ->assertSee('Early access')
            ->assertSee('These are built on your BOQ, measurements and bills.')
            ->assertSee('Tower B मध्ये या महिन्यात किती काँक्रीट मोजले गेले?')
            ->assertSee('The 4 measurement entries are linked below.')
            ->assertSee('An example answer, with sample data')
            ->assertSee('See the AI agents')
            ->assertDontSee('स्टील दिले')
            ->assertDontSee('42.6 MT');

        foreach (app(AiOffer::class)->agents() as [, $name, $text]) {
            $page->assertSee($name)->assertSee($text);
        }

        // The section can still be switched off from the admin screen.
        $this->settings(['section_ai' => '0']);
        $this->get('/')->assertOk()->assertDontSee('See the AI agents')->assertDontSee('A morning brief built from your own records.');
    }

    public function test_every_line_has_hindi_and_marathi_whatever_is_switched_on(): void
    {
        $translator = app('translator');
        $offer = app(AiOffer::class);

        // Everything on: every card, the live wording and the readers line on pricing.
        $this->connect();
        $this->release();
        $this->settings([AiOffer::VOICE_ACCEPTED => '1']);
        $this->deploy(AiOffer::VOICE_ROUTE, ...self::NOT_BUILT_ROUTES);
        $this->deployReaderKinds();

        $texts = $offer->everyLine();

        foreach (['/ai', '/', '/pricing'] as $path) {
            $texts = [...$texts, ...$this->recorded(fn () => $this->get($path)->assertOk())];
        }

        $this->assertContains(self::LIVE_INTRO, $texts);
        $this->assertContains(self::PRICING_READERS, $texts);
        $this->assertContains('Voice DPR', $texts);

        $missing = [];

        foreach (array_unique($texts) as $text) {
            foreach (['hi', 'mr'] as $locale) {
                if ($translator->defaultLine($text, $locale) === $text && ! $this->mayStayAsIs($text)) {
                    $missing[] = "{$locale}: {$text}";
                }
            }
        }

        $this->assertSame([], $missing, 'No translation for: '.implode(' | ', $missing));

        // And the pages do show them in that language.
        $this->asNewVisitor();
        $this->get('/hi/ai')->assertOk()->assertSee('आपके अपने रिकॉर्ड से काम करने वाले सहायक')->assertSee('बोलकर DPR')->assertDontSee(self::HELPERS)->assertDontSee('None of them are live yet');
        $this->asNewVisitor();
        $this->get('/mr/ai')->assertOk()->assertSee('तुमच्या स्वतःच्या नोंदींवरून काम करणारे मदतनीस')->assertSee('घरमालक पॅक')->assertDontSee('Scanned BOQ reader');
        $this->asNewVisitor();
        $this->get('/mr/pricing')->assertOk()->assertSee('AI एजंट आणि रीडर बिझनेस आणि एंटरप्राइज प्लॅनचा भाग आहेत')->assertDontSee(self::PRICING_READERS);
    }

    public function test_the_not_live_wording_is_translated_too(): void
    {
        $this->get('/hi/ai')->assertOk()->assertSee('इनमें से कोई भी अभी चालू नहीं है।')->assertDontSee('None of them are live yet');
        $this->asNewVisitor();
        $this->get('/mr/ai')->assertOk()->assertSee('यापैकी एकही अजून सुरू नाही.')->assertSee('साइटवर काय झाले ते मराठी, हिंदी किंवा इंग्रजीत टाइप करा.');
        $this->asNewVisitor();
        $this->get('/hi')->assertOk()->assertSee('टॉवर B में इस महीने कितना कंक्रीट नापा गया?')->assertSee('AI एजेंट देखें');
    }

    public function test_the_early_access_request_still_works(): void
    {
        $link = url('/contact').'?type=ai_early_access';

        $this->get('/ai')->assertOk()->assertSee('Request early access')->assertSee('href="'.$link.'"', false);

        // Once the agents work, the page no longer asks for early access: the button leads to the plans that have them.
        $this->connect();
        $this->release();
        $this->get('/ai')->assertOk()->assertDontSee('Request early access')->assertDontSee('href="'.$link.'"', false)->assertSee('href="'.url('/pricing').'"', false);
        $this->settings(['anthropic_api_key' => null]);

        $this->get('/contact?type=ai_early_access')->assertOk();
        $this->assertMatchesRegularExpression('/<option value="ai_early_access"\s+selected/', $this->get('/contact?type=ai_early_access')->getContent(), 'The form opens on the AI request.');

        $this->post('/contact', ['type' => 'ai_early_access', 'name' => 'Meera Kulkarni', 'company' => 'Sample Buildcon', 'email' => 'meera@example.com'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(['ai_early_access'], ContactRequest::pluck('type')->all());

        // A Hindi reader stays in Hindi on the way to the form.
        $this->asNewVisitor();
        $this->get('/hi/ai')->assertOk()->assertSee('href="'.url('/hi/contact').'?type=ai_early_access"', false);
    }

    public function test_the_pages_say_the_same_to_everyone_and_hold_no_company_data(): void
    {
        $this->connect();
        $guest = $this->get('/ai')->assertOk()->assertSee(self::LIVE_INTRO);

        // A signed-in owner on a plan without agents sees the same public page: what is offered is not their company's setting.
        $owner = $this->member($this->tenant('Sample Buildcon', 'free'), 'owner');
        $this->actingAs($owner)->get('/ai')->assertOk()->assertSee(self::LIVE_INTRO)->assertDontSee('Sample Buildcon');
        $this->actingAs($owner)->get('/pricing')->assertOk()->assertSee(self::PRICING_AGENTS);

        $this->assertStringNotContainsString('test-key-value', $guest->getContent(), 'The saved key never reaches a page.');
    }

    public function test_wording_that_must_not_be_used_is_absent(): void
    {
        $files = [
            ...glob(resource_path('views/public/*.blade.php')),
            resource_path('views/layouts/public.blade.php'),
            ...glob(lang_path('modules/website/*.json')),
            ...glob(lang_path('modules/site_pitch/*.json')),
            ...glob(lang_path('modules/seo/*.json')),
        ];

        $this->assertGreaterThan(10, count($files));

        foreach ($files as $file) {
            $this->assertSame([], BannedWording::found((string) file_get_contents($file)), 'Not to be said in '.str_replace(base_path().'/', '', $file));
        }

        foreach (app(AiOffer::class)->everyLine() as $line) {
            $this->assertSame([], BannedWording::found($line), $line);
        }

        // The check does find what it looks for, and leaves ordinary sentences alone.
        $this->assertSame(['guaranteed', 'the only'], BannedWording::found('The only reader with guaranteed results.'));
        $this->assertSame([], BannedWording::found('Payments, bill approvals and approved measurements always need a person. Only website pages are counted.'));
        $this->assertGreaterThan(15, count(BannedWording::phrases()));
    }

    /**
     * The tag, the intro, the button, the home page's tag and the pricing line say the same thing in each state of
     * the key, in every language: early access while no key is saved, and nothing of early access once one is.
     */
    public function test_the_tag_intro_button_and_pricing_line_agree_in_each_key_state_in_every_language(): void
    {
        $early = ['Early access', 'Request early access', self::NOT_LIVE_INTRO, self::PRICING_NOT_LIVE];
        $live = [self::LIVE_TAG, self::LIVE_INTRO];
        $words = fn (array $lines, string $locale) => array_map(fn (string $line) => __($line, [], $locale), $lines);

        foreach (['with no key' => false, 'with a key' => true] as $state => $key) {
            $this->settings(['anthropic_api_key' => $key ? 'test-key-value' : null]);

            foreach (['en' => '', 'hi' => '/hi', 'mr' => '/mr'] as $locale => $prefix) {
                $this->asNewVisitor();
                $ai = $this->get($prefix.'/ai')->assertOk();
                $this->asNewVisitor();
                $home = $this->get($prefix.'/')->assertOk();
                $this->asNewVisitor();
                $pricing = $this->get($prefix.'/pricing')->assertOk();

                [$shown, $hidden] = $key ? [$live, $early] : [$early, $live];

                foreach ($words(array_slice($shown, 0, 3), $locale) as $line) {
                    $ai->assertSee($line);
                }

                // The button: a request for early access, or the plans the agents are part of. The home page has its own pricing button.
                $key ? $ai->assertSee(__('See pricing', [], $locale)) : $ai->assertDontSee(__('See pricing', [], $locale));

                foreach ($words($hidden, $locale) as $line) {
                    $ai->assertDontSee($line);
                    $home->assertDontSee($line);
                    $pricing->assertDontSee($line);
                }

                $home->assertSee(__($key ? self::LIVE_TAG : 'Early access', [], $locale));
                $pricing->assertSee(__($key ? self::PRICING_AGENTS : self::PRICING_NOT_LIVE, [], $locale));

                // The agents' own lines are the same on the home page in both states.
                foreach (app(AiOffer::class)->agents() as [, $name, $text]) {
                    $home->assertSee(__($name, [], $locale))->assertSee(__($text, [], $locale));
                }

                // The helpers are never called AI on the page, and the readers' heading waits for a reader.
                $ai->assertSee(__(self::HELPERS, [], $locale))->assertDontSee('<h2 class="text-[30px]">'.__('Readers', [], $locale).'</h2>', false);
            }

            if ($key) {
                foreach (['/ai', '/', '/pricing'] as $path) {
                    $this->asNewVisitor();
                    $html = (string) $this->get($path)->assertOk()->getContent();
                    $this->assertDoesNotMatchRegularExpression('/early access|not live yet|not part of these plans yet/i', $html, "{$path} with a key");
                }
            }
        }

        // The sentence that says the work is a draft names the agents, never the helpers or "the AI" at large.
        $this->assertStringStartsWith('The agents work on the records', self::LIVE_INTRO);
        $this->assertStringContainsString('What an agent prepares', self::LIVE_INTRO);
        $this->assertStringNotContainsString('the AI', self::LIVE_INTRO);
        $this->assertStringNotContainsString('AI', self::HELPERS);
    }
}
