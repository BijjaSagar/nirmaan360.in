<?php

namespace Tests\Feature\Seo;

use App\Services\Seo\PublicPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\BuildsTenant;
use Tests\TestCase;

/**
 * Each website page has an address per language: English at the plain address, Hindi under /hi and
 * Marathi under /mr. A search engine keeps no session, so the address is how it asks for a language.
 */
class LanguageVersionsTest extends TestCase
{
    use BuildsTenant, ReadsPageHead, RefreshDatabase;

    /** Page => its path without a language prefix. */
    private const PAGES = ['home' => '', 'pricing' => '/pricing', 'calculators' => '/calculators', 'ai' => '/ai', 'contact' => '/contact'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** A new visitor with no cookies, as a crawler is on every request. */
    private function asNewVisitor(): void
    {
        $this->flushSession();
        app()->setLocale('en');
    }

    /** Short capital-letter terms such as MT, GST or BOQ read the same in every language. */
    private function mayStayAsIs(string $text): bool
    {
        return (bool) preg_match('/^[A-Z0-9][A-Z0-9 \/.#&%-]*s?$/', $text) && strlen($text) <= 8;
    }

    public function test_the_routes_match_the_pages_that_have_languages(): void
    {
        $pages = app(PublicPages::class);
        $this->assertSame(array_keys(self::PAGES), $pages->withLanguages());

        $routed = [];

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), PublicPages::LOCALIZED)) {
                $routed[] = $pages->forRoute($route);
                $this->assertSame('mr|hi', $route->wheres['locale'], 'Only the other languages have a prefix.');
            }
        }

        $this->assertEqualsCanonicalizing($pages->withLanguages(), $routed, 'routes/seo.php has a route for each page with languages, and no other.');
    }

    public function test_each_page_is_served_in_hindi_and_marathi_at_its_own_address(): void
    {
        foreach (self::PAGES as $page => $path) {
            foreach (['hi', 'mr'] as $locale) {
                $this->asNewVisitor();
                $address = '/'.$locale.$path;
                $tags = $this->tags($this->get($address)->assertOk());

                $this->assertSame($locale, $tags['lang'], "{$address} is marked as {$locale}");
                $this->assertSame(url($address), $tags['canonical'], "{$address} names itself");
                $this->assertSame(url($path === '' ? '/' : $path), $tags['alternates']['x-default']);
            }
        }

        $this->asNewVisitor();
        $this->get('/hi')->assertSee('साइट पर तीन चीज़ों का हिसाब नहीं रहता।')->assertDontSee('Three things go untracked on a site.');
        $this->asNewVisitor();
        $this->get('/mr')->assertSee('भारतातील बिल्डर, ठेकेदार आणि साइट इंजिनिअरांसाठी');
    }

    public function test_a_visitor_with_no_cookies_gets_english_at_the_plain_address(): void
    {
        $this->get('/hi')->assertOk();
        $this->asNewVisitor();

        $tags = $this->tags($this->get('/')->assertSee('Three things go untracked on a site.'));
        $this->assertSame('en', $tags['lang']);
        $this->assertSame(url('/'), $tags['canonical']);
    }

    public function test_links_on_a_language_page_stay_in_that_language(): void
    {
        $links = $this->bodyLinks($this->get('/hi/pricing'));

        foreach (['/hi', '/hi/pricing', '/hi/calculators', '/hi/ai', '/hi/contact'] as $path) {
            $this->assertContains(url($path), $links, "A Hindi page links to {$path}");
        }

        foreach (['/pricing', '/calculators', '/ai', '/contact'] as $path) {
            $this->assertNotContains(url($path), $links, "A Hindi page does not send readers to the English {$path}");
        }

        // Pages with one address, and the app, are linked as they are.
        $this->assertContains(url('/privacy'), $links);
        $this->assertContains(url('/terms'), $links);
        $this->assertContains(url('/login'), $links);
        $this->assertContains(url('/hi/contact').'?type=sales', $links, 'A query string is kept after the prefix.');
        $this->assertContains(url('/hi').'#product', $links);
    }

    public function test_the_prefix_does_not_leak_into_later_requests(): void
    {
        $this->get('/mr/pricing')->assertOk();

        $this->assertSame(url('/pricing'), route('pricing'), 'Outside a language page, a link is the plain address again.');

        $this->asNewVisitor();
        $this->assertContains(url('/pricing'), $this->bodyLinks($this->get('/')));
    }

    public function test_only_real_language_versions_exist(): void
    {
        foreach (['/en', '/en/pricing', '/fr', '/hi/privacy', '/hi/terms', '/mr/login', '/hi/register', '/hi/dashboard', '/hi/nothing'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_a_page_offered_in_hindi_and_marathi_is_fully_translated(): void
    {
        $translator = app('translator');
        $missing = [];

        foreach (self::PAGES as $page => $path) {
            $this->asNewVisitor();
            $translator->startRecording();

            try {
                $this->get($path === '' ? '/' : $path)->assertOk();
            } finally {
                $texts = $translator->stopRecording();
            }

            $this->assertGreaterThan(25, count($texts), "The recording caught the {$page} page text.");

            foreach ($texts as $text) {
                foreach (['hi', 'mr'] as $locale) {
                    if ($translator->defaultLine($text, $locale) === $text && ! $this->mayStayAsIs($text)) {
                        $missing[] = "{$page} {$locale}: {$text}";
                    }
                }
            }
        }

        $this->assertSame([], $missing, count($missing).' lines have no translation, so these pages cannot claim a Hindi and Marathi version: '.implode(' | ', array_slice($missing, 0, 40)));
    }

    public function test_placeholders_survive_translation_on_every_language_page(): void
    {
        $translator = app('translator');
        $broken = [];

        foreach (self::PAGES as $page => $path) {
            $this->asNewVisitor();
            $translator->startRecording();

            try {
                $this->get($path === '' ? '/' : $path);
            } finally {
                $texts = $translator->stopRecording();
            }

            foreach ($texts as $text) {
                preg_match_all('/:[a-z_]+/', $text, $expected);

                foreach (['hi', 'mr'] as $locale) {
                    foreach ($expected[0] as $placeholder) {
                        if (! str_contains($translator->defaultLine($text, $locale), $placeholder)) {
                            $broken[] = "{$page} {$locale}: {$text}";
                        }
                    }
                }
            }
        }

        $this->assertSame([], $broken, 'A placeholder was lost in: '.implode(' | ', $broken));
    }

    public function test_choosing_a_language_moves_to_that_languages_address(): void
    {
        $this->from(url('/hi/pricing'))->get('/locale/en')->assertRedirect(url('/pricing'));
        $this->from(url('/pricing'))->get('/locale/mr')->assertRedirect(url('/mr/pricing'));
        $this->from(url('/mr'))->get('/locale/hi')->assertRedirect(url('/hi'));
        $this->from(url('/contact').'?type=support')->get('/locale/hi')->assertRedirect(url('/hi/contact').'?type=support');

        // A page with one address is simply shown again, now in the chosen language.
        $this->from(url('/privacy'))->get('/locale/hi')->assertRedirect(url('/privacy'));
        $this->from(url('/login'))->get('/locale/mr')->assertRedirect(url('/login'));
        $this->get('/login')->assertSee('साइन इन करा');
    }

    public function test_choosing_english_on_a_hindi_page_really_shows_english(): void
    {
        $this->get('/hi/pricing')->assertOk();

        $this->followingRedirects()->from(url('/hi/pricing'))->get('/locale/en')
            ->assertOk()
            ->assertSee('Pay for the sites you run, not for every login.');

        $this->assertSame('en', session('locale'));
    }

    public function test_inside_the_app_the_language_switch_goes_back_to_the_same_screen(): void
    {
        $user = $this->member($this->tenant(), 'owner');
        $this->actingAs($user);

        $this->from(url('/dashboard'))->get('/locale/mr')->assertRedirect(url('/dashboard'));
        $this->assertSame('mr', $user->fresh()->locale);
    }

    public function test_a_first_visit_in_hindi_carries_over_to_the_sign_in_page(): void
    {
        $this->get('/hi')->assertOk();

        $this->get('/login')->assertSee('साइन इन करें');
    }

    public function test_a_language_already_chosen_is_not_overwritten_by_an_address(): void
    {
        $this->get('/locale/en');
        $this->get('/hi')->assertSee('साइट पर तीन चीज़ों का हिसाब नहीं रहता।');

        $this->assertSame('en', session('locale'), 'The page was Hindi, but the choice stays English.');
        $this->get('/login')->assertSee('Sign in')->assertDontSee('साइन इन करें');
    }

    public function test_a_signed_in_persons_saved_language_is_left_alone(): void
    {
        $user = $this->member($this->tenant(), 'owner');
        $user->update(['locale' => 'mr']);
        $this->actingAs($user);

        $this->get('/hi/pricing')->assertOk();

        $this->assertSame('mr', $user->fresh()->locale);
        $this->assertNull(session('locale'));
    }

    public function test_a_plain_address_shown_in_another_language_points_at_that_languages_address(): void
    {
        // Someone who chose Hindi and opens /pricing sees Hindi there. A search engine never does: it has no session.
        $this->get('/locale/hi');
        $tags = $this->tags($this->get('/pricing')->assertOk());

        $this->assertSame('hi', $tags['lang']);
        $this->assertSame(url('/hi/pricing'), $tags['canonical']);
    }
}
