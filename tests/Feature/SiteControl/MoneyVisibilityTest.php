<?php

namespace Tests\Feature\SiteControl;

use App\Models\BillNote;
use App\Models\PurchaseOrder;
use App\Models\RaBill;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Reflector;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Walks every signed-in GET route, web and API, as each site role and checks that no rupee figure
 * comes back. The fixtures carry unmistakable amounts (BuildsMoneyWorld::MONEY), so a leak shows up
 * as one of those digit strings, or as a rupee sign followed by a number, anywhere in the response,
 * including CSV, spreadsheet and PDF downloads.
 *
 * Routes added later are covered without any change here. A route whose parameters cannot be filled
 * from the fixtures fails the walk and names the model that needs a row in BuildsMoneyWorld.
 */
class MoneyVisibilityTest extends TestCase
{
    use BuildsMoneyWorld, RefreshDatabase;

    /** Routes that serve a stored file or hand off to an outside service, so there is no page to read. */
    private const SKIPPED = [
        'storage.local' => 'serves a file from local storage',
        'sso.redirect' => 'hands off to the identity provider',
        'sso.callback' => 'is called back by the identity provider',
        'notify.data.download' => 'serves a company data export file',
        'notify.selfie.photo' => 'serves a stored selfie',
        'crm.documents.download' => 'serves a stored tender document',
        'api/v1/attendance-selfies/{selfie}/photo' => 'serves a stored selfie',
    ];

    /** Query strings a route needs before it answers with a page. */
    private const QUERY = [
        'measurements.sheet' => ['from' => '2026-01-01', 'to' => '2026-12-31'],
        'ratelib.search' => ['q' => 'rcc'],
        'reports.xlsx' => ['report' => 'boq_ledger'],
        'reports.pdf' => ['report' => 'boq_ledger'],
    ];

    /**
     * Where the owner must see each figure. This is the control: the same walk, the same fixtures,
     * and the sentinels do appear, so the site-role walk cannot pass by never rendering them.
     */
    private const OWNER_SEES = [
        'dashboard' => ['contract_value'],
        'projects.index' => ['contract_value'],
        'projects.show' => ['contract_value'],
        'boq.index' => ['boq_rate'],
        'boq.export' => ['boq_rate'],
        'boq.excel.export' => ['boq_rate'],
        'work-orders.show' => ['wo_rate', 'wo_advance'],
        'ra-bills.index' => ['bill_gross', 'bill_net'],
        'ra-bills.show' => ['bill_rate', 'bill_amount', 'bill_net'],
        'ra-bills.pdf' => ['bill_net'],
        'bill-notes.show' => ['note_total'],
        'approvals.index' => ['bill_net'],
        'workers.index' => ['wage'],
        'petty.index' => ['other_wallet', 'own_wallet'],
        'assets.show' => ['hire_rate', 'fuel_rate', 'repair_cost'],
        'procurement.purchase-orders.show' => ['po_rate', 'po_total'],
        'procurement.purchase-orders.pdf' => ['po_total'],
        'procurement.rfqs.show' => ['quote_rate', 'quote_total'],
        'planning.index' => ['budget'],
        'planning.budget' => ['budget', 'cost_entry'],
        'ncrs.index' => ['rework'],
        'payroll.staff.index' => ['other_claim'],
        'measurements.sheet' => ['boq_rate'],
        'contractor-control.board' => ['wo_rate'],
        'contractor-control.payments.index' => ['payment'],
        'contractor-control.payments.show' => ['payment'],
        'contractor-control.tenders.show' => ['bid'],
        'material_control.challans.show' => ['challan_bill'],
        'project-setup.permits.index' => ['permit_fee'],
        'project-setup.history' => ['revision_value'],
        'alerts.index' => ['alert_money'],
        'api/v1/contractor-payments/{payment}' => ['payment'],
        'api/v1/projects/{project}/alerts' => ['alert_money'],
        'api/v1/projects/{project}/boq' => ['boq_rate'],
        'api/v1/sync/pull' => ['boq_rate', 'other_wallet'],
        'api/v1/projects/{project}/petty-cash' => ['other_wallet'],
        'api/v1/approvals' => ['bill_net'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePdf();
        $this->buildMoneyWorld();
    }

    /** @return array<string, array{0: string}> */
    public static function siteRoles(): array
    {
        return ['engineer' => ['engineer'], 'store' => ['store'], 'viewer' => ['viewer']];
    }

    #[DataProvider('siteRoles')]
    public function test_a_site_role_never_receives_money(string $role): void
    {
        // The engineer holds a wallet and raised a claim, so those two figures are theirs to see.
        $allowed = $role === 'engineer' ? ['own_wallet', 'own_claim'] : [];
        $visited = 0;

        foreach ($this->walk($this->users[$role]) as [$label, $response]) {
            $this->assertNoMoney($response, "{$role} at {$label}", $allowed);
            $visited++;
        }

        $this->assertGreaterThan(150, $visited, 'The walk reached the signed-in routes.');
    }

    public function test_the_owner_sees_every_sentinel_where_it_belongs(): void
    {
        $seen = [];

        foreach ($this->walk($this->users['owner']) as [$label, $response]) {
            $this->assertLessThan(500, $response->getStatusCode(), "owner at {$label} answered {$response->getStatusCode()}: ".$this->errorSummary($response));

            if (! isset(self::OWNER_SEES[$label])) {
                continue;
            }

            $this->assertSame(200, $response->getStatusCode(), "owner at {$label} answered {$response->getStatusCode()}");
            $digits = $this->digits($this->textOf($response));

            foreach (self::OWNER_SEES[$label] as $key) {
                $this->assertStringContainsString($this->needle($key), $digits, "owner at {$label} shows the {$key} figure");
            }

            $seen[] = $label;
        }

        $this->assertEqualsCanonicalizing(array_keys(self::OWNER_SEES), $seen, 'Every control route was visited.');
    }

    public function test_bill_documents_are_refused_rather_than_rendered_without_money(): void
    {
        $bill = $this->rows[RaBill::class];
        $note = $this->rows[BillNote::class];
        $order = $this->rows[PurchaseOrder::class];

        $this->actingAs($this->users['viewer']);
        $this->get(route('ra-bills.pdf', $bill))->assertForbidden();
        $this->get(route('bill-notes.pdf', $note))->assertForbidden();
        $this->get(route('billing-extras.position', $this->project))->assertForbidden();

        $this->actingAs($this->users['store']);
        $this->get(route('procurement.purchase-orders.pdf', $order))->assertForbidden();

        // Accounts sees amounts but not the company's total spend.
        $this->actingAs($this->users['accounts']);
        $this->get(route('planning.budget', $this->project))->assertForbidden();
        $this->actingAs($this->users['pm']);
        $this->get(route('planning.budget', $this->project))->assertOk();
    }

    /**
     * Every signed-in GET route as this person, web first and then the API.
     *
     * @return list<array{0: string, 1: TestResponse}>
     */
    private function walk(User $user): array
    {
        $responses = [];
        $unresolved = [];

        foreach ($this->routes() as [$label, $route]) {
            $isApi = str_starts_with($route->uri(), 'api/');
            $isApi ? Sanctum::actingAs($user) : $this->actingAs($user);

            $parameters = $this->parameters($route);

            if ($parameters === null) {
                $unresolved[] = $label;

                continue;
            }

            $uri = $route->uri();

            foreach ($parameters as $name => $value) {
                $uri = (string) preg_replace('/\{'.preg_quote($name, '/').'\??\}/', (string) $value, $uri);
            }

            $url = '/'.$uri.(isset(self::QUERY[$label]) ? '?'.http_build_query(self::QUERY[$label]) : '');
            $responses[] = [$label, $isApi ? $this->getJson($url) : $this->get($url)];
        }

        $this->assertSame([], $unresolved, 'These routes could not be visited. Add a row for their model to BuildsMoneyWorld, or list them in SKIPPED with a reason: '.implode(', ', $unresolved));

        return $responses;
    }

    /** @return list<array{0: string, 1: RouteDefinition}> */
    private function routes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            if (! in_array('auth', $middleware, true) && ! in_array('auth:sanctum', $middleware, true)) {
                continue;
            }

            $label = $route->getName() ?: $route->uri();

            if (isset(self::SKIPPED[$label]) || isset(self::SKIPPED[$route->uri()])) {
                continue;
            }

            $routes[] = [$label, $route];
        }

        // Web first, then the API, so the Sanctum guard is only switched on once.
        usort($routes, fn ($a, $b) => str_starts_with($a[1]->uri(), 'api/') <=> str_starts_with($b[1]->uri(), 'api/'));

        return $routes;
    }

    /**
     * Fills each route parameter from the fixtures: a plain value by name, else the row for the
     * model the controller asks for. Null when a parameter has nothing to fill it.
     *
     * @return array<string, string|int>|null
     */
    private function parameters(RouteDefinition $route): ?array
    {
        $classes = [];

        foreach ($route->signatureParameters() as $parameter) {
            $classes[$parameter->getName()] = Reflector::getParameterClassName($parameter);
        }

        $values = [];

        foreach ($route->parameterNames() as $name) {
            $value = $this->params[$name] ?? null;
            $class = $classes[$name] ?? null;

            if ($value === null && $class !== null && is_subclass_of($class, Model::class)) {
                $row = $this->rows[$class] ?? $class::query()->first();
                $value = $row?->getRouteKey();
            }

            if ($value === null) {
                return null;
            }

            $values[$name] = $value;
        }

        return $values;
    }

    /** @param  list<string>  $allowed  Figures this person is entitled to, such as their own wallet. */
    private function assertNoMoney(TestResponse $response, string $label, array $allowed): void
    {
        $status = $response->getStatusCode();
        $this->assertLessThan(500, $status, "{$label} answered {$status}: ".$this->errorSummary($response));

        // Redirects, refusals and missing records carry no page.
        if ($status !== 200) {
            return;
        }

        $text = $this->textOf($response);
        $digits = $this->digits($text);

        foreach (array_keys(self::MONEY) as $key) {
            if (! in_array($key, $allowed, true)) {
                $this->assertStringNotContainsString($this->needle($key), $digits, "{$label} shows the {$key} figure");
            }
        }

        // Any other rupee figure, such as a total worked out on the page, is a leak as well.
        $plain = str_replace(['₹', '&#8377;'], '₹', $text);

        foreach ($allowed as $key) {
            $plain = str_replace(inr(self::MONEY[$key]), '', $plain);
        }

        preg_match_all('/₹\s?-?[\d,]*\d(?:\.\d+)?/u', $plain, $found);
        $this->assertSame([], $found[0], "{$label} shows rupee figures: ".implode(', ', array_slice($found[0], 0, 5)));
    }

    /** The exception behind an error, so a failing walk says what broke. */
    private function errorSummary(TestResponse $response): string
    {
        $exception = $response->exceptions->first();

        if ($exception) {
            return get_class($exception).': '.$exception->getMessage().' at '.basename($exception->getFile()).':'.$exception->getLine();
        }

        $content = $response->baseResponse instanceof StreamedResponse ? '' : (string) $response->getContent();

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', strip_tags($content))), 0, 400);
    }

    /** The response as searchable text: HTML, JSON and CSV as they are, a spreadsheet as its cells, a PDF as the page it was made from. */
    private function textOf(TestResponse $response): string
    {
        $content = $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();

        if (str_contains((string) $response->headers->get('content-type'), 'spreadsheetml')) {
            return $this->spreadsheetText($content);
        }

        $this->assertFalse(str_starts_with($content, '%PDF'), 'A real PDF was rendered, so its text could not be checked.');

        return $content;
    }

    private function spreadsheetText(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'money-walk');
        file_put_contents($path, $bytes);

        try {
            $book = IOFactory::load($path);
        } finally {
            unlink($path);
        }

        $lines = [];

        foreach ($book->getAllSheets() as $sheet) {
            foreach ($sheet->toArray(null, true, false) as $row) {
                $lines[] = implode(' ', array_map(fn ($cell) => (string) $cell, $row));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * PDFs come back as the HTML they are rendered from, so their text can be searched like a page.
     * The stand-in answers the same chain of calls the controllers and PdfRenderer make.
     */
    private function fakePdf(): void
    {
        Pdf::shouldReceive('loadView')->andReturnUsing(function (string $view, array $data = []) {
            $html = view($view, $data)->render();

            $document = Mockery::mock(DomPdf::class);
            $document->shouldReceive('setPaper', 'setOption', 'setOptions')->andReturnSelf();
            $document->shouldReceive('output')->andReturn($html);
            $document->shouldReceive('download', 'stream')->andReturn(new Response($html, 200, ['Content-Type' => 'application/pdf']));

            return $document;
        });
    }
}
