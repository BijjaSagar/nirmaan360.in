<?php

namespace Tests\Feature\Procurement;

use App\Models\Material;
use App\Models\MaterialIssue;
use App\Models\MaterialStore;
use App\Models\MaterialUnit;
use App\Models\MaterialUnitConversion;
use App\Models\Organization;
use App\Models\Party;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Services\Procurement\MaterialIssueService;
use App\Services\Procurement\StockAdjustmentService;
use App\Services\Procurement\StockLedgerService;
use Illuminate\Support\Str;
use Tests\BuildsTenant;

/**
 * A company with one project, one store and two materials:
 * cement counted in kg with 1 bag = 50 kg, and steel counted in kg with 1 MT = 1000 kg.
 */
trait BuildsProcurement
{
    use BuildsTenant;

    protected Organization $organization;

    protected User $owner;

    protected Project $project;

    protected MaterialStore $store;

    protected MaterialUnit $kg;

    protected MaterialUnit $bag;

    protected MaterialUnit $mt;

    protected Material $cement;

    protected Material $steel;

    protected function buildWorld(string $plan = 'business'): void
    {
        $this->organization = $this->tenant('Acme Infra', $plan);
        $this->owner = $this->member($this->organization, 'owner');
        $this->actingAs($this->owner);
        $this->project = $this->makeProject($this->organization);
        $this->store = $this->makeStore($this->project, 'Main store');

        $this->kg = $this->makeUnit($this->organization, 'kg');
        $this->bag = $this->makeUnit($this->organization, 'bag');
        $this->mt = $this->makeUnit($this->organization, 'MT');

        $this->cement = $this->makeMaterial($this->organization, 'CEM-53', 'OPC 53 cement', $this->kg);
        $this->steel = $this->makeMaterial($this->organization, 'TMT-12', 'TMT bar 12 mm', $this->kg);
        $this->addConversion($this->cement, $this->bag, '50');
        $this->addConversion($this->steel, $this->mt, '1000');
    }

    protected function makeStore(Project $project, string $name): MaterialStore
    {
        return MaterialStore::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'name' => $name]);
    }

    protected function makeUnit(Organization $organization, string $code): MaterialUnit
    {
        return MaterialUnit::create(['organization_id' => $organization->id, 'code' => $code]);
    }

    protected function makeMaterial(Organization $organization, string $code, string $name, MaterialUnit $base): Material
    {
        return Material::create(['organization_id' => $organization->id, 'code' => $code, 'name' => $name, 'base_unit_id' => $base->id]);
    }

    protected function addConversion(Material $material, MaterialUnit $unit, string $factor): void
    {
        MaterialUnitConversion::create(['organization_id' => $material->organization_id, 'material_id' => $material->id, 'unit_id' => $unit->id, 'factor' => $factor]);
    }

    protected function makeVendor(string $name, ?Organization $organization = null): Party
    {
        return Party::create(['organization_id' => ($organization ?? $this->organization)->id, 'type' => 'vendor', 'name' => $name]);
    }

    /** Put opening stock in a store, in the material's base unit. */
    protected function stockIn(Material $material, string $qty, ?MaterialStore $store = null): StockAdjustment
    {
        return app(StockAdjustmentService::class)->create($this->project, [
            'store_id' => ($store ?? $this->store)->id,
            'material_id' => $material->id,
            'adjustment_type' => 'opening',
            'qty' => $qty,
            'adjustment_date' => '2026-11-01',
            'reason' => 'Opening balance',
        ], $this->owner);
    }

    protected function issue(Material $material, string $qty, array $line = [], array $header = []): MaterialIssue
    {
        return app(MaterialIssueService::class)->create($this->project, [
            'store_id' => $this->store->id,
            'issue_date' => '2026-11-02',
            ...$header,
            'items' => [['material_id' => $material->id, 'qty' => $qty, 'activity' => 'Slab', ...$line]],
        ], $this->owner);
    }

    protected function balanceOf(Material $material, ?MaterialStore $store = null): string
    {
        return app(StockLedgerService::class)->balance(($store ?? $this->store)->id, $material->id);
    }

    /** An issued PO for 100 bags of cement (5000 kg) at Rs 400 plus 18% GST. */
    protected function issuedOrder(?Party $vendor = null): PurchaseOrder
    {
        $order = PurchaseOrder::create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'party_id' => ($vendor ?? $this->makeVendor('Birla Traders'))->id,
            'store_id' => $this->store->id,
            'number' => 'PO-'.Str::upper(Str::random(6)),
            'po_date' => '2026-11-01',
            'status' => 'ISSUED',
            'subtotal' => '40000.00',
            'gst_amount' => '7200.00',
            'total' => '47200.00',
        ]);

        $order->items()->create([
            'organization_id' => $this->organization->id,
            'material_id' => $this->cement->id,
            'qty' => '100.000',
            'unit_id' => $this->bag->id,
            'base_qty' => '5000.000',
            'rate' => '400.00',
            'gst_pct' => '18.00',
            'amount' => '40000.00',
            'gst_amount' => '7200.00',
        ]);

        return $order->load('items');
    }
}
