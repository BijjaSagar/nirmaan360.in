<?php

namespace Tests\Feature\Website;

use App\Models\ContactRequest;
use App\Services\Agents\Autonomy;
use App\Services\Website\AiOffer;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\BuildsTenant;
use Tests\TestCase;

/**
 * What the website says about AI on the AI page, the home page and the pricing page.
 * It may describe only what is built, name it as live only while it can run, and show a reader or helper
 * only once that feature is deployed and, where it needs the AI model, released by the platform admin.
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

    /** Cards that work from the company's own records, with no AI model. */
    private const CODE_ONLY = ['RA bill pre-check', 'Rate helper', 'Hindrance register and letters', 'Home-owner pack'];

    /** Cards that send a page, a photo or words to the AI model. */
    private const NEEDS_MODEL = ['Scanned BOQ reader', 'Challan and bill photo', 'Tender reader'];

    private const WEB_ROUTES = [
        'boq-scans.create', 'slip-reads.store', 'tender-reads.show', 'bill-checks.store',
        'rate-suggestions.store', 'hindrances.index', 'owner-updates.index',
    ];

    private const LIVE_INTRO = 'These work on the records already in your account. What the AI prepares is saved as a draft and marked as one. Nothing is approved, posted to stock, billed or sent outside your company until a person confirms it.';

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

    private function release(): void
    {
        $this->settings([AiOffer::RELEASED => '1']);
    }

    /** Stands in for a later module going live: a route with the name its card waits for. */
    private function deploy(string ...$names): void
    {
        foreach ($names as $name) {
            Route::get('/_card-test/'.str_replace('.', '/', $name), fn () => '')->name($name);
        }

        Route::getRoutes()->refreshNameLookups();
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
        $this->deploy(AiOffer::VOICE_ROUTE, ...self::WEB_ROUTES);

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
        $this->deploy(AiOffer::VOICE_ROUTE, ...self::WEB_ROUTES);
        config(['modules.boq_scan.read_kinds' => ['boq' => 'BoqKind']]);

        $offer = app(AiOffer::class);
        $this->assertFalse($offer->agentsLive());
        $this->assertFalse($offer->readersReleased());
        $this->assertFalse($offer->readerDeployed());

        $page = $this->get('/ai')->assertOk()
            ->assertSee('None of them are live yet')
            ->assertSee('Early access')
            ->assertDontSee(self::LIVE_INTRO);

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

        $this->get('/ai')->assertOk()
            ->assertSee(self::LIVE_INTRO)
            ->assertSee('Early access')
            ->assertDontSee('None of them are live yet')
            ->assertDontSee('Readers and helpers');

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

        $page = $this->get('/ai')->assertOk()->assertDontSee('Readers and helpers');

        foreach ([...self::CODE_ONLY, ...self::NEEDS_MODEL, 'Voice DPR'] as $card) {
            $page->assertDontSee($card);
        }

        $this->deploy('hindrances.index', 'boq-scans.create');

        $page = $this->get('/ai')->assertOk()
            ->assertSee('Readers and helpers')
            ->assertSee('Hindrance register and letters')
            ->assertSee('Keeps a dated register from your daily reports')
            ->assertSee('Scanned BOQ reader')
            ->assertSee('you confirm every line before anything is saved');

        foreach (['RA bill pre-check', 'Rate helper', 'Home-owner pack', 'Challan and bill photo', 'Tender reader', 'Voice DPR'] as $card) {
            $page->assertDontSee($card);
        }
    }

    public function test_a_card_that_needs_the_model_waits_for_the_release_switch(): void
    {
        $this->deploy(...self::WEB_ROUTES);
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
        $this->assertTrue(app(AiOffer::class)->readersReleased());
        $sees([...self::CODE_ONLY, ...self::NEEDS_MODEL], ['Voice DPR']);

        // Taking the release back hides them again.
        $this->settings([AiOffer::RELEASED => null]);
        $sees(self::CODE_ONLY, self::NEEDS_MODEL);

        Http::assertNothingSent();
    }

    public function test_the_voice_card_also_waits_for_the_acceptance_test_on_real_phones(): void
    {
        $this->connect();
        $this->release();
        $this->deploy(AiOffer::VOICE_ROUTE);

        $this->get('/ai')->assertOk()->assertDontSee('Voice DPR')->assertDontSee('Readers and helpers');

        $this->settings([AiOffer::VOICE_ACCEPTED => '1']);

        $this->get('/ai')->assertOk()
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
        config(['modules.boq_scan.read_kinds' => ['boq' => 'BoqKind']]);
        $this->get('/pricing')->assertOk()->assertSee(self::PRICING_AGENTS)->assertDontSee('readers');

        $this->release();
        $this->get('/pricing')->assertOk()->assertSee(self::PRICING_READERS);

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
        $this->deploy(AiOffer::VOICE_ROUTE, ...self::WEB_ROUTES);

        $texts = [...$offer->everyLine(), 'Agents', 'Readers and helpers'];

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
        $this->get('/hi/ai')->assertOk()->assertSee('रीडर और सहायक')->assertSee('बोलकर DPR')->assertDontSee('Readers and helpers')->assertDontSee('None of them are live yet');
        $this->asNewVisitor();
        $this->get('/mr/ai')->assertOk()->assertSee('रीडर आणि मदतनीस')->assertSee('घरमालक पॅक')->assertDontSee('Scanned BOQ reader');
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

        $this->connect();
        $this->release();
        $this->get('/ai')->assertOk()->assertSee('Request early access')->assertSee('href="'.$link.'"', false);

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
}
