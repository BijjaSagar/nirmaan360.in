<?php

namespace App\Services\Seo;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\URL;

/**
 * The website pages a search engine may list. Every other page is served with noindex,
 * so a new screen stays out of search results until it is named here.
 *
 * A page with "languages" also answers under /hi and /mr (routes/modules/seo.php), which is what
 * lets a search engine tell the Hindi and Marathi versions apart. Only pages whose wording is fully
 * translated should have it: tests/Feature/Seo/LanguageVersionsTest checks.
 */
final class PublicPages
{
    /** Route name prefix of a page's address in another language, such as localized.pricing for /hi/pricing. */
    public const LOCALIZED = 'localized.';

    /**
     * Page => route name, label for the admin screen, own address per language, listed in the sitemap.
     * Titles and descriptions are in SeoText.
     */
    private const PAGES = [
        'home' => ['route' => 'home', 'label' => 'Home', 'languages' => true, 'sitemap' => true],
        'pricing' => ['route' => 'pricing', 'label' => 'Pricing', 'languages' => true, 'sitemap' => true],
        'calculators' => ['route' => 'calculators', 'label' => 'Calculators', 'languages' => true, 'sitemap' => true],
        'ai' => ['route' => 'ai', 'label' => 'AI agents', 'languages' => true, 'sitemap' => true],
        'contact' => ['route' => 'contact', 'label' => 'Contact', 'languages' => true, 'sitemap' => true],
        // The legal text is English only, so these have one address.
        'privacy' => ['route' => 'privacy', 'label' => 'Privacy', 'languages' => false, 'sitemap' => true],
        'terms' => ['route' => 'terms', 'label' => 'Terms', 'languages' => false, 'sitemap' => true],
        'refunds' => ['route' => 'refunds', 'label' => 'Refunds', 'languages' => false, 'sitemap' => true],
        'account-deletion' => ['route' => 'account-deletion', 'label' => 'Delete account', 'languages' => false, 'sitemap' => true],
        // Listed so people searching for the sign-in page find it, but not worth a sitemap line.
        'login' => ['route' => 'login', 'label' => 'Sign in', 'languages' => false, 'sitemap' => false],
        'register' => ['route' => 'register', 'label' => 'Create account', 'languages' => false, 'sitemap' => false],
    ];

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::PAGES);
    }

    public function has(string $page): bool
    {
        return isset(self::PAGES[$page]);
    }

    public function label(string $page): string
    {
        return self::PAGES[$page]['label'];
    }

    public function routeName(string $page): string
    {
        return self::PAGES[$page]['route'];
    }

    public function hasLanguages(string $page): bool
    {
        return self::PAGES[$page]['languages'];
    }

    public function inSitemap(string $page): bool
    {
        return self::PAGES[$page]['sitemap'] && Router::has(self::PAGES[$page]['route']);
    }

    /** @return list<string> Pages that have an address per language. */
    public function withLanguages(): array
    {
        return array_values(array_filter($this->keys(), fn (string $page) => $this->hasLanguages($page)));
    }

    /** The page a route serves, in whichever language, or null when it is not a listed page. */
    public function forRoute(mixed $route): ?string
    {
        $name = $route instanceof Route ? (string) $route->getName() : '';

        if ($name === '') {
            return null;
        }

        if (str_starts_with($name, self::LOCALIZED)) {
            $name = substr($name, strlen(self::LOCALIZED));
        }

        foreach (self::PAGES as $page => $definition) {
            if ($definition['route'] === $name) {
                return $page;
            }
        }

        return null;
    }

    /** The language the website is written in first. Its pages have no prefix. */
    public function defaultLocale(): string
    {
        return (string) array_key_first(config('buildos.locales'));
    }

    /** @return list<string> Languages whose pages sit under their own prefix, such as /hi. */
    public function prefixedLocales(): array
    {
        return array_values(array_diff(array_keys(config('buildos.locales')), [$this->defaultLocale()]));
    }

    /** @return list<string> The languages a page has an address in. */
    public function locales(string $page): array
    {
        return $this->hasLanguages($page) ? array_keys(config('buildos.locales')) : [$this->defaultLocale()];
    }

    /** The language a page is really shown in when the visitor asked for $locale. */
    public function localeFor(string $page, string $locale): string
    {
        return in_array($locale, $this->locales($page), true) ? $locale : $this->defaultLocale();
    }

    /** The path of a page without any language prefix: "" for the home page, "pricing" and so on. */
    public function path(string $page): string
    {
        $route = Router::getRoutes()->getByName($this->routeName($page));

        return trim((string) $route?->uri(), '/');
    }

    /**
     * The full address of a page in a language. Built with URL::to() so it is the same from any page:
     * route() would add the visitor's own language prefix on a /hi or /mr page.
     */
    public function url(string $page, ?string $locale = null): string
    {
        $locale = $this->localeFor($page, $locale ?? $this->defaultLocale());
        $prefix = $locale === $this->defaultLocale() ? '' : $locale;

        return URL::to('/'.trim($prefix.'/'.$this->path($page), '/'));
    }

    /**
     * Where the same website page lives in another language, keeping its query string.
     * Null when the address is not one of this site's pages with an address per language.
     */
    public function translate(string $url, string $locale): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['host'] ?? null) !== parse_url(URL::to('/'), PHP_URL_HOST)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', (string) ($parts['path'] ?? '')), fn ($segment) => $segment !== ''));

        if (in_array($segments[0] ?? null, $this->prefixedLocales(), true)) {
            array_shift($segments);
        }

        $path = implode('/', $segments);

        foreach ($this->withLanguages() as $page) {
            if ($this->path($page) === $path) {
                return $this->url($page, $locale).(isset($parts['query']) ? '?'.$parts['query'] : '');
            }
        }

        return null;
    }
}
