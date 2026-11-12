<?php

namespace Tests\Feature\Seo;

use App\Services\Seo\PageMeta;
use App\Services\Seo\PublicPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use SimpleXMLElement;
use Tests\TestCase;

/** /sitemap.xml and /robots.txt, and the rule that no public page can be added without deciding whether it is listed. */
class SitemapTest extends TestCase
{
    use ReadsPageHead, RefreshDatabase;

    /**
     * Routes a visitor can open without signing in that are not website pages, and why each is left out of search.
     * A new public route must be named here or in App\Services\Seo\PublicPages, or the test below fails.
     */
    private const NOT_LISTED = [
        'brand.logo' => 'an image',
        'seo.sitemap' => 'the sitemap itself',
        'seo.robots' => 'robots.txt',
        'seo.share-image' => 'an image',
        'sanctum.csrf-cookie' => 'sets a cookie for the API',
        'up' => 'the health check',
        'otp.show' => 'a sign-in helper, served noindex',
        'password.request' => 'a sign-in helper, served noindex',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array<string, array<string, string>> Address => its language versions. */
    private function sitemap(): array
    {
        $response = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringStartsWith('application/xml', (string) $response->headers->get('Content-Type'));

        $xml = new SimpleXMLElement((string) $response->getContent());
        $this->assertSame('urlset', $xml->getName());
        $this->assertSame('http://www.sitemaps.org/schemas/sitemap/0.9', $xml->getNamespaces()['']);

        $entries = [];

        foreach ($xml->url as $url) {
            $alternates = [];

            foreach ($url->children('http://www.w3.org/1999/xhtml')->link as $link) {
                $attributes = $link->attributes();
                $this->assertSame('alternate', (string) $attributes['rel']);
                $alternates[(string) $attributes['hreflang']] = (string) $attributes['href'];
            }

            $entries[(string) $url->loc] = $alternates;
        }

        return $entries;
    }

    public function test_the_sitemap_lists_every_public_page_in_every_language_it_has(): void
    {
        $expected = [];

        foreach (['', '/pricing', '/calculators', '/ai', '/contact'] as $path) {
            foreach (['', '/mr', '/hi'] as $prefix) {
                $expected[] = url($prefix.$path ?: '/');
            }
        }

        $expected = [...$expected, url('/privacy'), url('/terms'), url('/refund-policy')];

        $this->assertEqualsCanonicalizing($expected, array_keys($this->sitemap()));
    }

    public function test_each_entry_names_its_language_versions(): void
    {
        $sitemap = $this->sitemap();

        $pricing = ['en' => url('/pricing'), 'mr' => url('/mr/pricing'), 'hi' => url('/hi/pricing'), 'x-default' => url('/pricing')];
        $this->assertSame($pricing, $sitemap[url('/pricing')]);
        $this->assertSame($pricing, $sitemap[url('/hi/pricing')]);
        $this->assertSame($pricing, $sitemap[url('/mr/pricing')]);
        $this->assertSame([], $sitemap[url('/privacy')], 'A page with one address has no alternates.');
    }

    public function test_every_address_in_the_sitemap_opens_and_may_be_indexed_as_itself(): void
    {
        foreach (array_keys($this->sitemap()) as $address) {
            $this->flushSession();
            app()->setLocale('en');
            $tags = $this->tags($this->get($address)->assertOk());

            $this->assertSame(PageMeta::LISTED, $tags['robots'], "{$address} may be listed");
            $this->assertSame($address, $tags['canonical'], "{$address} is its own canonical address");
        }
    }

    public function test_sign_in_pages_and_private_addresses_stay_out_of_the_sitemap(): void
    {
        $addresses = implode("\n", array_keys($this->sitemap()));

        foreach (['/login', '/register', '/dashboard', '/admin', '/locale', '/api', '/forgot-password', '/client', '/invitations'] as $private) {
            $this->assertStringNotContainsString(url($private), $addresses);
        }
    }

    public function test_the_sitemap_uses_the_domain_it_is_fetched_from(): void
    {
        $content = (string) $this->get('https://nirmaan360.in/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<loc>https://nirmaan360.in/pricing</loc>', $content);
        $this->assertStringContainsString('<loc>https://nirmaan360.in/hi</loc>', $content);
    }

    public function test_robots_txt_points_to_the_sitemap_and_lets_crawlers_in(): void
    {
        $response = $this->get('/robots.txt')->assertOk();
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));

        $lines = array_map('trim', explode("\n", (string) $response->getContent()));

        $this->assertContains('User-agent: *', $lines);
        $this->assertContains('Sitemap: '.url('/sitemap.xml'), $lines);
        $this->assertContains('Allow: /', $lines);
        $this->assertNotContains('Disallow: /', $lines, 'The whole site must never be closed to crawlers.');
        $this->assertNotContains('Disallow:', $lines);

        // A page that carries noindex must stay fetchable, or the crawler never sees the noindex.
        foreach ($lines as $line) {
            foreach (['/login', '/client', '/invitations', '/dashboard', '/hi', '/mr', '/pricing'] as $page) {
                $this->assertFalse(str_starts_with($line, 'Disallow:') && str_contains($line, $page), "robots.txt must not close {$page}");
            }
        }

        $this->assertSame('Sitemap: https://nirmaan360.in/sitemap.xml', collect(explode("\n", (string) $this->get('https://nirmaan360.in/robots.txt')->getContent()))->first(fn ($line) => str_starts_with($line, 'Sitemap:')));
    }

    public function test_the_route_serves_robots_txt_because_no_file_shadows_it(): void
    {
        $this->assertFileDoesNotExist(public_path('robots.txt'), 'A file in public/ would be served instead of the route, without the sitemap line.');
        $this->assertFileDoesNotExist(public_path('sitemap.xml'));
    }

    public function test_search_files_start_no_session(): void
    {
        foreach (['/robots.txt', '/sitemap.xml'] as $file) {
            $response = $this->get($file)->assertOk();

            $this->assertSame([], $response->headers->getCookies(), "{$file} sets no cookie");
            $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        }
    }

    public function test_every_public_route_is_either_a_listed_page_or_deliberately_left_out(): void
    {
        $pages = app(PublicPages::class);
        $undecided = [];
        $found = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            $name = $route->getName() ?: $route->uri();
            // A language prefix is part of a page's address, not a record id.
            $plain = str_starts_with((string) $route->getName(), PublicPages::LOCALIZED) ? str_replace('{locale}', '', $route->uri()) : $route->uri();

            // Only pages anyone can open by typing an address: no sign-in, no signed link, no record id.
            if (! in_array('GET', $route->methods(), true)
                || str_starts_with($route->uri(), 'api/')
                || str_contains($plain, '{')
                || array_intersect(['auth', 'auth:sanctum', 'signed'], $middleware) !== []) {
                continue;
            }

            if ($pages->forRoute($route) !== null) {
                $found[] = $pages->forRoute($route);

                continue;
            }

            if (! isset(self::NOT_LISTED[$name])) {
                $undecided[] = $name;
            }
        }

        $this->assertSame([], $undecided, 'New public routes. Add each to App\Services\Seo\PublicPages to list it in search, or to NOT_LISTED here with the reason it is left out: '.implode(', ', $undecided));
        $this->assertEqualsCanonicalizing($pages->keys(), array_values(array_unique($found)), 'Every listed page has a route.');
    }
}
