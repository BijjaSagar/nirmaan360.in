<?php

namespace Tests\Feature\MaterialControl;

use App\Models\DeliveryChallan;
use App\Models\Material;
use App\Models\Organization;
use App\Models\User;
use App\Services\Access\TeamAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MaterialControlHttpTest extends TestCase
{
    use BuildsMaterialControl, RefreshDatabase;

    private Material $bags;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake();
        $this->setUpControl();
        $this->bags = $this->bulk('CEM', 'Cement', 'bag');
    }

    private function as(string $role): User
    {
        $user = $this->member($this->organization, $role);
        $this->actingAs($user);

        return $user;
    }

    private function challanData(array $overrides = []): array
    {
        return [
            'supplier_name' => 'Gupta Traders',
            'material_id' => $this->bags->id,
            'qty' => '40',
            'challan_date' => today()->toDateString(),
            'vehicle_no' => 'MH12AB1234',
            'client_uuid' => (string) Str::uuid(),
            ...$overrides,
        ];
    }

    private function postChallan(array $overrides = []): TestResponse
    {
        return $this->post(route('material_control.challans.store', $this->project), $this->challanData($overrides));
    }

    private function aChallan(array $attributes = []): DeliveryChallan
    {
        return $this->deliver($this->bags, '50', null, $attributes);
    }

    public function test_every_module_route_is_guarded_by_the_feature_and_a_permission(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->getActionName(), '\\Controllers\\MaterialControl\\'));

        $this->assertGreaterThan(20, $routes->count());

        foreach ($routes as $route) {
            $middleware = collect($route->gatherMiddleware());

            $this->assertTrue($middleware->contains('feature:site_control'), $route->uri().' has no feature check');
            $this->assertTrue($middleware->contains(fn ($m) => is_string($m) && str_starts_with($m, 'can:')), $route->uri().' has no permission check');
        }
    }

    public function test_store_keeper_and_engineer_can_enter_a_delivery_but_accounts_cannot(): void
    {
        $this->as('store');
        $this->postChallan()->assertRedirect();

        $this->as('engineer');
        $this->postChallan()->assertRedirect();

        $this->as('accounts');
        $this->postChallan()->assertForbidden();

        $this->as('viewer');
        $this->postChallan()->assertForbidden();

        $this->assertSame(2, DeliveryChallan::where('project_id', $this->project->id)->count());
    }

    public function test_entering_a_delivery_with_a_photo_stores_it_privately(): void
    {
        $this->as('store');

        $this->postChallan(['photo' => UploadedFile::fake()->image('challan.jpg')])->assertRedirect();

        $challan = DeliveryChallan::where('project_id', $this->project->id)->sole();
        $this->assertNotNull($challan->photo_path);
        Storage::assertExists($challan->photo_path);
        $this->assertStringStartsWith('org-'.$this->organization->id.'/', $challan->photo_path);
        $this->assertTrue($challan->billPending());

        $this->get(route('material_control.challans.photo', $challan))->assertOk();
    }

    public function test_a_form_resubmitted_with_the_same_client_uuid_is_saved_once(): void
    {
        $this->as('engineer');
        $uuid = (string) Str::uuid();

        $this->postChallan(['client_uuid' => $uuid])->assertRedirect();
        $this->postChallan(['client_uuid' => $uuid])->assertRedirect();

        $this->assertSame(1, DeliveryChallan::where('client_uuid', $uuid)->count());
        $this->assertSame('40.000', $this->balanceOf($this->bags));
    }

    public function test_engineer_cannot_enter_a_bill_amount_but_accounts_can(): void
    {
        $challan = $this->aChallan();

        $this->as('engineer');
        $this->post(route('material_control.challans.bill.store', $challan), [
            'bill_no' => 'INV-1', 'bill_amount' => '99999', 'bill' => UploadedFile::fake()->create('bill.pdf', 10, 'application/pdf'),
        ])->assertRedirect();

        $challan->refresh();
        $this->assertFalse($challan->billPending());
        $this->assertNull($challan->bill_amount);
        $this->assertNull($challan->bill_no);

        $this->as('accounts');
        $this->post(route('material_control.challans.bill.store', $challan), ['bill_no' => 'INV-1', 'bill_amount' => '20000'])->assertRedirect();
        $this->assertSame('20000.00', $challan->fresh()->bill_amount);
    }

    public function test_only_people_with_money_view_can_open_the_bill_file(): void
    {
        Storage::put('bills/x.pdf', 'x');
        $challan = $this->aChallan();
        $challan->update(['bill_status' => 'attached', 'bill_path' => 'bills/x.pdf']);

        $this->as('engineer');
        $this->get(route('material_control.challans.bill', $challan))->assertForbidden();

        $this->as('store');
        $this->get(route('material_control.challans.bill', $challan))->assertForbidden();

        $this->as('accounts');
        $this->get(route('material_control.challans.bill', $challan))->assertOk();
    }

    public function test_site_staff_see_quantities_and_no_money(): void
    {
        $item = $this->boqFor($this->bags, '100', '1');
        $this->workDone($item, '100');
        $challan = $this->aChallan();
        $challan->update(['bill_status' => 'attached', 'bill_no' => 'INV-55', 'bill_amount' => '123456.00']);
        $this->useOn($item, $this->bags, '50');

        foreach (['engineer', 'store'] as $role) {
            $this->as($role);

            foreach ([
                route('material_control.index', $this->project),
                route('material_control.challans.index', $this->project),
                route('material_control.challans.show', $challan),
                route('material_control.consumption.index', $this->project),
                route('material_control.stock.index', $this->project),
            ] as $url) {
                $this->get($url)->assertOk()->assertDontSee('₹')->assertDontSee('1,23,456')->assertDontSee('123456')->assertDontSee('INV-55');
            }
        }

        $this->as('accounts');
        $this->get(route('material_control.challans.show', $challan))->assertOk()->assertSee('INV-55')->assertSee('₹1,23,456.00');
    }

    public function test_the_store_keeper_has_no_bills_screen_and_the_accountant_does(): void
    {
        $this->aChallan();

        $this->as('store');
        $this->get(route('material_control.bills.index', $this->project))->assertForbidden();

        $this->as('accounts');
        $this->get(route('material_control.bills.index', $this->project))->assertOk()->assertSee('Bill pending');
    }

    public function test_a_marked_supplier_shows_a_warning_on_the_entry_form(): void
    {
        $supplier = $this->makeVendor('Patil Sand Supply');
        $this->actingAs($this->owner);
        $this->deliver($this->bags, '3', $supplier, ['challan_qty' => '4']);
        $this->deliver($this->bags, '3', $supplier, ['challan_qty' => '4']);

        $this->as('store');

        $this->get(route('material_control.challans.create', $this->project))
            ->assertOk()
            ->assertSee('Patil Sand Supply')
            ->assertSee('short 2 times');

        $this->get(route('material_control.suppliers.index'))->assertOk()->assertSee('short 2 times');
    }

    public function test_only_a_manager_can_rate_a_supplier(): void
    {
        $supplier = $this->makeVendor('New Traders');

        $this->as('store');
        $this->patch(route('material_control.suppliers.update', $supplier), ['rating' => 1])->assertForbidden();

        $this->as('pm');
        $this->patch(route('material_control.suppliers.update', $supplier), ['rating' => 2, 'marked' => '1', 'marked_reason' => 'Short twice'])->assertRedirect();

        $this->get(route('material_control.suppliers.show', $supplier))->assertOk()->assertSee('Short twice');
    }

    public function test_only_a_manager_can_reverse_a_delivery(): void
    {
        $challan = $this->aChallan();

        $this->as('engineer');
        $this->post(route('material_control.challans.reverse', $challan), ['reason' => 'Wrong'])->assertForbidden();

        $this->as('pm');
        $this->post(route('material_control.challans.reverse', $challan), ['reason' => 'Wrong'])->assertRedirect();

        $this->assertSame('REVERSED', $challan->fresh()->status);
    }

    public function test_a_reason_must_be_written(): void
    {
        $item = $this->boqFor($this->bags, '100', '1');
        $this->workDone($item, '100');
        $this->deliver($this->bags, '105');
        $this->useOn($item, $this->bags, '105');

        $this->as('engineer');
        $this->post(route('material_control.reasons.store', $this->project), ['material_id' => $this->bags->id, 'flag_type' => 'over_used', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('material_control.reasons.store', $this->project), ['material_id' => $this->bags->id, 'flag_type' => 'over_used', 'reason' => 'Formwork leak'])->assertSessionHasNoErrors();

        $this->get(route('material_control.index', $this->project))->assertOk()->assertSee('Formwork leak');
    }

    public function test_screens_open_for_someone_with_view_permission(): void
    {
        $item = $this->boqFor($this->bags, '100', '1');
        $this->workDone($item, '50');
        $challan = $this->aChallan();
        $this->useOn($item, $this->bags, '20');
        $this->countStock($this->bags, '28');

        $this->as('viewer');

        foreach ([
            route('material_control.index', $this->project),
            route('material_control.challans.index', $this->project),
            route('material_control.challans.show', $challan),
            route('material_control.consumption.index', $this->project),
            route('material_control.stock.index', $this->project),
            route('material_control.extra.index', $this->project),
            route('material_control.suppliers.index'),
            route('material_control.suppliers.show', $challan->supplier),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_a_company_on_a_plan_without_the_feature_is_refused(): void
    {
        $organization = Organization::create(['name' => 'Tiny', 'slug' => 'tiny-'.Str::random(4), 'plan' => 'free', 'plan_status' => 'active']);
        $organization->seedDefaults();
        $owner = $this->member($organization, 'owner');
        $project = $this->makeProject($organization);

        $this->actingAs($owner);

        $this->get(route('material_control.index', $project))->assertForbidden();
    }

    public function test_another_company_cannot_reach_a_challan_or_use_its_ids(): void
    {
        $challan = $this->aChallan();
        $other = $this->tenant('Other Co');
        $stranger = $this->member($other, 'owner');
        $theirProject = $this->makeProject($other);

        $this->actingAs($stranger);

        $this->get(route('material_control.challans.show', $challan))->assertNotFound();
        $this->get(route('material_control.challans.photo', $challan))->assertNotFound();
        $this->get(route('material_control.index', $this->project))->assertNotFound();
        $this->post(route('material_control.challans.bill.store', $challan), ['bill_no' => 'X'])->assertNotFound();
        $this->post(route('material_control.challans.reverse', $challan), ['reason' => 'Wrong'])->assertNotFound();

        $this->post(route('material_control.challans.store', $theirProject), [
            'supplier_name' => 'Anyone', 'material_id' => $this->bags->id, 'qty' => '1', 'challan_date' => today()->toDateString(),
        ])->assertSessionHasErrors('material_id');

        $this->assertSame('50.000', $this->balanceOf($this->bags));
    }

    public function test_someone_limited_to_another_project_cannot_reach_this_challan(): void
    {
        $challan = $this->aChallan();
        $otherProject = $this->makeProject($this->organization, ['name' => 'Other Site']);

        $limited = $this->member($this->organization, 'pm');
        app(TeamAccessService::class)->applyProjects($limited, true, [$otherProject->id]);
        $this->actingAs($limited->refresh());

        $this->get(route('material_control.challans.show', $challan))->assertNotFound();
        $this->get(route('material_control.index', $this->project))->assertNotFound();
        $this->get(route('material_control.index', $otherProject))->assertOk();
    }

    public function test_a_retried_delivery_from_a_hidden_project_is_not_found_and_writes_nothing(): void
    {
        $uuid = (string) Str::uuid();
        $this->aChallan(['client_uuid' => $uuid]);
        $otherProject = $this->makeProject($this->organization, ['name' => 'Other Site']);

        $limited = $this->member($this->organization, 'pm');
        app(TeamAccessService::class)->applyProjects($limited, true, [$otherProject->id]);
        $this->actingAs($limited->refresh());

        $this->post(route('material_control.challans.store', $otherProject), $this->challanData(['client_uuid' => $uuid]))
            ->assertSessionHasErrors('client_uuid');

        $this->assertSame(1, DeliveryChallan::withoutGlobalScopes()->where('client_uuid', $uuid)->count());
    }
}
