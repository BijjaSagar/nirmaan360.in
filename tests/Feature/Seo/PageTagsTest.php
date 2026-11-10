<?php

namespace Tests\Feature\Seo;

use App\Models\RaBill;
use App\Models\User;
use App\Services\Seo\PageMeta;
use App\Services\Seo\PublicPages;
use App\Services\Seo\Seo;
use App\Services\Seo\SeoText;
use App\Support\PlatformSettings;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Tests\BuildsTenant;
use Tests\TestCase;

/**
 * What each page tells a search engine: its own title and description, where it lives, its other
 * languages, how it looks when shared, and whether it may be listed at all.
 */
class PageTagsTest extends TestCase
{
    use BuildsTenant, ReadsPageHead, RefreshDatabase;

    /** Every listed website page and its plain address. */
    private const PAGES = [
        'home' => '/',
        'pricing' => '/pricing',
        'calculators' => '/calculators',
        'ai' => '/ai',
        'contact' => '/contact',
        'privacy' => '/privacy',
        'terms' => '/terms',
        'login' => '/login',
        'register' => '/register',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_list_of_pages_here_is_the_list_the_site_uses(): void
    {
        $this->assertSame(array_keys(self::PAGES), app(PublicPages::class)->keys());
    }

    public function test_every_public_page_has_its_own_title_and_description(): void
    {
        $titles = $descriptions = [];

        foreach (self::PAGES as $page => $path) {
            $head = $this->tags($this->get($path)->assertOk());

            $this->assertStringContainsString('Nirmaan360', $head['title'], "{$page} names the site in its title");
            $this->assertLessThanOrEqual(SeoText::TITLE_LENGTH, mb_strlen($head['title']), "{$page} title fits a search result: {$head['title']}");
            $this->assertGreaterThan(20, mb_strlen($head['title']), "{$page} title says something");
            $this->assertNotNull($head['description'], "{$page} has a description");
            $this->assertLessThanOrEqual(SeoText::DESCRIPTION_LENGTH, mb_strlen($head['description']), "{$page} description fits a search result");
            $this->assertGreaterThan(70, mb_strlen($head['description']), "{$page} description says something");
            $this->assertStringNotContainsString(':', str_replace(['Nirmaan360:', 'https:'], '', $head['title'].$head['description']), "{$page} has no unfilled placeholder");

            $titles[$page] = $head['title'];
            $descriptions[$page] = $head['description'];
        }

        $this->assertSame(count(self::PAGES), count(array_unique($titles)), 'No two pages share a title.');
        $this->assertSame(count(self::PAGES), count(array_unique($descriptions)), 'No two pages share a description.');
        $this->assertSame('Construction software for Indian contractors · Nirmaan360', $titles['home']);
        $this->assertSame('Contact the Nirmaan360 team', $titles['contact'], 'The site name is not repeated when the title already has it.');
        $this->assertStringContainsString('Indian builders and contractors', $descriptions['home']);
    }

    public function test_the_wording_makes_no_promise_and_does_not_repeat_keywords(): void
    {
        foreach (self::PAGES as $page => $path) {
            $head = $this->tags($this->get($path));
            $text = strtolower($head['title'].' '.$head['description']);

            foreach (['save', 'saving', 'guarantee', 'cheapest', 'best ', '#1', 'no.1', '%', 'roi', 'profit', 'reduce cost', 'cut cost'] as $promise) {
                $this->assertStringNotContainsString($promise, $text, "{$page} must not say \"{$promise}\".");
            }

            foreach (['construction', 'software', 'contractor', 'builder', 'calculator', 'india'] as $keyword) {
                $this->assertLessThanOrEqual(2, substr_count(strtolower((string) $head['description']), $keyword), "{$page} description repeats \"{$keyword}\".");
            }
        }
    }

    public function test_canonical_is_the_page_itself_without_its_query_string(): void
    {
        foreach (self::PAGES as $page => $path) {
            $this->assertSame(url($path), $this->tags($this->get($path))['canonical'], "{$page} names itself as the one true address");
        }

        $this->assertSame(url('/contact'), $this->tags($this->get('/contact?type=ai_early_access&utm_source=whatsapp'))['canonical']);
        $this->assertSame(url('/register'), $this->tags($this->get('/register?plan=business'))['canonical']);
    }

    public function test_tags_use_the_live_domain_when_the_page_is_served_from_it(): void
    {
        $head = $this->tags($this->get('https://nirmaan360.in/pricing')->assertOk());

        $this->assertSame('https://nirmaan360.in/pricing', $head['canonical']);
        $this->assertSame('https://nirmaan360.in/hi/pricing', $head['alternates']['hi']);
        $this->assertSame('https://nirmaan360.in/images/share.png', $head['meta']['og:image'][0]);
        $this->assertSame('https://nirmaan360.in/pricing', $head['meta']['og:url'][0]);
    }

    public function test_pages_with_language_versions_name_each_of_them(): void
    {
        foreach (['/' => '', '/pricing' => '/pricing', '/calculators' => '/calculators', '/ai' => '/ai', '/contact' => '/contact'] as $path => $tail) {
            $this->assertSame([
                'en' => url($path),
                'mr' => url('/mr'.$tail),
                'hi' => url('/hi'.$tail),
                'x-default' => url($path),
            ], $this->tags($this->get($path))['alternates'], "{$path} lists its English, Marathi and Hindi addresses");
        }
    }

    public function test_pages_with_one_address_claim_no_language_versions(): void
    {
        foreach (['/privacy', '/terms', '/login', '/register'] as $path) {
            $this->assertSame([], $this->tags($this->get($path))['alternates'], "{$path} has one address");
        }
    }

    public function test_the_html_language_matches_what_is_shown(): void
    {
        $this->assertSame('en', $this->tags($this->get('/'))['lang']);
        $this->assertSame('hi', $this->tags($this->get('/hi'))['lang']);
        $this->assertSame('mr', $this->tags($this->get('/mr/pricing'))['lang']);
    }

    public function test_share_tags_are_complete_on_every_public_page(): void
    {
        foreach (self::PAGES as $page => $path) {
            $head = $this->tags($this->get($path));
            $meta = $head['meta'];

            $this->assertSame('website', $meta['og:type'][0], $page);
            $this->assertSame('Nirmaan360', $meta['og:site_name'][0], $page);
            $this->assertSame($head['description'], $meta['og:description'][0], $page);
            $this->assertSame($head['canonical'], $meta['og:url'][0], $page);
            $this->assertSame('en_IN', $meta['og:locale'][0], $page);
            $this->assertSame(asset('images/share.png'), $meta['og:image'][0], $page);
            $this->assertSame(['1200', '630'], [$meta['og:image:width'][0], $meta['og:image:height'][0]], $page);
            $this->assertSame('summary_large_image', $meta['twitter:card'][0], $page);
            $this->assertSame($meta['og:title'][0], $meta['twitter:title'][0], $page);
            $this->assertSame($head['description'], $meta['twitter:description'][0], $page);
            $this->assertSame($meta['og:image'][0], $meta['twitter:image'][0], $page);
            $this->assertStringStartsWith($meta['og:title'][0], $head['title'], "{$page}: the share title is the page title without the site name");

            foreach (['description', 'robots', 'og:title', 'og:image', 'twitter:card'] as $once) {
                $this->assertCount(1, $meta[$once], "{$page} has exactly one {$once} tag");
            }
        }

        $this->assertSame(['mr_IN', 'hi_IN'], $this->tags($this->get('/'))['meta']['og:locale:alternate']);
        $this->assertArrayNotHasKey('og:locale:alternate', $this->tags($this->get('/privacy'))['meta']);
    }

    public function test_the_share_picture_is_a_real_file_of_the_stated_size(): void
    {
        $path = public_path(Seo::SHARE_IMAGE['path']);

        $this->assertFileExists($path);
        [$width, $height, $type] = getimagesize($path);

        $this->assertSame([Seo::SHARE_IMAGE['width'], Seo::SHARE_IMAGE['height']], [$width, $height]);
        $this->assertSame(IMAGETYPE_PNG, $type);
        $this->assertLessThan(300 * 1024, filesize($path), 'Small enough for WhatsApp to show it.');
    }

    public function test_public_pages_may_be_indexed(): void
    {
        foreach ([...array_values(self::PAGES), '/hi', '/mr/pricing', '/hi/contact'] as $path) {
            $this->assertSame(PageMeta::LISTED, $this->tags($this->get($path))['robots'], "{$path} may be listed");
        }
    }

    public function test_signed_in_app_pages_are_noindex(): void
    {
        $this->seed(DemoSeeder::class);
        Auth::forgetUser();
        $this->actingAs(User::where('email', 'owner@demo.test')->firstOrFail());
        $bill = RaBill::firstOrFail();

        foreach (['/dashboard', '/projects', '/approvals', '/team', '/settings', '/billing', "/projects/{$bill->project_id}", "/projects/{$bill->project_id}/boq", "/ra-bills/{$bill->id}", '/notification-settings'] as $path) {
            $head = $this->tags($this->get($path)->assertOk());

            $this->assertSame(PageMeta::HIDDEN, $head['robots'], "{$path} must not be listed");
            $this->assertNull($head['canonical'], "{$path} offers no address to list");
            $this->assertNull($head['description'], "{$path} has no search description");
            $this->assertSame([], $head['alternates']);
            $this->assertArrayNotHasKey('og:title', $head['meta']);
            $this->assertNotSame('', $head['title'], "{$path} keeps its own title");
        }
    }

    public function test_the_platform_admin_area_is_noindex(): void
    {
        $admin = $this->member($this->tenant(), 'owner');
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($admin);

        foreach (['/admin', '/admin/seo', '/admin/website'] as $path) {
            $this->assertSame(PageMeta::HIDDEN, $this->tags($this->get($path)->assertOk())['robots'], $path);
        }
    }

    public function test_private_links_and_sign_in_helpers_are_noindex(): void
    {
        $this->seed(DemoSeeder::class);
        Auth::forgetUser();

        $pages = [
            '/login/otp' => 200,
            '/forgot-password' => 200,
            URL::signedRoute('client.bill', RaBill::firstOrFail()) => 200,
            '/this-page-does-not-exist' => 404,
        ];

        foreach ($pages as $path => $status) {
            $response = $this->get($path);
            $response->assertStatus($status);
            $head = $this->tags($response);

            $this->assertSame(PageMeta::HIDDEN, $head['robots'], "{$path} must not be listed");
            $this->assertNull($head['canonical'], $path);
            $this->assertCount(1, $head['meta']['robots'], "{$path} has one robots tag");
        }
    }

    public function test_an_error_on_a_public_address_is_not_listed_as_that_page(): void
    {
        // The page breaks while it is drawn, so the site's own error page is served from the pricing address.
        config(['app.debug' => false, 'buildos.plans.contractor' => null]);
        $response = $this->get('/pricing');
        $response->assertStatus(500);

        $head = $this->tags($response);
        $this->assertSame(PageMeta::HIDDEN, $head['robots']);
        $this->assertNull($head['canonical']);
    }

    public function test_titles_follow_the_brand_name_and_trial_length_set_by_the_admin(): void
    {
        app(PlatformSettings::class)->set(['site_name' => 'Acme Build', 'trial_days' => '14']);

        $this->assertSame('Construction software for Indian contractors · Acme Build', $this->tags($this->get('/'))['title']);
        $this->assertSame('Contact the Acme Build team', $this->tags($this->get('/contact'))['title']);
        $this->assertSame('Start a 14-day trial · Acme Build', $this->tags($this->get('/register'))['title']);
        $this->assertStringContainsString('for 14 days', (string) $this->tags($this->get('/pricing'))['description']);
    }

    public function test_hindi_and_marathi_pages_have_their_own_wording(): void
    {
        $english = $this->tags($this->get('/pricing'));
        $hindi = $this->tags($this->get('/hi/pricing'));
        $marathi = $this->tags($this->get('/mr/pricing'));

        $this->assertSame('बिल्डरों और ठेकेदारों के लिए कीमत · Nirmaan360', $hindi['title']);
        $this->assertSame('बिल्डर आणि ठेकेदारांसाठी किंमत · Nirmaan360', $marathi['title']);
        $this->assertNotSame($english['description'], $hindi['description']);
        $this->assertNotSame($hindi['description'], $marathi['description']);
        $this->assertSame(url('/hi/pricing'), $hindi['canonical']);
        $this->assertSame('hi_IN', $hindi['meta']['og:locale'][0]);
        $this->assertEqualsCanonicalizing(['en_IN', 'mr_IN'], $hindi['meta']['og:locale:alternate']);
        $this->assertSame($english['alternates'], $hindi['alternates'], 'Every language version lists the same set.');
    }

    public function test_pages_that_are_not_listed_keep_the_title_their_view_gives(): void
    {
        $this->assertSame('Sign in with mobile OTP · Nirmaan360', $this->tags($this->get('/login/otp'))['title']);
        $this->assertSame('Reset your password · Nirmaan360', $this->tags($this->get('/forgot-password'))['title']);
    }
}
