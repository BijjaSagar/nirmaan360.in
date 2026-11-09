<?php

namespace Tests\Feature\Procurement;

use App\Models\Approval;
use App\Models\Indent;
use App\Models\Material;
use App\Models\MaterialIssue;
use App\Models\MaterialReturn;
use App\Models\MaterialStore;
use App\Models\Party;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\RfqQuotation;
use App\Models\StockTransfer;
use App\Services\Procurement\GrnService;
use App\Services\Procurement\MaterialCatalogService;
use App\Services\Procurement\StockTransferService;
use App\Support\Modules;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcurementHttpTest extends TestCase
{
    use BuildsProcurement, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildWorld();
    }

    private function indentBody(array $overrides = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'store_id' => $this->store->id,
            'items' => [['material_id' => $this->cement->id, 'qty' => '100', 'unit_id' => $this->bag->id]],
            ...$overrides,
        ];
    }

    public function test_the_whole_chain_works_through_the_screens(): void
    {
        $vendor = $this->makeVendor('Birla Traders');
        $boq = $this->makeBoqItem($this->project);

        // Indent, then approval by someone other than the requester.
        $engineer = $this->member($this->organization, 'engineer');
        $this->actingAs($engineer)->post(route('procurement.indents.store', $this->project), $this->indentBody())->assertRedirect();
        $indent = Indent::firstOrFail();
        $this->post(route('procurement.indents.submit', $indent))->assertRedirect();

        $this->actingAs($this->member($this->organization, 'pm'))
            ->post(route('approvals.act', Approval::firstOrFail()), ['action' => 'approve'])
            ->assertRedirect();
        $this->assertSame('APPROVED', $indent->fresh()->status);

        // RFQ, quotation, award.
        $this->actingAs($this->owner)->post(route('procurement.rfqs.store', $indent), ['party_ids' => [$vendor->id], 'client_uuid' => (string) Str::uuid()])->assertRedirect();
        $rfq = Rfq::firstOrFail();
        $line = $rfq->items()->firstOrFail();
        $this->post(route('procurement.rfqs.quotations.store', $rfq), [
            'party_id' => $vendor->id,
            'items' => [['rfq_item_id' => $line->id, 'rate' => '400', 'gst_pct' => '18']],
        ])->assertRedirect();
        $quotation = RfqQuotation::firstOrFail();
        $this->get(route('procurement.rfqs.show', $rfq))->assertOk()->assertSee('Birla Traders');

        $this->post(route('procurement.rfqs.award', $rfq), [
            'client_uuid' => (string) Str::uuid(),
            'picks' => [['rfq_item_id' => $line->id, 'quotation_id' => $quotation->id]],
        ])->assertRedirect();
        $order = PurchaseOrder::firstOrFail();
        $this->assertSame('47200.00', $order->total);

        // PO goes out with a PDF.
        $this->post(route('procurement.purchase-orders.issue', $order))->assertRedirect();
        $this->get(route('procurement.purchase-orders.pdf', $order))->assertOk()->assertHeader('content-type', 'application/pdf');

        // Goods arrive in two lots.
        $item = $order->items()->firstOrFail();
        $this->get(route('procurement.grns.create', [$this->project, 'purchase_order' => $order->id]))->assertOk();
        $this->post(route('procurement.grns.store', $this->project), [
            'client_uuid' => (string) Str::uuid(),
            'purchase_order_id' => $order->id,
            'grn_date' => today()->toDateString(),
            'items' => [['purchase_order_item_id' => $item->id, 'qty' => '60']],
        ])->assertRedirect();
        $this->assertSame('PARTIAL', $order->fresh()->status);
        $this->assertSame('3000.000', $this->balanceOf($this->cement));

        // Issue to a BOQ item, return some, transfer some.
        $this->post(route('procurement.issues.store', $this->project), [
            'client_uuid' => (string) Str::uuid(),
            'store_id' => $this->store->id,
            'issue_date' => today()->toDateString(),
            'items' => [['material_id' => $this->cement->id, 'qty' => '20', 'unit_id' => $this->bag->id, 'boq_item_id' => $boq->id]],
        ])->assertRedirect();
        $this->assertSame('2000.000', $this->balanceOf($this->cement));

        $issue = MaterialIssue::firstOrFail();
        $this->post(route('procurement.returns.store-from-issue', $issue), [
            'return_date' => today()->toDateString(),
            'items' => [['line_id' => $issue->items()->firstOrFail()->id, 'qty' => '5']],
        ])->assertRedirect();
        $this->assertSame('2250.000', $this->balanceOf($this->cement));
        $this->get(route('procurement.returns.show', MaterialReturn::firstOrFail()))->assertOk();

        $yard = $this->makeStore($this->project, 'Yard');
        $this->post(route('procurement.transfers.store', $this->project), [
            'from_store_id' => $this->store->id,
            'to_store_id' => $yard->id,
            'dispatch_date' => today()->toDateString(),
            'items' => [['material_id' => $this->cement->id, 'qty' => '250']],
        ])->assertRedirect();
        $transfer = StockTransfer::firstOrFail();
        $this->post(route('procurement.transfers.receive', $transfer), ['receive_date' => today()->toDateString()])->assertRedirect();

        $this->assertSame('2000.000', $this->balanceOf($this->cement));
        $this->assertSame('250.000', $this->balanceOf($this->cement, $yard));
    }

    public function test_every_screen_renders_with_data(): void
    {
        $vendor = $this->makeVendor('Birla Traders');
        $boq = $this->makeBoqItem($this->project);
        $order = $this->issuedOrder($vendor);
        $this->stockIn($this->cement, '1000');
        $grn = app(GrnService::class)->create($this->project, [
            'purchase_order_id' => $order->id,
            'grn_date' => '2026-11-03',
            'items' => [['purchase_order_item_id' => $order->items[0]->id, 'qty' => '10']],
        ], $this->owner);
        $issue = $this->issue($this->cement, '10', ['boq_item_id' => $boq->id, 'activity' => null]);
        $yard = $this->makeStore($this->project, 'Yard');
        $transfer = app(StockTransferService::class)->dispatch($this->project, [
            'from_store_id' => $this->store->id, 'to_store_id' => $yard->id, 'dispatch_date' => '2026-11-03',
            'items' => [['material_id' => $this->cement->id, 'qty' => '5']],
        ], $this->owner);
        app(MaterialCatalogService::class)->saveNorm($this->project, ['boq_item_id' => $boq->id, 'material_id' => $this->cement->id, 'qty_per_unit' => '1']);
        app(MaterialCatalogService::class)->setReorderLevel($this->store, $this->cement, '99999');
        $indent = Indent::create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'store_id' => $this->store->id, 'number' => 'IND-1']);

        foreach ([
            route('procurement.materials.index'),
            route('procurement.materials.show', $this->cement),
            route('procurement.stock.index', $this->project),
            route('procurement.stock.card', [$this->store, $this->cement]),
            route('procurement.indents.index', $this->project),
            route('procurement.indents.create', $this->project),
            route('procurement.indents.show', $indent),
            route('procurement.indents.edit', $indent),
            route('procurement.rfqs.index', $this->project),
            route('procurement.purchase-orders.index', $this->project),
            route('procurement.purchase-orders.show', $order),
            route('procurement.grns.index', $this->project),
            route('procurement.grns.show', $grn),
            route('procurement.returns.create-from-grn', $grn),
            route('procurement.issues.index', $this->project),
            route('procurement.issues.create', $this->project),
            route('procurement.issues.show', $issue),
            route('procurement.returns.create-from-issue', $issue),
            route('procurement.returns.index', $this->project),
            route('procurement.transfers.index', $this->project),
            route('procurement.transfers.create', $this->project),
            route('procurement.transfers.show', $transfer),
            route('procurement.reconciliation.index', $this->project),
            route('procurement.reconciliation.index', [$this->project, 'basis' => 'approved']),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_odd_query_strings_do_not_break_screens(): void
    {
        $this->get(route('procurement.reconciliation.index', [$this->project, 'basis' => ['x']]))->assertOk();
        $this->get(route('procurement.grns.create', [$this->project, 'purchase_order' => ['x']]))->assertNotFound();
    }

    public function test_screens_are_translated(): void
    {
        app()->setLocale('hi');

        $this->assertNotSame('Purchase orders', __('Purchase orders'));
        $this->assertNotSame('Stores & stock', __('Stores & stock'));

        app()->setLocale('mr');

        $this->assertNotSame('Goods receipts', __('Goods receipts'));
        $this->assertNotSame('Wastage alerts', __('Wastage alerts'));
    }

    public function test_the_module_is_in_the_plans_it_was_sold_on(): void
    {
        foreach (['contractor', 'business', 'enterprise'] as $plan) {
            $this->assertContains('procurement', config("buildos.plans.{$plan}.features"), $plan);
        }

        $this->assertNotContains('procurement', config('buildos.plans.free.features'));
    }

    public function test_saving_plans_in_admin_does_not_switch_the_module_off(): void
    {
        $settings = app(PlatformSettings::class);
        $settings->set(['plans' => json_encode(['business' => ['features' => ['site', 'billing']]])]);

        // What every request does at boot, in the same order.
        $settings->applyToConfig();
        Modules::apply();

        $this->assertContains('procurement', config('buildos.plans.business.features'));
    }

    public function test_a_contractor_plan_can_open_it_and_a_free_plan_cannot(): void
    {
        $contractor = $this->tenant('Small Co', 'contractor');
        $this->actingAs($this->member($contractor, 'owner'))->get(route('procurement.materials.index'))->assertOk();

        $free = $this->tenant('Free Co', 'free');
        $this->actingAs($this->member($free, 'owner'))->get(route('procurement.materials.index'))->assertForbidden();
    }

    public function test_the_menu_shows_the_module_only_where_it_is_included(): void
    {
        $this->get('/dashboard')->assertOk()->assertSee(__('Materials'));

        $onFree = array_filter(Modules::nav('company', $this->tenant('Free Co', 'free')), fn ($item) => str_starts_with($item['route'], 'procurement.'));
        $this->assertSame([], $onFree);
    }

    public function test_viewer_can_look_but_not_change_anything(): void
    {
        $this->actingAs($this->member($this->organization, 'viewer'));

        $this->get(route('procurement.stock.index', $this->project))->assertOk();
        $this->post(route('procurement.indents.store', $this->project), $this->indentBody())->assertForbidden();
        $this->post(route('procurement.issues.store', $this->project), [])->assertForbidden();
        $this->post(route('procurement.grns.store', $this->project), [])->assertForbidden();
        $this->post(route('procurement.transfers.store', $this->project), [])->assertForbidden();
        $this->post(route('procurement.materials.store'), [])->assertForbidden();
        $this->post(route('procurement.stock.adjustments.store', $this->project), [])->assertForbidden();
    }

    public function test_store_keeper_handles_stock_but_not_purchasing_or_reversals(): void
    {
        $this->stockIn($this->cement, '100');
        $issue = $this->issue($this->cement, '10');
        $this->actingAs($this->member($this->organization, 'store'));

        $this->post(route('procurement.issues.store', $this->project), [
            'client_uuid' => (string) Str::uuid(),
            'store_id' => $this->store->id,
            'issue_date' => today()->toDateString(),
            'items' => [['material_id' => $this->cement->id, 'qty' => '1', 'activity' => 'Slab']],
        ])->assertRedirect();
        $this->assertSame('89.000', $this->balanceOf($this->cement));

        $this->post(route('procurement.issues.reverse', $issue), ['reason' => 'x'])->assertForbidden();
        $this->post(route('procurement.stock.adjustments.store', $this->project), [])->assertForbidden();
        $this->post(route('procurement.materials.store'), [])->assertForbidden();
    }

    public function test_engineer_cannot_approve_an_indent(): void
    {
        $this->assertFalse($this->member($this->organization, 'engineer')->can('indent.approve'));
        $this->assertTrue($this->member($this->organization, 'pm')->can('indent.approve'));
    }

    public function test_people_without_view_rights_are_refused(): void
    {
        config(['buildos.roles.limited' => ['label' => 'Limited', 'permissions' => ['dashboard.view']]]);
        $this->actingAs($this->member($this->organization, 'limited'));

        $this->get(route('procurement.materials.index'))->assertForbidden();
        $this->get(route('procurement.stock.index', $this->project))->assertForbidden();
        $this->get(route('procurement.reconciliation.index', $this->project))->assertForbidden();
    }

    public function test_another_company_cannot_reach_anything(): void
    {
        $order = $this->issuedOrder();
        $this->stockIn($this->cement, '100');
        $issue = $this->issue($this->cement, '10');
        $indent = Indent::create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'store_id' => $this->store->id, 'number' => 'IND-1']);
        $store = $this->store;
        $cement = $this->cement;
        $project = $this->project;

        $this->actingAs($this->member($this->tenant('Other Co'), 'owner'));

        foreach ([
            route('procurement.materials.show', $cement),
            route('procurement.stock.index', $project),
            route('procurement.stock.card', [$store, $cement]),
            route('procurement.indents.show', $indent),
            route('procurement.purchase-orders.show', $order),
            route('procurement.purchase-orders.pdf', $order),
            route('procurement.issues.show', $issue),
            route('procurement.grns.index', $project),
        ] as $url) {
            $this->get($url)->assertNotFound();
        }

        $this->post(route('procurement.indents.store', $project), [])->assertNotFound();
        $this->post(route('procurement.issues.reverse', $issue), ['reason' => 'x'])->assertNotFound();
        $this->post(route('procurement.purchase-orders.issue', $order))->assertNotFound();
    }

    public function test_foreign_ids_from_another_company_are_refused_on_forms(): void
    {
        $other = $this->tenant('Other Co');
        $theirMaterial = $this->makeMaterial($other, 'X', 'Their cement', $this->makeUnit($other, 'kg'));
        $theirVendor = Party::create(['organization_id' => $other->id, 'type' => 'vendor', 'name' => 'Theirs']);
        $otherStore = MaterialStore::create(['organization_id' => $other->id, 'project_id' => $this->makeProject($other)->id, 'name' => 'Their store']);

        $this->post(route('procurement.indents.store', $this->project), $this->indentBody([
            'items' => [['material_id' => $theirMaterial->id, 'qty' => '1']],
        ]))->assertSessionHasErrors('items.0.material_id');

        $this->post(route('procurement.indents.store', $this->project), $this->indentBody(['store_id' => $otherStore->id]))
            ->assertSessionHasErrors('store_id');

        $indent = Indent::create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'store_id' => $this->store->id, 'number' => 'IND-1', 'status' => 'APPROVED']);
        $this->post(route('procurement.rfqs.store', $indent), ['party_ids' => [$theirVendor->id]])
            ->assertSessionHasErrors('party_ids.0');

        $this->post(route('procurement.transfers.store', $this->project), [
            'from_store_id' => $this->store->id,
            'to_store_id' => $otherStore->id,
            'dispatch_date' => today()->toDateString(),
            'items' => [['material_id' => $this->cement->id, 'qty' => '1']],
        ])->assertSessionHasErrors('to_store_id');
    }

    public function test_a_form_resubmitted_with_the_same_uuid_makes_one_indent(): void
    {
        $body = $this->indentBody();

        $this->post(route('procurement.indents.store', $this->project), $body)->assertRedirect();
        $this->post(route('procurement.indents.store', $this->project), $body)->assertRedirect();

        $this->assertSame(1, Indent::count());
    }

    public function test_a_material_can_be_added_with_a_conversion(): void
    {
        $this->post(route('procurement.materials.store'), [
            'client_uuid' => (string) Str::uuid(),
            'code' => 'SAND-1',
            'name' => 'River sand',
            'base_unit_id' => $this->kg->id,
            'gst_pct' => '5',
        ])->assertRedirect();

        $material = Material::where('code', 'SAND-1')->firstOrFail();
        $this->post(route('procurement.materials.conversions.store', $material), ['unit_id' => $this->bag->id, 'factor' => '40'])->assertRedirect();

        $this->assertSame('40.000000', $material->conversions()->firstOrFail()->factor);
    }

    public function test_the_base_unit_is_fixed_once_a_material_has_moved(): void
    {
        $this->stockIn($this->cement, '10');

        $this->patch(route('procurement.materials.update', $this->cement), [
            'code' => $this->cement->code,
            'name' => $this->cement->name,
            'base_unit_id' => $this->bag->id,
            'gst_pct' => '18',
        ])->assertSessionHasErrors('base_unit_id');
    }

    public function test_a_store_can_be_added_and_reorder_set(): void
    {
        $this->post(route('procurement.stock.stores.store', $this->project), ['name' => 'Second store', 'client_uuid' => (string) Str::uuid()])->assertRedirect();
        $this->assertSame(2, MaterialStore::where('project_id', $this->project->id)->count());

        $this->patch(route('procurement.stock.reorder', [$this->store, $this->cement]), ['reorder_level' => '50'])->assertRedirect();
        $this->get(route('procurement.stock.index', $this->project))->assertOk()->assertSee(__('Below reorder level'));
    }

    public function test_an_indent_in_the_approval_inbox_links_to_the_document(): void
    {
        $this->post(route('procurement.indents.store', $this->project), $this->indentBody())->assertRedirect();
        $indent = Indent::firstOrFail();
        $this->post(route('procurement.indents.submit', $indent))->assertRedirect();

        $this->assertSame(route('procurement.indents.show', $indent), Approval::firstOrFail()->documentUrl());
        $this->actingAs($this->member($this->organization, 'pm'))->get(route('approvals.index'))->assertOk()->assertSee($indent->number);
    }

    public function test_an_indent_in_the_mobile_approval_list_does_not_break_it(): void
    {
        $this->post(route('procurement.indents.store', $this->project), $this->indentBody())->assertRedirect();
        $this->post(route('procurement.indents.submit', Indent::firstOrFail()))->assertRedirect();

        $this->actingAs($this->member($this->organization, 'pm'), 'sanctum')->getJson('/api/v1/approvals')->assertOk();
    }

    public function test_a_blocked_rejection_of_stock_shows_the_reason_on_the_form(): void
    {
        $this->stockIn($this->cement, '10');

        $this->from(route('procurement.issues.create', $this->project))->post(route('procurement.issues.store', $this->project), [
            'client_uuid' => (string) Str::uuid(),
            'store_id' => $this->store->id,
            'issue_date' => today()->toDateString(),
            'items' => [['material_id' => $this->cement->id, 'qty' => '11', 'activity' => 'Slab']],
        ])->assertRedirect(route('procurement.issues.create', $this->project))->assertSessionHasErrors('stock');
    }
}
