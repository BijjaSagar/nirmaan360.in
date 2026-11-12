<?php

namespace Tests\Feature\ContractorPortal;

use App\Models\Party;
use App\Models\User;
use App\Services\SiteControl\WorkProgress;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * The contractor login's boundary. Signed in as a contractor, every web and API route outside
 * the portal is refused or redirected, and the portal itself returns only that contractor's rows
 * when a second contractor holds a contract on the same project and the same BOQ line.
 */
class ContractorPortalBoundaryTest extends TestCase
{
    use BuildsContractorPortal, RefreshDatabase;

    /** What a contractor login may still reach outside the portal. */
    private const OPEN_NAMES = ['contractor-portal.*', 'logout', 'locale', 'account.delete', 'account.destroy'];

    private const OPEN_URIS = ['api/v1/me', 'api/v1/auth/logout', 'api/v1/auth/account/deletion', 'api/v1/contractor-portal', 'api/v1/contractor-portal/*'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake();
        $this->buildPortalWorld();
    }

    /** Real ids from the world, so a route is turned away by the gate rather than by a missing record. */
    private function fill(string $uri): string
    {
        $known = [
            'project' => $this->project->id, 'workOrder' => $this->workOrder->id, 'bill' => $this->bill->id, 'payment' => $this->payment->id,
            'party' => $this->party->id, 'item' => $this->item->id, 'user' => $this->engineer->id, 'milestone' => $this->milestone->id,
            'organization' => $this->organization->id, 'locale' => 'hi',
        ];

        return (string) preg_replace_callback('/\{(\w+)\??\}/', fn ($match) => (string) ($known[$match[1]] ?? 1), $uri);
    }

    private function isOpen(\Illuminate\Routing\Route $route): bool
    {
        $name = $route->getName();

        return ($name !== null && Str::is(self::OPEN_NAMES, $name)) || Str::is(self::OPEN_URIS, $route->uri());
    }

    public function test_every_signed_in_route_outside_the_portal_turns_a_contractor_away(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            if ((! in_array('auth', $middleware, true) && ! in_array('auth:sanctum', $middleware, true)) || $this->isOpen($route)) {
                continue;
            }

            $routes[] = $route;
        }

        // Web first, then the API, so the Sanctum guard is only switched on once.
        usort($routes, fn ($a, $b) => str_starts_with($a->uri(), 'api/') <=> str_starts_with($b->uri(), 'api/'));
        $checked = 0;

        foreach ($routes as $route) {
            $isApi = str_starts_with($route->uri(), 'api/');
            $isApi ? Sanctum::actingAs($this->contractor) : $this->actingAs($this->contractor);
            $uri = '/'.$this->fill($route->uri());

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $isApi ? $this->json($method, $uri) : $this->call($method, $uri);
                $status = $response->getStatusCode();

                $this->assertContains($status, [302, 403, 404], "{$method} {$route->uri()} answered {$status} to a contractor");

                if ($status === 302) {
                    $this->assertSame(route('contractor-portal.home'), $response->headers->get('Location'), "{$method} {$route->uri()} sent a contractor somewhere other than the portal");
                }

                $checked++;
            }
        }

        $this->assertGreaterThan(500, $checked, 'The route walk found too few routes to prove anything.');
    }

    public function test_a_contractor_is_sent_to_the_portal_from_every_page(): void
    {
        $this->actingAs($this->contractor);

        foreach (['/dashboard', '/projects', "/projects/{$this->project->id}", '/approvals', '/team', '/parties', '/settings', '/audit', '/contractor-control/contractors', "/work-orders/{$this->workOrder->id}"] as $path) {
            $this->get($path)->assertRedirect(route('contractor-portal.home'));
        }

        $this->post("/approvals/{$this->pendingPayment->currentApproval->id}/act", ['action' => 'approve'])->assertForbidden();
        $this->assertSame('IN_REVIEW', $this->pendingPayment->refresh()->status);
    }

    public function test_the_api_refuses_a_contractor_everywhere_but_the_portal(): void
    {
        Sanctum::actingAs($this->contractor);

        $this->getJson('/api/v1/projects')->assertForbidden();
        $this->getJson('/api/v1/sync/pull')->assertForbidden();
        $this->getJson('/api/v1/approvals')->assertForbidden();
        $this->getJson("/api/v1/projects/{$this->project->id}/contractor-control")->assertForbidden();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.kind', 'contractor');
        // A contractor login can delete itself, as any account can.
        $this->getJson('/api/v1/auth/account/deletion')->assertOk()->assertJsonPath('data.mode', 'account');
    }

    public function test_the_portal_lists_only_the_contractors_own_contracts(): void
    {
        $this->actingAs($this->contractor);

        $response = $this->get('/contractor-portal')->assertOk();

        $response->assertSee('Shree Formwork')->assertSee('WO-OURS')->assertSee('Slab formwork')->assertSee('Riverside Tower');
        $response->assertSee(inr('410000.00'))->assertSee(inr(self::OUR_PAYMENT))->assertSee('Paid 6.1%, Work 70%')->assertSee('Before Lakshmi Puja');
        $response->assertDontSee('Dashboard')->assertDontSee('Approvals')->assertDontSee('Settings');
        $this->assertNothingPrivate($response, 'home');
    }

    public function test_the_contract_page_shows_contract_rates_and_quantities_done_and_nothing_of_the_clients(): void
    {
        $this->actingAs($this->contractor);

        $response = $this->get("/contractor-portal/work-orders/{$this->workOrder->id}")->assertOk();

        $response->assertSee($this->item->code)->assertSee(inr(self::OUR_RATE))->assertSee('70.000')->assertSee('30.000')->assertSee('70%');
        $response->assertSee('Plinth complete')->assertSee(inr(self::OUR_MILESTONE))->assertSee('Not done');
        $response->assertSee($this->payment->number)->assertSee('UTR-OURS-1')->assertSee($this->bill->number);
        $response->assertDontSee($this->pendingPayment->number)->assertDontSee($this->draftBill->number);
        $this->assertNothingPrivate($response, 'contract');
    }

    public function test_payments_and_bills_pages_hold_only_the_contractors_own_rows(): void
    {
        $this->actingAs($this->contractor);

        $payments = $this->get('/contractor-portal/payments')->assertOk();
        $payments->assertSee($this->payment->number)->assertSee(inr(self::OUR_PAYMENT))->assertDontSee($this->rivalPayment->number)->assertDontSee($this->pendingPayment->number);
        $this->assertNothingPrivate($payments, 'payments');

        $bills = $this->get('/contractor-portal/bills')->assertOk();
        $bills->assertSee($this->bill->number)->assertDontSee($this->rivalBill->number)->assertDontSee($this->draftBill->number);
        $this->assertNothingPrivate($bills, 'bills');

        $bill = $this->get("/contractor-portal/bills/{$this->bill->id}")->assertOk();
        $bill->assertSee($this->bill->number)->assertSee(inr($this->bill->net_payable))->assertSee('Retention');
        $this->assertNothingPrivate($bill, 'bill');
    }

    public function test_another_contractors_records_and_the_companys_drafts_are_not_found(): void
    {
        $this->actingAs($this->contractor);

        $this->get("/contractor-portal/work-orders/{$this->rivalWorkOrder->id}")->assertNotFound();
        $this->get("/contractor-portal/bills/{$this->rivalBill->id}")->assertNotFound();
        $this->get("/contractor-portal/bills/{$this->rivalBill->id}/pdf")->assertNotFound();
        $this->get("/contractor-portal/bills/{$this->draftBill->id}")->assertNotFound();
        $this->get('/contractor-portal/work-orders/999999')->assertNotFound();
    }

    public function test_the_bill_pdf_is_the_contractors_own_document(): void
    {
        Pdf::shouldReceive('loadView')->andReturnUsing(function (string $view, array $data = []) {
            $html = view($view, $data)->render();
            $document = Mockery::mock(DomPdf::class);
            $document->shouldReceive('setPaper')->andReturnSelf();
            $document->shouldReceive('download')->andReturn(new Response($html, 200, ['Content-Type' => 'application/pdf']));

            return $document;
        });

        $this->actingAs($this->contractor);

        $response = $this->get("/contractor-portal/bills/{$this->bill->id}/pdf")->assertOk();
        $response->assertSee($this->bill->number)->assertSee('Shree Formwork');
        $this->assertNothingPrivate($response, 'bill pdf');
    }

    public function test_staff_cannot_open_the_portal(): void
    {
        foreach ([$this->owner, $this->manager, $this->engineer] as $user) {
            $this->actingAs($user)->get('/contractor-portal')->assertForbidden();
            $this->actingAs($user)->get("/contractor-portal/work-orders/{$this->workOrder->id}")->assertForbidden();
        }

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/contractor-portal')->assertForbidden();
    }

    public function test_another_company_is_out_of_reach(): void
    {
        $other = $this->tenant('Other Co');
        $this->actingAs($this->member($other, 'owner'));
        $foreignParty = Party::create(['organization_id' => $other->id, 'type' => 'subcontractor', 'name' => 'Foreign Formwork']);
        $foreignProject = $this->makeProject($other, ['name' => 'Foreign Works']);
        $foreignItem = $this->makeBoqItem($foreignProject);
        $foreignOrder = $this->contractFor($foreignParty, 'WO-FOREIGN', 'Foreign slab', '10.00', $foreignProject, $foreignItem);
        $this->signOut();

        // A contractor of the other company sees only its own company's contract.
        $this->actingAs($this->contractorLogin($foreignParty));
        $this->get("/contractor-portal/work-orders/{$this->workOrder->id}")->assertNotFound();
        $this->get("/contractor-portal/bills/{$this->bill->id}")->assertNotFound();
        $this->get('/contractor-portal')->assertOk()->assertSee('WO-FOREIGN')->assertDontSee('WO-OURS')->assertDontSee('Shree Formwork');
        $this->signOut();

        // A login in our company whose link points at the other company's party gets nothing at all.
        $mislinked = $this->contractorLogin($foreignParty);
        $mislinked->forceFill(['organization_id' => $this->organization->id])->save();
        $this->actingAs($mislinked);
        $this->get('/contractor-portal')->assertForbidden();
        $this->get("/contractor-portal/work-orders/{$foreignOrder->id}")->assertNotFound();
        $this->get("/contractor-portal/work-orders/{$this->workOrder->id}")->assertForbidden();
        $this->get('/dashboard')->assertRedirect(route('contractor-portal.home'));
    }

    public function test_a_login_whose_party_is_gone_or_no_longer_a_subcontractor_gets_nothing(): void
    {
        $this->actingAs($this->contractor);
        $this->get('/contractor-portal')->assertOk();

        Party::withoutGlobalScopes()->whereKey($this->party->id)->update(['type' => 'vendor']);
        $this->get('/contractor-portal')->assertForbidden();

        Party::withoutGlobalScopes()->whereKey($this->party->id)->update(['type' => 'subcontractor', 'deleted_at' => now()]);
        $this->get('/contractor-portal')->assertForbidden();
        $this->get("/contractor-portal/work-orders/{$this->workOrder->id}")->assertForbidden();

        Sanctum::actingAs($this->contractor);
        $this->getJson('/api/v1/contractor-portal')->assertForbidden();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.contractor', null);
    }

    /** A real bearer token switches the default guard to Sanctum, so the web steps name their guard. */
    public function test_revoked_access_shuts_the_door_at_once_and_can_be_restored(): void
    {
        $token = $this->contractor->createToken('Phone')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->getJson('/api/v1/contractor-portal', $headers)->assertOk();

        $this->actingAs($this->manager, 'web')->post("/contractor-portal/access/{$this->party->id}/logins/{$this->contractor->id}/revoke")->assertRedirect();
        $this->assertFalse($this->contractor->refresh()->is_active);
        $this->signOut();

        $this->getJson('/api/v1/contractor-portal', $headers)->assertUnauthorized();
        $this->actingAs($this->contractor, 'web')->get('/contractor-portal')->assertRedirect(route('login'));
        $this->assertGuest('web');
        $this->post('/login', ['email' => $this->contractor->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->actingAs($this->manager, 'web')->post("/contractor-portal/access/{$this->party->id}/logins/{$this->contractor->id}/restore")->assertRedirect();
        $this->assertTrue($this->contractor->refresh()->is_active);
        $this->signOut();

        $this->actingAs($this->contractor, 'web')->get('/contractor-portal')->assertOk();
    }

    public function test_the_api_gives_the_same_rows_and_nothing_private(): void
    {
        Sanctum::actingAs($this->contractor);

        $me = $this->getJson('/api/v1/me')->assertOk();
        $me->assertJsonPath('user.kind', 'contractor')->assertJsonPath('user.contractor.party_id', $this->party->id)->assertJsonPath('user.contractor.name', 'Shree Formwork');
        $can = $me->json('user.can');
        $this->assertTrue($can['contractor_portal.view']);
        $this->assertFalse($can['project.view']);
        $this->assertFalse($can['money.view']);
        $this->assertFalse($can['contractor.view']);

        $overview = $this->getJson('/api/v1/contractor-portal')->assertOk();
        $overview->assertJsonPath('data.contractor.party_id', $this->party->id)->assertJsonPath('data.summary.contracts', 1)->assertJsonPath('data.summary.paid', self::OUR_PAYMENT);
        $overview->assertJsonPath('data.contracts.0.number', 'WO-OURS')->assertJsonPath('data.contracts.0.work_pct', '70.00')->assertJsonPath('data.contracts.0.days_left', 10)->assertJsonPath('data.contracts.0.deadline_label', 'Before Lakshmi Puja');
        $this->assertNothingPrivate($overview, 'api overview');
        $this->assertStringNotContainsString('"over_limit"', $overview->getContent());
        $this->assertStringNotContainsString('"limit_pct"', $overview->getContent());

        $contract = $this->getJson("/api/v1/contractor-portal/work-orders/{$this->workOrder->id}")->assertOk();
        $contract->assertJsonPath('data.lines.0.rate', self::OUR_RATE)->assertJsonPath('data.lines.0.qty', '100.000')->assertJsonPath('data.lines.0.done_qty', '70.000')->assertJsonPath('data.lines.0.work_pct', '70.00');
        $contract->assertJsonPath('data.parts.0.title', 'Plinth complete')->assertJsonPath('data.payments.0.number', $this->payment->number)->assertJsonPath('data.bills.0.number', $this->bill->number);
        $contract->assertJsonCount(1, 'data.payments')->assertJsonCount(1, 'data.bills');
        $this->assertNothingPrivate($contract, 'api contract');

        $payments = $this->getJson('/api/v1/contractor-portal/payments')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', self::OUR_PAYMENT);
        $this->assertNothingPrivate($payments, 'api payments');

        $bills = $this->getJson('/api/v1/contractor-portal/bills')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $this->bill->number);
        $this->assertNothingPrivate($bills, 'api bills');

        $bill = $this->getJson("/api/v1/contractor-portal/bills/{$this->bill->id}")->assertOk()->assertJsonPath('data.items.0.rate', self::OUR_RATE)->assertJsonPath('data.deductions.0.label', 'Retention');
        $this->assertNothingPrivate($bill, 'api bill');

        $this->getJson("/api/v1/contractor-portal/work-orders/{$this->rivalWorkOrder->id}")->assertNotFound();
        $this->getJson("/api/v1/contractor-portal/bills/{$this->rivalBill->id}")->assertNotFound();
        $this->getJson("/api/v1/contractor-portal/bills/{$this->draftBill->id}")->assertNotFound();
    }

    public function test_staff_see_themselves_as_staff(): void
    {
        Sanctum::actingAs($this->manager);

        $me = $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.kind', 'staff')->assertJsonPath('user.contractor', null);
        $this->assertFalse($me->json('user.can')['contractor_portal.view']);
        $this->assertTrue($me->json('user.can')['contractor.view']);
    }

    public function test_work_on_a_shared_line_is_attributed_to_each_contract(): void
    {
        $this->actingAs($this->owner);
        $progress = app(WorkProgress::class);

        $ours = $progress->workOrderLineFigures($this->workOrder);
        $theirs = $progress->workOrderLineFigures($this->rivalWorkOrder);

        $this->assertSame(['done' => '70.000', 'percent' => '70.00'], array_values($ours)[0]);
        $this->assertSame(['done' => '30.000', 'percent' => '30.00'], array_values($theirs)[0]);
        $this->assertSame(['70.00'], array_values($progress->workOrderLinePercents($this->workOrder)));
    }

    public function test_the_contractor_role_holds_only_the_portal(): void
    {
        $this->assertTrue($this->contractor->isContractor());
        $this->assertTrue($this->contractor->hasPermission('contractor_portal.view'));

        foreach (['project.view', 'boq.view', 'contractor.view', 'money.view', 'user.manage', 'contractor_portal.manage', 'dashboard.view'] as $permission) {
            $this->assertFalse($this->contractor->hasPermission($permission), "A contractor holds {$permission}.");
        }

        $this->assertFalse(User::factory()->make(['role' => 'pm'])->isContractor());
    }
}
