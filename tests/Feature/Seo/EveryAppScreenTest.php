<?php

namespace Tests\Feature\Seo;

use App\Services\Seo\PageMeta;
use App\Support\PlatformSettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Reflector;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\Feature\SiteControl\BuildsMoneyWorld;
use Tests\TestCase;

/**
 * Walks every signed-in web page as the owner, with analytics switched on for app screens, and checks
 * each one: it tells search engines not to list it, and what it hands to analytics is only the route's
 * pattern and name. The fixtures carry real-looking company, client, project and people names and
 * unmistakable amounts, so anything personal that reached analytics would show up here.
 *
 * Screens added later are covered without any change. The walk and its fixtures are the ones
 * MoneyVisibilityTest uses, with whatever each module added in its file in SiteControl/MoneyWorld.
 */
class EveryAppScreenTest extends TestCase
{
    use BuildsMoneyWorld, ReadsPageHead, RefreshDatabase;

    /** Routes that serve a stored file or hand off to an outside service, so there is no page to read. Modules add theirs through $this->skippedRoutes. */
    private const SKIPPED = ['storage.local', 'sso.redirect', 'sso.callback', 'notify.data.download', 'notify.selfie.photo', 'crm.documents.download'];

    /** Query strings a route needs before it answers with a page. */
    private const QUERY = [
        'measurements.sheet' => ['from' => '2026-01-01', 'to' => '2026-12-31'],
        'ratelib.search' => ['q' => 'rcc'],
        'reports.xlsx' => ['report' => 'boq_ledger'],
        'reports.pdf' => ['report' => 'boq_ledger'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // A PDF is drawn from its own view, which is not a page.
        Pdf::shouldReceive('loadView')->andReturnUsing(function (string $view, array $data = []) {
            $document = Mockery::mock(DomPdf::class);
            $document->shouldReceive('setPaper', 'setOption', 'setOptions')->andReturnSelf();
            $document->shouldReceive('output')->andReturn('%PDF');
            $document->shouldReceive('download', 'stream')->andReturn(new Response('%PDF', 200, ['Content-Type' => 'application/pdf']));

            return $document;
        });

        $this->buildMoneyWorld();
        app(PlatformSettings::class)->set(['ga4_measurement_id' => 'G-AB12CD34EF', 'ga4_include_app' => '1']);
    }

    public function test_every_signed_in_page_is_hidden_from_search_and_hands_analytics_nothing_personal(): void
    {
        $owner = $this->users['owner'];
        $this->actingAs($owner);

        $private = [
            $this->organization->name, $this->project->name, $this->project->code, $owner->name, $owner->email,
            $this->users['engineer']->name, $this->users['engineer']->email, 'Kalyani', 'Shree Formwork', 'Birla', 'Tower A',
        ];
        $pages = 0;
        $unfilled = [];

        foreach (Route::getRoutes() as $route) {
            $label = $route->getName() ?: $route->uri();

            if (! in_array('GET', $route->methods(), true)
                || str_starts_with($route->uri(), 'api/')
                || ! in_array('auth', $route->gatherMiddleware(), true)
                || $this->skipsRoute($route, self::SKIPPED)) {
                continue;
            }

            $parameters = $this->parameters($route);

            if ($parameters === null) {
                $unfilled[] = $label;

                continue;
            }

            $uri = $route->uri();

            foreach ($parameters as $name => $value) {
                $uri = (string) preg_replace('/\{'.preg_quote($name, '/').'\??\}/', (string) $value, $uri);
            }

            $response = $this->get('/'.$uri.$this->queryFor($route, self::QUERY));

            if (! $this->isPage($response)) {
                continue;
            }

            $pages++;
            $tags = $this->tags($response);

            $this->assertSame([PageMeta::HIDDEN], $tags['meta']['robots'] ?? [], "{$label} must tell search engines not to list it, once.");
            $this->assertNull($tags['canonical'], "{$label} offers no address to list");
            $this->assertSame([], $tags['alternates'], $label);
            $this->assertArrayNotHasKey('description', $tags['meta'], $label);
            $this->assertArrayNotHasKey('og:title', $tags['meta'], $label);
            $this->assertSame([], $this->structuredData($response), "{$label} carries no structured data");

            $config = $this->analyticsConfig($response);

            if ($config === null) {
                // Only an outside contractor's pages are left out, and the owner is not one.
                $this->fail("{$label} is an app page, but carries no analytics although app screens are included.");
            }

            $pattern = (string) preg_replace('/\{(\w+)\??\}/', ':$1', $route->uri());

            $this->assertSame(url('/'.$pattern), $config['location'], "{$label} is reported by its pattern, with no record id and no query string.");
            $this->assertSame($label, $config['title'], "{$label} is reported by its route name, never by the page's own title.");

            $handed = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            foreach ($private as $word) {
                $this->assertStringNotContainsString((string) $word, $handed, "{$label} hands \"{$word}\" to analytics.");
            }

            $this->assertStringNotContainsString('₹', $handed, $label);

            foreach (array_keys(self::MONEY) as $figure) {
                $this->assertStringNotContainsString($this->needle($figure), $this->digits($handed), "{$label} hands the {$figure} figure to analytics.");
            }

            $this->assertSame([], $config['events'], "{$label} sends no event by itself.");
        }

        $this->assertSame([], $unfilled, $this->unfilledRoutes($unfilled));
        $this->assertGreaterThan(150, $pages, 'The walk reached the signed-in pages.');
    }

    /** A full HTML document answered with 200: not a redirect, a refusal, a download or a fragment. */
    private function isPage(TestResponse $response): bool
    {
        if ($response->getStatusCode() !== 200 || ! str_contains((string) $response->headers->get('content-type'), 'text/html')) {
            return false;
        }

        $content = (string) $response->getContent();

        return str_contains($content, '<html') && str_contains($content, '</head>');
    }

    /**
     * Fills each route parameter from the fixtures, as MoneyVisibilityTest does.
     *
     * @return array<string, string|int>|null Null when a parameter has nothing to fill it.
     */
    private function parameters(RouteDefinition $route): ?array
    {
        $classes = [];

        foreach ($route->signatureParameters() as $parameter) {
            $classes[$parameter->getName()] = Reflector::getParameterClassName($parameter);
        }

        $values = [];

        foreach ($route->parameterNames() as $name) {
            $value = $this->givenParameter($route, $name);
            $class = $classes[$name] ?? null;

            if ($value === null && $class !== null && is_subclass_of($class, Model::class)) {
                $value = ($this->rows[$class] ?? $class::query()->first())?->getRouteKey();
            }

            if ($value === null) {
                return null;
            }

            $values[$name] = $value;
        }

        return $values;
    }
}
